<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * One return: some of one order line, and what has been decided about it.
 *
 * The money fields are nullable and stay null until a refund is actually
 * recorded. Null is «هنوز بازپرداختی ثبت نشده», which is a different fact from
 * zero — the same distinction FIN-02 makes about an unset commission rate, and
 * for the same reason: a zero that means "unknown" is a number somebody will
 * add up.
 *
 * There is no `shippingRefundMinor` and no `feeMinor`. Who pays return
 * postage, and whether a restocking fee applies, are DEC-03 and undecided;
 * a field with a default of zero would answer them.
 */
final class ReturnRequest
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderItemId,
        public readonly int $vendorUserId,
        public readonly int $quantity,
        public readonly ReturnStatus $status,
        public readonly string $reason = '',
        public readonly string $note = '',
        public readonly ?int $refundMinor = null,
        public readonly ?int $taxRefundMinor = null,
        public readonly ?int $commissionReversedMinor = null,
        public readonly ?int $vendorShareReversedMinor = null,
        public readonly int $restockedQuantity = 0,
        public readonly ?string $reversalEventKey = null,
        public readonly ?int $wcRefundId = null,
        public readonly int $requestedBy = 0,
        public readonly string $requestedAt = '',
        public readonly ?int $decidedBy = null,
        public readonly ?string $decidedAt = null,
        public readonly ?string $receivedAt = null,
        public readonly ?string $refundedAt = null,
        /**
         * THE UNIT the four money fields are in, where the row records it.
         *
         * `null` is «this row does not say», which is the truth about every
         * return written before `alpha.67` and is resolved exactly as it was
         * then: from the vendor's single money unit. It is not exponent 0 —
         * `array_key_exists` separates the two at the hydrator (`alpha.60`).
         */
        public readonly ?string $currency = null,
        public readonly ?int $exponent = null
    ) {
    }

    /**
     * The exponent a screen should print these figures at.
     *
     * The row's own where it has one; otherwise the fallback the caller knows
     * (the vendor's money unit), and 0 only when even that is unknown — which
     * is what every release before `alpha.67` assumed for every row.
     */
    public function exponentOr(int $fallback = 0): int
    {
        return $this->exponent ?? $fallback;
    }

    public function belongsTo(int $vendorUserId): bool
    {
        return $this->vendorUserId === $vendorUserId;
    }

    /** Whether the ledger already carries the reversal for this return. */
    public function isReversed(): bool
    {
        return $this->reversalEventKey !== null && $this->refundedAt !== null;
    }
}
