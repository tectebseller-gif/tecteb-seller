<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryLinkReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;

/**
 * `wp_term_relationships` for `product_cat`, in one query per batch.
 *
 * Read with SQL rather than `wp_get_object_terms()` per product for a
 * measured reason: the preview this feeds covers up to 500 rows, and the
 * per-product call is one query each plus a term-cache fill. The ids that
 * come back are then validated against `ProductCategoryDirectoryInterface`,
 * which is the same list the picker draws from — so «a valid category id on
 * this site» means the same thing on the preview, in the apply action and on
 * the vendor's form, instead of three functions agreeing by coincidence
 * (`alpha.79`).
 *
 * ⛔ **Nothing here writes.** There is no `INSERT`, no `UPDATE`, no `DELETE`,
 * and no call to `wp_set_object_terms()`, `wp_insert_term()` or any sibling.
 * That is the owner's constraint — «هیچ دسته یا رابطهٔ ووکامرس ساخته، حذف یا
 * تغییر نکند» — and `CategoryLinksAreReadOnlyTest` checks it against this
 * file's bytes with the comments stripped first (`alpha.22`), because a
 * docblock that promises it is the thing that breaks the test otherwise.
 *
 * **A failed read is reported, not swallowed.** `getResults()` answers «no
 * rows» and «the statement failed» with the same empty array (`alpha.8`), and
 * here those are opposites: an empty answer for a product that really has
 * three categories would let the preview offer a write. So the rows are
 * counted in a separate statement first, and a count that cannot be read — or
 * that does not match what the listing returned — makes every product in the
 * batch `unreadable`.
 */
final class WpProductCategoryLinkReader implements ProductCategoryLinkReaderInterface
{
    /** WooCommerce's option holding the term it substitutes for «no category». */
    public const DEFAULT_OPTION = 'default_product_cat';

    private ?int $defaultTermId = null;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ProductCategoryDirectoryInterface $categories
    ) {
    }

    /**
     * Whether an attached id can be TURNED INTO an answer on this site.
     *
     * Deliberately not `taxonomy_exists('product_cat')`. The relationship
     * rows live in plain WordPress tables and are readable whether or not
     * WooCommerce has registered its taxonomy yet — registration happens on a
     * hook, so asking a function whether a feature is ready is the mistake
     * `alpha.13` paid for with Action Scheduler. What this reader actually
     * needs is a directory to validate ids against: with none, an attached id
     * cannot be called a category OR called invalid, and the honest answer is
     * «I could not read this».
     */
    public function isAvailable(): bool
    {
        return $this->categories->total() > 0;
    }

    public function linksFor(array $wcProductIds): array
    {
        $ids = [];
        foreach ($wcProductIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $unreadable = [];
        foreach ($ids as $id) {
            $unreadable[$id] = CategoryLink::unreadable($id);
        }
        if (!$this->isAvailable()) {
            return $unreadable;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $from = 'FROM ' . $this->db->prefix() . 'term_relationships tr'
            . ' INNER JOIN ' . $this->db->prefix() . 'term_taxonomy tt'
            . ' ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . " WHERE tt.taxonomy = 'product_cat' AND tr.object_id IN ({$placeholders})";

        // Counted FIRST and in its own statement, so «this product has no
        // categories» can be told apart from «the read did not happen».
        $expected = $this->db->getVar('SELECT COUNT(*) ' . $from, $ids);
        if ($expected === null) {
            return $unreadable;
        }
        $expected = (int) $expected;

        $rows = $this->db->getResults(
            'SELECT tr.object_id AS wc_id, tt.term_id AS term_id ' . $from
            . ' ORDER BY tr.object_id ASC, tt.term_id ASC',
            $ids
        );
        if (count($rows) !== $expected) {
            // Either the listing failed, or it came back short. Both are
            // «I could not read this», and a short read is the dangerous one:
            // it looks exactly like a product with fewer categories.
            return $unreadable;
        }

        $attached = [];
        foreach ($rows as $row) {
            $attached[(int) $row['wc_id']][] = (int) $row['term_id'];
        }

        $defaultTermId = $this->defaultTermId();
        $out = [];
        foreach ($ids as $id) {
            $terms = $attached[$id] ?? [];
            $out[$id] = CategoryLink::of($id, $terms, $this->pathsFor($terms), $defaultTermId);
        }
        return $out;
    }

    public function defaultTermId(): int
    {
        if ($this->defaultTermId !== null) {
            return $this->defaultTermId;
        }
        // Memoised per INSTANCE, never statically: a static memo outlives the
        // request and makes a test that changes the option read the stale
        // answer (`alpha.67`).
        $raw = function_exists('get_option') ? get_option(self::DEFAULT_OPTION, 0) : 0;
        return $this->defaultTermId = is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Ancestry for each attached id that resolves in the directory today.
     *
     * An id missing from the result is an id the directory does not know, and
     * `CategoryLink::of()` records it as invalid rather than as a category —
     * so a term somebody deleted since the import cannot become a written key.
     *
     * @param list<int> $termIds
     * @return array<int,string>
     */
    private function pathsFor(array $termIds): array
    {
        $paths = [];
        foreach ($termIds as $id) {
            $found = $this->categories->find((string) $id);
            if ($found === null) {
                continue;
            }
            $paths[$id] = $found->path !== '' ? $found->path : $found->name;
        }
        return $paths;
    }
}
