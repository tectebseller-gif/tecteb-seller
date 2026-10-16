<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as T;

/**
 * Supplier payments on disk — written once, never edited.
 *
 * **The two unique keys do the work, not a read beforehand.** A refreshed page
 * collides on `idempotency_key`; an order item somebody already paid for
 * collides on `order_item_id`. Both are the database refusing, which is the
 * only kind of refusal two tabs and a double click cannot both get past —
 * «به‌روزرسانی صفحه یا ارسال دوبارهٔ درخواست نباید پرداخت دوم بسازد». A
 * check-then-insert would let both through and write two payments.
 *
 * Which of the two collided matters to the person, so the two are told apart:
 * «you already recorded this» sends them to the row that exists, and «item 412
 * is already paid» sends them to a different payment entirely.
 */
final class DbSupplierPaymentRepository implements SupplierPaymentRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(SupplierPayment $payment, array $orderItemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderItemIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || $payment->supplierUserId <= 0) {
            return ['outcome' => self::FAILED, 'payment_id' => 0, 'conflicting_item' => 0];
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if (!$this->db->begin()) {
            return ['outcome' => self::FAILED, 'payment_id' => 0, 'conflicting_item' => 0];
        }

        // THE HEADER FIRST, because its unique key is the guard against the
        // same submission twice. `INSERT IGNORE` so a collision is a row count
        // of nought rather than an error we would have to parse.
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->payments() . '`
             (supplier_user_id, amount_minor, currency, exponent, share_minor, paid_on,
              reference, note, receipt_path, recorded_by, idempotency_key, ledger_event, posting, created_at)
             VALUES (%d, %d, %s, %d, %d, %s, %s, %s, %s, %d, %s, %s, %s, %s)',
            [
                $payment->supplierUserId,
                $payment->amount->minor,
                $payment->amount->currency,
                $payment->amount->exponent,
                $payment->share->minor,
                $payment->paidOn,
                $payment->reference,
                $payment->note,
                $payment->receiptPath,
                $payment->recordedBy,
                $payment->idempotencyKey,
                '',
                $payment->posting,
                $now,
            ]
        );
        if ($written === null) {
            $this->db->rollback();
            return ['outcome' => self::FAILED, 'payment_id' => 0, 'conflicting_item' => 0];
        }
        if ($written === 0) {
            // The key was taken: this exact submission is already recorded.
            // Rolled back and read OUTSIDE the transaction, so the id reported
            // is the committed row's rather than this transaction's view.
            $this->db->rollback();
            $existing = $this->byKey($payment->idempotencyKey);
            return [
                'outcome' => self::ALREADY_RECORDED,
                'payment_id' => $existing?->id ?? 0,
                'conflicting_item' => 0,
            ];
        }
        $paymentId = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
        if ($paymentId <= 0) {
            $this->db->rollback();
            return ['outcome' => self::FAILED, 'payment_id' => 0, 'conflicting_item' => 0];
        }

        // One row per item, each its own statement, so the item that collides
        // can be NAMED. A single multi-row insert would tell us that one of
        // them was taken and not which, and «۱۲ قلم، یکی‌شان قبلاً پرداخت شده»
        // is not something a person can act on.
        foreach ($ids as $itemId) {
            $line = $this->db->execute(
                'INSERT IGNORE INTO `' . $this->items() . '`
                 (payment_id, order_item_id, supplier_user_id, share_minor, created_at)
                 VALUES (%d, %d, %d, %d, %s)',
                [$paymentId, $itemId, $payment->supplierUserId, 0, $now]
            );
            if ($line === null || $line === 0) {
                $this->db->rollback();
                return [
                    'outcome' => $line === 0 ? self::ITEM_ALREADY_PAID : self::FAILED,
                    'payment_id' => 0,
                    'conflicting_item' => $line === 0 ? $itemId : 0,
                ];
            }
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            return ['outcome' => self::FAILED, 'payment_id' => 0, 'conflicting_item' => 0];
        }
        return ['outcome' => self::RECORDED, 'payment_id' => $paymentId, 'conflicting_item' => 0];
    }

    public function find(int $paymentId): ?SupplierPayment
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->payments() . '` WHERE id = %d', [$paymentId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function forOrderItem(int $orderItemId): ?SupplierPayment
    {
        $row = $this->db->getRow(
            'SELECT p.* FROM `' . $this->payments() . '` p
             INNER JOIN `' . $this->items() . '` i ON i.payment_id = p.id
             WHERE i.order_item_id = %d',
            [$orderItemId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function forSupplier(int $supplierUserId, int $limit = 50): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->payments() . '` WHERE supplier_user_id = %d ORDER BY id DESC LIMIT %d',
            [$supplierUserId, max(1, $limit)]
        ));
    }

    public function awaitingDefinition(int $limit = 100): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->payments() . '` WHERE posting = %s ORDER BY id DESC LIMIT %d',
            [T::POSTING_NEEDS_DEFINITION, max(1, $limit)]
        ));
    }

    public function markPosted(int $paymentId, string $ledgerEvent): bool
    {
        if (trim($ledgerEvent) === '') {
            return false;
        }
        // Guarded on the state it is moving OUT of, so a second attempt
        // matches no row and is told so rather than overwriting the event of
        // the first. Zero rows is NOT success here — the other half of the
        // `alpha.8` rule.
        $moved = $this->db->execute(
            'UPDATE `' . $this->payments() . '`
                SET ledger_event = %s, posting = %s
              WHERE id = %d AND posting = %s AND ledger_event = %s',
            [$ledgerEvent, T::POSTING_POSTED, $paymentId, T::POSTING_NEEDS_DEFINITION, '']
        );
        return $moved !== null && $moved > 0;
    }

    public function findByKey(string $idempotencyKey): ?SupplierPayment
    {
        return trim($idempotencyKey) === '' ? null : $this->byKey(trim($idempotencyKey));
    }

    private function byKey(string $key): ?SupplierPayment
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->payments() . '` WHERE idempotency_key = %s',
            [$key]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): SupplierPayment
    {
        $currency = (string) ($row['currency'] ?? 'IRR');
        $exponent = (int) ($row['exponent'] ?? 0);
        $id = (int) ($row['id'] ?? 0);
        return new SupplierPayment(
            $id,
            (int) ($row['supplier_user_id'] ?? 0),
            Money::of((int) ($row['amount_minor'] ?? 0), $currency, $exponent),
            Money::of((int) ($row['share_minor'] ?? 0), $currency, $exponent),
            (string) ($row['paid_on'] ?? ''),
            (string) ($row['reference'] ?? ''),
            (string) ($row['note'] ?? ''),
            (string) ($row['receipt_path'] ?? ''),
            (int) ($row['recorded_by'] ?? 0),
            (string) ($row['idempotency_key'] ?? ''),
            (string) ($row['ledger_event'] ?? ''),
            (string) ($row['posting'] ?? T::POSTING_NEEDS_DEFINITION),
            (string) ($row['created_at'] ?? ''),
            $this->itemIdsOf($id)
        );
    }

    /** @return list<int> */
    private function itemIdsOf(int $paymentId): array
    {
        if ($paymentId <= 0) {
            return [];
        }
        return array_map(
            static fn (array $r): int => (int) $r['order_item_id'],
            $this->db->getResults(
                'SELECT order_item_id FROM `' . $this->items() . '` WHERE payment_id = %d ORDER BY order_item_id',
                [$paymentId]
            )
        );
    }

    private function payments(): string
    {
        return T::table($this->db, T::PAYMENTS);
    }

    private function items(): string
    {
        return T::table($this->db, T::PAYMENT_ITEMS);
    }
}
