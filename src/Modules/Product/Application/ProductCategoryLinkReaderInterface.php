<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

/**
 * Reads the `product_cat` relationship WooCommerce already holds — only reads.
 *
 * This is the port the owner's instruction asks for: «دستهٔ هر محصول را از
 * ارتباط واقعی همان محصول ووکامرس در taxonomy product_cat بخواند … تطبیق بر
 * اساس شناسهٔ معتبر دسته در همین سایت باشد، نه شباهت نام، جست‌وجوی متنی یا
 * حدس.» So there is no method here that takes a name, and no method that
 * writes: «هیچ دسته یا رابطهٔ ووکامرس ساخته، حذف یا تغییر نکند» is enforced by
 * the absence of the capability, and `CategoryLinksAreReadOnlyTest` asserts
 * that from the implementation's bytes — not from this paragraph
 * (`alpha.58`'s rule: a missing method is a property of OUR code, so the
 * guard has to be checked where the code is).
 *
 * Batched on purpose. One query per product is 184 queries on the owner's
 * catalogue for a screen that exists to be looked at before deciding
 * anything.
 */
interface ProductCategoryLinkReaderInterface
{
    /** False when WooCommerce is not running; `linksFor()` then reports unreadable. */
    public function isAvailable(): bool;

    /**
     * The relationship each of these products carries, by term id.
     *
     * Every id returned has been checked against the directory that is
     * actually on this site, so an id attached to a term somebody has since
     * deleted comes back as invalid rather than as a category.
     *
     * A product whose relationship could not be read is present in the result
     * as `CategoryLink::unreadable()`. It is never absent and never empty:
     * «no row» and «the read failed» are opposite facts here (`alpha.8`), and
     * silently returning the empty set would make the preview offer a write
     * for a product nobody could read.
     *
     * @param list<int> $wcProductIds
     * @return array<int,CategoryLink> Keyed by WooCommerce product id, one
     *                                 entry for every id asked for.
     */
    public function linksFor(array $wcProductIds): array;

    /**
     * WooCommerce's own `default_product_cat`, or 0 when it cannot be read.
     *
     * Needed because that term is what the shop substitutes for a product
     * saved with no categories, so it marks «بدون دسته» rather than a
     * decision. Measured on `alpha.80`: the intake stored it as a real key
     * (`row_none_category_key=15`).
     */
    public function defaultTermId(): int;
}
