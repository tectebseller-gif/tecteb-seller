<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;

/**
 * A WooCommerce catalogue in an array, so the classification and the intake
 * can be asked about states a disposable site would have to be bent into.
 *
 * **Why a double, and what it is NOT.** The real reader is
 * `WpCatalogueReader`, and what it does — `wc_get_product()`, the
 * `product_type` taxonomy, the brand terms, the author's roles — needs
 * WooCommerce, which the suites do not have. What the suites CAN ask is the
 * part that decides: given these facts, which bucket is this product in, what
 * does the intake write, and what does it refuse. That is this file's job and
 * it is named a double rather than presented as WooCommerce.
 *
 * The real reader is measured on real WooCommerce 11.0.1 by
 * `tools/alpha55-reproduction.sh`, and its answers there are reported
 * separately from these.
 */
final class FakeCatalogueReader implements CatalogueReaderInterface
{
    /** @var array<int,array<string,mixed>> wc id => the page row */
    public array $rows = [];

    /** @var array<int,array<string,mixed>> wc id => the detail */
    public array $details = [];

    /** @var array<int,array<string,mixed>> wc id => the vendor signals */
    public array $signals = [];

    /** @var array<int,array<string,string>> wc id => preservation risks */
    public array $risks = [];

    /** @var array<int,array{min:int,max:?int,step:int}> */
    public array $quantities = [];

    /** @var list<array{wc_product_id:int, rows:int}> */
    public array $duplicates = [];

    /**
     * Marketplace rows with no WooCommerce product, which only the unified
     * list can show: a vendor's draft, and a row pointing at a post that is
     * gone.
     *
     * @var list<array<string,mixed>>
     */
    public array $marketplaceOnly = [];

    /**
     * Which marketplace rows are «waiting», by marketplace row id.
     *
     * The real reader asks `ReviewQueueSql::waiting()`, which reads the
     * revision and brand-proposal tables — things this double does not have.
     * Rather than ignore the filter (a double that silently drops a condition
     * agrees with every build), the test says which rows are waiting and the
     * filter is honoured against that.
     *
     * @var list<int>
     */
    public array $waiting = [];

    public bool $available = true;

    /** @var array<string,mixed> */
    public array $counts = [
        'by_status' => [],
        'by_type' => [],
        'variations' => 0,
        'unsupported' => [],
        'total' => 0,
        // The scope notes a real reader answers from `alpha.56` on. Zero is
        // «nothing odd», which is what a double should default to.
        'without_type' => 0,
        'with_two_types' => 0,
        'strays' => ['on_variations' => 0, 'on_other_post_types' => 0, 'no_post_at_all' => 0],
    ];

    /**
     * One product, with everything the intake reads and sensible neighbours
     * for everything it does not.
     *
     * @param array<string,mixed> $detail overrides for the detail
     * @param array<string,mixed> $signals overrides for the vendor evidence
     */
    public function add(int $wcId, array $detail = [], array $signals = []): void
    {
        $this->details[$wcId] = array_merge([
            'wc_product_id' => $wcId,
            'type' => 'simple',
            'status' => 'publish',
            'catalog_visibility' => 'visible',
            'title' => 'محصول ' . $wcId,
            'slug' => 'product-' . $wcId,
            'permalink' => 'https://example.test/?p=' . $wcId,
            'short_description' => 'خلاصه',
            'description' => 'متن کامل',
            'sku' => 'SKU-' . $wcId,
            'regular_price' => '450000',
            'sale_price' => '',
            'date_on_sale_from' => '',
            'date_on_sale_to' => '',
            'manage_stock' => true,
            'stock_quantity' => 7,
            'stock_status' => 'instock',
            'backorders' => 'no',
            'sold_individually' => false,
            'weight' => '1.5',
            'length' => '',
            'width' => '',
            'height' => '',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'shipping_class_id' => 0,
            'category_ids' => [31, 12],
            'tag_ids' => [],
            'brand' => '',
            'brand_term_id' => 0,
            'image_id' => 0,
            'gallery_image_ids' => [],
            'attribute_count' => 0,
            'variation_ids' => [],
            'upsell_ids' => [],
            'cross_sell_ids' => [],
            'menu_order' => 0,
        ], $detail);

        $this->signals[$wcId] = array_merge([
            'author_id' => 0,
            'author_exists' => true,
            'author_roles' => [],
            'author_is_admin' => false,
            'vendor_profile' => '',
            'vendor_profile_user' => 0,
            'dokan_seller' => false,
            'marketplace_vendor_meta' => 0,
            'marketplace_rows' => [],
        ], $signals);

        $this->rows[$wcId] = [
            'wc_product_id' => $wcId,
            'post_status' => (string) $this->details[$wcId]['status'],
            'post_author' => (int) $this->signals[$wcId]['author_id'],
            'title' => (string) $this->details[$wcId]['title'],
            'slug' => (string) $this->details[$wcId]['slug'],
            'type' => (string) $this->details[$wcId]['type'],
            'type_supported' => in_array((string) $this->details[$wcId]['type'], ['simple', 'variable'], true),
            'sku' => (string) $this->details[$wcId]['sku'],
            'variations' => count((array) $this->details[$wcId]['variation_ids']),
            'marketplace_rows' => $this->signals[$wcId]['marketplace_rows'],
            // Distinct per product so a sort has something to sort by, and
            // ASCENDING with the id, so «newest first» and «by id» are not
            // the same order by accident — a double that cannot tell two
            // orders apart proves nothing about either.
            'changed_at' => (string) ($detail['changed_at'] ?? sprintf('2026-01-%02d 00:00:00', min(28, max(1, $wcId % 28)))),
        ];
        ksort($this->rows);
    }

