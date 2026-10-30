<?php
/**
 * The fixture shared by `alpha.82` and `alpha.83` for this round's upgrade
 * check — on the disposable site only.
 *
 * It exists because the round's whole promise is about a value somebody else
 * wrote, and that cannot be measured from a fresh product: intake copies the
 * shop's own numbers into the marketplace row, so the FIRST projection after
 * it writes nothing new and records what it found. Only from there can «the
 * owner edited this in WooCommerce» be told from «nobody has claimed this
 * value». Every verb below therefore starts from a product that has ALREADY
 * BEEN TAKEN IN — «آزمون هدفمند باید از محصول ازقبل‌واردشده شروع شود».
 *
 * ⛔ It is loaded by BOTH packages, so everything this round added is asked
 * for with `method_exists`/`class_exists` and a verb about an absent
 * capability prints `absent=1` rather than dying (`alpha.32`, `alpha.62`).
 *
 * ⛔ Gives back everything it spends (`alpha.45`): `reset` removes exactly the
 * user, posts, terms and rows it made, and nothing else.
 *
 *   wp eval-file tools/upgrade-alpha82-state.php arrange|fields|digest|shop-edit|project|reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

// Both halves, and the database check FIRST — the exact shape
// `DisposableOnlyToolsTest` measures from these bytes.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    echo "refused=1 reason=not_a_disposable_database\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    echo "refused=1 reason=not_a_local_site\n";
    return;
}

const FIXTURE = 'tmc_a83_upgrade';
const PRICE_MINOR = 200000;
const GRAMS = 12;
const SKU = 'A83-INTAKE';

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = implode(',', $value);
    }
    $value = (string) $value;
    echo $key . '=' . ($value === '' ? '__empty__' : $value) . "\n";
};

$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class ($manager) implements CapabilityCheckerInterface {
    public function __construct(private int $id)
    {
    }

    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return $this->id;
    }
});

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
$command = (string) ($args[0] ?? 'fields');

$state = static function (): array {
    $raw = get_option(FIXTURE, []);
    return (is_array($raw) ? $raw : []) + ['vendor' => 0, 'terms' => [], 'wc' => 0, 'row' => 0];
};

// Read COLD every time: `wc_get_product()` is an instance cache, so reading
// straight after a write reports what we sent rather than what is there
// (`alpha.50`).
$shop = static function (int $wcId): array {
    clean_post_cache($wcId);
    clean_object_term_cache($wcId, 'product_cat');
    $p = wc_get_product($wcId);
    if (!$p) {
        return ['price' => '__absent__', 'sku' => '__absent__', 'weight' => '__absent__', 'terms' => []];
    }
    $terms = wp_get_object_terms($wcId, 'product_cat', ['fields' => 'ids']);
    $terms = is_wp_error($terms) ? [] : array_map('intval', $terms);
    sort($terms);
    return [
        'price' => (string) $p->get_regular_price(),
        'sku' => (string) $p->get_sku(),
        'weight' => (string) $p->get_weight(),
        'terms' => $terms,
    ];
};

switch ($command) {
    case 'arrange':
        $f = $state();

        $existing = get_user_by('login', 'a83vendor');
        if ($existing) {
            $f['vendor'] = (int) $existing->ID;
        }
        if ((int) $f['vendor'] === 0 || !get_userdata((int) $f['vendor'])) {
            $uid = wp_insert_user([
                'user_login' => 'a83vendor',
                'user_pass' => wp_generate_password(20),
                'user_email' => 'a83vendor@example.test',
                'role' => 'customer',
            ]);
            if (is_wp_error($uid)) {
                $say('error', $uid->get_error_message());
                return;
            }
            $f['vendor'] = (int) $uid;
        }
        update_option(FIXTURE, $f);

        // An APPROVED vendor through the documented road, never by writing a
        // status (`alpha.17`).
        /** @var VendorRepositoryInterface $vendors */
        $vendors = $c->get(VendorRepositoryInterface::class);
        if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
            $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
                'فروشگاه ت۸۳',
                'شرکت ت۸۳',
                'a83@example.test',
                '09120000083',
                'تهران، خیابان آزمایش، پلاک ۸۳',
                true
            ));
            $vendors->updateStatus(
                (int) $vendors->findApplicationByUser((int) $f['vendor'])->id,
                ApplicationStatus::Submitted
            );
        }
        $application = $vendors->findApplicationByUser((int) $f['vendor']);
        if ($application !== null && $application->status !== ApplicationStatus::Approved) {
            $c->get(ReviewApplication::class)->approve($application->id);
        }
        $say('vendor', $f['vendor']);

        // A PARENT and its own CHILD, because that is the shape the owner
        // named and the one a single key cannot express. The parent FIRST:
        // a child made before its parent lands at `parent => 0` and the tree
        // is flat (`alpha.81`).
        foreach (['root' => ['ت۸۳ تجهیزات', ''], 'child' => ['ت۸۳ ترالی استیل', 'root']] as $key => [$name, $parent]) {
            $slug = 'a83-' . $key;
            $term = get_term_by('slug', $slug, 'product_cat');
            if (!$term) {
                $made = wp_insert_term($name, 'product_cat', [
                    'slug' => $slug,
                    'parent' => $parent === '' ? 0 : (int) ($f['terms'][$parent] ?? 0),
                ]);
                if (is_wp_error($made)) {
                    $say('term_error_' . $key, $made->get_error_message());
                    continue;
                }
                $f['terms'][$key] = (int) $made['term_id'];
            } else {
                $f['terms'][$key] = (int) $term->term_id;
            }
            update_option(FIXTURE, $f);
        }
        $wantedTerms = array_values(array_filter(array_map('intval', $f['terms'])));
        sort($wantedTerms);
        $say('terms', $wantedTerms);

        if ((int) $f['wc'] === 0 || !get_post((int) $f['wc'])) {
            $wc = new \WC_Product_Simple();
            $wc->set_name('ت۸۳ ترالی دو منظوره');
            $wc->set_status('publish');
            $wc->set_regular_price((string) PRICE_MINOR);
            $wc->set_sku(SKU);
            $wc->set_weight((string) round(GRAMS / 1000, 3));
            $wc->set_manage_stock(true);
            $wc->set_stock_quantity(5);
            $wc->set_category_ids($wantedTerms);
            $f['wc'] = (int) $wc->save();
            update_option(FIXTURE, $f);
        }
        $wcId = (int) $f['wc'];
        $say('wc', $wcId);

        // The marketplace row carries exactly what the shop already holds —
        // which is what intake does, and the reason the first projection
        // below writes nothing new.
        if ((int) $f['row'] === 0 || $products->find((int) $f['row']) === null) {
            $f['row'] = $products->create(
                (int) $f['vendor'],
                new ProductDetails(
                    title: 'ت۸۳ ترالی دو منظوره',
                    categoryKey: (string) ($wantedTerms[0] ?? ''),
                    shortDescription: 'یک جمله',
                    priceMinor: PRICE_MINOR,
                    sku: SKU,
                    stock: 5,
                    weightGrams: GRAMS,
                    categoryIds: $wantedTerms
                ),
                ProductStatus::Published
            );
            $products->link((int) $f['row'], $wcId);
            $products->setLinkOwnership((int) $f['row'], LinkOwnership::Marketplace);
            if (method_exists($products, 'recordCategorySet')) {
                $say('set_recorded', $products->recordCategorySet((int) $f['row'], $wantedTerms));
            } else {
                $say('set_recorded', 'absent');
            }
            update_option(FIXTURE, $f);
        }
        $rowId = (int) $f['row'];
        $say('row', $rowId);

        // The ownership stamps intake and `take` leave behind: without them
        // `owns()` is false and a projection would make a NEW product.
        update_post_meta($wcId, '_tmc_product_id', $rowId);
        update_post_meta($wcId, '_tmc_vendor_id', (int) $f['vendor']);

        // And ONE projection, so the record of «what we last wrote» exists.
        $product = $products->find($rowId);
        $out = $c->get(CatalogProjectorInterface::class)->project($product);
        $say('first_projection_ok', (bool) ($out['ok'] ?? false));
        $say('first_projection_code', (string) ($out['code'] ?? '__absent__'));
        $s = $shop($wcId);
        $say('shop_price', $s['price']);
        $say('shop_sku', $s['sku']);
        $say('shop_weight', $s['weight']);
        $say('shop_terms', $s['terms']);
        break;

    case 'shop-edit':
        // What the owner does: open the product in WooCommerce and change it.
        $f = $state();
        $wcId = (int) $f['wc'];
        $p = wc_get_product($wcId);
        if (!$p) {
            $say('refused', 'no_product');
            break;
        }
        $p->set_regular_price('480000');
        $p->set_sku('A83-OWNER');
        $p->set_weight('0.014');
        $p->save();
        $s = $shop($wcId);
        $say('shop_price', $s['price']);
        $say('shop_sku', $s['sku']);
        $say('shop_weight', $s['weight']);
        break;

    case 'project':
        $f = $state();
        $wcId = (int) $f['wc'];
        $product = $products->find((int) $f['row']);
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $before = $shop($wcId);
        $out = $c->get(CatalogProjectorInterface::class)->project($product);
        $say('project_ok', (bool) ($out['ok'] ?? false));
        $say('project_code', (string) ($out['code'] ?? '__absent__'));
        $after = $shop($wcId);
        foreach (['price', 'sku', 'weight'] as $field) {
            $say($field . '_before', $before[$field]);
            $say($field . '_after', $after[$field]);
            $say($field . '_kept', $before[$field] === $after[$field]);
        }
        $say('terms_before', $before['terms']);
        $say('terms_after', $after['terms']);
        $say('terms_kept', $before['terms'] === $after['terms']);
        break;

    case 'vendor-price':
        // The vendor's OWN price change, through the two calls
        // `ManageProducts::saveAsRevision()` makes in this order: the row
        // first, then the mirror. Doing it this way rather than through the
        // panel keeps the fixture free of a form and a capability, and the
        // honest statement is that it is the SAME two calls — not the panel.
        $f = $state();
        $rowId = (int) $f['row'];
        $wanted = (int) ($args[1] ?? 500000);
        if (!method_exists($products, 'updatePrice')) {
            $say('absent', 1);
            break;
        }
        $say('row_updated', $products->updatePrice($rowId, $wanted, null, null, null));
        $sync = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class);
        if (!method_exists($sync, 'pushPrice')) {
            $say('absent', 1);
            break;
        }
        $out = $sync->pushPrice($rowId);
        $say('push_ok', $out->ok);
        $say('push_code', (string) $out->code);
        $s = $shop((int) $f['wc']);
        $say('shop_price', $s['price']);
        $say('shop_price_is_the_vendors', $s['price'] !== '' && (int) $s['price'] === $wanted);
        break;

    case 'fields':
        $f = $state();
        $wcId = (int) $f['wc'];
        $rowId = (int) $f['row'];
        $s = $shop($wcId);
        $say('wc', $wcId);
        $say('row', $rowId);
        $say('shop_price', $s['price']);
        $say('shop_sku', $s['sku']);
        $say('shop_weight', $s['weight']);
        $say('shop_terms', $s['terms']);
        $row = $products->find($rowId);
        $say('row_price', $row === null ? '__no_row__' : (string) $row->details->priceMinor);
        $say('row_sku', $row === null ? '__no_row__' : $row->details->sku);
        $say('row_weight', $row === null ? '__no_row__' : (string) $row->details->weightGrams);
        $recorded = method_exists($products, 'categoryIdsFor') ? $products->categoryIdsFor($rowId) : null;
        $say('row_set', $recorded === null ? '__unreadable__' : $recorded);
        // This round's report, asked for by name so the OLD package can say
        // it has none rather than dying.
        $cls = 'Tecteb\\Marketplace\\Modules\\Product\\Infrastructure\\WooCommerce\\ProjectedShopFields';
        if (class_exists($cls)) {
            $report = $cls::conflicts($wcId);
            $say('conflicts', array_keys($report));
            foreach ($report as $field => $entry) {
                $say('conflict_' . $field, ($entry['answer'] ?? '') . ':' . ($entry['shop'] ?? '') . '/' . ($entry['ours'] ?? ''));
            }
            $say('record', array_keys($cls::record($wcId)));
        } else {
            $say('conflicts', 'absent');
            $say('record', 'absent');
        }
        break;

    case 'digest':
        $f = $state();
        $s = $shop((int) $f['wc']);
        $row = $products->find((int) $f['row']);
        $recorded = method_exists($products, 'categoryIdsFor') ? $products->categoryIdsFor((int) $f['row']) : null;
        $say('identity', hash('sha256', implode('|', [
            $s['price'], $s['sku'], $s['weight'], implode(',', $s['terms']),
            $row === null ? '-' : $row->details->priceMinor,
            $row === null ? '-' : $row->details->sku,
            $row === null ? '-' : $row->details->weightGrams,
            $recorded === null ? '-' : implode(',', $recorded),
        ])));
        $say('shop_price', $s['price']);
        $say('shop_sku', $s['sku']);
        $say('shop_weight', $s['weight']);
        $say('shop_terms', $s['terms']);
        $say('row_set', $recorded === null ? '__unreadable__' : $recorded);
        break;

    case 'reset':
        $f = $state();
        global $wpdb;
        if ((int) $f['row'] > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tmc_product_categories WHERE product_id = %d",
                (int) $f['row']
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}tmc_products WHERE id = %d",
                (int) $f['row']
            ));
        }
        if ((int) $f['wc'] > 0 && get_post((int) $f['wc'])) {
            wp_delete_post((int) $f['wc'], true);
        }
        foreach ($f['terms'] as $termId) {
            if (get_term((int) $termId, 'product_cat')) {
                wp_delete_term((int) $termId, 'product_cat');
            }
        }
        if ((int) $f['vendor'] > 0 && get_userdata((int) $f['vendor'])) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_applications WHERE user_id = %d", (int) $f['vendor']));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_vendor_profiles WHERE user_id = %d", (int) $f['vendor']));
            wp_delete_user((int) $f['vendor']);
        }
        delete_option(FIXTURE);
        $say('reset', 'done');
        break;

    default:
        $say('usage', 'arrange|fields|digest|shop-edit|project|reset');
}
