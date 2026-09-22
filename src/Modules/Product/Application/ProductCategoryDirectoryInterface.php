<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;

/**
 * Where product categories come from: WooCommerce's own `product_cat`.
 *
 * Until `alpha.24` the vendor's «دسته» dropdown was built from the manager's
 * SPEC TEMPLATES, and the projector then invented a `tmc-…` term for whatever
 * key it found. Two consequences, both measured on the owner's staging site:
 * a shop with 1,070 real categories offered the vendor **none**, so step 1 of
 * the form could never be completed and no product ever reached review; and
 * any product that did get through was filed under a duplicate term that the
 * shop's own menus, filters and Elementor templates know nothing about.
 *
 * So the directory is a read interface over the taxonomy that is already
 * there. It never creates a term. A category with no products and a category
 * with no template are both perfectly selectable — «hide_empty» is a display
 * choice for a storefront, not a rule about what a vendor may sell, and a
 * template is guidance the manager may not have written yet.
 */
interface ProductCategoryDirectoryInterface
{
    /**
     * Categories whose name, path or slug matches — ordered by how closely.
     *
     * An empty query returns the top of the tree, which is what the picker
     * shows before anybody has typed. `$limit` is a real cap: a query of one
     * letter against 1,070 categories must not render 1,070 radio buttons.
     *
     * @return list<ProductCategory>
     */
    public function search(string $query, int $limit = 50): array;

    /** How many matched in total, so the picker can say «۱۲۰ مورد، ۵۰ نمایش داده شد». */
    public function countMatches(string $query): int;

    /**
     * One category by the value a form carried.
     *
     * Accepts a term id, and — so that products saved by earlier versions
     * still render — a legacy template key or slug.
     */
    public function find(string $value): ?ProductCategory;

    /** Total number of categories that exist, template or no template. */
    public function total(): int;
}
