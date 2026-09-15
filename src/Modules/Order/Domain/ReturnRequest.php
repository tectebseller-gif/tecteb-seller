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
        public readonly ?string $refundedAt = null
    ) {
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
