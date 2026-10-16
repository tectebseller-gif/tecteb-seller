<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as T;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0030SupplierPaymentShareUnknown as U;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as OrderTables;

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

    public function record(SupplierPayment $payment, array $itemShares): array
    {
        $shares = [];
        foreach ($itemShares as $id => $share) {
            $id = (int) $id;
            if ($id > 0) {
                $shares[$id] = $share === null ? null : (int) $share;
            }
        }
        $ids = array_keys($shares);
        if ($ids === [] || $payment->supplierUserId <= 0) {
            return $this->refusal(self::FAILED);
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if (!$this->db->begin()) {
            return $this->refusal(self::FAILED);
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
            return $this->refusal(self::FAILED);
        }
        if ($written === 0) {
            // The key was taken: this exact submission is already recorded.
            // Rolled back and read OUTSIDE the transaction, so the id reported
            // is the committed row's rather than this transaction's view.
            $this->db->rollback();
            $existing = $this->byKey($payment->idempotencyKey);
            return $this->refusal(self::ALREADY_RECORDED, $existing?->id ?? 0);
        }
        $paymentId = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
        if ($paymentId <= 0) {
            $this->db->rollback();
            return $this->refusal(self::FAILED);
        }

        // One row per item, each its own statement, so the item that collides
        // can be NAMED. A single multi-row insert would tell us that one of
        // them was taken and not which, and «۱۲ قلم، یکی‌شان قبلاً پرداخت شده»
        // is not something a person can act on.
        //
        // And each insert READS THE ORDER LINE, so the withdrawal cycle's
        // claim is part of the same statement: `INSERT ... SELECT` takes
        // shared locks on the rows it reads, so a `reserve()` running in
        // another request either waits for this transaction or finds the
        // payment row already there. A `SELECT` beforehand cannot do that —
        // «یک SELECT دیگر خارج از تراکنش کافی نیست» (`alpha.50`), and both
        // paths are separate requests by definition.
        foreach ($shares as $itemId => $share) {
            $line = $this->db->execute(
                'INSERT IGNORE INTO `' . $this->items() . '`
                 (payment_id, order_item_id, supplier_user_id, share_minor, created_at)
                 SELECT %d, oi.id, %d, ' . ($share === null ? 'NULL' : '%d') . ', %s
                   FROM `' . $this->orderItems() . '` oi
                  WHERE oi.id = %d
                    AND oi.vendor_user_id = %d
                    AND oi.withdrawal_id IS NULL',
                $share === null
                    ? [$paymentId, $payment->supplierUserId, $now, $itemId, $payment->supplierUserId]
                    : [$paymentId, $payment->supplierUserId, $share, $now, $itemId, $payment->supplierUserId]
            );
            if ($line === 1) {
                continue;
            }
            if ($line === null) {
                $this->db->rollback();
                return $this->refusal(self::FAILED, 0, $itemId);
            }
            // Nought has THREE causes here and they are three different
            // sentences: the unique key refused (another payment holds the
            // item), the `SELECT` found nothing (a withdrawal holds it, or the
            // line is not this supplier's), or the line is gone. Asked while
            // the transaction is still open, so the answer is about the rows
            // this statement looked at — and only to decide which true
            // sentence to say; the guard was the statement.
            $held = (int) ($this->db->getVar(
                'SELECT COALESCE(withdrawal_id, 0) FROM `' . $this->orderItems() . '` WHERE id = %d',
                [$itemId]
            ) ?? 0);
            $this->db->rollback();
            return $held > 0
                ? $this->refusal(self::ITEM_HELD_BY_WITHDRAWAL, 0, $itemId, $held)
                : $this->refusal(self::ITEM_ALREADY_PAID, 0, $itemId);
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            return $this->refusal(self::FAILED);
        }
        return [
            'outcome' => self::RECORDED,
            'payment_id' => $paymentId,
            'conflicting_item' => 0,
            'conflicting_withdrawal' => 0,
        ];
    }

    /** @return array{outcome:string, payment_id:int, conflicting_item:int, conflicting_withdrawal:int} */
    private function refusal(string $outcome, int $paymentId = 0, int $item = 0, int $withdrawal = 0): array
    {
        return [
            'outcome' => $outcome,
            'payment_id' => $paymentId,
            'conflicting_item' => $item,
            'conflicting_withdrawal' => $withdrawal,
        ];
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
        // BOTH unposted states, because both are waiting on a person: one on
        // the owner's definition of «مبلغ و سهم فرق دارند» and one on the
        // share of an item that recorded no money at all.
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->payments() . '` WHERE posting IN (%s, %s) ORDER BY id DESC LIMIT %d',
            [T::POSTING_NEEDS_DEFINITION, U::POSTING_SHARE_UNKNOWN, max(1, $limit)]
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
        $items = $this->itemsOf($id);
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
            $items['ids'],
            $items['unknown']
        );
    }

    /**
     * The items of one payment, and which of them had NO recorded share.
     *
     * `NULL` in `share_minor` is that second answer, and it is why migration
     * 30 made the column nullable: the row has to be able to say «unknown» in
     * a spelling that is not the same as «nought».
     *
     * @return array{ids:list<int>, unknown:list<int>}
     */
    private function itemsOf(int $paymentId): array
    {
        if ($paymentId <= 0) {
            return ['ids' => [], 'unknown' => []];
        }
        $ids = [];
        $unknown = [];
        foreach ($this->db->getResults(
            'SELECT order_item_id, share_minor FROM `' . $this->items() . '`
              WHERE payment_id = %d ORDER BY order_item_id',
            [$paymentId]
        ) as $row) {
            $id = (int) $row['order_item_id'];
            $ids[] = $id;
            if (($row['share_minor'] ?? null) === null) {
                $unknown[] = $id;
            }
        }
        return ['ids' => $ids, 'unknown' => $unknown];
    }

    public function paidOrderItemIds(int $supplierUserId): array
    {
        if ($supplierUserId <= 0) {
            return [];
        }
        return array_map(
            static fn (array $r): int => (int) $r['order_item_id'],
            $this->db->getResults(
                'SELECT order_item_id FROM `' . $this->items() . '` WHERE supplier_user_id = %d',
                [$supplierUserId]
            )
        );
    }

    private function payments(): string
    {
        return T::table($this->db, T::PAYMENTS);
    }

    /**
     * The marketplace's order lines — read, never written, and named from the
     * migration that creates them rather than from memory (`alpha.10`).
     *
     * `DbWithdrawalRepository` reads the same table for the same reason: the
     * claim a settlement makes lives on the line, so the two paths that can
     * claim it both have to be able to see it.
     */
    private function orderItems(): string
    {
        return OrderTables::table($this->db, OrderTables::ORDER_ITEMS);
    }

    private function items(): string
    {
        return T::table($this->db, T::PAYMENT_ITEMS);
    }
}
