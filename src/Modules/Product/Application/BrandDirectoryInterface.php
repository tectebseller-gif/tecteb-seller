<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Brand;

/**
 * The list of approved brands, and the ONE write that adds to it.
 *
 * **Where the list lives, and why it is not ours.** WooCommerce 11.0.1
 * registers a `product_brand` taxonomy of its own — measured on the disposable
 * install rather than read off a changelog. The owner asked for exactly this
 * to be checked first: «ابتدا ساختار موجود برندها را بررسی کن؛ منبع موازی
 * غیرضروری نساز». A `tmc_brands` table beside it would be that parallel
 * source: two lists for one question, and the manager maintaining whichever
 * one they happened to open.
 *
 * So the approved brands ARE those terms. The manager adds and renames them in
 * WooCommerce's own screen, and this interface reads them — the same
 * arrangement `ProductCategoryDirectoryInterface` has had for categories since
 * `alpha.25`, and for the same reason.
 *
 * **The one difference is `register()`.** Categories are never created by this
 * plugin (`NoParallelTaxonomyTest`), because a category a projection invented
 * is a category nobody's menus know. A brand is the opposite case: the owner
 * asked that a vendor be able to propose a name and THE MANAGER approve and add
 * it, in one click, from the review screen. That is a decision-driven write, in
 * the shape `applySlug()` already has — a projection may not move a name a
 * person chose, and a person choosing one is a different event with a different
 * method.
 *
 * **What `register()` must not do is make a second brand for one name.**
 * `findByKey()` is asked first, with `BrandName`'s key, so «آمبو بگ» proposed
 * twice — or proposed while «آمبوبگ» already exists — adopts the term that is
 * there. Two managers approving the same name at the same moment therefore end
 * with one term and two approvals pointing at it.
 */
interface BrandDirectoryInterface
{
    /** False when WooCommerce is not running; every other method then answers empty. */
    public function isAvailable(): bool;

    /**
     * Brands whose name matches, for the vendor's picker.
     *
     * @return list<Brand>
     */
    public function search(string $query, int $limit = 30): array;

    public function find(int $termId): ?Brand;

    /** The approved brand this name IS, by `BrandName::key()`, or null. */
    public function findByKey(string $key): ?Brand;

    /**
     * Adds the name to the list, or returns the brand it already is.
     *
     * Null when the write refused — which is NOT «it is not there»: a caller
     * that read a refusal as an absence would report a brand registered when
     * nothing was.
     */
    public function register(string $name): ?Brand;

    /** How many approved brands there are, for the «the list is empty» case. */
    public function count(): int;
}
