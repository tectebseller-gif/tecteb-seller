<?php
/**
 * The phase-7 services, driven from the command line on a DISPOSABLE
 * WordPress — and, for the two that reach a real basket, a way to ask what a
 * cart would actually charge.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/marketplace-state.php seed <wc-product-id>
 *   wp eval-file tools/marketplace-state.php tiers <tmc-product-id>
 *   wp eval-file tools/marketplace-state.php wholesale-buyer [login]
 *   wp eval-file tools/marketplace-state.php fresh-buyer [login]
 *   wp eval-file tools/marketplace-state.php wholesale-account <user>
 *   wp eval-file tools/marketplace-state.php wholesale-status <user> <status>
 *   wp eval-file tools/marketplace-state.php price-for <user> <tmc-product> <qty>
 *   wp eval-file tools/marketplace-state.php cart-price <user> <wc-product> <qty>
 *   wp eval-file tools/marketplace-state.php coupon-uses <code>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Marketplace\Application\EngagementRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0009EngagementTables;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

// --- refuses to run anywhere but the disposable install -------------------
//
// This file WRITES test data — shops, products, orders, refunds. On the
// owner's site that is not a seeding tool, it is damage. A docblock saying
// «DISPOSABLE» is documentation, not a guard: it stops nobody who pastes the
// command at the wrong shell.
//
// Two independent facts, the same pair tools/disposable-site.sh already
// trusts: the database must be the disposable one by name, and the site must
// be on a host nobody outside the container can reach. Deliberately NOT
// wp_get_environment_type(), which reports `production` on the disposable
// container itself because nobody set the constant.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes test data and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


$command = (string) ($args[0] ?? '');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
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
    };
});

$products = $c->get(ProductRepositoryInterface::class);
$coupons = $c->get(ManageCoupons::class);
$wholesale = $c->get(ManageWholesale::class);
$repository = $c->get(EngagementRepositoryInterface::class);

switch ($command) {
    case 'seed':
        // One vendor coupon and one price ladder for a real marketplace
        // product, made through the services a vendor's own screen uses.
        $wcProductId = (int) ($args[1] ?? 0);
        $product = $products->findByWcProduct($wcProductId);
        if ($product === null) {
            echo "not a marketplace product\n";
            break;
        }
        $code = 'TMCCART' . strtoupper(substr(md5((string) time()), 0, 5));
        $created = $coupons->create(
            $product->vendorUserId,
            $product->vendorUserId,
            $code,
            Coupon::PERCENT,
            2000        // twenty percent, in basis points
        );
        $retail = $product->details->priceMinor;
        $tiers = $wholesale->setTiers($product->vendorUserId, $product->vendorUserId, $product->id, [
            5 => (int) round($retail * 0.8),
            20 => (int) round($retail * 0.6),
        ]);
        printf(
            "seed product=%d vendor=%d code=%s coupon_ok=%s tiers_ok=%s retail=%d\n",
            $product->id,
            $product->vendorUserId,
            $code,
            $created->ok ? 'true' : 'false',
            $tiers->ok ? 'true' : 'false',
            $retail
        );
        break;

    case 'tiers':
        $productId = (int) ($args[1] ?? 0);
        $steps = [];
        foreach ($wholesale->tiersFor($productId) as $tier) {
            $steps[] = $tier->minQuantity . ':' . $tier->unitPriceMinor;
        }
        printf("tiers product=%d steps=%s\n", $productId, $steps === [] ? '-' : implode(',', $steps));
        break;

    case 'wholesale-buyer':
        $login = (string) ($args[1] ?? 'tmcwholesale');
        $user = get_user_by('login', $login);
        if (!$user) {
            $user = get_user_by('id', wp_insert_user([
                'user_login' => $login,
                'user_pass' => 'TmcWholesale!2026',
                'user_email' => $login . '@example.test',
                'role' => 'customer',
            ]));
        }
        $wholesale->apply((int) $user->ID, 'شرکت عمده آزمایشی', '99887766');
        printf("user=%d login=%s\n", (int) $user->ID, $login);
        break;

    case 'fresh-buyer':
        // A customer who has NEVER asked, so the application form is what the
        // «حساب من» page shows. The row is removed rather than the user, so
        // the login and its password stay the same between runs.
        $login = (string) ($args[1] ?? 'tmcfreshbuyer');
        $user = get_user_by('login', $login);
        if (!$user) {
            $user = get_user_by('id', wp_insert_user([
                'user_login' => $login,
                'user_pass' => 'TmcWholesale!2026',
                'user_email' => $login . '@example.test',
                'role' => 'customer',
            ]));
        }
        // The table's name comes from the migration that made it. Writing it
        // out by hand here deleted nothing at all, silently, and the check
        // that noticed only noticed because it asked the state afterwards.
        global $wpdb;
        $table = $wpdb->prefix . M0009EngagementTables::B2B_ACCOUNTS;
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE user_id = %d", (int) $user->ID));
        printf("user=%d login=%s account=%s\n", (int) $user->ID, $login,
            $wholesale->accountFor((int) $user->ID) === null ? 'none' : 'still-there');
        break;

    case 'wholesale-account':
        $account = $wholesale->accountFor((int) ($args[1] ?? 0));
        printf(
            "account user=%s status=%s company=%s\n",
            (string) ($args[1] ?? ''),
            $account === null ? 'none' : $account->status->value,
            $account === null ? '-' : $account->company
        );
        break;

    case 'wholesale-status':
        $status = WholesaleStatus::tryFrom((string) ($args[2] ?? ''));
        if ($status === null) {
            echo "unknown status\n";
            break;
        }
        $result = $wholesale->decide((int) ($args[1] ?? 0), $status);
        printf("status user=%s to=%s ok=%s\n", (string) ($args[1] ?? ''), $status->value, $result->ok ? 'true' : 'false');
        break;

    case 'price-for':
        $price = $wholesale->priceFor((int) ($args[1] ?? 0), (int) ($args[2] ?? 0), (int) ($args[3] ?? 1));
        printf("price=%s\n", $price === null ? '-' : (string) $price);
        break;

    case 'cart-price':
        // What a REAL cart charges: the cart is built, the hooks run, and the
        // per-unit price WooCommerce settled on is reported. This is the
        // difference between "the service returns a number" and "a shopper
        // pays it".
        $userId = (int) ($args[1] ?? 0);
        $wcProductId = (int) ($args[2] ?? 0);
        $quantity = max(1, (int) ($args[3] ?? 1));
        wp_set_current_user($userId);
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($wcProductId, $quantity);
        WC()->cart->calculate_totals();
        $unit = 0;
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) $item['data']->get_id() === $wcProductId) {
                $unit = (int) round((float) $item['data']->get_price());
            }
        }
        $retail = (int) round((float) wc_get_product($wcProductId)->get_regular_price());
        printf("unit=%d retail=%d quantity=%d subtotal=%d\n", $unit, $retail, $quantity, (int) round((float) WC()->cart->get_subtotal()));
        WC()->cart->empty_cart();
        wp_set_current_user($manager);
        break;

    case 'coupon-uses':
        $coupon = $repository->findCouponByCode(strtoupper((string) ($args[1] ?? '')));
        printf(
            "coupon=%s uses=%d\n",
            $coupon === null ? '-' : (string) $coupon->id,
            $coupon === null ? 0 : $repository->couponUseCount($coupon->id)
        );
        break;

    default:
        echo "usage: seed | tiers | wholesale-buyer | wholesale-status | price-for | cart-price | coupon-uses\n";
        break;
}
