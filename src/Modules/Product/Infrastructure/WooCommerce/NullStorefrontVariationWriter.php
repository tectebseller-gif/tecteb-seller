<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationWriterInterface;

/**
 * No WooCommerce, so there is no variation to write to — said, not guessed.
 *
 * The same shape as `NullCatalogProjector`: a build without the storefront
 * answers every verb with the one honest code rather than with a `false` that
 * reads as «the write failed» (`alpha.59` — a double that models a verb more
 * generously than the real one makes the broken state inexpressible).
 */
final class NullStorefrontVariationWriter implements StorefrontVariationWriterInterface
{
    public function priceExponent(): ?int
    {
        return null;
    }

    public function writePricesIfUnchanged(
        int $parentWcId,
        int $variationId,
        ?int $regularMinor,
        ?int $saleMinor,
        ?int $seenRegularMinor,
        ?int $seenSaleMinor
    ): array {
        return [
            'outcome' => 'no_woocommerce',
            'found_regular' => null,
            'found_sale' => null,
            'exponent' => null,
        ];
    }

    public function writeStockIfUnchanged(
        int $parentWcId,
        int $variationId,
        int $stock,
        ?int $seen
    ): array {
        return ['outcome' => 'no_woocommerce', 'found' => null];
    }
}
