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
    /** The statement failed. Not the same as either refusal above. */
    public const FAILED = 'failed';

    /**
     * Writes the payment and its items as ONE unit of work, or writes neither.
     *
     * Three answers rather than a `bool`, because the three mean three
     * different things to a person: «done», «you already did this», and «this
     * did not work, try again». `alpha.39` learned the cost of collapsing the
     * second into the third — a duplicate callback reported a storage failure
     * about money that was correctly on the books.
     *
     * @param list<int> $orderItemIds
     * @return array{outcome:string, payment_id:int, conflicting_item:int}
     */
    public function record(SupplierPayment $payment, array $orderItemIds): array;

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

    /** @return list<SupplierPayment> newest first */
    public function forSupplier(int $supplierUserId, int $limit = 50): array;

    /**
     * Payments recorded but NOT posted to the ledger, because posting them
     * would state something nobody has defined.
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
