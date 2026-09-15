<?php
/**
 * Dumps the order-stage evidence from a DISPOSABLE WordPress, reading through
 * the plugin's own services and WooCommerce's own API — never by hand-written
 * SQL, so what the file shows is what the code actually produced.
 *
 * Every command is read-only except `reset` and `project`, and both of those
 * touch ONLY the marketplace's own rows: `reset` deletes marketplace tables
 * and the WooCommerce products that carry a `_tmc_product_id`, and leaves
 * everything else — the shop's own catalogue, Dokan's, the orders — alone.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/order-evidence.php <command> [args…]
 *
 *   trial-matrix          the switch refused in production, honoured on staging
 *   projection            what the storefront holds after an approval
 *   idempotency <wc-id>   three more projections, and a foreign product untouched
 *   recorded-order        the money and the stock behind the browser's order
 *   reset                 remove the marketplace's own rows and products
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;

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

/** One WooCommerce product, as a shopper's side of the story. */
$snapshot = static function (int $wcProductId): array {
    $product = wc_get_product($wcProductId);
    if (!$product) {
        return ['id' => $wcProductId, 'status' => 'missing'];
    }
    return [
        'id' => $wcProductId,
        'status' => $product->get_status(),
        'price' => $product->get_regular_price(),
        'stock' => $product->get_stock_quantity(),
        'sku' => $product->get_sku(),
        'tmc_meta' => (string) get_post_meta($wcProductId, '_tmc_product_id', true),
        'modified' => get_post_field('post_modified', $wcProductId),
    ];
};

