<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceBrandDirectory;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * The existing WooCommerce catalogue, read through WordPress and WooCommerce.
 *
 * **Why the counts are SQL and the detail is `wc_get_product()`.** A count
 * over tens of thousands of products has to be `COUNT(*) … GROUP BY` or the
 * preview is a timeout; one product's values have to come from WooCommerce's
 * own data store, because WooCommerce is the authority on its own product and
 * reading `postmeta` by hand would reproduce — slightly differently — the
 * dozen filters it applies. `alpha.50` measured what that costs: a `_price`
 * row and a `WC_Product` object disagreeing in the same request, because
 * `wc_get_product()` keeps an instance cache.
 *
 * For a READ that disagreement does not matter, and where it would — the
 * compare-and-write of the Min Max handover — a different port asks the rows
 * directly (`WpShopQuantityPort::readRows()`).
 *
 * Nothing here writes. There is no method that could.
 */
final class WpCatalogueReader implements CatalogueReaderInterface
{
    /**
     * The product types this marketplace's own form can express.
     *
     * Anything else is reported BY NAME and never converted — a grouped
     * product has no price of its own and an external one is a link to
     * somebody else's shop, and silently importing either as «simple» would
     * be this plugin deciding what a product is.
     */
    public const SUPPORTED_TYPES = [ProductType::SIMPLE, ProductType::VARIABLE];

    /**
     * Meta-key prefixes worth naming when they are on a product.
     *
     * The question is «does this product depend on another plugin», so the
     * answer is a tally of PREFIXES. Values are never read: a dependency
     * report must not become an export of whatever those plugins store.
     */
    private const KNOWN_PREFIXES = [
        '_wcmmq' => 'Min Max Control',
        'wcmmq' => 'Min Max Control',
        '_dokan' => 'Dokan',
        'dokan' => 'Dokan',
        'rank_math' => 'Rank Math',
        '_yoast' => 'Yoast SEO',
        '_aioseo' => 'All in One SEO',
        '_elementor' => 'Elementor',
        '_wpml' => 'WPML',
        '_tmc' => 'این افزونه',
        'tmc_' => 'این افزونه',
    ];

