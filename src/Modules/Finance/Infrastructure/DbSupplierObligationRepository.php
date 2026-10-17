<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierObligationRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierObligation;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as P;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as T;

/**
 * Obligations, their history and their allocations on disk.
 *
 * **The balance guard is in the statement, not above it.** `alpha.50` paid
 * for the other shape on stock: a read, then an unconditional write, then a
 * read-back that proves only that A write happened. Here the insert is
 *
 *     INSERT INTO allocations (…)
 *     SELECT …, %d, …
 *       FROM obligations o
 *      WHERE o.id = %d
 *        AND o.agreed_minor IS NOT NULL
 *        AND COALESCE((SELECT SUM(a.amount_minor) FROM allocations a
 *                        INNER JOIN payments p ON p.id = a.payment_id
 *                       WHERE a.obligation_id = o.id AND p.voided_at IS NULL), 0)
 *            + %d <= o.agreed_minor
 *
 * so the arithmetic that decides is evaluated by the database, against the
 * rows as they are at that instant, inside the caller's transaction. Two
 * requests that both read a balance of 5,000,000 and both try to put
 * 3,000,000 against it cannot both land: the second waits on the row lock the
 * first took and then fails its own `WHERE`.
 *
 * A voided payment's allocations are left on disk and left OUT of the sum —
 * «سابقه حذف نشود» and «آزادسازی مانده … فقط پس از موفقیت کامل عملیات», which
 * together mean the rows stay and the join decides.
 */
