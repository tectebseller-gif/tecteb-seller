<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;

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

    public bool $available = true;

    /** @var array<string,mixed> */
    public array $counts = [
        'by_status' => [],
        'by_type' => [],
        'variations' => 0,
        'unsupported' => [],
        'total' => 0,
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
}
