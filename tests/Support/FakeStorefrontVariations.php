<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;

/**
 * What the shop answers about a product's combinations, without WooCommerce.
 *
 * The real reader needs `WC_Product_Variable`; the database suite has no
 * WooCommerce, so the only way to express «the shop holds the combinations»
 * there is a double. It carries NO writing method, for the same reason the
 * interface does not.
 */
final class FakeStorefrontVariations implements StorefrontVariationReaderInterface
{
    /** @var array<int,StorefrontVariationSet> */
    private array $sets = [];

    /** @var array<int,StorefrontPriceRange> */
    private array $ranges = [];

    /** @var list<int> every id `setFor()` was asked about, in order */
    public array $asked = [];

    /** @var list<int> every id `rangesFor()` was asked about, in order */
    public array $askedRanges = [];

    /** @param list<StorefrontVariation> $variations */
    public function holds(int $wcProductId, array $variations, int $priceExponent = 0): void
    {
        $this->sets[$wcProductId] = StorefrontVariationSet::read($variations, $priceExponent);
    }

    public function answers(int $wcProductId, StorefrontVariationSet $set): void
    {
        $this->sets[$wcProductId] = $set;
    }

    public function range(int $wcProductId, StorefrontPriceRange $range): void
    {
        $this->ranges[$wcProductId] = $range;
    }

    public function setFor(int $wcProductId): StorefrontVariationSet
    {
        $this->asked[] = $wcProductId;
        return $this->sets[$wcProductId]
            ?? StorefrontVariationSet::unavailable(StorefrontVariationSet::NOT_VARIABLE);
    }

    public function rangesFor(array $wcProductIds): array
    {
        $out = [];
        foreach ($wcProductIds as $id) {
            $id = (int) $id;
            $this->askedRanges[] = $id;
            $out[$id] = $this->ranges[$id] ?? StorefrontPriceRange::unreadable();
        }
        return $out;
    }
}
