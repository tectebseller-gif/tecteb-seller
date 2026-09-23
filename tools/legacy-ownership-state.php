<?php
/**
 * A product made by the PREVIOUS version, carried across an upgrade.
 *
 * The protection `alpha.26` added was real and it protected only products
 * created after it shipped. Every other product — which is to say every
 * product on a shop that had been running — carries no ownership stamp, and
 * the rule read «no stamp, therefore ours» and wrote straight over whatever
 * the manager had typed into WooCommerce. A test that starts by creating a
 * product cannot see that: the product it creates is stamped.
 *
 * So this is driven by a shell script that installs `alpha.25`, projects with
 * it, edits the product in WooCommerce as a manager would, installs the new
 * package, and only then asks what survived. The commands here are split so
 * that the ones which run under the OLD package use only what the old package
 * had — `project` and `read` touch nothing newer than `SyncCatalog`.
 *
 * Run on a DISPOSABLE WordPress, one command per pass.
 *
 *   wp eval-file tools/legacy-ownership-state.php seed
 *   wp eval-file tools/legacy-ownership-state.php project <product>
 *   wp eval-file tools/legacy-ownership-state.php manager-edit <product>
 *   wp eval-file tools/legacy-ownership-state.php read <product>
 *   wp eval-file tools/legacy-ownership-state.php vendor-save <product>
 *   wp eval-file tools/legacy-ownership-state.php fields <product>
 *   wp eval-file tools/legacy-ownership-state.php settle <product> keep|accept
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES: it creates products, edits titles in WooCommerce and
// moves rows between statuses. Both disposable databases are named because
// the clean-install demo is the second one and this phase's evidence lives
// there; nothing else is accepted.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$args = $args ?? [];
$command = (string) ($args[0] ?? 'read');
$productId = (int) ($args[1] ?? 0);

$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
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

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);

$say = static function (array $fields): void {
    $line = [];
    foreach ($fields as $key => $value) {
        $line[] = $key . '=' . str_replace(' ', '_', (string) $value);
    }
    echo implode(' ', $line) . "\n";
};

/** The arrangement of a storefront product's pictures, as plain text. */
$arrangement = static function (int $wcId): string {
    $wcProduct = wc_get_product($wcId);
    if (!$wcProduct instanceof WC_Product) {
        return 'none';
    }
    return 'main:' . (int) $wcProduct->get_image_id()
        . '|gallery:' . implode(',', array_map('intval', $wcProduct->get_gallery_image_ids()));
};

