<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
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

    public function __construct(private readonly ?SpecTemplateRepositoryInterface $templates = null)
    {
    }

    public function search(string $query, int $limit = 50): array
    {
        $matches = $this->matching($query);
        return array_map(fn (int $id): ProductCategory => $this->make($id), array_slice($matches, 0, max(1, $limit)));
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
     * Ordered ids for a query: exact name first, then prefix, then anywhere.
     *
     * @return list<int>
     */
    private function matching(string $query): array
    {
        $this->load();
        $query = $this->fold($query);

        if ($query === '') {
            // Nothing typed: the top of the tree, which is a browsable answer
            // rather than an arbitrary first fifty out of a thousand.
            $top = [];
            foreach ($this->terms as $id => $term) {
                if ($term['parent'] === 0) {
                    $top[] = $id;
                }
            }
            usort($top, fn (int $a, int $b): int => $this->compareNames($a, $b));
            return $top;
        }

        $exact = [];
        $prefix = [];
        $anywhere = [];
        foreach ($this->terms as $id => $term) {
            $name = $this->fold($term['name']);
            $haystack = $name . ' ' . $this->fold($term['slug']) . ' ' . $this->fold($this->paths[$id] ?? '');
            if ($name === $query) {
                $exact[] = $id;
            } elseif (str_starts_with($name, $query)) {
                $prefix[] = $id;
            } elseif (str_contains($haystack, $query)) {
                $anywhere[] = $id;
            }
        }
        foreach ([&$exact, &$prefix, &$anywhere] as &$bucket) {
            usort($bucket, fn (int $a, int $b): int => $this->compareNames($a, $b));
        }
        unset($bucket);
        return array_merge($exact, $prefix, $anywhere);
    }

    private function compareNames(int $a, int $b): int
    {
        return strcmp($this->paths[$a] ?? '', $this->paths[$b] ?? '');
    }

    /**
     * Case and Arabic/Persian letter shapes folded, so «كتاب» finds «کتاب».
     *
     * Typing Persian on an Arabic keyboard — or pasting from a supplier's
     * sheet — produces U+064A and U+0643 where the site has U+06CC and U+06A9.
     * They look identical and never match as bytes.
     */
    private function fold(string $text): string
    {
        $text = strtr($text, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و']);
        $text = preg_replace('/[\x{200c}\x{200f}\x{200e}]/u', '', $text) ?? $text;
        return trim(function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    }

    private function make(int $id): ProductCategory
    {
        $term = $this->terms[$id];
        return new ProductCategory(
            $id,
            $term['name'],
            $this->paths[$id] ?? $term['name'],
            $term['parent'],
            $term['count'],
            isset($this->templated[(string) $id])
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