    /** @param list<array{product_id:int, vendor_user_id:int, ownership:string, status:string}> $links */
    public function link(int $wcId, array $links): void
    {
        $this->signals[$wcId]['marketplace_rows'] = $links;
        $this->rows[$wcId]['marketplace_rows'] = $links;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function counts(): array
    {
        return $this->counts;
    }

    public function productsAfter(int $afterId, int $limit): array
    {
        $out = [];
        foreach ($this->rows as $id => $row) {
            if ($id > $afterId && count($out) < max(1, $limit)) {
                $out[] = $row;
            }
        }
        $ids = array_map(static fn (array $r): int => (int) $r['wc_product_id'], $out);
        return [
            'rows' => $out,
            'cursor' => $ids === [] ? $afterId : (int) end($ids),
            'done' => count($out) < max(1, $limit),
        ];
    }

    /** A marketplace row with no WooCommerce product behind it. */
    public function addMarketplaceOnly(
        int $productId,
        int $vendorUserId,
        string $title = '',
        string $status = 'draft',
        string $ownership = 'marketplace',
        bool $orphanLink = false
    ): void {
        $this->marketplaceOnly[] = [
            'wc_product_id' => 0,
            'product_id' => $productId,
            'title' => $title === '' ? 'پیش‌نویس ' . $productId : $title,
            'sku' => '',
            'wc_status' => CatalogueFacets::STATUS_ABSENT,
            'author_id' => 0,
            'ownership' => $ownership,
            'marketplace_status' => $status,
            'vendor_user_id' => $vendorUserId,
            'rows_seen' => 1,
            'changed_at' => '2026-01-01 00:00:00',
            'source' => CatalogueFacets::SOURCE_DRAFT,
            'management' => $ownership === LinkOwnership::Marketplace->value
                ? CatalogueFacets::MANAGED_OURS
                : CatalogueFacets::MANAGED_OBSERVED,
            'type' => 'simple',
            'type_supported' => true,
            'variations' => 0,
            'marketplace_rows' => [],
            'thumbnail_id' => 0,
            'orphan_link' => $orphanLink,
            'submission' => 's0.r0.b0',
        ];
    }

    public function unifiedPage(array $filters, int $limit, int $offset, string $sort = 'updated'): array
    {
        $all = [];
        foreach ($this->rows as $row) {
            $links = (array) $row['marketplace_rows'];
            $first = $links[0] ?? null;
            $ownership = $first === null ? '' : (string) $first['ownership'];
            $all[] = [
                'wc_product_id' => (int) $row['wc_product_id'],
                'product_id' => $first === null ? 0 : (int) $first['product_id'],
                'title' => (string) $row['title'],
                'sku' => (string) $row['sku'],
                'wc_status' => (string) $row['post_status'],
                'author_id' => (int) $row['post_author'],
                'ownership' => $ownership,
                'marketplace_status' => $first === null ? '' : (string) $first['status'],
                'vendor_user_id' => $first === null ? 0 : (int) $first['vendor_user_id'],
                'rows_seen' => count($links),
                'changed_at' => (string) ($row['changed_at'] ?? '2026-01-01 00:00:00'),
                'source' => $first === null
                    ? CatalogueFacets::SOURCE_WOOCOMMERCE
                    : CatalogueFacets::SOURCE_MARKETPLACE,
                'management' => match ($ownership) {
                    LinkOwnership::Marketplace->value => CatalogueFacets::MANAGED_OURS,
                    LinkOwnership::Observed->value => CatalogueFacets::MANAGED_OBSERVED,
                    default => CatalogueFacets::MANAGED_NONE,
                },
                'type' => (string) $row['type'],
                'type_supported' => (bool) $row['type_supported'],
                'variations' => (int) $row['variations'],
                'marketplace_rows' => $links,
                'thumbnail_id' => 0,
                'orphan_link' => false,
                'submission' => 's0.r0.b0',
            ];
        }
        foreach ($this->marketplaceOnly as $row) {
            $all[] = $row;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $source = CatalogueFacets::source((string) ($filters['source'] ?? ''));
        $management = CatalogueFacets::management((string) ($filters['management'] ?? ''));
        $status = CatalogueFacets::wcStatus((string) ($filters['wc_status'] ?? ''));
        $marketplaceStatus = trim((string) ($filters['marketplace_status'] ?? ''));
        $needsReview = ($filters['needs_review'] ?? false) === true;
        $waiting = $this->waiting;

        $matched = array_values(array_filter($all, static function (array $row) use (
            $search,
            $source,
            $management,
            $status,
            $marketplaceStatus,
            $needsReview,
            $waiting
        ): bool {
            if ($marketplaceStatus !== '' && (string) $row['marketplace_status'] !== $marketplaceStatus) {
                return false;
            }
            if ($needsReview && !in_array((int) $row['product_id'], $waiting, true)) {
                return false;
            }
            if ($search !== ''
                && !str_contains((string) $row['title'], $search)
                && !str_contains((string) $row['sku'], $search)
                && (string) $row['wc_product_id'] !== $search
                && (string) $row['product_id'] !== $search) {
                return false;
            }
            if ($source !== '' && (string) $row['source'] !== $source) {
                return false;
            }
            if ($management !== '' && (string) $row['management'] !== $management) {
                return false;
            }
            return $status === '' || (string) $row['wc_status'] === $status;
        }));

        $facets = ['source' => [], 'wc_status' => [], 'management' => []];
        foreach ($all as $row) {
            foreach (['source', 'management', 'wc_status'] as $key) {
                $value = (string) $row[$key];
                $facets[$key][$value] = ($facets[$key][$value] ?? 0) + 1;
            }
        }

        usort($matched, static function (array $a, array $b) use ($sort): int {
            $first = $sort === 'title'
                ? strcmp((string) $a['title'], (string) $b['title'])
                : ($sort === 'oldest'
                    ? strcmp((string) $a['changed_at'], (string) $b['changed_at'])
                    : strcmp((string) $b['changed_at'], (string) $a['changed_at']));
            // The same tiebreaker the real reader uses, for the same reason:
            // `changed_at` has second precision and a page that re-sorts the
            // same second differently shows one product twice.
            if ($first !== 0) {
                return $first;
            }
            $byWc = (int) $b['wc_product_id'] <=> (int) $a['wc_product_id'];
            return $byWc !== 0 ? $byWc : ((int) $b['product_id'] <=> (int) $a['product_id']);
        });

        return [
            'rows' => array_slice($matched, max(0, $offset), max(1, $limit)),
            'total' => count($matched),
            'facets' => $facets,
        ];
    }

    public function authorSignals(array $authorIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $authorIds)) as $id) {
            $found = null;
            foreach ($this->signals as $signals) {
                if ((int) $signals['author_id'] === $id) {
                    $found = $signals;
                    break;
                }
            }
            $out[$id] = [
                'author_id' => $id,
                'author_exists' => (bool) ($found['author_exists'] ?? false),
                'author_roles' => (array) ($found['author_roles'] ?? []),
                'author_is_admin' => (bool) ($found['author_is_admin'] ?? false),
                'dokan_seller' => (bool) ($found['dokan_seller'] ?? false),
                'vendor_profile' => (string) ($found['vendor_profile'] ?? ''),
            ];
        }
        return $out;
    }

    public function detail(int $wcProductId): array
    {
        return $this->details[$wcProductId] ?? [];
    }

    public function quantityRule(int $wcProductId): array
    {
        return $this->quantities[$wcProductId] ?? ['min' => 1, 'max' => null, 'step' => 1];
    }

    public function vendorSignals(int $wcProductId): array
    {
        return $this->signals[$wcProductId] ?? [
            'author_id' => 0,
            'author_exists' => false,
            'author_roles' => [],
            'author_is_admin' => false,
            'vendor_profile' => '',
            'vendor_profile_user' => 0,
            'dokan_seller' => false,
            'marketplace_vendor_meta' => 0,
            'marketplace_rows' => [],
        ];
    }

    public function duplicateLinks(int $limit = 50): array
    {
        return $this->duplicates;
    }

    public function pluginKeys(int $wcProductId): array
    {
        return [];
    }

    public function preservationRisks(int $wcProductId): array
    {
        return $this->risks[$wcProductId] ?? [];
    }

    /** @var list<array{wc_product_id:int, product_id:int, title:string, shop_price:string, ownership:string}> */
    public array $pricelessPublished = [];

    public function publishedWithoutPrice(int $limit = 100): array
    {
        return array_slice($this->pricelessPublished, 0, max(1, $limit));
    }
}
