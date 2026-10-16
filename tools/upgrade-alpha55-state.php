<?php
/**
 * The product an intake must not damage, written by whichever build is installed.
 *
 * `alpha.55 → alpha.56` has **no migration**: `SchemaVersion::TARGET` is 28 on
 * both trees and no file under `src/**\/Migrations/` moved. So the thing the
 * upgrade check has to prove is not that a column arrived — it is that
 * replacing the files costs NOTHING: the schema stays 28, the row an intake
 * created stays exactly as it was, and the WooCommerce product it points at is
 * untouched down to its id, address, status, author, price, stock, images,
 * variations and its link to an earlier order.
 *
 * Everything here runs on BOTH builds (`alpha.32`'s rule, which broke the
 * previous round's probe on the very bytes it existed to measure):
 *
 *  - the arrangement is pure WordPress and WooCommerce — no plugin class at
 *    all, so it cannot name something `alpha.55` lacks;
 *  - `ImportExistingProducts` exists on both, and only `newRunId()`,
 *    `importBatch($run, $ids, $assignTo)` and `rollback($run)` are called,
 *    which have the same arity on both. `rememberRun()` gained a third
 *    optional parameter this round and is NOT called here;
 *  - the marketplace row is READ with a plain statement, so a column this
 *    round did not add cannot make the read fail.
 *
 * ONE key per line — the shell's reader takes the rest of the line as the
 * value (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha55-state.php arrange
 *   wp eval-file tools/upgrade-alpha55-state.php digest
 *   wp eval-file tools/upgrade-alpha55-state.php intake
 *   wp eval-file tools/upgrade-alpha55-state.php row
 *   wp eval-file tools/upgrade-alpha55-state.php shopmove <price>
 *   wp eval-file tools/upgrade-alpha55-state.php rollback <run>
 *   wp eval-file tools/upgrade-alpha55-state.php reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES products, variations and an order. On the owner's site that
// is not a fixture — and §11 of the brief forbids it in words.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}
if (!class_exists('WC_Product_Variable')) {
    echo "refused=1 reason=woocommerce_absent\n";
    return;
}

const TMC_U55_SKU = 'WC-U55-UPGRADE';
const TMC_U55_SKU_SIMPLE = 'WC-U55-SIMPLE';
const TMC_U55_VENDOR = 9556;
const TMC_U55_RUN = 'upgrade-a55';

$verb = (string) ($args[0] ?? 'report');
$arg1 = (string) ($args[1] ?? '');
$db = new WpDatabase($GLOBALS['wpdb']);
$table = $GLOBALS['wpdb']->prefix . 'tmc_products';

/** An attachment that is a real row with real bytes, so a thumbnail id means something. */
$attachment = static function (string $name): int {
    $existing = get_posts([
        'post_type' => 'attachment',
        'name' => $name,
        'numberposts' => 1,
        'fields' => 'ids',
        'post_status' => 'inherit',
    ]);
    if ($existing !== []) {
        return (int) $existing[0];
    }
    $upload = wp_upload_bits($name . '.png', null, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
    ));
    if (!empty($upload['error'])) {
        return 0;
    }
    $id = wp_insert_attachment([
        'post_title' => $name,
        'post_name' => $name,
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
    ], $upload['file']);
    return (int) $id;
};

/** The product id the fixture works on, found by SKU so the verbs are separate requests. */
$findProduct = static function (string $sku = TMC_U55_SKU): int {
    $id = (int) wc_get_product_id_by_sku($sku);
    return $id > 0 ? $id : 0;
};

$marketplaceRow = static function (int $wcId) use ($db, $table): ?array {
    if ($wcId === 0) {
        return null;
    }
    $rows = $db->getResults("SELECT * FROM `$table` WHERE wc_product_id = %d ORDER BY id", [$wcId]);
    return $rows === [] || $rows === null ? null : (array) $rows[0];
};

$value = static fn (mixed $v): string => $v === null ? '__none__' : ($v === '' ? '__empty__' : (string) $v);

