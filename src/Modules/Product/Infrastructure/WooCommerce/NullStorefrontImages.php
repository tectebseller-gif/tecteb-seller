<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontImageReaderInterface;

/**
 * No WooCommerce, so no storefront picture to fall back to.
 *
 * Answers with an empty map, which is the one honest answer: the caller's
 * state for «this product has no picture here» already exists and draws a
 * labelled placeholder. Bound by the probe, the same way
 * `NullStorefrontVariationReader` is, so «the shop is not running» is a
 * decision taken once at wiring rather than a `function_exists()` scattered
 * through the screens.
 */
final class NullStorefrontImages implements StorefrontImageReaderInterface
{
    public function thumbnailUrls(array $wcProductIds): array
    {
        return [];
    }
}
