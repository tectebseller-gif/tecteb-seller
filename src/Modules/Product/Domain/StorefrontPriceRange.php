<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * What a variable parent costs, for a LIST of products.
 *
 * ⛔ A separate, smaller type than `StorefrontVariationSet` on purpose, and the
 * reason is cost, not taste. The vendor's product list shows up to a hundred
 * rows; reading every parent's children through `wc_get_product()` would be one
 * product load per row plus one per child — the shape `alpha.91` opened on the
 * handover page and `alpha.96` opened again on the sync step. WooCommerce
 * already maintains `wc_product_meta_lookup`, which holds `min_price`,
 * `max_price`, `stock_status` and `stock_quantity` per product, so the list
 * costs ONE indexed query however many rows it has.
 *
 * The full read belongs to the form, where there is one product.
 *
 * `readable === false` is not «free» and not «out of stock»: it is «this row
 * was not answered», and the list prints it as «نامشخص».
 */
final class StorefrontPriceRange
{
    private function __construct(
        public readonly bool $readable,
        public readonly ?int $minMinor,
        public readonly ?int $maxMinor,
        /** The shop's price scale, so the figures are formatted at the unit they were read in. */
        public readonly int $priceExponent,
        /** `instock` / `outofstock` / `onbackorder`, '' when unreadable. */
        public readonly string $stockStatus,
        /** The parent's own number — `null` whenever the parent leaves stock to its children. */
        public readonly ?int $stockQuantity
    ) {
    }

    public static function unreadable(): self
    {
        return new self(false, null, null, 0, '', null);
    }

    public static function of(
        ?int $minMinor,
        ?int $maxMinor,
        int $priceExponent,
        string $stockStatus,
        ?int $stockQuantity
    ): self {
        return new self(true, $minMinor, $maxMinor, $priceExponent, $stockStatus, $stockQuantity);
    }

    /** Is there a price to print at all? A parent with no priced child has none. */
    public function hasPrice(): bool
    {
        return $this->readable && $this->minMinor !== null;
    }

    /** One number rather than two, when every child costs the same. */
    public function isSinglePrice(): bool
    {
        return $this->hasPrice() && $this->minMinor === $this->maxMinor;
    }

    /**
     * Only `outofstock` is «ناموجود». An empty status is unreadable and
     * `onbackorder` is orderable — reading either as out of stock is how a
     * parent that sells fine came to be shown as unavailable.
     */
    public function isOutOfStock(): bool
    {
        return $this->stockStatus === 'outofstock';
    }
}
