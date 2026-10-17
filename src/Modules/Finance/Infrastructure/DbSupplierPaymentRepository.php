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
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as V;
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

    public function record(SupplierPayment $payment, array $itemShares, array $partialItemIds = []): array
    {
        $partial = [];
        foreach ($partialItemIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $partial[$id] = true;
            }
        }
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
                 (payment_id, order_item_id, live_item_id, supplier_user_id, share_minor, created_at)
                 SELECT %d, oi.id, oi.id, %d, ' . ($share === null ? 'NULL' : '%d') . ', %s
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
            // sentences: the unique key refused (a payment already claimed the
            // item), the `SELECT` found nothing (a withdrawal holds it, or the
            // line is not this supplier's), or the line is gone. Asked while
            // the transaction is still open, so the answer is about the rows
            // this statement looked at — and only to decide which true
            // sentence to say; the guard was the statement.
            $held = (int) ($this->db->getVar(
                'SELECT COALESCE(withdrawal_id, 0) FROM `' . $this->orderItems() . '` WHERE id = %d',
                [$itemId]
            ) ?? 0);
            if ($held > 0) {
                $this->db->rollback();
                return $this->refusal(self::ITEM_HELD_BY_WITHDRAWAL, 0, $itemId, $held);
            }
            // A SECOND PAYMENT AGAINST THE SAME ITEM — which is now an
            // ordinary thing and not a mistake («پرداخت کمتر از تعهد، پرداخت
            // جزئی با باقی‌ماندهٔ مانده است»). The claim row is already there
            // because an earlier payment of this supplier's made it, so there
            // is nothing to write here: the money goes on an allocation and
            // the balance guard over there decides whether it may.
            //
            // Narrow on purpose. The caller has to have NAMED this item, and
            // the existing claim has to be a LIVE payment OF THIS SUPPLIER's;
            // anything else keeps the refusal it had before. A voided claim
            // is not a claim at all, so the insert is retried without the
            // `IGNORE`-shaped ambiguity by removing nothing — the row is
            // simply reported as free and the allocation decides.
            if (isset($partial[$itemId]) && $this->claimedBySupplier($itemId, $payment->supplierUserId)) {
                // AND THE COVERAGE IS STILL WRITTEN, with a NULL live key.
                //
                // `alpha.62` wrote nothing here, which left the second
                // payment with no row on the item at all — so when the FIRST
                // payment was voided and gave the live key up, nothing was
                // left to say the item was still covered, and the withdrawal
                // cycle could reserve and pay it a second time. The row is
                // not a claim (one live key per item is the unique index);
                // it is the record `void()` reads to find a survivor.
                $covered = $this->db->execute(
                    'INSERT INTO `' . $this->items() . '`
                     (payment_id, order_item_id, live_item_id, supplier_user_id, share_minor, created_at)
                     SELECT %d, oi.id, NULL, %d, ' . ($share === null ? 'NULL' : '%d') . ', %s
                       FROM `' . $this->orderItems() . '` oi
                      WHERE oi.id = %d
                        AND oi.vendor_user_id = %d
                        AND oi.withdrawal_id IS NULL',
                    $share === null
                        ? [$paymentId, $payment->supplierUserId, $now, $itemId, $payment->supplierUserId]
                        : [$paymentId, $payment->supplierUserId, $share, $now, $itemId, $payment->supplierUserId]
                );
                if ($covered !== 1) {
                    // Nought means the line moved between the two statements;
                    // null means the write failed. Either way this payment
                    // would be invisible to `void()`, which is the defect.
                    $this->db->rollback();
                    return $this->refusal(self::FAILED, 0, $itemId);
                }
                continue;
            }
            $this->db->rollback();
            return $this->refusal(self::ITEM_ALREADY_PAID, 0, $itemId);
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

    public function findForUpdate(int $paymentId): array
    {
        if ($paymentId <= 0) {
            return ['outcome' => self::NOT_FOUND, 'payment' => null];
        }
        // THE COUNT TAKES THE LOCK, and it is asked separately on purpose:
        // `getVar()` answers `null` when the statement failed and `'0'` when
        // the row is not there, and those two have to stay apart here
        // (`alpha.54`). From this statement onwards the row is held, so the
        // read below cannot find it changed or gone — which is what lets a
        // `null` there be reported as a failure rather than as absence.
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->payments() . '` WHERE id = %d FOR UPDATE',
            [$paymentId]
        );
        if ($counted === null) {
            return ['outcome' => self::FAILED, 'payment' => null];
        }
        if ((int) $counted === 0) {
            return ['outcome' => self::NOT_FOUND, 'payment' => null];
        }
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->payments() . '` WHERE id = %d FOR UPDATE',
            [$paymentId]
        );
        if ($row === null) {
            return ['outcome' => self::FAILED, 'payment' => null];
        }
        return ['outcome' => self::LOCKED, 'payment' => $this->hydrate($row)];
    }

    public function forOrderItem(int $orderItemId): ?SupplierPayment
    {
        $row = $this->db->getRow(
            'SELECT p.* FROM `' . $this->payments() . '` p
             INNER JOIN `' . $this->items() . '` i ON i.payment_id = p.id
             WHERE i.live_item_id = %d',
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
            'SELECT * FROM `' . $this->payments() . '`
              WHERE posting IN (%s, %s, %s) AND voided_at IS NULL
              ORDER BY id DESC LIMIT %d',
            [T::POSTING_NEEDS_DEFINITION, U::POSTING_SHARE_UNKNOWN, V::POSTING_AGREED_UNKNOWN, max(1, $limit)]
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
            $items['unknown'],
            ($row['voided_at'] ?? null) === null ? null : (string) $row['voided_at'],
            (int) ($row['voided_by'] ?? 0),
            (string) ($row['void_reason'] ?? ''),
            (string) ($row['void_event'] ?? ''),
            ($row['share_minor_final'] ?? null) === null ? null : (int) $row['share_minor_final'],
            ($row['reconciled_at'] ?? null) === null ? null : (string) $row['reconciled_at'],
            (int) ($row['reconciled_by'] ?? 0)
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
                'SELECT order_item_id FROM `' . $this->items() . '`
                  WHERE supplier_user_id = %d AND live_item_id IS NOT NULL',
                [$supplierUserId]
            )
        );
    }

    public function void(
        int $paymentId,
        string $reason,
        int $actorId,
        string $compensatingEvent,
        string $when
    ): array {
        if ($paymentId <= 0) {
            return ['outcome' => self::NOT_FOUND, 'payment_id' => 0];
        }
        if (trim($reason) === '') {
            // A void with no reason is an erasure with a timestamp.
            return ['outcome' => self::REASON_REQUIRED, 'payment_id' => $paymentId];
        }
        // ONE GUARDED STATEMENT. `voided_at IS NULL` in the `WHERE` is the
        // whole of the idempotency: the second request matches no row and is
        // told so, rather than overwriting the first void's reason, actor and
        // time — and rather than letting the caller write a second
        // compensating entry («اثر مالی دوم»). Zero rows is NOT success here
        // (the other half of the `alpha.8` rule).
        $moved = $this->db->execute(
            'UPDATE `' . $this->payments() . '`
                SET voided_at = %s, voided_by = %d, void_reason = %s, void_event = %s
              WHERE id = %d AND voided_at IS NULL',
            [$when, $actorId, self::shorten($reason), $compensatingEvent, $paymentId]
        );
        if ($moved === null) {
            return ['outcome' => self::FAILED, 'payment_id' => $paymentId];
        }
        if ($moved > 0) {
            // WHICH ITEMS THIS PAYMENT HELD, read BEFORE the release and with
            // its own count, because `getResults()` gives one answer for «no
            // rows» and «the read failed» (`alpha.54`) and here those are
            // opposites: a failed read looks like «nothing to hand over».
            $counted = $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $this->items() . '`
                  WHERE payment_id = %d AND live_item_id IS NOT NULL',
                [$paymentId]
            );
            if ($counted === null) {
                return ['outcome' => self::FAILED, 'payment_id' => $paymentId];
            }
            $items = [];
            if ((int) $counted > 0) {
                $items = array_map(
                    static fn (array $r): int => (int) $r['order_item_id'],
                    $this->db->getResults(
                        'SELECT order_item_id FROM `' . $this->items() . '`
                          WHERE payment_id = %d AND live_item_id IS NOT NULL',
                        [$paymentId]
                    )
                );
                // The count is what makes the list trustworthy: short means
                // the second read failed or lost a row, and acting on a short
                // list is exactly how an item gets handed to nobody.
                if (count($items) !== (int) $counted) {
                    return ['outcome' => self::FAILED, 'payment_id' => $paymentId];
                }
            }
            // THE CLAIMS ARE RELEASED — in the caller's unit of work, so
            // «آزادسازی … فقط پس از موفقیت کامل عملیات» is the transaction
            // rather than an ordering somebody has to get right. The rows
            // stay; only the live key goes.
            $released = $this->db->execute(
                'UPDATE `' . $this->items() . '` SET live_item_id = NULL WHERE payment_id = %d',
                [$paymentId]
            );
            if ($released === null) {
                return ['outcome' => self::FAILED, 'payment_id' => $paymentId];
            }
            // AND THE LIVE KEY IS HANDED ON, not dropped. Voiding one payment
            // of several partial ones says nothing about the others: while any
            // non-voided payment still covers the item, the item is still
            // claimed, so the withdrawal cycle cannot take it. The oldest
            // surviving row gets the key — oldest because it is the one whose
            // money went first, and because `MIN(id)` is a decision rather
            // than whatever order the rows come back in.
            foreach ($items as $itemId) {
                if (!$this->handOverClaim($itemId, $paymentId)) {
                    return ['outcome' => self::FAILED, 'payment_id' => $paymentId];
                }
            }
            return ['outcome' => self::VOIDED, 'payment_id' => $paymentId];
        }
        // Nought: already void, or no such payment. Two different sentences,
        // and only a read can tell them apart.
        $exists = (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->payments() . '` WHERE id = %d',
            [$paymentId]
        ) ?? 0);
        return [
            'outcome' => $exists > 0 ? self::ALREADY_VOID : self::NOT_FOUND,
            'payment_id' => $paymentId,
        ];
    }

    public function markReconciled(
        int $paymentId,
        ?int $shareMinorFinal,
        string $posting,
        string $ledgerEvent,
        int $actorId,
        string $when
    ): bool {
        if ($paymentId <= 0 || trim($posting) === '') {
            return false;
        }
        $params = [$when, $actorId];
        if ($shareMinorFinal !== null) {
            $params[] = $shareMinorFinal;
        }
        $params[] = $posting;
        if ($ledgerEvent !== '') {
            $params[] = $ledgerEvent;
        }
        $params[] = $paymentId;
        // `share_minor` is deliberately absent from the SET list: the figure
        // recorded when the money changed hands keeps its column, and what
        // the review established goes in `share_minor_final` beside it.
        //
        // `reconciled_at IS NULL` is the idempotency and `ledger_event = ''`
        // is the second half of it: a review that posts a money line can only
        // do so onto a payment that has none, so a repeat writes no second
        // entry and is told that nothing moved.
        $moved = $this->db->execute(
            'UPDATE `' . $this->payments() . '`
                SET reconciled_at = %s, reconciled_by = %d,
                    share_minor_final = ' . ($shareMinorFinal === null ? 'NULL' : '%d') . ',
                    posting = %s'
                . ($ledgerEvent !== '' ? ', ledger_event = %s' : '') . '
              WHERE id = %d AND reconciled_at IS NULL AND voided_at IS NULL'
                . ($ledgerEvent !== '' ? " AND ledger_event = ''" : ''),
            $params
        );
        return $moved !== null && $moved > 0;
    }

    /**
     * Is this item's one claim row held by a LIVE payment of this supplier's?
     *
     * Two facts in one question, and each matters: a claim by another
     * supplier's payment is a mistake in the submission, and no live claim at
     * all means the `SELECT` inside the insert found nothing (a withdrawal, a
     * wrong supplier, a missing line) rather than the unique key refusing. A
     * voided payment's row has `live_item_id IS NULL` and so is not a claim,
     * which is the whole reason that column exists.
     */
    /**
     * Give this item's live key to the oldest surviving payment, if any.
     *
     * Three states and they are kept apart: the read failed (refuse — the
     * whole void goes back), nobody else covers the item (nothing to do: the
     * release stands and the item is genuinely free), or a non-voided payment
     * does (it becomes the claim). `live_item_id IS NULL` in the `WHERE` of
     * the promotion means a second void in the same transaction cannot move
     * the key twice, and the unique index is what stops two live rows.
     */
    private function handOverClaim(int $orderItemId, int $voidedPaymentId): bool
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS n, COALESCE(MIN(i.id), 0) AS pick
               FROM `' . $this->items() . '` i
               INNER JOIN `' . $this->payments() . '` p ON p.id = i.payment_id
              WHERE i.order_item_id = %d
                AND i.payment_id <> %d
                AND p.voided_at IS NULL',
            [$orderItemId, $voidedPaymentId]
        );
        if ($row === null) {
            return false;
        }
        if ((int) $row['n'] === 0) {
            return true;            // nobody else paid for it; it is free
        }
        $pick = (int) $row['pick'];
        if ($pick <= 0) {
            return false;
        }
        return $this->db->execute(
            'UPDATE `' . $this->items() . '`
                SET live_item_id = order_item_id
              WHERE id = %d AND live_item_id IS NULL',
            [$pick]
        ) !== null;
    }

    private function claimedBySupplier(int $orderItemId, int $supplierUserId): bool
    {
        $found = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->items() . '`
              WHERE live_item_id = %d AND supplier_user_id = %d',
            [$orderItemId, $supplierUserId]
        );
        return $found !== null && (int) $found > 0;
    }

    private static function shorten(string $text): string
    {
        $text = trim($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, 255) : substr($text, 0, 255);
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
