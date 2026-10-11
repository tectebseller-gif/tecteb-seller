<?php
/**
 * The site the `alpha.48` browser run needs, built through the plugin's own
 * services.
 *
 * The browser path is the point of §6's «مسیر کامل مرورگری از فروشنده تا مدیر
 * تا ووکامرس، نه فقط فراخوان مستقیم متدها», so this file does the ARRANGING
 * and nothing else: it makes a shop that may sell, a published product that is
 * really projected into WooCommerce, an image so the approval path can pass
 * readiness, and one approved brand so the picker has something in it. Every
 * assertion the run makes is made in the browser or by WP-CLI.
 *
 * ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tools/alpha48-ui-state.php seed
 *   wp eval-file tools/alpha48-ui-state.php report
 *   wp eval-file tools/alpha48-ui-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

// --- refuses to run anywhere but a disposable install ---------------------
//
// BOTH halves, because this file writes product rows, media and taxonomy
// terms. `DisposableOnlyToolsTest` starts from the DIRECTORY rather than from a
// list, so a tool with one half is a failing test rather than a quiet gap
// (`alpha.22`).
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes product rows and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes product rows and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

const TMC_UI_SKU = 'SKU-UI-48';
const TMC_UI_BRAND = 'آریا طب';
const TMC_UI_CATEGORY = 'tmc-ui-48';

$command = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$manager = (int) (get_user_by('login', 'tmcowner')?->ID ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$products = new DbProductRepository($db, $clock);
$vendors = new DbVendorRepository($db, $clock);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
/** @var BrandProposalRepositoryInterface $brandRequests */
$brandRequests = $c->get(BrandProposalRepositoryInterface::class);

$vendorId = (int) (get_user_by('login', 'demo-vendor')?->ID ?? 0);
if ($vendorId <= 0) {
    echo "refused=1 reason=no_demo_vendor\n";
    return;
}

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n"], [''], (string) $value) . "\n";
};

$findBySku = static function () use ($products, $vendorId): int {
    foreach ($products->forVendor($vendorId) as $product) {
        if ($product->details->sku === TMC_UI_SKU) {
            return $product->id;
        }
    }
    return 0;
};

/** A real category term, because the form's picker reads WooCommerce's own. */
$categoryTerm = static function (): int {
    $term = get_term_by('slug', TMC_UI_CATEGORY, 'product_cat');
    if ($term instanceof \WP_Term) {
        return (int) $term->term_id;
    }
    $made = wp_insert_term('دستکش آزمون ۴۸', 'product_cat', ['slug' => TMC_UI_CATEGORY]);
    return is_array($made) ? (int) ($made['term_id'] ?? 0) : 0;
};

