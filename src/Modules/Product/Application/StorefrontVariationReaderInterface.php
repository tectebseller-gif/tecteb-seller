<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;

/**
 * What WooCommerce holds for a variable product — read, and only read.
 *
 * ⛔ THERE IS NO WRITING METHOD AND THERE MUST NEVER BE ONE. That is how
 * «این مسیر به محصول زنده دست نمی‌زند» is enforced here: not by a sentence in
 * a docblock and not by a capability check, but by there being nothing to
 * call. `StorefrontVariationReaderIsReadOnlyTest` reads the bytes of every
 * implementation and refuses the WooCommerce verbs.
 *
 * Two methods, because the two callers have different costs and the same
 * answer served both badly:
 *
 *  - `setFor()` is the FORM: one product, every field the panel shows, the
 *    full `wc_get_product()` walk over `get_children()`.
 *  - `rangesFor()` is the LIST: up to a hundred rows, so ONE indexed query
 *    over `wc_product_meta_lookup` and nothing per row (`alpha.91`).
 *
 * Both answer «نمی‌دانم» as its own state. A product this reader could not
 * read is never reported as a product with no variations and never as a price
 * of zero: those were the two readings that made the imported catalogue look
 * empty and unsellable in the vendor's own panel.
 */
interface StorefrontVariationReaderInterface
{
    /** The whole truth about one variable parent, or the named reason there is none. */
    public function setFor(int $wcProductId): StorefrontVariationSet;

    /**
     * Price ranges and stock status for many parents at once.
     *
     * Ids this reader could not answer for are present with
     * `StorefrontPriceRange::unreadable()` rather than absent, so a caller
     * cannot mistake «not answered» for «not asked».
     *
     * @param list<int> $wcProductIds
     * @return array<int,StorefrontPriceRange>
     */
    public function rangesFor(array $wcProductIds): array;
}
