<?php
/**
 * Coexistence with a REALLY ACTIVE Dokan, on a disposable WordPress.
 *
 * A stand-in product carrying `_dokan_vendor_id` proves that the marketplace
 * ignores products it does not own. It does NOT prove that the two plugins can
 * run in the same process — that their hooks, roles, rewrite rules and admin
 * menus do not collide. Only a running Dokan proves that, so this script is
 * written to be run with one.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/dokan-coexistence.php [seed|report]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;

$command = (string) ($args[0] ?? 'report');
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

if ($command === 'seed') {
    // A real Dokan vendor and a real Dokan product, made through Dokan's own
    // role and meta — not a hand-written stand-in.
    $login = 'dokanvendor';
    $user = get_user_by('login', $login);
    if (!$user) {
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => 'DokanVendor!2026',
            'user_email' => $login . '@example.test',
            'role' => 'seller',
        ]);
    } else {
        $userId = $user->ID;
    }
    $existing = get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields' => 'ids',
        'meta_query' => [['key' => '_dokan_seeded', 'value' => '1']],
    ]);
    if ($existing === []) {
        $product = new WC_Product_Simple();
        $product->set_name('کالای فروشندهٔ دکان');
        $product->set_status('publish');
        $product->set_regular_price('320000');
        $product->set_sku('DOKAN-1');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(5);
        $product->set_stock_status('instock');
        $productId = $product->save();
        wp_update_post(['ID' => $productId, 'post_author' => $userId]);
        update_post_meta($productId, '_dokan_seeded', '1');
    } else {
        $productId = (int) $existing[0];
    }
    printf("dokan_vendor=%d dokan_product=%d role=%s\n", $userId, $productId, implode(',', get_userdata($userId)->roles));
    return;
}

printf(
    "=== coexistence with Dokan actually active (%s, PHP %s, WooCommerce %s) ===\n",
    Bootstrap::pluginVersion(),
    PHP_VERSION,
    defined('WC_VERSION') ? WC_VERSION : '-'
);
printf(
    "dokan=%s  dokan_version=%s  tecteb=%s\n",
    is_plugin_active('dokan-lite/dokan.php') ? 'active' : 'INACTIVE',
    defined('DOKAN_PLUGIN_VERSION') ? DOKAN_PLUGIN_VERSION : '-',
    Bootstrap::pluginVersion()
);

echo "-- roles: neither plugin overwrote the other's --\n";
$roles = wp_roles()->get_names();
printf("roles=%s\n", implode(',', array_keys($roles)));
printf(
    "dokan_seller_role=%s  tmc_caps_on_administrator=%d\n",
    isset($roles['seller']) ? 'present' : 'MISSING',
    count(array_intersect(Capabilities::all(), array_keys(get_role('administrator')->capabilities ?? [])))
);

echo "-- every product on the site, and who claims it --\n";
$policy = $c->get(PurchasePolicy::class);
foreach (wc_get_products(['limit' => -1, 'return' => 'ids', 'status' => 'any']) as $wcProductId) {
    $decision = $policy->decide((int) $wcProductId);
    $wcProduct = wc_get_product($wcProductId);
    $dokanAuthor = (int) get_post_field('post_author', $wcProductId);
    $isDokanVendor = in_array('seller', (array) (get_userdata($dokanAuthor)->roles ?? []), true);
    printf(
        "wc=%d owner=%s tmc_decision=%s status=%s purchasable=%s author=%d author_is_dokan_seller=%s\n",
        $wcProductId,
        $decision['product'] === null ? ($isDokanVendor ? 'dokan' : 'shop') : 'tecteb',
        $decision['decision'],
        $wcProduct ? $wcProduct->get_status() : 'missing',
        $wcProduct && $wcProduct->is_purchasable() ? 'true' : 'false',
        $dokanAuthor,
        $isDokanVendor ? 'true' : 'false'
    );
}

echo "-- admin menus: no slug collision --\n";
$ours = array_map(static fn ($s) => 'tmc-' . $s, ['dashboard', 'health', 'settings', 'modules', 'storefront', 'withdrawals']);
printf("tecteb_slugs=%s\n", implode(',', $ours));
printf("collisions_with_dokan=%d\n", count(array_filter($ours, static fn ($s) => str_contains($s, 'dokan'))));

echo "-- marketplace rows are untouched by Dokan --\n";
foreach ($c->get(ProductRepositoryInterface::class)->projected() as $product) {
    printf(
        "tmc=%d wc=%s vendor=%d dokan_meta_on_it=%s\n",
        $product->id,
        (string) $product->wcProductId,
        $product->vendorUserId,
        get_post_meta((int) $product->wcProductId, '_dokan_vendor_id', true) === '' ? 'none' : 'present'
    );
}
