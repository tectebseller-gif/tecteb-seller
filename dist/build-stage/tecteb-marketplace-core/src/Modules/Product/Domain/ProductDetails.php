<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The four-step product form of §6, as one value object.
 *
 * Prices are integer minor units for the same reason the ledger's are: a
 * price that arrives as a float is a price that can be off by a rial, and it
 * is the number every commission is computed from.
 */
final class ProductDetails
{
    public function __construct(
        // 1. introduction
        public readonly string $title = '',
        public readonly string $type = ProductType::SIMPLE,
        public readonly string $categoryKey = '',
        public readonly string $brand = '',
        public readonly string $shortDescription = '',
        // 2. price and inventory
        public readonly int $priceMinor = 0,
        public readonly ?int $salePriceMinor = null,
        public readonly ?string $saleFrom = null,
        public readonly ?string $saleTo = null,
        public readonly string $sku = '',
        public readonly int $stock = 0,
        public readonly int $minPurchase = 1,
        public readonly ?int $maxPurchase = null,
        // 3. technical
        public readonly int $weightGrams = 0,
        public readonly string $dimensions = '',
        public readonly string $taxClass = ''
    ) {
    }

    /**
     * Fields that must be filled before the product can be submitted. Stock
     * is NOT here: a product may legitimately be submitted out of stock, and
     * zero stock stops the sale rather than the paperwork (A.1).
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];
        if (trim($this->title) === '') {
            $missing[] = 'title';
        }
        if (trim($this->categoryKey) === '') {
            $missing[] = 'category';
        }
        if ($this->priceMinor <= 0) {
            $missing[] = 'price';
        }
        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /**
     * The price a buyer would pay right now — the base every commission is
     * calculated from (FIN-01: after discount, before tax).
     */
    public function effectivePriceMinor(string $today): int
    {
        if ($this->salePriceMinor === null || $this->salePriceMinor < 0) {
            return $this->priceMinor;
        }
        if ($this->saleFrom !== null && $today < $this->saleFrom) {
            return $this->priceMinor;
        }
        if ($this->saleTo !== null && $today > $this->saleTo) {
            return $this->priceMinor;
        }
        return $this->salePriceMinor;
    }

    /**
     * Zero stock stops the purchase. Backorder and pre-order are not
     * "disabled by default" — they do not exist (A.1), so there is no setting
     * that could turn them on.
     */
    public function isPurchasable(string $today): bool
    {
        return $this->stock > 0 && $this->effectivePriceMinor($today) > 0;
    }
}
