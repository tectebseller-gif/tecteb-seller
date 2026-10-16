<?php
/**
 * The four accounts and one product the `alpha.57` browser run needs.
 *
 * §9 asks for «مقصد نهایی دکمهٔ حساب کاربری برای نقش‌های مرتبط», and a
 * destination is a fact about a PERSON: a vendor, a manager who also has a
 * shop, a manager who has none, and an ordinary customer. Three of those four
 * do not exist on a demo site by accident, so they are arranged here and every
 * assertion is made in the browser.
 *
 * **The approved shop goes through the plugin's own path**, not by writing a
 * status: `alpha.17` paid for a fixture that wrote the paperwork directly and
 * left every rebuilt shop «approved and unable to sell», because the
 * operational key is `canSell` and not the status column.
 *
 * ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tools/alpha57-ui-state.php seed
 *   wp eval-file tools/alpha57-ui-state.php report
 *   wp eval-file tools/alpha57-ui-state.php reset
 */

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes users and will not run here.\n");
    echo "refused=1\n";
    echo "reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes users and will not run here.\n");
    echo "refused=1\n";
    echo "reason=home_url_is_not_local\n";
    return;
}

const TMC_U57_PASS = 'demo-a57-2026';
const TMC_U57_VENDOR_LOGIN = 'tmca57vendor';
const TMC_U57_MANAGER_SHOP_LOGIN = 'tmca57mgrshop';
const TMC_U57_MANAGER_BARE_LOGIN = 'tmca57mgrbare';
const TMC_U57_CUSTOMER_LOGIN = 'tmca57buyer';
const TMC_U57_SKU = 'SKU-UI-57';

$command = (string) ($args[0] ?? 'report');
$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$vendors = new DbVendorRepository($db, $clock);
$say = static fn (string $k, string|int $v): int => printf("%s=%s\n", $k, (string) $v);

/** A user by login, made if absent. Named by LOGIN, never by a query that may answer differently tomorrow (`alpha.36`). */
$user = static function (string $login, string $role): int {
    $existing = get_user_by('login', $login);
    if ($existing instanceof \WP_User) {
        return (int) $existing->ID;
    }
    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => TMC_U57_PASS,
        'user_email' => $login . '@example.test',
        'display_name' => $login,
        'role' => $role,
    ]);
    return is_wp_error($id) ? 0 : (int) $id;
};

switch ($command) {
    case 'seed':
        // 1. a plain vendor with a shop that may sell
        $vendorId = $user(TMC_U57_VENDOR_LOGIN, 'customer');
        $vendors->upsertProfile($vendorId, 'فروشگاه آزمون ۵۷', true, false);
        $say('vendor', $vendorId);
        $say('vendor_can_sell', $vendors->findProfileByUser($vendorId)?->canSell ? 'yes' : 'no');

        // 2. a MANAGER who also has a shop — the case the owner reported
        $mgrShop = $user(TMC_U57_MANAGER_SHOP_LOGIN, 'customer');
        $mgrShopUser = new \WP_User($mgrShop);
        foreach ([Capabilities::REVIEW_PRODUCTS, Capabilities::VIEW_DASHBOARD, 'edit_posts'] as $cap) {
            $mgrShopUser->add_cap($cap);
        }
        $vendors->upsertProfile($mgrShop, 'فروشگاه مدیر ۵۷', true, false);
        $say('manager_with_shop', $mgrShop);

        // 3. a manager with NO shop
        $mgrBare = $user(TMC_U57_MANAGER_BARE_LOGIN, 'customer');
        $mgrBareUser = new \WP_User($mgrBare);
        foreach ([Capabilities::REVIEW_PRODUCTS, Capabilities::VIEW_DASHBOARD, 'edit_posts'] as $cap) {
            $mgrBareUser->add_cap($cap);
        }
        $say('manager_without_shop', $mgrBare);

        // 4. an ordinary customer
        $say('customer', $user(TMC_U57_CUSTOMER_LOGIN, 'customer'));

        // …and a WooCommerce product for the commission tab.
        $wcId = (int) wc_get_product_id_by_sku(TMC_U57_SKU);
        if ($wcId <= 0 && class_exists('WC_Product_Simple')) {
            $product = new \WC_Product_Simple();
            $product->set_name('کالای رابط ۵۷');
            $product->set_sku(TMC_U57_SKU);
            $product->set_regular_price('450000');
            $product->set_status('publish');
            $wcId = (int) $product->save();
        }
        $say('wc_product', $wcId);
        $say('pass', TMC_U57_PASS);
        break;

    case 'shop-of':
        // Asked of the repository rather than of a hand-written table name
        // (`alpha.10`), and reported as a single key so the browser run can
        // read it without quoting a namespace through two shells.
        $say('profile', $vendors->findProfileByUser((int) ($args[1] ?? 0)) !== null ? 1 : 0);
        break;

    case 'report':
        foreach ([
            'vendor' => TMC_U57_VENDOR_LOGIN,
            'manager_with_shop' => TMC_U57_MANAGER_SHOP_LOGIN,
            'manager_without_shop' => TMC_U57_MANAGER_BARE_LOGIN,
            'customer' => TMC_U57_CUSTOMER_LOGIN,
        ] as $key => $login) {
            $say($key, (int) (get_user_by('login', $login)?->ID ?? 0));
        }
        $say('wc_product', (int) wc_get_product_id_by_sku(TMC_U57_SKU));
        break;

    case 'reset':
        // Gives back everything it spent (`alpha.33`).
        foreach ([
            TMC_U57_VENDOR_LOGIN,
            TMC_U57_MANAGER_SHOP_LOGIN,
            TMC_U57_MANAGER_BARE_LOGIN,
            TMC_U57_CUSTOMER_LOGIN,
        ] as $login) {
            $u = get_user_by('login', $login);
            if ($u instanceof \WP_User) {
                $db->execute(
                    'DELETE FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_vendor_applications` WHERE user_id = %d',
                    [(int) $u->ID]
                );
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user((int) $u->ID);
            }
        }
        $wcId = (int) wc_get_product_id_by_sku(TMC_U57_SKU);
        if ($wcId > 0) {
            delete_post_meta($wcId, '_tmc_commission_type');
            delete_post_meta($wcId, '_tmc_commission_value');
            wp_delete_post($wcId, true);
        }
        $say('reset', 1);
        break;

    default:
        echo "usage: seed|report|shop-of <user>|reset\n";
}
