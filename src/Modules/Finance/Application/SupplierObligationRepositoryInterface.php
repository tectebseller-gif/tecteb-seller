<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\SupplierObligation;

/**
 * The agreed purchase amount for an order item, its history, and the money
 * allocated against it.
 *
 * **Why `allocate()` takes an amount and not a payment.** A payment can cover
 * several items and can be smaller than all of them together; which part of
 * it lands on which obligation is a decision the caller makes and this
 * interface records. The guard the owner asked for in place of the old «one
 * payment per item» key is inside this method rather than above it: the
 * implementation locks the obligation row and carries
 * `paid_so_far + amount <= agreed` into the writing statement, so a caller
 * that forgot to check still cannot write an over-payment, and two requests
 * that both checked cannot both write.
 */
interface SupplierObligationRepositoryInterface
{
    public const AGREED = 'agreed';
    public const UNCHANGED = 'agreed_unchanged';
    /** The new amount is below what has already been paid against it. */
    public const BELOW_PAID = 'agreed_below_paid';
    public const FAILED = 'storage_failed';

    public const ALLOCATED = 'allocated';
    /** This payment already has an allocation against this obligation. */
    public const ALREADY_ALLOCATED = 'already_allocated';
    /** `paid_so_far + amount` would exceed the agreed amount. */
    public const ABOVE_BALANCE = 'above_balance';
    /** There is no agreed amount to allocate against. */
    public const NOT_AGREED = 'not_agreed';

    /**
     * Record, or change, what was agreed — and write the change to history.
     *
     * `null` is a legitimate request and means «we no longer have an agreed
     * amount», which is not the same as zero and is kept apart from it all
     * the way to the column. Lowering the amount below what has already been
     * paid is refused: that is not a correction, it is a statement that an
     * over-payment happened, and §3 says an over-payment has no defined
     * meaning yet.
     *
     * @return array{outcome:string, obligation_id:int, previous_minor:?int, paid_minor:int}
     */
    public function agree(
        int $orderItemId,
        int $supplierUserId,
        ?int $agreedMinor,
        string $currency,
        int $exponent,
        int $actorId,
        string $reason
    ): array;

    public function forOrderItem(int $orderItemId): ?SupplierObligation;

    public function find(int $obligationId): ?SupplierObligation;

    /**
     * Every change to one obligation's agreed amount, newest first.
     *
     * @return list<array{id:int, previous_minor:?int, new_minor:?int, reason:string, changed_by:int, created_at:string}>
     */
    public function historyFor(int $obligationId, int $limit = 50): array;

    /**
     * @return list<SupplierObligation>
     */
    public function forSupplier(int $supplierUserId, int $limit = 50): array;

    /**
     * Put money against an obligation, inside the caller's transaction.
     *
     * @return array{outcome:string, allocation_id:int, balance_minor:?int}
     */
    public function allocate(
        int $paymentId,
        int $obligationId,
        int $orderItemId,
        int $supplierUserId,
        int $amountMinor,
        string $currency,
        int $exponent
    ): array;

    /**
     * What one payment put against each obligation.
     *
     * @return list<array{obligation_id:int, order_item_id:int, amount_minor:int}>
     */
    public function allocationsOf(int $paymentId): array;
}
