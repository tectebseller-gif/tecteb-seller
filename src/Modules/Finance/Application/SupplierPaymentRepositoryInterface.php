<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment;

/**
 * Where a supplier payment is written down — and the two refusals that keep it
 * from being written twice.
 *
 * `record()` is the only writer and it has **no update and no delete**. That is
 * deliberate and it is the same posture the ledger has: «سفارش‌ها و پرداخت‌های
 * قبلی بازنویسی نشوند». A payment recorded wrongly is corrected by recording
 * the correction, where a correction is a decision somebody makes and signs,
 * not a row somebody edits.
 */
interface SupplierPaymentRepositoryInterface
{
    /** The row was written. */
    public const RECORDED = 'recorded';
    /** This exact submission is already on disk — a refresh, a double click, a retry. */
    public const ALREADY_RECORDED = 'already_recorded';
    /** One of the named order items is already covered by another payment. */
    public const ITEM_ALREADY_PAID = 'item_already_paid';
    /**
     * One of the named order items is claimed by the vendor's own withdrawal
     * cycle — reserved in an open request, or kept by a paid one.
     *
     * A DIFFERENT refusal from `ITEM_ALREADY_PAID`, because it points at a
     * different place: that one sends the manager to another supplier payment,
     * this one to a withdrawal. One obligation for one item must not be
     * settled from two paths, and the two paths leave their claims in two
     * tables, so the answer has to name which.
     */
    public const ITEM_HELD_BY_WITHDRAWAL = 'item_held_by_withdrawal';
    /** The statement failed. Not the same as any refusal above. */
    public const FAILED = 'failed';
    /** The row is read and HELD for the rest of this unit of work. */
    public const LOCKED = 'locked';

    /**
     * Writes the payment and its items as ONE unit of work, or writes neither.
     *
     * Three answers rather than a `bool`, because the three mean three
     * different things to a person: «done», «you already did this», and «this
     * did not work, try again». `alpha.39` learned the cost of collapsing the
     * second into the third — a duplicate callback reported a storage failure
     * about money that was correctly on the books.
     *
     * **The claim on each item is made INSIDE the write**, against both
     * tables that can hold one: the unique key on `order_item_id` refuses a
     * second supplier payment, and the insert reads the order line itself so
     * an item the withdrawal cycle holds cannot be taken. A check beforehand
     * cannot do it — «یک SELECT دیگر خارج از تراکنش کافی نیست» — and the two
     * paths run in two requests.
     *
     * @param array<int,?int> $itemShares order-item id => the vendor share
     *        recorded for that item at this moment, or `null` when the sale
     *        recorded no money. `null` is STORED as `NULL`, not as nought:
     *        a payment whose match could not be computed has to still say
     *        which item it could not be computed for.
     * @return array{outcome:string, payment_id:int, conflicting_item:int, conflicting_withdrawal:int}
     * @param list<int> $partialItemIds items whose CLAIM row may already
     *        exist because an earlier payment of this supplier's made it.
     *        `UNIQUE (order_item_id)` on the claim table is NOT removed —
     *        «کلید یکتای «هر قلم فقط یک پرداخت» را کورکورانه حذف نکن» — it is
     *        read for what it always guaranteed: one claim per item. The
     *        money of a second, third and fourth payment lives on an
     *        allocation against the obligation instead, and the balance guard
     *        there is what keeps those from adding up to more than was agreed.
     *        An item not in this list keeps the old refusal exactly.
     */
    public function record(SupplierPayment $payment, array $itemShares, array $partialItemIds = []): array;

    /** A void with no reason is not a correction, it is an erasure. */
    public const REASON_REQUIRED = 'void_reason_required';
    /** Already void — nothing written, and no second financial effect. */
    public const ALREADY_VOID = 'already_void';
    public const VOIDED = 'voided';
    /** The payment is not there. */
    public const NOT_FOUND = 'not_found';

    /**
     * Mark one payment VOID — the record stays, the money stops counting.
     *
     * One statement, guarded on `voided_at IS NULL`, so «درخواست تکراری ابطال
     * نباید اثر مالی دوم بسازد» is the row count rather than a check the
     * caller does first. The compensating ledger entry is the CALLER's, in
     * the caller's transaction: this method only records that the document
     * was voided, and `$compensatingEvent` is written beside the reason so a
     * reader can find the entry that undid the money.
     *
     * Nothing is deleted — not the payment, not its items, not its
     * allocations. Every reader that decides something asks `voided_at`.
     *
     * @return array{outcome:string, payment_id:int}
     */
    public function void(
        int $paymentId,
        string $reason,
        int $actorId,
        string $compensatingEvent,
        string $when
    ): array;

