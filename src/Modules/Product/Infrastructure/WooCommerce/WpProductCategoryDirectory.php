<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryMatcher;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;

/**
 * WooCommerce's `product_cat`, read once per request and indexed.
 *
 * `hide_empty => false` is the whole point: the owner's shop has 1,070
 * categories and most of them have no product yet, because the products are
 * what the vendors are about to add. A directory that hid them would hand
 * back an empty list on the site that needs it most.
 *
 * The tree is loaded in ONE query and the ancestry is walked in PHP. The
 * obvious alternative — `get_ancestors()` per term — is 1,070 extra queries
 * to draw one dropdown.
 */
final class WpProductCategoryDirectory implements ProductCategoryDirectoryInterface
{
    /** The prefix `alpha.23` and earlier used when inventing a term per template key. */
    public const LEGACY_SLUG_PREFIX = 'tmc-';

    /** @var array<int,array{name:string,slug:string,parent:int,count:int}>|null */
    private ?array $terms = null;

    /** @var array<int,string>|null term id => full path */
    private ?array $paths = null;

    /** @var array<string,true>|null category keys that have a template */
    private ?array $templated = null;

    /**
     * Ranked hits, per query, for this request.
     *
     * One render asks the same question twice — `search()` for the page and
     * `countMatches()` for «۴۰ مورد از ۱۲۰». Measured on 1,070 terms, one pass
     * is 7ms of `preg_replace`; doing it twice is 7ms of nothing.
     *
     * @var array<string,list<array{id:int,rank:int}>>
     */
    private array $ranked = [];

    public function __construct(private readonly ?SpecTemplateRepositoryInterface $templates = null)
    {
    }

    public function search(string $query, int $limit = 50): array
    {
        $matches = array_slice($this->matching($query), 0, max(1, $limit));
        return array_map(
            fn (array $hit): ProductCategory => $this->make($hit['id'], CategoryMatcher::isFuzzy($hit['rank'])),
            $matches
        );
    }

    public function countMatches(string $query): int
    {
        return count($this->matching($query));
    }

    public function find(string $value): ?ProductCategory
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $this->load();

        if (ctype_digit($value) && isset($this->terms[(int) $value])) {
            return $this->make((int) $value);
        }

        // A product saved before `alpha.24` carries a template key. It maps to
        // the `tmc-…` term the old projector made, if that term was ever
        // created; otherwise to a term whose slug simply matches. Resolving it
        // here is what keeps those products rendering instead of showing an
        // empty «دسته» the vendor never chose to clear.
        foreach ([self::LEGACY_SLUG_PREFIX . $value, $value] as $slug) {
            foreach ($this->terms as $id => $term) {
                if ($term['slug'] === $slug) {
                    return $this->make($id);
                }
            }
        }
        return null;
    }

    public function total(): int
    {
        $this->load();
        return count($this->terms);
    }

    /**
     * Ranked hits for a query: every exact match before any near one.
     *
     * The ranking itself is `CategoryMatcher`, which is pure and tested on its
     * own. This method only decides WHAT is compared: the term name against
     * the query, with the slug and the whole ancestry as the secondary
     * haystack. An ancestor matching is a reason to offer the row, never a
     * reason to call it exact — «تجهیزات پزشکی» is not the category, it is
     * where the category lives.
     *
     * Ties break on the path, so two branches that both end in «لوازم جانبی»
     * come out in a stable, readable order rather than whatever the database
     * felt like.
     *
     * @return list<array{id:int,rank:int}>
     */
    private function matching(string $query): array
    {
        $this->load();
        if (isset($this->ranked[$query])) {
            return $this->ranked[$query];
        }

        if (CategoryMatcher::fold($query) === '') {
            // Nothing typed: the top of the tree, which is a browsable answer
            // rather than an arbitrary first fifty out of a thousand.
            $top = [];
            foreach ($this->terms as $id => $term) {
                if ($term['parent'] === 0) {
                    $top[] = ['id' => $id, 'rank' => CategoryMatcher::CONTAINS];
                }
            }
            usort($top, fn (array $a, array $b): int => $this->compareNames($a['id'], $b['id']));
            return $this->ranked[$query] = $top;
        }

        $hits = [];
        foreach ($this->terms as $id => $term) {
            $rank = CategoryMatcher::rank(
                $query,
                $term['name'],
                $term['slug'] . ' ' . ($this->paths[$id] ?? '')
            );
            if ($rank !== CategoryMatcher::NO_MATCH) {
                $hits[] = ['id' => $id, 'rank' => $rank];
            }
        }
        usort($hits, function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank'] ?: $this->compareNames($a['id'], $b['id']);
        });
        return $this->ranked[$query] = $hits;
    }

    private function compareNames(int $a, int $b): int
    {
        return strcmp($this->paths[$a] ?? '', $this->paths[$b] ?? '');
    }

    private function make(int $id, bool $fuzzy = false): ProductCategory
    {
        $term = $this->terms[$id];
        return new ProductCategory(
            $id,
            $term['name'],
            $this->paths[$id] ?? $term['name'],
            $term['parent'],
            $term['count'],
            isset($this->templated[(string) $id]),
            $fuzzy
        );
    }

    private function load(): void
    {
        if ($this->terms !== null) {
            return;
        }
        $this->terms = [];
        $this->paths = [];
        $this->templated = [];

        if (!function_exists('get_terms') || !function_exists('taxonomy_exists') || !taxonomy_exists('product_cat')) {
            return;
        }
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
        ]);
        if (!is_array($terms)) {
            return;
        }
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $this->terms[(int) $term->term_id] = [
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => (int) $term->parent,
                'count' => (int) $term->count,
            ];
        }
        foreach (array_keys($this->terms) as $id) {
            $this->paths[$id] = $this->buildPath($id);
        }
        foreach ($this->templates?->all() ?? [] as $template) {
            $this->templated[$template->categoryKey] = true;
        }
    }

    /** Walks up to the root, with a depth cap so a cyclic parent cannot hang a page. */
    private function buildPath(int $id): string
    {
        $names = [];
        $cursor = $id;
        for ($depth = 0; $depth < 12 && $cursor > 0 && isset($this->terms[$cursor]); $depth++) {
            array_unshift($names, $this->terms[$cursor]['name']);
            $cursor = $this->terms[$cursor]['parent'];
        }
        return implode(' › ', $names);
    }
}
