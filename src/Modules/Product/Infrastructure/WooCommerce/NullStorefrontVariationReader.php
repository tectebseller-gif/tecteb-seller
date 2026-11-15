<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;

/**
 * No WooCommerce, so no storefront variations — said, not faked.
 *
 * ⛔ The answer is `NO_WOOCOMMERCE`, which is a different sentence on the
 * screen from `UNREADABLE` and from «no variations». A build with no
 * storefront is a legitimate build (the marketplace's own tests run in one),
 * and a panel that told such a vendor their variations had failed to load
 * would be describing a fault that does not exist.
 */
final class NullStorefrontVariationReader implements StorefrontVariationReaderInterface
{
    public function setFor(int $wcProductId): StorefrontVariationSet
    {
        return StorefrontVariationSet::unavailable(StorefrontVariationSet::NO_WOOCOMMERCE);
    }

    public function rangesFor(array $wcProductIds): array
    {
        $out = [];
        foreach ($wcProductIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = StorefrontPriceRange::unreadable();
            }
        }
        return $out;
    }
}
