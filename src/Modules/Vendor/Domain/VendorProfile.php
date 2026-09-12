<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * The vendor record created when an application is approved.
 *
 * Two separate permissions, exactly as the master spec insists (§4.1):
 * being approved as a vendor and being allowed to publish products without
 * review are different decisions, and the second is off unless granted.
 */
final class VendorProfile
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $storeName,
        public readonly bool $canSell,
        public readonly bool $canPublishDirectly,
        public readonly string $createdAt = ''
    ) {
    }
}