switch ($command) {
    // ------------------------------------------------------------------ seed
    case 'seed':
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => 3,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (count($ids) < 3) {
            $say(['seeded' => 0, 'reason' => 'need_three_attachments', 'found' => count($ids)]);
            break;
        }
        $vendor = (int) ($products->inStatus(ProductStatus::Published, 1)[0]->vendorUserId ?? 2);
        $category = (string) ($products->inStatus(ProductStatus::Published, 1)[0]->details->categoryKey ?? '');
        $details = new ProductDetails(
            'محصول نسخهٔ قدیمی',
            'simple',
            $category,
            'برند نمونه',
            'توضیح کوتاه اولیهٔ فروشنده',
            1500000,
            null,
            null,
            null,
            'LEGACY-' . gmdate('His'),
            7
        );
        $newId = $products->create($vendor, $details, ProductStatus::Published);
        if ($newId <= 0) {
            $say(['seeded' => 0, 'reason' => 'insert_failed']);
            break;
        }
        $products->saveImages($newId, array_map('intval', $ids), (int) $ids[0]);
        $say([
            'seeded' => 1,
            'product' => $newId,
            'vendor' => $vendor,
            'images' => implode(',', array_map('intval', $ids)),
        ]);
        break;

    // --------------------------------------------------------------- project
    //
    // Runs under WHICHEVER package is installed. Under `alpha.25` this is the
    // call that creates an unstamped WooCommerce product — the thing the rest
    // of the run is about.
    case 'project':
        $result = $catalog->publish($productId);
        $product = $products->find($productId);
        $say([
            'projected' => $result->ok ? 1 : 0,
            'code' => $result->code,
            'wc' => (int) ($product->wcProductId ?? 0),
            'plugin' => defined('TMC_PLUGIN_VERSION') ? TMC_PLUGIN_VERSION : '?',
        ]);
        break;

    // --------------------------------------------------------- manager-edit
    //
    // What a manager does on the WooCommerce product screen, before any
    // upgrade: rewrite the text, promote a gallery picture to featured,
    // reorder the rest, and fill the SEO box.
    case 'manager-edit':
        $product = $products->find($productId);
        $wcId = (int) ($product->wcProductId ?? 0);
        $wcProduct = $wcId > 0 ? wc_get_product($wcId) : null;
        if (!$wcProduct instanceof WC_Product) {
            $say(['edited' => 0, 'reason' => 'not_projected']);
            break;
        }
        $gallery = array_map('intval', $wcProduct->get_gallery_image_ids());
        $wasMain = (int) $wcProduct->get_image_id();
        $wcProduct->set_name('عنوانی که مدیر در ووکامرس نوشته');
        $wcProduct->set_short_description('توضیح کوتاهی که مدیر در ووکامرس نوشته');
        $wcProduct->set_description('متن کاملی که مدیر در ووکامرس نوشته');
        if ($gallery !== []) {
            // Promote the LAST gallery picture to featured and reverse the
            // rest. Both are invisible to a sorted id list, which is the
            // whole point of choosing them.
            $promoted = array_pop($gallery);
            $wcProduct->set_image_id($promoted);
            $wcProduct->set_gallery_image_ids(array_reverse(array_merge($gallery, [$wasMain])));
        }
        $wcProduct->save();
        // Rank Math is not installed in this environment, so what is written
        // here are the meta keys it uses — the question being asked is «does
        // a projection wipe post meta it did not write», and that is answered
        // by any key it does not own.
        update_post_meta($wcId, 'rank_math_title', 'عنوان سئوی نوشتهٔ مدیر');
        update_post_meta($wcId, 'rank_math_description', 'توضیح سئوی نوشتهٔ مدیر');
        update_post_meta($wcId, 'rank_math_focus_keyword', 'تجهیزات پزشکی');
        $say(['edited' => 1, 'wc' => $wcId, 'images' => $arrangement($wcId)]);
        break;

    // ------------------------------------------------------------------ read
    case 'read':
        $product = $products->find($productId);
        $wcId = (int) ($product->wcProductId ?? 0);
        $wcProduct = $wcId > 0 ? wc_get_product($wcId) : null;
        if (!$wcProduct instanceof WC_Product) {
            $say(['read' => 0, 'reason' => 'not_projected']);
            break;
        }
        $say([
            'read' => 1,
            'wc' => $wcId,
            'title' => $wcProduct->get_name(),
            'short' => $wcProduct->get_short_description(),
            'long' => $wcProduct->get_description(),
            'images' => $arrangement($wcId),
            'seo_title' => (string) get_post_meta($wcId, 'rank_math_title', true),
            'seo_desc' => (string) get_post_meta($wcId, 'rank_math_description', true),
            'seo_keyword' => (string) get_post_meta($wcId, 'rank_math_focus_keyword', true),
            'record_title' => $product->details->title,
            'record_main' => $product->mainImageId,
            'record_images' => implode(',', array_map('intval', $product->imageIds)),
        ]);
        break;

    // ----------------------------------------------------------- vendor-save
    //
    // The vendor edits their product and it is synced — the exact sequence
    // that used to destroy everything above.
    case 'vendor-save':
        $product = $products->find($productId);
        $stamp = gmdate('His');
        $details = new ProductDetails(
            'عنوان تازهٔ فروشنده ' . $stamp,
            $product->details->type,
            $product->details->categoryKey,
            $product->details->brand,
            'توضیح کوتاه تازهٔ فروشنده ' . $stamp,
            $product->details->priceMinor,
            $product->details->salePriceMinor,
            $product->details->saleFrom,
            $product->details->saleTo,
            $product->details->sku,
            $product->details->stock,
            $product->details->minPurchase,
            $product->details->maxPurchase,
            $product->details->weightGrams,
            $product->details->dimensions,
            $product->details->taxClass
        );
        $saved = $products->updateDetails($productId, $details, $product->rowVersion);
        $result = $catalog->publish($productId);
        $say([
            'saved' => $saved ? 1 : 0,
            'synced' => $result->ok ? 1 : 0,
            'vendor_title' => $details->title,
            'vendor_short' => $details->shortDescription,
        ]);
        break;

    // ---------------------------------------------------------------- fields
    case 'fields':
        if (!interface_exists(\Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface::class)) {
            $say(['fields' => 0, 'reason' => 'package_has_no_ownership_reader']);
            break;
        }
        $storefront = $c->get(\Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface::class);
        $product = $products->find($productId);
        if ($storefront === null || $product === null) {
            $say(['fields' => 0, 'reason' => 'unavailable']);
            break;
        }
        $out = ['fields' => 1];
        foreach ($storefront->compare($product) as $field) {
            $out[$field->key] = $field->owner . ($field->needsDecision() ? '/asks' : '/settled');
        }
        $say($out);
        break;

    // ---------------------------------------------------------------- settle
    case 'settle':
        $decision = (string) ($args[2] ?? 'keep');
        $review = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ReviewProducts::class);
        if (!method_exists($review, 'resolveProduct')) {
            $say(['settled' => 0, 'reason' => 'package_has_no_bulk_resolve']);
            break;
        }
        $result = $review->resolveProduct($productId, $decision);
        $say([
            'settled' => $result->ok ? 1 : 0,
            'code' => $result->code,
            'count' => $result->context['settled'] ?? 0,
        ]);
        break;

    default:
        $say(['usage' => 'seed|project|manager-edit|read|vendor-save|fields|settle']);
}
