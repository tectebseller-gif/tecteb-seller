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
     * The same details with a few fields replaced.
     *
     * Sixteen readonly promoted properties make a hand-written copy a place
     * for a field to go missing — and the manager's correction is exactly the
     * write where losing one would be silent. Unknown keys are ignored rather
     * than guessed at.
     *
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        $pick = fn (string $key, mixed $current): mixed => array_key_exists($key, $changes) ? $changes[$key] : $current;
        return new self(
            (string) $pick('title', $this->title),
            (string) $pick('type', $this->type),
            (string) $pick('categoryKey', $this->categoryKey),
            (string) $pick('brand', $this->brand),
            (string) $pick('shortDescription', $this->shortDescription),
            (int) $pick('priceMinor', $this->priceMinor),
            ($v = $pick('salePriceMinor', $this->salePriceMinor)) === null ? null : (int) $v,
            ($v = $pick('saleFrom', $this->saleFrom)) === null ? null : (string) $v,
            ($v = $pick('saleTo', $this->saleTo)) === null ? null : (string) $v,
            (string) $pick('sku', $this->sku),
            (int) $pick('stock', $this->stock),
            (int) $pick('minPurchase', $this->minPurchase),
            ($v = $pick('maxPurchase', $this->maxPurchase)) === null ? null : (int) $v,
            (int) $pick('weightGrams', $this->weightGrams),
            (string) $pick('dimensions', $this->dimensions),
            (string) $pick('taxClass', $this->taxClass)
        );
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
