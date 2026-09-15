<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * "From this many, each one costs this much" — one step of a vendor's own
 * wholesale ladder.
 *
 * Both numbers come from the vendor, for their own product. The marketplace
 * has no tariff and no minimum of its own: DEC-05 says «تعرفه/حداقل عمده»
 * still needs final rules, so there is no default here to be mistaken for one.
 */
final class PriceTier
{
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly int $minQuantity,
        public readonly int $unitPriceMinor
    ) {
    }

    public function appliesTo(int $quantity): bool
    {
        return $quantity >= $this->minQuantity;
    }
}
