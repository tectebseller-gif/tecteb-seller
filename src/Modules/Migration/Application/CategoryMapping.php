<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * What the category mapping IS, and what applying it would do — read only.
 *
 * `CategoryMap` has stored the decision since `alpha.17` and `ImportFromDokan`
 * has read it at every creation site since. What never existed was a way for a
 * person to take the decision: `CategoryMap::save()` had no caller anywhere in
 * `src/`, so the only screen that mentioned the mapping was the handover page
 * naming `categories_mapped` as a blocker with no door out of it. A function
 * with no caller is not a capability (`alpha.77`), and a gate whose only exit
 * is a developer editing an option row is worse than no gate.
 *
 * This class is the half that only looks, and it has **no write method of any
 * kind** — `ApplyCategoryMapping` is the one writer, and an architecture test
 * asserts both halves from these files' bytes. So «first see what would
 * change» is a property of the class rather than a rule the page keeps.
 *
 * **Two questions that are not the same question, and the whole round turns on
 * the difference:**
 *
 *   `sourceCategories()` asks Dokan which source categories its products
 *   carry, and what each one is mapped to today. It is about the DECISION.
 *
 *   `plan()` asks our own table which imported rows still have no category,
 *   and what each one would become. It is about the PRODUCTS — and saving a
 *   mapping does not move a single one of them. Dokan's «unmapped» list
 *   empties the moment the option is written; the 184 rows keep
 *   `category_key = ''` until somebody applies it on purpose.
 *
 * **Nothing is inferred for a product with no source category.** It is counted
 * and named (`no_source_category`) and never offered for application: no
 * mapping can fix it, because there is nothing to map. The way out is a human
 * choosing a category on the product review page, which already exists.
 *
 * **An invalid target is named, not hidden.** A mapping may point at a term
 * somebody has since deleted or renamed away; the row says so
 * (`target_valid = false`) and the product is held rather than written with a
 * key that resolves to nothing.
 */
final class CategoryMapping
{
    /** How many waiting rows one plan page covers. */
    public const ROW_LIMIT = 500;

    /** How many marketplace categories one picker shows — the `alpha.25` cap. */
    public const PICK_LIMIT = 40;

    /** The key under which a product carrying no source category is grouped. */
    public const NO_SOURCE = '';

    public function __construct(
        private readonly CategoryMap $map,
        private readonly ProductRepositoryInterface $products,
        private readonly ?DokanReaderInterface $dokan = null,
        private readonly ?ProductCategoryDirectoryInterface $categories = null
    ) {
    }

    /**
     * Whether the source side can be asked at all.
     *
     * With Dokan gone there is no stored source category on our rows — the
     * import reads it and does not keep it — so the mapping table cannot be
     * drawn and the application cannot be planned. Said out loud, because the
     * alternative is a screen that shows an empty list and reads as «nothing
     * to map».
     */
    public function sourceAvailable(): bool
    {
        return $this->dokan !== null && $this->dokan->isAvailable();
    }

    /** Whether marketplace categories can be offered — WooCommerce, in practice. */
    public function targetsAvailable(): bool
    {
        return $this->categories !== null && $this->categories->total() > 0;
    }

