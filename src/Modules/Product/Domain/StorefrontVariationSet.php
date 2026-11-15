<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * What the shop holds for one variable parent, and WHY when it holds nothing.
 *
 * ⛔ The reason is the point. Until this type existed the panel had one way of
 * saying «no variations» and four different situations arrived through it:
 * WooCommerce is not installed, the marketplace row has no storefront product,
 * the storefront product is not a variable one, and the read failed. Those have
 * four different next steps and only one of them is «define an attribute».
 *
 * `READ` is the only state that carries variations, and it may carry none — a
 * variable parent with no children is a real, and empty, answer.
 */
final class StorefrontVariationSet
{
    public const READ = 'read';
    public const NO_WOOCOMMERCE = 'no_woocommerce';
    public const NO_STOREFRONT_PRODUCT = 'no_storefront_product';
    public const NOT_VARIABLE = 'not_variable';
    public const UNREADABLE = 'unreadable';

    /** @param list<StorefrontVariation> $variations */
    private function __construct(
        public readonly string $state,
        public readonly array $variations,
        /** The shop's price scale, so every figure is formatted at the unit it was read in. */
        public readonly int $priceExponent,
        /**
         * Children the shop listed and this reader could not load.
         *
         * Counted rather than dropped: `count()` plus this number is what the
         * shop says it has, and a panel that printed «۳ ترکیب» about a parent
         * with four children would be the same class of lie as the zero price
         * — a partial read presented as the whole (`alpha.100`).
         */
        public readonly int $unreadableChildren
    ) {
    }

    /** @param list<StorefrontVariation> $variations */
    public static function read(array $variations, int $priceExponent = 0, int $unreadableChildren = 0): self
    {
        return new self(self::READ, array_values($variations), $priceExponent, max(0, $unreadableChildren));
    }

    public static function unavailable(string $reason): self
    {
        return new self($reason === self::READ ? self::UNREADABLE : $reason, [], 0, 0);
    }

    /** Did the read see everything the shop listed? */
    public function isComplete(): bool
    {
        return $this->isRead() && $this->unreadableChildren === 0;
    }

    public function isRead(): bool
    {
        return $this->state === self::READ;
    }

    /** Read, and the shop actually holds combinations — the case the panel must show. */
    public function hasVariations(): bool
    {
        return $this->isRead() && $this->variations !== [];
    }

    public function count(): int
    {
        return count($this->variations);
    }

    /**
     * The cheapest price a buyer could pay, or `null` when not one variation
     * had a readable price. Unreadable prices are SKIPPED rather than counted
     * as zero, so a set where one of three is unreadable still reports a real
     * range for the two it could read — and `count()` beside it says three.
     */
    public function minPriceMinor(): ?int
    {
        return $this->extreme(true);
    }

    public function maxPriceMinor(): ?int
    {
        return $this->extreme(false);
    }

    private function extreme(bool $lowest): ?int
    {
        $best = null;
        foreach ($this->variations as $variation) {
            $price = $variation->currentPriceMinor;
            if ($price === null) {
                continue;
            }
            if ($best === null || ($lowest ? $price < $best : $price > $best)) {
                $best = $price;
            }
        }
        return $best;
    }

    /** How many a buyer could add to a basket today. */
    public function buyableCount(): int
    {
        $n = 0;
        foreach ($this->variations as $variation) {
            $n += $variation->isBuyable() ? 1 : 0;
        }
        return $n;
    }

    /** How many prices could not be read at this shop's scale. */
    public function unreadablePriceCount(): int
    {
        $n = 0;
        foreach ($this->variations as $variation) {
            $n += $variation->currentPriceMinor === null ? 1 : 0;
        }
        return $n;
    }

    /**
     * The total only exists when EVERY variation manages its own stock and
     * every number was readable. A parent whose children leave stock to
     * WooCommerce has no total, and inventing one by treating «not managed» as
     * nought is the defect this round is closing.
     */
    public function totalStock(): ?int
    {
        if ($this->variations === []) {
            return null;
        }
        $total = 0;
        foreach ($this->variations as $variation) {
            if (!$variation->manageStock || $variation->stockQuantity === null) {
                return null;
            }
            $total += $variation->stockQuantity;
        }
        return $total;
    }

    /**
     * The axes, derived from the combinations themselves rather than from a
     * stored attribute list — because an imported product HAS no stored list,
     * and the children already say which keys and options exist.
     *
     * @return array<string,list<string>> attribute key => the options seen, in first-seen order
     */
    public function axes(): array
    {
        $axes = [];
        foreach ($this->variations as $variation) {
            foreach ($variation->attributes as $key => $value) {
                if ($key === '' || $value === '') {
                    continue;
                }
                $axes[$key] ??= [];
                if (!in_array($value, $axes[$key], true)) {
                    $axes[$key][] = $value;
                }
            }
        }
        return $axes;
    }

    /** @return list<int> the shop's own ids, for a report that reconciles by id */
    public function wcVariationIds(): array
    {
        return array_map(
            static fn (StorefrontVariation $v): int => $v->wcVariationId,
            $this->variations
        );
    }
}
