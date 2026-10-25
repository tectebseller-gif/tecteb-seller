<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;

/**
 * A handful of `product_cat` terms, by id.
 *
 * ⛔ `find()` answers `null` for an id that is not here, and that matters more
 * than the search: «this mapping points at a category that no longer exists»
 * is a state the mapping screen has to be able to express, and a double that
 * resolved every id would make it inexpressible — the `alpha.59`/`alpha.70`
 * trap, where a stub that cannot model the failure shows an incomplete guard
 * as a complete one.
 */
final class FakeProductCategoryDirectory implements ProductCategoryDirectoryInterface
{
    /** @var array<int,array{name:string,path:string}> */
    public array $terms = [];

    public function search(string $query, int $limit = 50): array
    {
        $out = [];
        foreach ($this->terms as $id => $term) {
            if ($query !== '' && !str_contains($term['name'], $query) && !str_contains($term['path'], $query)) {
                continue;
            }
            $out[] = new ProductCategory((int) $id, $term['name'], $term['path']);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function countMatches(string $query): int
    {
        return count($this->search($query, PHP_INT_MAX));
    }

    public function find(string $value): ?ProductCategory
    {
        $id = (int) $value;
        if (!isset($this->terms[$id])) {
            return null;
        }
        return new ProductCategory($id, $this->terms[$id]['name'], $this->terms[$id]['path']);
    }

    public function total(): int
    {
        return count($this->terms);
    }
}