$detailsOf = static function (int $categoryTermId, string $brand, int $stock = 20): ProductDetails {
    $named = [
        'title' => 'دستکش آزمون چهل‌وهشت',
        'type' => 'simple',
        'categoryKey' => (string) $categoryTermId,
        'brand' => $brand,
        'shortDescription' => 'یک جمله برای آزمون مرورگر.',
        'priceMinor' => 450000,
        'sku' => TMC_UI_SKU,
        'stock' => $stock,
        'minPurchase' => 1,
        'maxPurchase' => 20,
        'description' => 'متن کامل فروشنده برای آزمون مرورگر.',
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

switch ($command) {
    case 'seed':
        $vendors->upsertProfile($vendorId, 'داروخانهٔ آزمون ۴۸', true, false);
        $say('vendor', $vendorId);
        $term = $categoryTerm();
        $say('category_term', $term);
        // One approved brand, so the picker is not empty. WooCommerce's own
        // taxonomy — there is no second list (§4).
        if (!get_term_by('name', TMC_UI_BRAND, 'product_brand')) {
            wp_insert_term(TMC_UI_BRAND, 'product_brand');
        }
        $brandTerm = get_term_by('name', TMC_UI_BRAND, 'product_brand');
        $say('brand_term', $brandTerm instanceof \WP_Term ? $brandTerm->term_id : 0);

        $id = $findBySku();
        if ($id === 0) {
            $id = $products->create($vendorId, $detailsOf($term, TMC_UI_BRAND), ProductStatus::Published);
        } else {
            $products->updateDetails($id, $detailsOf($term, TMC_UI_BRAND), $products->find($id)?->rowVersion ?? '');
            $products->updateInventory($id, 20, TMC_UI_SKU, 1, 20, 1);
            $products->updateStatus($id, ProductStatus::Published, '');
        }
        $say('product', $id);

        // An image, through the media library, so readiness passes and the
        // approval path can actually finish.
        if (($products->find($id)?->imageIds ?? []) === []) {
            $file = wp_upload_dir()['path'] . '/tmc-ui-48.png';
            if (!file_exists($file)) {
                file_put_contents($file, base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
                ));
            }
            $attachment = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'tmc-ui-48',
                'post_status' => 'inherit',
                'post_author' => $vendorId,
            ], $file);
            if ($attachment > 0) {
                // THE OWNERSHIP STAMP, and the fixture is void without it.
                // `WpProductImages::ownedBy()` asks for BOTH the author and
                // `_tmc_product_image`, and `cleanImages()` drops a media id
                // that fails it — so a gallery seeded without the stamp is
                // silently removed from every save and the form proposes
                // deleting the pictures. Measured: the browser run's first
                // pass made a revision nobody asked for, for exactly this.
                update_post_meta($attachment, '_tmc_product_image', $vendorId);
                $products->saveImages($id, [$attachment], $attachment);
            }
        }
        $say('images', implode(',', $products->find($id)?->imageIds ?? []));

        // Nothing waiting: every verb below measures what the BROWSER does.
        $pending = $revisions->pendingFor($id);
        if ($pending !== null) {
            $revisions->decide($pending->id, ProductRevision::SUPERSEDED, 0, '');
        }
        $openBrand = $brandRequests->openFor($id);
        if ($openBrand !== null) {
            $brandRequests->decide($openBrand->id, BrandProposal::REJECTED, $manager, 'reset', 0, $openBrand->status);
        }
        foreach (['برند مرورگر ۴۸'] as $name) {
            $stale = get_term_by('name', $name, 'product_brand');
            if ($stale instanceof \WP_Term) {
                wp_delete_term($stale->term_id, 'product_brand');
            }
        }
        $say('pending_revision', $revisions->pendingFor($id) === null ? 'none' : 'yes');
        $say('open_brand_request', $brandRequests->openFor($id) === null ? 'none' : 'yes');

        $sync = $catalog->publish($id);
        $say('sync', $sync->code);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $say('wc_product', $wcId);
        $shop = wc_get_product($wcId);
        if ($shop instanceof \WC_Product) {
            $shop->set_manage_stock(true);
            $shop->set_stock_quantity(20);
            $shop->save();
        }
        $say('shop_stock', $shop instanceof \WC_Product ? (int) $shop->get_stock_quantity() : '__unreadable__');
        break;

    case 'reset':
        $id = $findBySku();
        if ($id === 0) {
            $say('reset', 'nothing');
            break;
        }
        $pending = $revisions->pendingFor($id);
        if ($pending !== null) {
            $revisions->decide($pending->id, ProductRevision::SUPERSEDED, 0, '');
        }
        $openBrand = $brandRequests->openFor($id);
        if ($openBrand !== null) {
            $brandRequests->decide($openBrand->id, BrandProposal::REJECTED, $manager, 'reset', 0, $openBrand->status);
        }
        $say('reset', 'done');
        break;

    case 'report':
    default:
        $id = $findBySku();
        if ($id === 0) {
            $say('product', 0);
            break;
        }
        $product = $products->find($id);
        $wcId = (int) ($product?->wcProductId ?? 0);
        $say('product', $id);
        $say('wc_product', $wcId);
        $say('status', (string) ($product?->status->value ?? '?'));
        $say('row_brand', (string) ($product?->details->brand ?? ''));
        $say('row_stock', (string) ($product?->details->stock ?? ''));
        $say('row_limits', ($product?->details->minPurchase ?? '') . '/'
            . ($product?->details->maxPurchase ?? '') . '/'
            . ($product?->details->purchaseStep ?? ''));
        foreach (['_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step'] as $key) {
            $say('shop' . $key, metadata_exists('post', $wcId, $key)
                ? (string) get_post_meta($wcId, $key, true)
                : '__absent__');
        }
        $shop = wc_get_product($wcId);
        $say('shop_stock', $shop instanceof \WC_Product ? (int) $shop->get_stock_quantity() : '__unreadable__');
        $brandOnShop = '__absent__';
        if ($shop instanceof \WC_Product) {
            foreach ($shop->get_attributes() as $attribute) {
                if ($attribute->get_name() === 'برند') {
                    $brandOnShop = implode('|', $attribute->get_options());
                }
            }
        }
        $say('shop_attribute_brand', $brandOnShop);
        $pending = $revisions->pendingFor($id);
        $say('pending_revision', $pending === null ? 'none' : (string) $pending->id);
        $say('proposed_stock', $pending === null
            ? '__none__'
            : (string) ($pending->payload['stock_proposal']['value'] ?? '__absent__'));
        $open = $brandRequests->openFor($id);
        $say('open_brand_request', $open === null ? 'none' : $open->name . ':' . $open->status);
        $say('needs_review', count($products->forManager(null, '', 100, 0, 0, null, true)));
}
