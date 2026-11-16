<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * THE MAIN PICTURE WOOCOMMERCE HOLDS FOR A PRODUCT — read, and only read.
 *
 * The vendor's own list resolved its thumbnail from `tmc_product_images`,
 * which is the right source for a product a vendor BUILT here: those
 * attachments are theirs, `ownedBy()` guards them, and the gallery is the
 * thing they edit. An IMPORTED product has no such row — intake records the
 * fields it can own and deliberately copies no media — so the cell was empty
 * beside a product whose picture a buyer can see on the storefront right now.
 *
 * ⛔ THERE IS NO WRITING METHOD AND THERE MUST NEVER BE ONE. No attachment is
 * created, re-parented, re-stamped or deleted by this path, and nothing here
 * puts the shop's attachment id into the vendor's gallery: an id that is not
 * `ownedBy()` this shop would be dropped by the very next save, and copying
 * the file would make a second attachment for a picture that already has one.
 * It is a READ for display, which is what the empty cell needed.
 *
 * One method, batched, because the list and the edit form must show the SAME
 * picture for the same product and two readers are how two screens come to
 * disagree (`alpha.78`). The list asks about a page of rows at once — one
 * indexed query, not one per row (`alpha.91`) — and the form asks about one.
 */
interface StorefrontImageReaderInterface
{
    /**
     * Thumbnail URLs by WooCommerce product id.
     *
     * An id that has no featured image, or that this reader could not answer
     * for, is ABSENT from the result rather than present with an empty
     * string: the caller already has a state for «no picture» and renders a
     * labelled placeholder for it, and inventing a URL would be worse than
     * saying nothing.
     *
     * @param list<int> $wcProductIds
     * @return array<int,string>
     */
    public function thumbnailUrls(array $wcProductIds): array;
}