    public function __construct(
        private readonly DatabaseInterface $db,
        // Optional so a build without the vendor module still constructs. Its
        // absence means the author's application status reads as unknown,
        // which pushes a product towards «ambiguous» — the safe direction.
        private readonly ?VendorRepositoryInterface $vendors = null
    ) {
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    public function counts(): array
    {
        $out = [
            'by_status' => [],
            'by_type' => [],
            'variations' => 0,
            'unsupported' => [],
            'total' => 0,
        ];
        $posts = '`' . $this->db->prefix() . 'posts`';
        foreach ($this->db->getResults(
            'SELECT post_status, COUNT(*) AS total FROM ' . $posts . '
              WHERE post_type = %s GROUP BY post_status',
            ['product']
        ) as $row) {
            $out['by_status'][(string) $row['post_status']] = (int) $row['total'];
            $out['total'] += (int) $row['total'];
        }
        $out['variations'] = (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM ' . $posts . ' WHERE post_type = %s',
            ['product_variation']
        ) ?? 0);

        // WooCommerce's own `product_type` taxonomy, which is where a
        // product's type actually lives — not a meta key and not a column.
        foreach ($this->db->getResults(
            'SELECT t.name AS type, COUNT(*) AS total
               FROM `' . $this->db->prefix() . 'term_relationships` r
         INNER JOIN `' . $this->db->prefix() . 'term_taxonomy` tt
                 ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s
         INNER JOIN `' . $this->db->prefix() . 'terms` t ON t.term_id = tt.term_id
              GROUP BY t.name',
            ['product_type']
        ) as $row) {
            $type = (string) $row['type'];
            $out['by_type'][$type] = (int) $row['total'];
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $out['unsupported'][$type] = (int) $row['total'];
            }
        }
        return $out;
    }

    public function productsAfter(int $afterId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->getResults(
            'SELECT ID, post_status, post_author, post_title, post_name
               FROM `' . $this->db->prefix() . 'posts`
              WHERE post_type = %s AND ID > %d
              ORDER BY ID ASC LIMIT %d',
            ['product', max(0, $afterId), $limit]
        );
        if ($rows === []) {
            return ['rows' => [], 'cursor' => $afterId, 'done' => true];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['ID'], $rows);
        // Four batched lookups for the whole page, so the cost of a page does
        // not grow with its size.
        $types = $this->typesOf($ids);
        $skus = $this->metaOf($ids, '_sku');
        $children = $this->childCounts($ids);
        $links = $this->marketplaceRowsFor($ids);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $type = $types[$id] ?? '';
            $out[] = [
                'wc_product_id' => $id,
                'post_status' => (string) $row['post_status'],
                'post_author' => (int) $row['post_author'],
                'title' => (string) $row['post_title'],
                'slug' => (string) $row['post_name'],
                'type' => $type,
                'type_supported' => in_array($type, self::SUPPORTED_TYPES, true),
                'sku' => (string) ($skus[$id] ?? ''),
                'variations' => (int) ($children[$id] ?? 0),
                'marketplace_rows' => $links[$id] ?? [],
            ];
        }
        return [
            'rows' => $out,
            'cursor' => (int) end($ids),
            'done' => count($rows) < $limit,
        ];
    }

    public function detail(int $wcProductId): array
    {
        if ($wcProductId <= 0 || !$this->isAvailable()) {
            return [];
        }
        $product = wc_get_product($wcProductId);
        if (!is_object($product) || !method_exists($product, 'get_id') || (int) $product->get_id() <= 0) {
            return [];
        }
        $brand = $this->brandOf($wcProductId);
        return [
            'wc_product_id' => (int) $product->get_id(),
            'type' => (string) $product->get_type(),
            'status' => (string) $product->get_status(),
            'catalog_visibility' => (string) $product->get_catalog_visibility(),
            'title' => (string) $product->get_name(),
            'slug' => (string) $product->get_slug(),
            'permalink' => function_exists('get_permalink') ? (string) get_permalink($wcProductId) : '',
            'short_description' => (string) $product->get_short_description(),
            'description' => (string) $product->get_description(),
            'sku' => (string) $product->get_sku(),
            // The decimal STRINGS, never floats: `alpha.39`'s rule, and the
            // caller decides what an inexact one means rather than rounding.
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'date_on_sale_from' => $this->dateOf($product, 'get_date_on_sale_from'),
            'date_on_sale_to' => $this->dateOf($product, 'get_date_on_sale_to'),
            'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity() === null ? null : (int) $product->get_stock_quantity(),
            'stock_status' => (string) $product->get_stock_status(),
            'backorders' => (string) $product->get_backorders(),
            'sold_individually' => (bool) $product->get_sold_individually(),
            'weight' => (string) $product->get_weight(),
            'length' => (string) $product->get_length(),
            'width' => (string) $product->get_width(),
            'height' => (string) $product->get_height(),
            'tax_status' => (string) $product->get_tax_status(),
            'tax_class' => (string) $product->get_tax_class(),
            'shipping_class_id' => (int) $product->get_shipping_class_id(),
            'category_ids' => array_map('intval', (array) $product->get_category_ids()),
            'tag_ids' => array_map('intval', (array) $product->get_tag_ids()),
            'brand' => $brand['name'],
            'brand_term_id' => $brand['term_id'],
            'image_id' => (int) $product->get_image_id(),
            'gallery_image_ids' => array_map('intval', (array) $product->get_gallery_image_ids()),
            'attribute_count' => count((array) $product->get_attributes()),
            'variation_ids' => array_map('intval', (array) $product->get_children()),
            'upsell_ids' => array_map('intval', (array) $product->get_upsell_ids()),
            'cross_sell_ids' => array_map('intval', (array) $product->get_cross_sell_ids()),
            'menu_order' => (int) $product->get_menu_order(),
        ];
    }

    public function quantityRule(int $wcProductId): array
    {
        $meta = [];
        foreach (ProjectedQuantity::keys() as $key) {
            $meta[$key] = $wcProductId > 0 && function_exists('get_post_meta')
                ? (string) get_post_meta($wcProductId, $key, true)
                : '';
        }
        // `ProjectedQuantity::ruleFrom()` is the one reader of these three
        // rows, and reusing it is the point: a second interpretation of
        // «empty maximum» or «step 1» would be a second rule.
        $rule = ProjectedQuantity::ruleFrom($meta);
        return ['min' => $rule->min, 'max' => $rule->max, 'step' => $rule->step];
    }

    public function vendorSignals(int $wcProductId): array
    {
        $out = [
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
        if ($wcProductId <= 0) {
            return $out;
        }
        $author = (int) ($this->db->getVar(
            'SELECT post_author FROM `' . $this->db->prefix() . 'posts` WHERE ID = %d',
            [$wcProductId]
        ) ?? 0);
        $out['author_id'] = $author;
        $out['marketplace_rows'] = $this->marketplaceRowsFor([$wcProductId])[$wcProductId] ?? [];
        if (function_exists('get_post_meta')) {
            $out['marketplace_vendor_meta'] = (int) get_post_meta(
                $wcProductId,
                WooCommerceProjector::VENDOR_META,
                true
            );
        }
        if ($author <= 0) {
            return $out;
        }
        $user = function_exists('get_userdata') ? get_userdata($author) : false;
        if ($user !== false && is_object($user)) {
            $out['author_exists'] = true;
            $out['author_roles'] = array_values(array_map('strval', (array) ($user->roles ?? [])));
            $out['author_is_admin'] = in_array('administrator', $out['author_roles'], true)
                || in_array('shop_manager', $out['author_roles'], true);
            // The SAME definition `WpDokanReader` uses — the `seller` role
            // inside the serialized capabilities meta — so the two readers
            // cannot disagree about who a Dokan seller is.
            $out['dokan_seller'] = in_array('seller', $out['author_roles'], true);
        }
        $application = $this->vendors?->findApplicationByUser($author);
        if ($application !== null) {
            $out['vendor_profile'] = $application->status->value;
            $out['vendor_profile_user'] = $author;
        } elseif ($this->vendors?->findProfileByUser($author) !== null) {
            // A profile with no application: an imported or hand-made shop.
            // Named rather than read as «no vendor», because it is one.
            $out['vendor_profile'] = 'profile_without_application';
            $out['vendor_profile_user'] = $author;
        }
        return $out;
    }

    public function duplicateLinks(int $limit = 50): array
    {
        $rows = $this->db->getResults(
            'SELECT wc_product_id, COUNT(*) AS rows_seen
               FROM `' . $this->db->prefix() . 'tmc_products`
              WHERE wc_product_id IS NOT NULL
              GROUP BY wc_product_id HAVING COUNT(*) > 1
              ORDER BY wc_product_id ASC LIMIT %d',
            [max(1, min(200, $limit))]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'wc_product_id' => (int) $row['wc_product_id'],
                'rows' => (int) $row['rows_seen'],
            ];
        }
        return $out;
    }

    public function pluginKeys(int $wcProductId): array
    {
        if ($wcProductId <= 0) {
            return [];
        }
        // Keys only. `meta_value` is never selected, so this cannot become a
        // dump of another plugin's data.
        $rows = $this->db->getResults(
            'SELECT DISTINCT meta_key FROM `' . $this->db->prefix() . 'postmeta`
              WHERE post_id = %d',
            [$wcProductId]
        );
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            foreach (self::KNOWN_PREFIXES as $prefix => $label) {
                if (str_starts_with($key, $prefix)) {
                    $out[$label] = ($out[$label] ?? 0) + 1;
                    continue 2;
                }
            }
        }
        return $out;
    }

    public function preservationRisks(int $wcProductId): array
    {
        $detail = $this->detail($wcProductId);
        if ($detail === []) {
            return ['product' => 'محصول خوانده نشد'];
        }
        $risks = [];
        if (!in_array((string) $detail['type'], self::SUPPORTED_TYPES, true)) {
            $risks['type'] = sprintf(
                'نوع «%s» در فرم این بازارگاه وجود ندارد و تبدیل نمی‌شود.',
                (string) $detail['type']
            );
        }
        if ((string) $detail['catalog_visibility'] !== 'visible') {
            // `project()` writes catalogue visibility from the marketplace
            // STATUS — `visible` for published, `hidden` otherwise — so any
            // other value is lost on the first projection. There is no field
            // for it in the four-step form.
            $risks['catalog_visibility'] = sprintf(
                'نمایش در فهرست «%s» است و این بازارگاه فیلدی برایش ندارد؛ اولین همگام‌سازی آن را به «visible» برمی‌گرداند.',
                (string) $detail['catalog_visibility']
            );
        }
        if ($detail['manage_stock'] === false) {
            $risks['manage_stock'] = 'مدیریت موجودی خاموش است و همگام‌سازی بازارگاه آن را روشن می‌کند.';
        }
        if ((string) $detail['backorders'] !== 'no') {
            $risks['backorders'] = sprintf(
                'سفارش پس‌افت «%s» است و این بازارگاه فیلدی برایش ندارد.',
                (string) $detail['backorders']
            );
        }
        if ($detail['sold_individually'] === true) {
            $risks['sold_individually'] = 'فروش تک‌واحدی روشن است؛ قاعدهٔ تعداد خرید بازارگاه کنار می‌کشد و تناقض ثبت می‌شود.';
        }
        foreach (['length' => 'طول', 'width' => 'عرض', 'height' => 'ارتفاع'] as $key => $label) {
            if (trim((string) $detail[$key]) !== '') {
                $risks['dimensions'] = 'ابعاد (طول/عرض/ارتفاع) در این بازارگاه یک فیلد متنی است و همگام‌سازی آن‌ها را نمی‌نویسد؛ مقدار فعلی ووکامرس دست‌نخورده می‌مانَد.';
                break;
            }
        }
        if ((int) $detail['shipping_class_id'] > 0) {
            $risks['shipping_class'] = 'کلاس ارسال در این بازارگاه فیلدی ندارد؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if ($detail['tag_ids'] !== []) {
            $risks['tags'] = 'برچسب‌های محصول در این بازارگاه فیلدی ندارند؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if ($detail['upsell_ids'] !== [] || $detail['cross_sell_ids'] !== []) {
            $risks['related'] = 'محصولات مرتبط (upsell/cross-sell) در این بازارگاه فیلدی ندارند؛ مقدار فعلی دست‌نخورده می‌مانَد.';
        }
        if (!$this->isIntegerAmount((string) $detail['regular_price'])
            || !$this->isIntegerAmount((string) $detail['sale_price'])) {
            $risks['price_scale'] = 'قیمت این محصول عدد صحیح نیست و این بازارگاه قیمت را به‌صورت عدد صحیح نگه می‌دارد؛ گرد کردنش ممنوع است.';
        }
        return $risks;
    }

    /** `''` and an exact integer both pass; anything with a fraction does not. */
    private function isIntegerAmount(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        return preg_match('/^-?\d+(\.0+)?$/', $value) === 1;
    }

    private function dateOf(mixed $product, string $getter): string
    {
        if (!method_exists($product, $getter)) {
            return '';
        }
        $date = $product->{$getter}();
        if ($date === null) {
            return '';
        }
        return is_object($date) && method_exists($date, 'date') ? (string) $date->date('Y-m-d') : '';
    }

    /** @return array{name:string, term_id:int} */
    private function brandOf(int $wcProductId): array
    {
        if (!function_exists('wp_get_object_terms') || !function_exists('taxonomy_exists')
            || !taxonomy_exists(WooCommerceBrandDirectory::TAXONOMY)) {
            return ['name' => '', 'term_id' => 0];
        }
        $terms = wp_get_object_terms($wcProductId, WooCommerceBrandDirectory::TAXONOMY);
        if (!is_array($terms) || $terms === [] || !is_object($terms[0])) {
            return ['name' => '', 'term_id' => 0];
        }
        return ['name' => (string) $terms[0]->name, 'term_id' => (int) $terms[0]->term_id];
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function typesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT r.object_id, t.name AS type
               FROM `' . $this->db->prefix() . 'term_relationships` r
         INNER JOIN `' . $this->db->prefix() . 'term_taxonomy` tt
                 ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s
         INNER JOIN `' . $this->db->prefix() . 'terms` t ON t.term_id = tt.term_id
              WHERE r.object_id IN (' . $placeholders . ')',
            array_merge(['product_type'], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['object_id']] = (string) $row['type'];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function metaOf(array $ids, string $key): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT post_id, meta_value FROM `' . $this->db->prefix() . 'postmeta`
              WHERE meta_key = %s AND post_id IN (' . $placeholders . ')
              ORDER BY meta_id ASC',
            array_merge([$key], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['post_id'];
            // The LOWEST `meta_id` wins, which is what WordPress itself reads
            // — `wp_postmeta` has no unique index on `(post_id, meta_key)`.
            $out[$id] ??= (string) $row['meta_value'];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function childCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT post_parent, COUNT(*) AS total FROM `' . $this->db->prefix() . 'posts`
              WHERE post_type = %s AND post_parent IN (' . $placeholders . ')
              GROUP BY post_parent',
            array_merge(['product_variation'], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post_parent']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * Every marketplace row pointing at each of these products — ALL of them,
     * including a second one, because a duplicate link is a fact the inventory
     * has to be able to report.
     *
     * @param list<int> $ids
     * @return array<int,list<array{product_id:int, vendor_user_id:int, ownership:string, status:string}>>
     */
    private function marketplaceRowsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT id, wc_product_id, vendor_user_id, status, `' . M0010LinkOwnership::COLUMN . '` AS ownership
               FROM `' . $this->db->prefix() . 'tmc_products`
              WHERE wc_product_id IN (' . $placeholders . ')
              ORDER BY id ASC',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['wc_product_id']][] = [
                'product_id' => (int) $row['id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'ownership' => (string) $row['ownership'],
                'status' => (string) $row['status'],
            ];
        }
        return $out;
    }
}