switch ($command) {
    case 'trial-matrix':
        $settings = $c->get(SettingsService::class);
        $options = $c->get(OptionStoreInterface::class);
        $before = $settings->load()->environmentOverride;
        $options->set(TrialUnlock::OPTION, true);
        printf(
            "=== trial switch: requested everywhere, honoured only outside production ===\n"
            . "plugin=%s  php=%s  wc=%s\n",
            Bootstrap::pluginVersion(),
            PHP_VERSION,
            defined('WC_VERSION') ? WC_VERSION : '-'
        );
        foreach (['production', 'staging'] as $override) {
            $settings->save($settings->load()->withEnvironmentOverride($override));
            $trial = new TrialUnlock($options, $c->get(EnvironmentResolver::class));
            printf(
                "override=%-10s resolved=%-10s requested=%-5s permitted=%-5s active=%-5s refusal=%s\n",
                $override,
                $trial->environmentName(),
                $trial->isRequested() ? 'true' : 'false',
                $trial->isPermitted() ? 'true' : 'false',
                $trial->isActive() ? 'true' : 'false',
                $trial->refusal() === '' ? '-' : $trial->refusal()
            );
        }
        $settings->save($settings->load()->withEnvironmentOverride($before));
        printf("restored override=%s\n", $before);
        break;

    case 'projection':
        $products = $c->get(ProductRepositoryInterface::class);
        $variations = $c->get(VariationRepositoryInterface::class);
        printf(
            "=== the storefront after projection (%s, PHP %s, WooCommerce %s) ===\n",
            Bootstrap::pluginVersion(),
            PHP_VERSION,
            defined('WC_VERSION') ? WC_VERSION : '-'
        );
        foreach ($products->projected() as $product) {
            $wcProductId = (int) $product->wcProductId;
            $wcProduct = wc_get_product($wcProductId);
            printf(
                "tmc=%d wc=%d type=%s status=%s price=%s stock=%s sku=%s tmc_meta=%s vendor_meta=%s children=%d\n",
                $product->id,
                $wcProductId,
                $product->details->type,
                $wcProduct ? $wcProduct->get_status() : 'missing',
                $wcProduct && $wcProduct->get_regular_price() !== '' ? $wcProduct->get_regular_price() : '-',
                $wcProduct && $wcProduct->get_stock_quantity() !== null ? $wcProduct->get_stock_quantity() : 'NULL',
                $wcProduct ? $wcProduct->get_sku() : '-',
                get_post_meta($wcProductId, '_tmc_product_id', true),
                get_post_meta($wcProductId, '_tmc_vendor_id', true),
                $wcProduct && $wcProduct->is_type('variable') ? count($wcProduct->get_children()) : 0
            );
            if ($product->details->type !== ProductType::VARIABLE) {
                continue;
            }
            echo "--- variations of the variable product ---\n";
            foreach ($variations->variations($product->id) as $variation) {
                $wcVariation = ($variation->wcVariationId ?? 0) > 0 ? wc_get_product($variation->wcVariationId) : null;
                printf(
                    "wc_variation=%s attrs=%s price=%s stock=%s sku=%s status=%s tmc_variation=%d\n",
                    $variation->wcVariationId ?: '-',
                    json_encode($wcVariation ? $wcVariation->get_attributes() : [], JSON_UNESCAPED_UNICODE),
                    $wcVariation ? $wcVariation->get_regular_price() : '-',
                    $wcVariation && $wcVariation->get_stock_quantity() !== null ? $wcVariation->get_stock_quantity() : 'NULL',
                    $wcVariation ? $wcVariation->get_sku() : '-',
                    $wcVariation ? $wcVariation->get_status() : '-',
                    $variation->id
                );
            }
        }
        printf("--- total WooCommerce products on the site ---\nproducts=%d\n", count(wc_get_products(['limit' => -1, 'return' => 'ids'])));
        break;

    case 'idempotency':
        $foreign = (int) ($args[1] ?? 0);
        $products = $c->get(ProductRepositoryInterface::class);
        $catalog = $c->get(SyncCatalog::class);
        $linksOf = static fn () => implode(',', array_map(
            static fn ($p) => (string) $p->wcProductId,
            $products->projected()
        ));
        echo "=== projecting every marketplace product again: no duplicates, and the shop's own product untouched ===\n";
        $snapshotBefore = $snapshot($foreign);
        echo "-- before --\n" . json_encode($snapshotBefore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $before = $linksOf();
        for ($round = 0; $round < 3; $round++) {
            foreach ($products->projected() as $product) {
                $catalog->publish($product->id);
            }
        }
        $after = $linksOf();
        printf(
            "links before=%s after=%s identical=%s\n",
            $before,
            $after,
            $before === $after ? 'true' : 'false'
        );
        printf("products on the site after three projections=%d\n", count(wc_get_products(['limit' => -1, 'return' => 'ids'])));
        $now = $snapshot($foreign);
        echo "-- after --\n" . json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        printf(
            "RESULT: the shop's own product is %s\n",
            $now === json_decode(json_encode($snapshotBefore), true)
                ? 'byte-for-byte unchanged — post_modified included'
                : 'CHANGED, which it must never be'
        );
        break;

    case 'recorded-order':
        $items = $c->get(OrderItemRepositoryInterface::class);
        $ledger = $c->get(LedgerRepositoryInterface::class);
        $products = $c->get(ProductRepositoryInterface::class);
        $orderId = (int) ($args[1] ?? 0);
        printf(
            "=== after the browser order (%s from the ZIP, PHP %s, WooCommerce %s) ===\n",
            Bootstrap::pluginVersion(),
            PHP_VERSION,
            defined('WC_VERSION') ? WC_VERSION : '-'
        );
        echo "-- one line per vendor, with its own snapshot --\n";
        $vendorIds = [];
        foreach ($items->forOrder($orderId) as $item) {
            $vendorIds[$item->vendorUserId] = true;
            printf(
                "vendor=%d order=%d product=%d qty=%d base=%d commission=%s share=%s rate_bp=%s source=%s status=%s ledger=%s\n",
                $item->vendorUserId,
                $item->orderId,
                $item->productId,
                $item->quantity,
                $item->baseMinor,
                $item->commissionMinor === null ? 'UNRECORDED' : $item->commissionMinor,
                $item->vendorShareMinor === null ? 'UNRECORDED' : $item->vendorShareMinor,
                $item->rateBasisPoints === null ? 'UNSET' : $item->rateBasisPoints,
                $item->rateSource === '' ? '-' : $item->rateSource,
                $item->status->value,
                $item->ledgerEvent
            );
        }
        echo "-- the ledger behind it --\n";
        foreach (array_keys($vendorIds) as $vendorId) {
            printf("vendor=%d balances=%s\n", $vendorId, json_encode($ledger->balances($vendorId)));
        }
        echo "-- stock: WooCommerce is the source of truth, the marketplace mirrors it --\n";
        foreach ($products->projected() as $product) {
            $wcProduct = wc_get_product((int) $product->wcProductId);
            $woo = $wcProduct ? $wcProduct->get_stock_quantity() : null;
            printf(
                "tmc=%d wc=%s marketplace_mirror=%d woocommerce=%s agree=%s\n",
                $product->id,
                (string) $product->wcProductId,
                $product->details->stock,
                $woo === null ? 'NULL' : (string) $woo,
                // A variable parent holds no stock of its own: NULL on the
                // storefront and 0 here is agreement, not a discrepancy.
                ($woo === null ? $product->details->stock === 0 : $woo === $product->details->stock) ? 'true' : 'false'
            );
        }
        break;

    case 'reset':
        global $wpdb;
        // Only OUR products: a WooCommerce product without `_tmc_product_id`
        // is somebody else's and is never touched.
        $ours = get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [['key' => '_tmc_product_id', 'compare' => 'EXISTS']],
        ]);
        foreach ($ours as $postId) {
            $children = get_posts(['post_type' => 'product_variation', 'post_parent' => $postId, 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']);
            foreach ($children as $childId) {
                wp_delete_post($childId, true);
            }
            wp_delete_post($postId, true);
        }
        foreach ([
            // The settlement tables go first and are NOT optional: a reset
            // that empties tmc_order_items while leaving withdrawal lines
            // behind leaves reservations pointing at ids the next seed will
            // reuse, and the next run then fails with `reservation_lost` for
            // reasons that have nothing to do with the code.
            'tmc_withdrawal_lines', 'tmc_withdrawals',
            'tmc_order_items', 'tmc_ledger_entries', 'tmc_commission_rules',
            'tmc_product_variations', 'tmc_product_attributes', 'tmc_product_revisions',
            'tmc_product_specs', 'tmc_product_images', 'tmc_products',
            'tmc_spec_fields', 'tmc_spec_templates',
            'tmc_vendor_staff', 'tmc_vendor_stores', 'tmc_vendor_documents',
            'tmc_vendor_change_requests', 'tmc_vendor_profiles', 'tmc_vendor_applications',
        ] as $suffix) {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $suffix);
        }
        printf("removed marketplace products=%d and emptied the marketplace tables\n", count($ours));
        printf("WooCommerce products still on the site=%d\n", count(wc_get_products(['limit' => -1, 'return' => 'ids'])));
        break;

    default:
        echo "unknown command\n";
        break;
}
