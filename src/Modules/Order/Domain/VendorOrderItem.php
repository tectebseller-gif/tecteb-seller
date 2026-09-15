<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * One line of an order, as the marketplace records it.
 *
 * It carries the commission SNAPSHOT (FIN-01: «قانون در ایجاد قلم سفارش
 * snapshot می‌شود») rather than a reference to today's rate, so changing the
 * rate tomorrow cannot change what this sale owed. It carries no customer
 * contact detail at all — not because the view hides it, but because it was
 * never read into this object (PRIV-01).
 */
final class VendorOrderItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $orderItemId,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly string $title,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly int $unitPriceMinor,
        public readonly int $baseMinor,
        public readonly int $taxMinor = 0,
        public readonly ?int $commissionMinor = null,
        public readonly ?int $vendorShareMinor = null,
        public readonly ?int $rateBasisPoints = null,
        public readonly string $rateSource = '',
        public readonly string $ledgerEvent = '',
        public readonly OrderItemStatus $status = OrderItemStatus::Placed,
        public readonly string $carrier = '',
        public readonly string $trackingCode = '',
        public readonly ?string $shippedAt = null,
        public readonly ?int $variationId = null,
        public readonly int $wcProductId = 0,
        public readonly string $createdAt = ''
    ) {
    }

    public function belongsTo(int $vendorUserId): bool
    {
        return $this->vendorUserId === $vendorUserId;
    }

    /** Whether the money for this line reached the ledger. */
    public function isRecorded(): bool
    {
        return $this->ledgerEvent !== '' && $this->vendorShareMinor !== null;
    }
}
