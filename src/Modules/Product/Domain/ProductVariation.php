<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One sellable combination of a variable product, with its own price, stock,
 * SKU and picture.
 *
 * The `combination` string is the identity: attribute keys sorted, joined as
 * `key=value|key=value`. Sorting is what makes «size=s|color=red» and
 * «color=red|size=s» the same variation, which a unique index then enforces —
 * a shop cannot end up with two rows for one combination and two different
 * prices.
 */
final class ProductVariation
{
    /** @param array<string,string> $attributes attribute key => chosen option */
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly array $attributes,
        public readonly int $priceMinor = 0,
        public readonly ?int $salePriceMinor = null,
        public readonly string $sku = '',
        public readonly int $stock = 0,
        public readonly int $mediaId = 0,
        public readonly bool $enabled = true,
        public readonly ?int $wcVariationId = null
    ) {
    }

    /** @param array<string,string> $attributes */
    public static function combinationOf(array $attributes): string
    {
        $pairs = [];
        foreach ($attributes as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if ($key === '' || $value === '') {
                continue;
            }
            $pairs[$key] = $key . '=' . $value;
        }
        ksort($pairs);
        return implode('|', $pairs);
    }

    public function combination(): string
    {
        return self::combinationOf($this->attributes);
    }

    /**
     * A variation is sellable when it has a price. Stock may be zero — that is
     * «ناموجود», which stops the purchase without making the variation
     * incomplete (A.1, same rule as the parent product).
     */
    public function isComplete(): bool
    {
        return $this->priceMinor > 0 && $this->attributes !== [];
    }

    public function effectivePriceMinor(string $today): int
    {
        return $this->salePriceMinor !== null && $this->salePriceMinor >= 0 && $this->salePriceMinor <= $this->priceMinor
            ? $this->salePriceMinor
            : $this->priceMinor;
    }

    public function isPurchasable(string $today): bool
    {
        return $this->enabled && $this->stock > 0 && $this->effectivePriceMinor($today) > 0;
    }
}