    /**
     * Every source category Dokan's products carry, with its decision.
     *
     * @return list<array{
     *     key:string, label:string, products:int,
     *     target:string, target_path:string, target_valid:bool, decided:bool
     * }>
     */
    public function sourceCategories(): array
    {
        if (!$this->sourceAvailable()) {
            return [];
        }
        $rows = [];
        foreach ($this->dokan->products() as $product) {
            $key = trim((string) ($product['source_category_key'] ?? ''));
            if ($key === self::NO_SOURCE) {
                // Counted by `productsWithoutSourceCategory()` instead. It is
                // not a category and must not be offered a target.
                continue;
            }
            if (!isset($rows[$key])) {
                $target = $this->map->marketplaceCategoryFor($key);
                $rows[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($product['source_category_label'] ?? '')),
                    'products' => 0,
                    'target' => $target,
                    'target_path' => $target === '' ? '' : $this->targetPath($target),
                    'target_valid' => $target !== '' && $this->targetPath($target) !== '',
                    'decided' => $target !== '',
                ];
            }
            $rows[$key]['products']++;
        }
        ksort($rows);
        return array_values($rows);
    }

    /** How many SOURCE products carry no category at all. */
    public function productsWithoutSourceCategory(): int
    {
        if (!$this->sourceAvailable()) {
            return 0;
        }
        $n = 0;
        foreach ($this->dokan->products() as $product) {
            if (trim((string) ($product['source_category_key'] ?? '')) === self::NO_SOURCE) {
                $n++;
            }
        }
        return $n;
    }

    /** The full ancestry of a marketplace category key, or `''` when it resolves to nothing. */
    public function targetPath(string $key): string
    {
        $key = trim($key);
        if ($key === '' || $this->categories === null) {
            return '';
        }
        $found = $this->categories->find($key);
        return $found === null ? '' : ($found->path !== '' ? $found->path : $found->name);
    }

    /**
     * The marketplace categories a picker may offer, bounded.
     *
     * @return array{results:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductCategory>, matched:int, total:int}
     */
    public function pick(string $query): array
    {
        if ($this->categories === null) {
            return ['results' => [], 'matched' => 0, 'total' => 0];
        }
        return [
            'results' => $this->categories->search($query, self::PICK_LIMIT),
            'matched' => $this->categories->countMatches($query),
            'total' => $this->categories->total(),
        ];
    }

    /**
     * What applying the mapping WOULD do to the products that are already here.
     *
     * Read only, and shown before anything is written — «پیش از اعمال، تعداد
     * محصولات متأثر و تغییر پیشنهادی نمایش داده شود.»
     *
     * `total` is counted with no `LIMIT` and `finished` says whether this page
     * reached it, because a report built from `count($page)` cannot tell «that
     * is all» from «that is as far as I looked» (`alpha.70`). A failed count
     * arrives as `-1` and `finished` is then false.
     *
     * @return array{
     *     source_readable:bool, scanned:int, total:int, finished:bool,
     *     apply:list<array{product_id:int, wc_product_id:int, title:string, vendor_user_id:int,
     *                      source_key:string, source_label:string, target:string, target_path:string}>,
     *     held:array<string,list<array{product_id:int, wc_product_id:int, title:string, source_key:string}>>,
     *     counts:array<string,int>
     * }
     */
    public function plan(int $limit = self::ROW_LIMIT): array
    {
        $waiting = $this->products->importedWithoutCategory($limit);
        $total = $this->products->countImportedWithoutCategory();
        $readable = $this->sourceAvailable();

        $apply = [];
        $held = [];
        $hold = function (string $reason, array $row, string $sourceKey) use (&$held): void {
            $held[$reason][] = [
                'product_id' => (int) $row['id'],
                'wc_product_id' => (int) $row['wc_product_id'],
                'title' => (string) $row['title'],
                'source_key' => $sourceKey,
            ];
        };

        if (!$readable) {
            // ⛔ Not «nothing to do». The source category of an imported row
            // lives in Dokan and nowhere else, so with Dokan gone every
            // waiting row is held under one honest reason.
            foreach ($waiting as $row) {
                $hold('source_unreadable', $row, '');
            }
            return $this->summarise($readable, $waiting, $total, $apply, $held);
        }

        $source = [];
        foreach ($this->dokan->products() as $product) {
            $source[(int) $product['wc_product_id']] = $product;
        }

        foreach ($waiting as $row) {
            $wcId = (int) $row['wc_product_id'];
            $product = $source[$wcId] ?? null;
            if ($wcId === 0 || $product === null) {
                // The row points at no WooCommerce product, or at one Dokan
                // no longer lists. Either way its source category cannot be
                // read, and nothing is guessed from the title.
                $hold('source_missing', $row, '');
                continue;
            }
            $sourceKey = trim((string) ($product['source_category_key'] ?? ''));
            if ($sourceKey === self::NO_SOURCE) {
                $hold('no_source_category', $row, '');
                continue;
            }
            $target = $this->map->marketplaceCategoryFor($sourceKey);
            if ($target === '') {
                $hold('unmapped', $row, $sourceKey);
                continue;
            }
            $path = $this->targetPath($target);
            if ($path === '') {
                $hold('invalid_target', $row, $sourceKey);
                continue;
            }
            $apply[] = [
                'product_id' => (int) $row['id'],
                'wc_product_id' => $wcId,
                'title' => (string) $row['title'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'source_key' => $sourceKey,
                'source_label' => trim((string) ($product['source_category_label'] ?? '')),
                'target' => $target,
                'target_path' => $path,
            ];
        }

        return $this->summarise($readable, $waiting, $total, $apply, $held);
    }

    /**
     * @param list<array{id:int, vendor_user_id:int, wc_product_id:int, title:string}> $waiting
     * @param list<array<string,mixed>> $apply
     * @param array<string,list<array<string,mixed>>> $held
     * @return array<string,mixed>
     */
    private function summarise(bool $readable, array $waiting, int $total, array $apply, array $held): array
    {
        $counts = ['apply' => count($apply)];
        foreach ($held as $reason => $rows) {
            $counts[$reason] = count($rows);
        }
        ksort($held);
        return [
            'source_readable' => $readable,
            'scanned' => count($waiting),
            'total' => $total,
            'finished' => $total >= 0 && count($waiting) >= $total,
            'apply' => $apply,
            'held' => $held,
            'counts' => $counts,
        ];
    }
}