switch ($verb) {
    case 'arrange':
        $wcId = $findProduct();
        if ($wcId === 0) {
            $thumb = $attachment('u55-main');
            $gallery = $attachment('u55-gallery');
            $parent = new WC_Product_Variable();
            $parent->set_name('محصول سندِ ارتقا ۵۵');
            $parent->set_sku(TMC_U55_SKU);
            $parent->set_status('publish');
            $parent->set_regular_price('450000');
            $parent->set_manage_stock(true);
            $parent->set_stock_quantity(30);
            // A Latin attribute name on purpose: `sanitize_title()` percent-encodes
            // Persian, and `WC_Cart::add_to_cart()` wants `attribute_` + the
            // sanitised name (`class-wc-cart.php:1243`). The NAME is not what this
            // fixture is about, and the reason is written down rather than guessed.
            $attribute = new WC_Product_Attribute();
            $attribute->set_name('Size');
            $attribute->set_options(['S', 'L']);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $parent->set_attributes(['size' => $attribute]);
            $parent->save();
            $wcId = (int) $parent->get_id();
            if ($thumb > 0) {
                set_post_thumbnail($wcId, $thumb);
            }
            if ($gallery > 0) {
                update_post_meta($wcId, '_product_image_gallery', (string) $gallery);
            }
            foreach (['S' => '450000', 'L' => '490000'] as $option => $price) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($wcId);
                $variation->set_attributes(['size' => $option]);
                $variation->set_regular_price($price);
                $variation->set_status('publish');
                $variation->save();
            }
            // An EARLIER order, the way the owner's site has them: the link that
            // must still be there after the upgrade is this item's `product_id`.
            $order = wc_create_order(['status' => 'completed']);
            $order->add_product(wc_get_product($wcId), 2);
            $order->calculate_totals();
            $order->save();
            update_post_meta($wcId, '_tmc_u55_order', (string) $order->get_id());
        }
        // A SIMPLE product beside the variable one, and the reason is a
        // measurement rather than a preference: a variable parent's price is
        // not where its price lives, so the reader answers `0` for it both
        // before and after a parent-price edit and the freshness guard has
        // nothing to compare. The guard is therefore measured on the product
        // type it covers, and the gap on a variable parent is NAMED in the
        // delivery document rather than hidden behind a green check.
        $simpleId = $findProduct(TMC_U55_SKU_SIMPLE);
        if ($simpleId === 0) {
            $simple = new WC_Product_Simple();
            $simple->set_name('کالای سادهٔ سندِ ارتقا ۵۵');
            $simple->set_sku(TMC_U55_SKU_SIMPLE);
            $simple->set_status('publish');
            $simple->set_catalog_visibility('visible');
            $simple->set_short_description('یک جمله');
            $simple->set_regular_price('450000');
            $simple->set_manage_stock(true);
            $simple->set_stock_quantity(12);
            $simpleId = (int) $simple->save();
        }

        // The vendor the row is assigned to must be a REAL vendor, or the
        // intake answers `assigned_vendor_is_not_a_vendor` — correctly — and
        // every later check would be about a refusal rather than an intake.
        // `upsertProfile()` has the same four parameters on both builds.
        (new DbVendorRepository($db, new SystemClock()))
            ->upsertProfile(TMC_U55_VENDOR, 'فروشگاه سندِ ارتقا ۵۵', true, false);
        // An explicit author, so «the author was preserved» is a statement
        // about a real user id rather than about zero.
        $adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
        $db->execute(
            'UPDATE `' . $GLOBALS['wpdb']->prefix . 'posts` SET post_author = %d WHERE ID = %d AND post_author <> %d',
            [$adminId, $wcId, $adminId]
        );
        clean_post_cache($wcId);
        $product = wc_get_product($wcId);
        echo 'wc=' . $wcId . "\n";
        echo 'variations=' . count($product->get_children()) . "\n";
        echo 'wc_simple=' . $simpleId . "\n";
        echo 'author=' . (int) get_post_field('post_author', $wcId) . "\n";
        echo 'vendor=' . TMC_U55_VENDOR . "\n";
        echo 'order=' . $value(get_post_meta($wcId, '_tmc_u55_order', true)) . "\n";
        break;

    case 'digest':
        // Everything §9 names, read from WooCommerce and WordPress rather than
        // from anything this plugin wrote: «حفظ شناسه/نشانی/وضعیت/نویسنده/قیمت/
        // موجودی/تصویرها/تنوع‌ها و پیوند سفارش‌های قبلی».
        $wcId = $findProduct();
        if ($wcId === 0) {
            echo "wc=0 absent=1\n";
            break;
        }
        $post = get_post($wcId);
        $product = wc_get_product($wcId);
        $children = $product->get_children();
        sort($children);
        $orderId = (int) get_post_meta($wcId, '_tmc_u55_order', true);
        $linked = 0;
        if ($orderId > 0 && ($order = wc_get_order($orderId))) {
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_product_id() === $wcId) {
                    $linked++;
                }
            }
        }
        $fields = [
            'wc' => $wcId,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'author' => (int) $post->post_author,
            'price' => (string) get_post_meta($wcId, '_price', true),
            'stock' => (string) get_post_meta($wcId, '_stock', true),
            'thumb' => (int) get_post_thumbnail_id($wcId),
            'gallery' => (string) get_post_meta($wcId, '_product_image_gallery', true),
            'variations' => implode(',', array_map('intval', $children)),
            'order' => $orderId,
            'order_items_linked' => $linked,
        ];
        foreach ($fields as $key => $v) {
            echo $key . '=' . $value($v) . "\n";
        }
        echo 'identity=' . substr(hash('sha256', implode('|', $fields)), 0, 16) . "\n";
        break;

    case 'intake':
        $wcId = $findProduct();
        if ($wcId === 0) {
            echo "wc=0 absent=1\n";
            break;
        }
        $c = Bootstrap::container();
        $adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
        wp_set_current_user($adminId);
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
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $run = TMC_U55_RUN . '-' . substr((string) time(), -6);
        $ids = [$wcId];
        $simpleId = $findProduct(TMC_U55_SKU_SIMPLE);
        if ($simpleId > 0) {
            $ids[] = $simpleId;
        }
        $batch = $intake->importBatch($run, $ids, TMC_U55_VENDOR);
        $first = $batch['results'][0] ?? [];
        echo 'run=' . $run . "\n";
        echo 'batch=' . $value($batch['code'] ?? null) . "\n";
        echo 'verdict=' . $value($first['verdict'] ?? null) . "\n";
        echo 'reason=' . $value($first['reason'] ?? null) . "\n";
        echo 'product=' . (int) ($first['product_id'] ?? 0) . "\n";
        echo 'imported=' . (int) ($batch['counts']['imported'] ?? 0) . "\n";
        break;

    case 'accept': {
        // §2-ج in the upgrade path itself: accepting management must not
        // overwrite a shop-side change nobody saw.
        //
        // `take()` is called with TWO arguments, which are legal on BOTH
        // builds, so each build answers the SAME question: `alpha.55` takes
        // ownership and the next sync overwrites the newer price, while this
        // build refuses and names the field. The third parameter — «what the
        // page showed» — is reported present or absent by reflection
        // (`alpha.38`), and only the build that has it is asked to confirm.
        $wcId = $findProduct(TMC_U55_SKU_SIMPLE);
        $row = $marketplaceRow($wcId);
        if ($row === null) {
            echo "row=__none__\n";
            break;
        }
        echo 'wc=' . $wcId . "\n";
        $productId = (int) $row['id'];
        $arity = (new ReflectionMethod(TransferOwnership::class, 'take'))->getNumberOfParameters();
        echo 'take_arity=' . $arity . "\n";
        echo 'seen_shop_parameter=' . ($arity >= 3 ? 'present' : 'absent') . "\n";
        $c = Bootstrap::container();
        $adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
        wp_set_current_user($adminId);
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
        /** @var TransferOwnership $transfer */
        $transfer = $c->get(TransferOwnership::class);
        // A difference the manager would see on the page, then a shop-side
        // change AFTER it was drawn. Two arguments are legal on BOTH builds,
        // so this is the same call on each: `alpha.55` takes ownership and the
        // next sync overwrites the newer price; this build refuses and names
        // the field.
        $product = wc_get_product($wcId);
        $product->set_regular_price('470000');
        $product->set_price('470000');
        $product->save();
        wp_cache_delete((string) $wcId, 'post_meta');
        echo 'shop_price_now=' . $value(get_post_meta($wcId, '_regular_price', true)) . "\n";

        $risks = array_keys($transfer->risksFor($productId));
        echo 'risks=' . ($risks === [] ? '__none__' : implode(',', $risks)) . "\n";
        $blind = $transfer->take($productId, $risks);
        echo 'stale_code=' . $value($blind->code) . "\n";
        // Two refusals, two keys: `storefront_moved` names every field whose
        // value the form did not carry, `storefront_moved_again` names the one
        // field whose value moved after the page was drawn.
        echo 'stale_field=' . $value($blind->context['field'] ?? null) . "\n";
        echo 'stale_fields=' . $value($blind->context['fields'] ?? null) . "\n";
        echo 'ownership_after_refusal=' . $transfer->currentOwnership($productId)->value . "\n";

        if ($arity < 3 || !method_exists(TransferOwnership::class, 'refreshFromStorefront')) {
            // `alpha.55`: there is no «what the page showed» to carry, so the
            // only thing left to measure is what the next sync does to the
            // newer price.
            $synced = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class)->publish($productId);
            wp_cache_delete((string) $wcId, 'post_meta');
            echo 'sync_code=' . $value($synced->code) . "\n";
            echo 'shop_price_after_sync=' . $value(get_post_meta($wcId, '_regular_price', true)) . "\n";
            break;
        }
        $refresh = $transfer->refreshFromStorefront($productId);
        echo 'refresh_code=' . $value($refresh->code) . "\n";
        $seen = [];
        foreach ($transfer->storefrontDrift($productId) as $field => $pair) {
            $seen[$field] = (string) ($pair['fingerprint'] ?? '');
        }
        echo 'fields_shown=' . ($seen === [] ? '__none__' : implode(',', array_keys($seen))) . "\n";
        $taken = $transfer->take($productId, array_keys($transfer->risksFor($productId)), $seen);
        echo 'fresh_code=' . $value($taken->code) . "\n";
        echo 'ownership_after=' . $transfer->currentOwnership($productId)->value . "\n";
        break;
    }

    case 'row':
        $row = $marketplaceRow($findProduct());
        if ($row === null) {
            echo "row=__none__\n";
            break;
        }
        foreach (['id', 'vendor_user_id', 'title', 'price_minor', 'stock', 'status',
                  'link_ownership', 'wc_product_id', 'import_run_id', 'row_version'] as $column) {
            echo $column . '=' . $value($row[$column] ?? null) . "\n";
        }
        echo 'baseline=' . ($value($row['approved_baseline'] ?? null) === '__none__' ? 'none' : 'recorded') . "\n";
        $simpleRow = $marketplaceRow($findProduct(TMC_U55_SKU_SIMPLE));
        echo 'simple_row=' . ($simpleRow === null ? '__none__' : (string) $simpleRow['id']) . "\n";
        echo 'simple_price=' . ($simpleRow === null ? '__none__' : $value($simpleRow['price_minor'])) . "\n";
        echo 'simple_ownership=' . ($simpleRow === null ? '__none__' : $value($simpleRow['link_ownership'])) . "\n";
        break;

    case 'shopmove':
        // A second writer AFTER the intake, on the shop side — the change §2-ج
        // says must never be overwritten unseen.
        $wcId = $findProduct();
        $product = $wcId > 0 ? wc_get_product($wcId) : null;
        if ($product === null) {
            echo "wc=0 absent=1\n";
            break;
        }
        $product->set_regular_price($arg1 === '' ? '470000' : $arg1);
        $product->save();
        echo 'wc=' . $wcId . "\n";
        echo 'price=' . $value(get_post_meta($wcId, '_price', true)) . "\n";
        break;

    case 'rollback':
        $c = Bootstrap::container();
        $adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
        wp_set_current_user($adminId);
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
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $result = $intake->rollback($arg1);
        echo 'code=' . $value($result->code) . "\n";
        echo 'row_after=' . ($marketplaceRow($findProduct()) === null ? '__none__' : 'present') . "\n";
        break;

    case 'reset':
        // Gives back only what the MARKETPLACE side spent. The WooCommerce
        // product, its variations, its media and the earlier order stay — they
        // are the thing being preserved, and a fixture that deleted them would
        // measure itself (`alpha.33`).
        $wcId = $findProduct();
        foreach ([$findProduct(TMC_U55_SKU_SIMPLE)] as $extra) {
            if ($extra > 0) {
                $db->execute("DELETE FROM `$table` WHERE wc_product_id = %d", [$extra]);
                foreach (['_tmc_product_id', '_tmc_vendor_id', '_tmc_claimed_from_status',
                          '_tmc_projected_title', '_tmc_projected_short_description',
                          '_tmc_projected_description', '_tmc_projected_slug'] as $stamp) {
                    delete_post_meta($extra, $stamp);
                }
                $simple = wc_get_product($extra);
                if ($simple !== null && $simple !== false) {
                    $simple->set_regular_price('450000');
                    $simple->set_price('450000');
                    $simple->set_stock_quantity(12);
                    $simple->set_status('publish');
                    $simple->save();
                }
            }
        }
        if ($wcId > 0) {
            $db->execute("DELETE FROM `$table` WHERE wc_product_id = %d", [$wcId]);
            // Everything the marketplace may have stamped on the post. Leaving
            // `_tmc_product_id` behind made the NEXT run's claim refuse with
            // `storefront_refused` — a correct answer («another row already
            // owns this post») about a build that was working, which is the
            // `alpha.33` rule: a fixture that spends something gives it back.
            foreach (['_tmc_product_id', '_tmc_vendor_id', '_tmc_claimed_from_status',
                      '_tmc_projected_title', '_tmc_projected_short_description',
                      '_tmc_projected_description', '_tmc_projected_slug'] as $stamp) {
                delete_post_meta($wcId, $stamp);
            }
            $product = wc_get_product($wcId);
            if ($product !== null && $product !== false) {
                $product->set_regular_price('450000');
                $product->set_stock_quantity(30);
                $product->save();
            }
        }
        echo 'reset=1' . "\n";
        echo 'wc=' . $wcId . "\n";
        echo 'row_after=' . ($marketplaceRow($wcId) === null ? '__none__' : 'present') . "\n";
        break;

    default:
        echo "usage: arrange|digest|intake|row|shopmove|rollback|reset\n";
}
