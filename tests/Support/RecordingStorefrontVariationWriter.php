<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationWriterInterface;

/**
 * Every call the service made, and what it was handed.
 *
 * The real writer needs `WC_Product_Variable`, which the database suite has
 * no WooCommerce for — its own behaviour is measured in
 * `StorefrontVariationWriteTest` against the WooCommerce doubles. What this
 * one is for is the half that suite cannot see: whether the SERVICE refused
 * before writing at all, and WHICH PARENT it handed over.
 *
 * ⛔ That second question is the one that matters most. The parent id must be
 * the one read from the marketplace row `findOwned()` proved belongs to this
 * vendor — never a number that arrived in the form — so «a vendor cannot name
 * another shop's parent» is measured rather than argued.
 */
final class RecordingStorefrontVariationWriter implements StorefrontVariationWriterInterface
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public ?int $exponent = 0;

    /** @var array{outcome:string, found_regular:?int, found_sale:?int, exponent:?int}|null */
    public ?array $priceAnswer = null;

    /** @var array{outcome:string, found:?int}|null */
    public ?array $stockAnswer = null;

    public function priceExponent(): ?int
    {
        return $this->exponent;
    }

    public function writePricesIfUnchanged(
        int $parentWcId,
        int $variationId,
        ?int $regularMinor,
        ?int $saleMinor,
        ?int $seenRegularMinor,
        ?int $seenSaleMinor
    ): array {
        $this->calls[] = [
            'verb' => 'prices',
            'parent' => $parentWcId,
            'variation' => $variationId,
            'regular' => $regularMinor,
            'sale' => $saleMinor,
            'seen_regular' => $seenRegularMinor,
            'seen_sale' => $seenSaleMinor,
        ];
        return $this->priceAnswer ?? [
            'outcome' => 'written',
            'found_regular' => $regularMinor,
            'found_sale' => $saleMinor,
            'exponent' => $this->exponent,
        ];
    }

    public function writeStockIfUnchanged(
        int $parentWcId,
        int $variationId,
        int $stock,
        ?int $seen
    ): array {
        $this->calls[] = [
            'verb' => 'stock',
            'parent' => $parentWcId,
            'variation' => $variationId,
            'stock' => $stock,
            'seen' => $seen,
        ];
        return $this->stockAnswer ?? ['outcome' => 'written', 'found' => $stock];
    }
}