    /**
     * Record what a later review established about this SAME payment.
     *
     * `share_minor` is not touched: the figure recorded when the money
     * changed hands keeps its column and this writes beside it, which is the
     * whole of «snapshot اولیهٔ پرداخت پاک نشود». Guarded on
     * `reconciled_at IS NULL` so a repeated submission writes once.
     */
    public function markReconciled(
        int $paymentId,
        ?int $shareMinorFinal,
        string $posting,
        string $ledgerEvent,
        int $actorId,
        string $when
    ): bool;

    /**
     * THE SAME ROW, READ UNDER A LOCK — for a decision that then writes it.
     *
     * `find()` is a plain read and is right for a screen. It is wrong as the
     * basis of a financial decision, and the shape of that wrongness was
     * measured on `alpha.64`: `void()` read the payment, decided from it that
     * no compensating entry was owed, and an independent `reconcile()`
     * request gave the payment a money line in the gap. The void then
     * succeeded, released the items, and wrote no reverse entry — so the
     * supplier's books said the money had left for a document marked void.
     * Both verbs are POST actions on the same admin screen, so «two
     * requests» is the ordinary case rather than a contrived one.
     *
     * MUST be called with a transaction already open, because a lock taken
     * outside one is released by the statement that took it. The caller's
     * `begin()` is what makes this method mean anything.
     *
     * Three answers rather than a nullable row, because `getRow()` gives one
     * answer for «no row» and «the read failed» (`alpha.54`) and here those
     * are opposites: a failed read reported as `NOT_FOUND` would tell a
     * manager that a payment they are looking at does not exist.
     *
     * @return array{outcome:string, payment:?SupplierPayment}
     */
    public function findForUpdate(int $paymentId): array;

    public function find(int $paymentId): ?SupplierPayment;

    /**
     * The payment this exact submission already wrote, if it did.
     *
     * Asked BEFORE the per-item checks, and the order is the point: a
     * refreshed page fails both the key and the per-item test, and «you
     * already recorded this» points the manager at their own payment while
     * «item 412 is already paid» reads like a mistake about somebody else's.
     * The unique key is still the guard — this read only decides which true
     * sentence to say.
     */
    public function findByKey(string $idempotencyKey): ?SupplierPayment;

    /** The payment that already covers this order item, if one does. */
    public function forOrderItem(int $orderItemId): ?SupplierPayment;

    /**
     * The order-item ids this supplier has already been paid for by hand.
     *
     * So the vendor's own balance can stop offering money that has already
     * been handed over: without this, a manually paid line stayed «قابل
     * درخواست» and the withdrawal cycle would settle it a second time —
     * measured on `alpha.60` (`balance_eligible_has_c=yes`,
     * `reserve_result=6`).
     *
     * Reads nothing but this module's own tables, and answers `[]` when they
     * are not there: on a site whose schema has not reached 29 there can be no
     * supplier payment, so there is nothing to exclude. That is a definite
     * answer rather than a guess, and it is what keeps a Finance read from
     * being able to empty the Order module's balance.
     *
     * @return list<int>
     */
    public function paidOrderItemIds(int $supplierUserId): array;

    /** @return list<SupplierPayment> newest first */
    public function forSupplier(int $supplierUserId, int $limit = 50): array;

    /**
     * Payments recorded but NOT posted to the ledger — because posting them
     * would state something nobody has defined, or because the comparison
     * could not be made at all.
     *
     * The detection half of the conflict §6 asks to be identified: a remedy
     * nobody can find is not a remedy (`alpha.39`), so the rows that are
     * waiting on the owner's definition are listable.
     *
     * @return list<SupplierPayment>
     */
    public function awaitingDefinition(int $limit = 100): array;

    /**
     * Marks a recorded payment as posted, under the ledger event that holds its
     * money line.
     *
     * Separate from `record()` and guarded on the CURRENT state, because the
     * two writes are to two different stores and the second can fail: a row
     * that says «posted» with no entry behind it is the failure FIN-03 exists
     * to prevent, and so is an entry with no row. The caller wraps both.
     */
    public function markPosted(int $paymentId, string $ledgerEvent): bool;
}