final class DbSupplierObligationRepository implements SupplierObligationRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function agree(
        int $orderItemId,
        int $supplierUserId,
        ?int $agreedMinor,
        string $currency,
        int $exponent,
        int $actorId,
        string $reason
    ): array {
        if ($orderItemId <= 0 || $supplierUserId <= 0 || $currency === '') {
            return $this->agreeRefusal(self::FAILED);
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction && !$this->db->begin()) {
            return $this->agreeRefusal(self::FAILED);
        }

        // The row first, so the lock below has something to take. `INSERT
        // IGNORE` because two managers opening the same item is ordinary.
        $created = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->obligations() . '`
             (order_item_id, supplier_user_id, agreed_minor, currency, exponent, agreed_by, created_at, updated_at)
             VALUES (%d, %d, NULL, %s, %d, %d, %s, %s)',
            [$orderItemId, $supplierUserId, $currency, $exponent, 0, $now, $now]
        );
        if ($created === null) {
            return $this->fail($ownTransaction);
        }

        $locked = $this->db->getRow(
            'SELECT id, agreed_minor FROM `' . $this->obligations() . '`
              WHERE order_item_id = %d FOR UPDATE',
            [$orderItemId]
        );
        if ($locked === null) {
            return $this->fail($ownTransaction);
        }
        $obligationId = (int) $locked['id'];
        $previous = ($locked['agreed_minor'] ?? null) === null ? null : (int) $locked['agreed_minor'];
        $paid = $this->paidAgainst($obligationId);

        if ($previous === $agreedMinor) {
            // Nothing changed, so nothing is written — a history row saying
            // «from 5,000,000 to 5,000,000» is noise in the one place a
            // person goes to find out what changed.
            if ($ownTransaction && !$this->db->commit()) {
                return $this->fail($ownTransaction);
            }
            return [
                'outcome' => self::UNCHANGED,
                'obligation_id' => $obligationId,
                'previous_minor' => $previous,
                'paid_minor' => $paid,
            ];
        }
        if ($agreedMinor !== null && $agreedMinor < $paid) {
            // Not a correction. Lowering the agreed amount under what has
            // already been handed over says an over-payment happened, and §3
            // has not defined what one of those is.
            if ($ownTransaction) {
                $this->db->rollback();
            }
            return [
                'outcome' => self::BELOW_PAID,
                'obligation_id' => $obligationId,
                'previous_minor' => $previous,
                'paid_minor' => $paid,
            ];
        }

        $updated = $this->db->execute(
            'UPDATE `' . $this->obligations() . '`
                SET agreed_minor = ' . ($agreedMinor === null ? 'NULL' : '%d') . ',
                    currency = %s, exponent = %d, agreed_by = %d, updated_at = %s
              WHERE id = %d',
            $agreedMinor === null
                ? [$currency, $exponent, $actorId, $now, $obligationId]
                : [$agreedMinor, $currency, $exponent, $actorId, $now, $obligationId]
        );
        if ($updated === null) {
            return $this->fail($ownTransaction);
        }
        // APPEND-ONLY, and in the same unit of work as the change it records:
        // a history that can be missing the change beside it is not a history.
        $params = [$obligationId];
        if ($previous !== null) {
            $params[] = $previous;
        }
        if ($agreedMinor !== null) {
            $params[] = $agreedMinor;
        }
        array_push($params, $currency, $exponent, self::shorten($reason), $actorId, $now);
        $logged = $this->db->execute(
            'INSERT INTO `' . $this->history() . '`
             (obligation_id, previous_minor, new_minor, currency, exponent, reason, changed_by, created_at)
             VALUES (%d, ' . ($previous === null ? 'NULL' : '%d') . ', '
                . ($agreedMinor === null ? 'NULL' : '%d') . ', %s, %d, %s, %d, %s)',
            $params
        );
        if ($logged === null) {
            return $this->fail($ownTransaction);
        }
        if ($ownTransaction && !$this->db->commit()) {
            return $this->fail($ownTransaction);
        }
        return [
            'outcome' => self::AGREED,
            'obligation_id' => $obligationId,
            'previous_minor' => $previous,
            'paid_minor' => $paid,
        ];
    }

    public function allocate(
        int $paymentId,
        int $obligationId,
        int $orderItemId,
        int $supplierUserId,
        int $amountMinor,
        string $currency,
        int $exponent
    ): array {
        if ($paymentId <= 0 || $obligationId <= 0 || $amountMinor <= 0) {
            return ['outcome' => self::FAILED, 'allocation_id' => 0, 'balance_minor' => null];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // The lock first, so two records of a partial payment queue rather
        // than race. `FOR UPDATE` on the obligation and not on the payment:
        // the balance is a property of the obligation, and that is the row
        // both writers must pass through.
        $row = $this->db->getRow(
            'SELECT agreed_minor FROM `' . $this->obligations() . '` WHERE id = %d FOR UPDATE',
            [$obligationId]
        );
        if ($row === null) {
            return ['outcome' => self::FAILED, 'allocation_id' => 0, 'balance_minor' => null];
        }
        if (($row['agreed_minor'] ?? null) === null) {
            return ['outcome' => self::NOT_AGREED, 'allocation_id' => 0, 'balance_minor' => null];
        }

        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->allocations() . '`
             (payment_id, obligation_id, order_item_id, supplier_user_id, amount_minor, currency, exponent, created_at)
             SELECT %d, o.id, %d, %d, %d, %s, %d, %s
               FROM `' . $this->obligations() . '` o
              WHERE o.id = %d
                AND o.agreed_minor IS NOT NULL
                AND COALESCE((SELECT SUM(a.amount_minor)
                                FROM `' . $this->allocations() . '` a
                          INNER JOIN `' . $this->payments() . '` p ON p.id = a.payment_id
                               WHERE a.obligation_id = o.id AND p.voided_at IS NULL), 0) + %d
                    <= o.agreed_minor',
            [
                $paymentId, $orderItemId, $supplierUserId, $amountMinor, $currency, $exponent, $now,
                $obligationId, $amountMinor,
            ]
        );
        if ($written === null) {
            return ['outcome' => self::FAILED, 'allocation_id' => 0, 'balance_minor' => null];
        }
        if ($written === 1) {
            $id = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
            $agreed = (int) $row['agreed_minor'];
            return [
                'outcome' => self::ALLOCATED,
                'allocation_id' => $id,
                'balance_minor' => $agreed - $this->paidAgainst($obligationId),
            ];
        }
        // Nought has TWO causes and they are two sentences: the unique key
        // refused (this payment already has a row against this obligation),
        // or the `WHERE` found no obligation with room. Asked while the lock
        // is still held, so the answer is about the rows the statement saw —
        // and only to pick the sentence; the guard was the statement.
        $duplicate = (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->allocations() . '`
              WHERE payment_id = %d AND obligation_id = %d',
            [$paymentId, $obligationId]
        ) ?? 0);
        $agreed = (int) $row['agreed_minor'];
        return [
            'outcome' => $duplicate > 0 ? self::ALREADY_ALLOCATED : self::ABOVE_BALANCE,
            'allocation_id' => 0,
            'balance_minor' => $agreed - $this->paidAgainst($obligationId),
        ];
    }

    public function forOrderItem(int $orderItemId): ?SupplierObligation
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->obligations() . '` WHERE order_item_id = %d',
            [$orderItemId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function find(int $obligationId): ?SupplierObligation
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->obligations() . '` WHERE id = %d', [$obligationId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function historyFor(int $obligationId, int $limit = 50): array
    {
        $rows = [];
        foreach ($this->db->getResults(
            'SELECT id, previous_minor, new_minor, reason, changed_by, created_at
               FROM `' . $this->history() . '` WHERE obligation_id = %d ORDER BY id DESC LIMIT %d',
            [$obligationId, max(1, $limit)]
        ) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'previous_minor' => ($row['previous_minor'] ?? null) === null ? null : (int) $row['previous_minor'],
                'new_minor' => ($row['new_minor'] ?? null) === null ? null : (int) $row['new_minor'],
                'reason' => (string) ($row['reason'] ?? ''),
                'changed_by' => (int) ($row['changed_by'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $rows;
    }

    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->obligations() . '`
              WHERE supplier_user_id = %d ORDER BY id DESC LIMIT %d',
            [$supplierUserId, max(1, $limit)]
        ));
    }

    public function allocationsOf(int $paymentId): array
    {
        $rows = [];
        foreach ($this->db->getResults(
            'SELECT obligation_id, order_item_id, amount_minor FROM `' . $this->allocations() . '`
              WHERE payment_id = %d ORDER BY id',
            [$paymentId]
        ) as $row) {
            $rows[] = [
                'obligation_id' => (int) $row['obligation_id'],
                'order_item_id' => (int) $row['order_item_id'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }
        return $rows;
    }

    /**
     * What stands against this obligation — voided payments excluded.
     *
     * Excluded by a join rather than by deleting the allocation rows, because
     * «سابقه حذف نشود»: the rows of a voided payment are the record of what
     * was undone, and a sum that ignores them is the only thing that has to
     * change.
     */
    private function paidAgainst(int $obligationId): int
    {
        return (int) ($this->db->getVar(
            'SELECT COALESCE(SUM(a.amount_minor), 0)
               FROM `' . $this->allocations() . '` a
         INNER JOIN `' . $this->payments() . '` p ON p.id = a.payment_id
              WHERE a.obligation_id = %d AND p.voided_at IS NULL',
            [$obligationId]
        ) ?? 0);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): SupplierObligation
    {
        $currency = (string) ($row['currency'] ?? 'IRR');
        $exponent = (int) ($row['exponent'] ?? 0);
        $id = (int) ($row['id'] ?? 0);
        $agreed = ($row['agreed_minor'] ?? null) === null
            ? null
            : Money::of((int) $row['agreed_minor'], $currency, $exponent);
        return new SupplierObligation(
            $id,
            (int) ($row['order_item_id'] ?? 0),
            (int) ($row['supplier_user_id'] ?? 0),
            $agreed,
            $this->paidAgainst($id),
            $currency,
            $exponent,
            (int) ($row['agreed_by'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    /** @return array{outcome:string, obligation_id:int, previous_minor:?int, paid_minor:int} */
    private function agreeRefusal(string $outcome): array
    {
        return ['outcome' => $outcome, 'obligation_id' => 0, 'previous_minor' => null, 'paid_minor' => 0];
    }

    /** @return array{outcome:string, obligation_id:int, previous_minor:?int, paid_minor:int} */
    private function fail(bool $ownTransaction): array
    {
        if ($ownTransaction) {
            $this->db->rollback();
        }
        return $this->agreeRefusal(self::FAILED);
    }

    /** 255 bytes of reason, cut on a character boundary rather than mid-byte. */
    private static function shorten(string $reason): string
    {
        $reason = trim($reason);
        return function_exists('mb_substr') ? mb_substr($reason, 0, 255) : substr($reason, 0, 255);
    }

    private function obligations(): string
    {
        return T::table($this->db, T::OBLIGATIONS);
    }

    private function history(): string
    {
        return T::table($this->db, T::OBLIGATION_HISTORY);
    }

    private function allocations(): string
    {
        return T::table($this->db, T::ALLOCATIONS);
    }

    private function payments(): string
    {
        return P::table($this->db, P::PAYMENTS);
    }
}
