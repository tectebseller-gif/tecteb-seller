<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One variation as WooCommerce holds it — read, never written.
 *
 * This exists because `ProductVariation` is the marketplace's OWN row and a
 * product that came in from Dokan has none: `DbVariationRepository` reads
 * `tmc_product_variations` and nothing else, so for shop #102's imported
 * products `variations()` answered `[]` and `attributes()` answered `[]`.
 * `VariationsView` then printed «هنوز ویژگی‌ای تعریف نشده است» about a parent
 * that has three healthy children in WooCommerce, and the list printed the
 * parent's own zero price as «۰ تومان / ناموجود».
 *
 * So the two are deliberately different types rather than one with a flag:
 *
 *  - `ProductVariation` is a row this plugin may edit and project;
 *  - `StorefrontVariation` is a fact about the shop, with the shop's real id,
 *    which nothing in this object can change.
 *
 * ⛔ EVERY NUMBER HERE IS NULLABLE WHERE «نمی‌دانم» IS POSSIBLE, because the
 * whole defect being fixed was an unreadable value arriving as a zero. A price
 * WooCommerce holds at a scale this shop cannot express exactly is `null` and
 * prints as «نامشخص»; it is never 0, which would read as «رایگان».
 */
final class StorefrontVariation
{
    /** @param array<string,string> $attributes the shop's own keys => chosen value */
    public function __construct(
        public readonly int $wcVariationId,
        public readonly array $attributes,
        public readonly string $sku,
        public readonly ?int $regularPriceMinor,
        public readonly ?int $salePriceMinor,
        /** What a buyer would pay now — sale when one is live, else regular. */
        public readonly ?int $currentPriceMinor,
        /** `publish`; a variation WooCommerce hides is `private`. */
        public readonly bool $enabled,
        public readonly bool $manageStock,
        /** `null` when the variation does not manage stock, or we could not read it. */
        public readonly ?int $stockQuantity,
        /** `instock` / `outofstock` / `onbackorder`, or '' when unreadable. */
        public readonly string $stockStatus,
        public readonly string $backorders,
        public readonly int $imageId
    ) {
    }

    /**
     * Could a buyer put this in a basket today?
     *
     * Three facts, and all three are the shop's: it is published, it has a
     * price, and WooCommerce does not call it out of stock. A variation whose
     * price we could not read is NOT buyable from here — not because it isn't,
     * but because this object may not claim what it did not read.
     */
    public function isBuyable(): bool
    {
        return $this->enabled
            && $this->currentPriceMinor !== null
            && $this->stockStatus !== 'outofstock';
    }

    /** The combination in the marketplace's own spelling, for comparison only. */
    public function combination(): string
    {
        return ProductVariation::combinationOf($this->attributes);
    }
}
