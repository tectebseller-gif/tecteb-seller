<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierReceivableRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierReceivable;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as T;

/**
 * Supplier receivables on disk: one debt, and append-only settlements.
 *
 * Two unique keys do the work rather than a read beforehand — the pattern
 * this module settled on in `alpha.57` and has not had reason to leave:
 *
 *  - `event_key` on the receivable, so the same return and the same item
 *    cannot produce a second debt however many times the page is submitted;
 *  - `idempotency_key` on the settlement, so a refreshed form cannot collect
 *    the same money twice.
 *
 * And the balance is carried INTO the settlement's insert, so «یک مبلغ دوبار
 * وصول یا کسر نشود» is enforced by the statement and not by the caller's
 * memory of what it read.
 */
final class DbSupplierReceivableRepository implements SupplierReceivableRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(
        int $supplierUserId,
        int $orderItemId,
        int $returnId,
        int $quantity,
        int $amountMinor,
        string $currency,
        int $exponent,
        string $reason,
        int $actorId,
        string $eventKey
    ): array {
        if ($supplierUserId <= 0 || $orderItemId <= 0 || $amountMinor <= 0 || trim($eventKey) === '') {
            return ['outcome' => self::FAILED, 'receivable_id' => 0];
        }
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->receivables() . '`
             (supplier_user_id, order_item_id, return_id, quantity, amount_minor, currency, exponent,
              reason, recorded_by, event_key, created_at)
             VALUES (%d, %d, %d, %d, %d, %s, %d, %s, %d, %s, %s)',
            [
                $supplierUserId, $orderItemId, max(0, $returnId), max(0, $quantity), $amountMinor,
                $currency, $exponent, self::shorten($reason), $actorId, trim($eventKey),
                $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
        if ($written === null) {
            return ['outcome' => self::FAILED, 'receivable_id' => 0];
        }
        if ($written === 0) {
            $existing = (int) ($this->db->getVar(
                'SELECT id FROM `' . $this->receivables() . '` WHERE event_key = %s',
                [trim($eventKey)]
            ) ?? 0);
            return ['outcome' => self::ALREADY_RECORDED, 'receivable_id' => $existing];
        }
        return ['outcome' => self::RECORDED, 'receivable_id' => (int) $this->db->getVar('SELECT LAST_INSERT_ID()')];
    }

    public function settle(
        int $receivableId,
        string $kind,
        int $amountMinor,
        int $paymentId,
        string $reference,
        string $note,
        int $actorId,
        string $idempotencyKey
    ): array {
        $kind = trim($kind);
        if ($receivableId <= 0 || $amountMinor <= 0 || trim($idempotencyKey) === ''
            || !in_array($kind, [T::SETTLEMENT_COLLECTED, T::SETTLEMENT_OFFSET], true)) {
            return ['outcome' => self::FAILED, 'settlement_id' => 0, 'balance_minor' => 0];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction && !$this->db->begin()) {
            return ['outcome' => self::FAILED, 'settlement_id' => 0, 'balance_minor' => 0];
        }

        // The debt row is the one both writers pass through, so it is the one
        // that is locked. The amount is read from it inside the lock, so the
        // balance reported afterwards is about the same instant the write was.
        $row = $this->db->getRow(
            'SELECT amount_minor FROM `' . $this->receivables() . '` WHERE id = %d FOR UPDATE',
            [$receivableId]
        );
        if ($row === null) {
            return $this->settleFail($ownTransaction);
        }
        $owed = (int) $row['amount_minor'];

        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->settlements() . '`
             (receivable_id, kind, amount_minor, payment_id, reference, note, recorded_by, idempotency_key, created_at)
             SELECT %d, %s, %d, %d, %s, %s, %d, %s, %s
               FROM `' . $this->receivables() . '` r
              WHERE r.id = %d
                AND COALESCE((SELECT SUM(s.amount_minor) FROM `' . $this->settlements() . '` s
                               WHERE s.receivable_id = r.id), 0) + %d <= r.amount_minor',
            [
                $receivableId, $kind, $amountMinor, max(0, $paymentId), self::shorten($reference),
                $note, $actorId, trim($idempotencyKey), $now, $receivableId, $amountMinor,
            ]
        );
        if ($written === null) {
            return $this->settleFail($ownTransaction);
        }
        if ($written === 1) {
            $id = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
            $balance = $owed - $this->settledAgainst($receivableId);
            if ($ownTransaction && !$this->db->commit()) {
                return $this->settleFail($ownTransaction);
            }
            return ['outcome' => self::SETTLED, 'settlement_id' => $id, 'balance_minor' => $balance];
        }
        // Two causes, two sentences — the key refused this exact submission,
        // or there was not enough balance left for it.
        $duplicate = (int) ($this->db->getVar(
            'SELECT id FROM `' . $this->settlements() . '` WHERE idempotency_key = %s',
            [trim($idempotencyKey)]
        ) ?? 0);
        $balance = $owed - $this->settledAgainst($receivableId);
        if ($ownTransaction) {
            $this->db->rollback();
        }
        return [
            'outcome' => $duplicate > 0 ? self::ALREADY_SETTLED : self::ABOVE_BALANCE,
            'settlement_id' => $duplicate,
            'balance_minor' => $balance,
        ];
    }

    public function find(int $receivableId): ?SupplierReceivable
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->receivables() . '` WHERE id = %d', [$receivableId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function settlementsOf(int $receivableId, int $limit = 50): array
    {
        $rows = [];
        foreach ($this->db->getResults(
            'SELECT id, kind, amount_minor, payment_id, reference, recorded_by, created_at
               FROM `' . $this->settlements() . '` WHERE receivable_id = %d ORDER BY id DESC LIMIT %d',
            [$receivableId, max(1, $limit)]
        ) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'amount_minor' => (int) $row['amount_minor'],
                'payment_id' => (int) ($row['payment_id'] ?? 0),
                'reference' => (string) ($row['reference'] ?? ''),
                'recorded_by' => (int) ($row['recorded_by'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $rows;
    }

    public function openFor(int $supplierUserId, int $limit = 50): array
    {
        return array_values(array_filter(
            $this->forSupplier($supplierUserId, $limit),
            static fn (SupplierReceivable $r): bool => $r->isOpen()
        ));
    }

    public function allOpen(int $limit = 100): array
    {
        // The balance is asked of the SETTLEMENTS table in the statement, so
        // a receivable settled down to nought never reaches PHP. `HAVING`
        // rather than a filter afterwards: a page of a hundred rows that are
        // then thrown away is a page of nothing.
        $rows = $this->db->getResults(
            'SELECT r.* FROM `' . $this->receivables() . '` r
              WHERE COALESCE((SELECT SUM(s.amount_minor) FROM `' . $this->settlements() . '` s
                               WHERE s.receivable_id = r.id), 0) < r.amount_minor
              ORDER BY r.id DESC LIMIT %d',
            [max(1, $limit)]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->receivables() . '`
              WHERE supplier_user_id = %d ORDER BY id DESC LIMIT %d',
            [$supplierUserId, max(1, $limit)]
        ));
    }

    private function settledAgainst(int $receivableId): int
    {
        return (int) ($this->db->getVar(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM `' . $this->settlements() . '` WHERE receivable_id = %d',
            [$receivableId]
        ) ?? 0);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): SupplierReceivable
    {
        $currency = (string) ($row['currency'] ?? 'IRR');
        $exponent = (int) ($row['exponent'] ?? 0);
        $id = (int) ($row['id'] ?? 0);
        return new SupplierReceivable(
            $id,
            (int) ($row['supplier_user_id'] ?? 0),
            (int) ($row['order_item_id'] ?? 0),
            (int) ($row['return_id'] ?? 0),
            (int) ($row['quantity'] ?? 0),
            Money::of((int) ($row['amount_minor'] ?? 0), $currency, $exponent),
            $this->settledAgainst($id),
            (string) ($row['reason'] ?? ''),
            (int) ($row['recorded_by'] ?? 0),
            (string) ($row['event_key'] ?? ''),
            (string) ($row['created_at'] ?? '')
        );
    }

    /** @return array{outcome:string, settlement_id:int, balance_minor:int} */
    private function settleFail(bool $ownTransaction): array
    {
        if ($ownTransaction) {
            $this->db->rollback();
        }
        return ['outcome' => self::FAILED, 'settlement_id' => 0, 'balance_minor' => 0];
    }

    private static function shorten(string $text): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 190) : substr($text, 0, 190);
    }

    private function receivables(): string
    {
        return T::table($this->db, T::RECEIVABLES);
    }

    private function settlements(): string
    {
        return T::table($this->db, T::RECEIVABLE_SETTLEMENTS);
    }
}
