<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * One parcel: part of one order line, on its way, with its own tracking.
 *
 * A line shipped in two parcels has two of these, and «how much of this line
 * has shipped» is their sum. That is the reason this is a row and not two
 * columns on the order line — a `carrier` column can hold one carrier, so the
 * second parcel would either overwrite the first or travel untracked.
 */
final class Shipment
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderItemId,
        public readonly int $vendorUserId,
        public readonly int $quantity,
        public readonly string $carrier,
        public readonly string $trackingCode,
        public readonly string $trackingUrl = '',
        public readonly string $note = '',
        public readonly string $shippedAt = '',
        public readonly int $createdBy = 0,
        public readonly string $createdAt = ''
    ) {
    }

    public function isTracked(): bool
    {
        return $this->trackingCode !== '';
    }
}
