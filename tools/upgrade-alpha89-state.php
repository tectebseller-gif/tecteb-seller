<?php
/**
 * The state verbs the `alpha.89 → alpha.90` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so anything this round
 * adds is reached through `class_exists()`/`isKnown()` and a verb about
 * something the older build does not have answers with that ABSENCE — which
 * is the measurement, not a failure.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha89-state.php <verb>
 *
 * Verbs: arrange · rows · take · give-back · audit · products · reset
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
// Both halves, and `DisposableOnlyToolsTest` is what says so (`alpha.22`).
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_U90_VENDOR = 9901;
const TMC_U90_RUN = 'upg90-run';
const TMC_U90_SKU = 'UPG90-OWN';
const TMC_U90_TITLE = 'ارتقا UPG90-OWN';
const TMC_U90_META = '_tmc_product_id';
const TMC_U90_VENDOR_META = '_tmc_vendor_user_id';
const TMC_U90_FROM_META = '_tmc_claimed_from_status';

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'rows');

global $wpdb;
$products = $wpdb->prefix . 'tmc_products';
$audit = $wpdb->prefix . 'tmc_audit_events';

$rowId = static function () use ($wpdb, $products): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM `{$products}` WHERE vendor_user_id = %d AND sku = %s",
        TMC_U90_VENDOR,
        TMC_U90_SKU
    ));
};
$wcIdOf = static function (int $id) use ($wpdb, $products): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT wc_product_id FROM `{$products}` WHERE id = %d",
        $id
    ));
};
/** Our products with this title — one, unless a projection created a second. */
$postsNamed = static function () use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$wpdb->posts}`
          WHERE post_type = 'product' AND post_title = %s AND post_status <> 'trash'",
        TMC_U90_TITLE
    ));
};
/**
 * The transfer service, built by the plugin's own container.
 *
 * Asked by name so the verb answers `absent` rather than fatalling on a build
 * that does not bind it.
 */
$transfer = static function () {
    $container = Bootstrap::container();
    $port = 'Tecteb\\Marketplace\\Modules\\Migration\\Application\\TransferOwnership';
    return $container->has($port) ? $container->get($port) : null;
};
// Ownership is a capability decision, so somebody has to be holding it.
$asManager = static function (): int {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $id = (int) ($admins[0] ?? 0);
    if ($id > 0) {
        wp_set_current_user($id);
    }
    return $id;
};

switch ($verb) {
    case 'arrange': {
        $wc = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_title' => TMC_U90_TITLE,
        ]);
        $now = current_time('mysql', true);
        if ($wc) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$products}`
                   (vendor_user_id, wc_product_id, title, title_sort, sku, status,
                    price_minor, stock, category_key, link_ownership, import_run_id,
                    created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, 'published', 450000, 3, '', 'observed', %s, %s, %s)",
                TMC_U90_VENDOR,
                (int) $wc,
                TMC_U90_TITLE,
                TMC_U90_SKU,
                TMC_U90_SKU,
                TMC_U90_RUN,
                $now,
                $now
            ));
        }
        $id = $rowId();
        echo 'arranged=' . ($id > 0 ? 'yes' : 'no') . "\n";
        echo 'row=' . $id . "\n";
        echo 'wc=' . $wcIdOf($id) . "\n";
        break;
    }

    case 'rows': {
        $id = $rowId();
        $wc = $wcIdOf($id);
        echo 'row=' . $id . "\n";
        echo 'wc=' . $wc . "\n";
        echo 'owner=' . ($wpdb->get_var($wpdb->prepare(
            "SELECT link_ownership FROM `{$products}` WHERE id = %d",
            $id
        )) ?? '__absent__') . "\n";
        // ⛔ The stamp is read from the ROW in `postmeta`, not from
        // `get_post_meta()`: the object cache answers that one and this
        // script is asking what is on disk (`alpha.50`).
        foreach ([TMC_U90_META => 'stamp', TMC_U90_VENDOR_META => 'stamp_vendor', TMC_U90_FROM_META => 'stamp_from'] as $key => $label) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = %s",
                $wc,
                $key
            ));
            echo $label . '=' . ($value === null ? '__absent__' : ($value === '' ? '__empty__' : $value)) . "\n";
        }
        echo 'post_status=' . (get_post_status($wc) ?: '__absent__') . "\n";
        echo 'posts_named=' . $postsNamed() . "\n";
        echo 'price=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT price_minor FROM `{$products}` WHERE id = %d",
            $id
        )) . "\n";
        break;
    }

    case 'take':
    case 'give-back': {
        $service = $transfer();
        if ($service === null) {
            echo "service=absent\n";
            break;
        }
        $actor = $asManager();
        $id = $rowId();
        echo 'actor=' . $actor . "\n";
        if ($verb === 'give-back') {
            $result = $service->giveBack($id);
        } else {
            // Exactly what the page sends: the risks it listed, and the
            // fingerprint of each shop value it printed (`alpha.49`).
            $risks = array_keys($service->risksFor($id));
            $seen = [];
            foreach ($service->storefrontDrift($id) as $field => $pair) {
                $seen[(string) $field] = (string) ($pair['fingerprint'] ?? '');
            }
            echo 'risks=' . ($risks === [] ? '__none__' : implode(',', $risks)) . "\n";
            echo 'drift=' . ($seen === [] ? '__none__' : implode(',', array_keys($seen))) . "\n";
            $result = $service->take($id, $risks, $seen);
        }
        echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
        echo 'code=' . $result->code . "\n";
        break;
    }

    case 'audit': {
        $id = $rowId();
        $catalog = 'Tecteb\\Marketplace\\Core\\Audit\\AuditEventCatalog';
        $changed = 'migration.ownership_changed';
        $half = 'migration.ownership_half_written';
        echo 'changed_known=' . (method_exists($catalog, 'isKnown') && $catalog::isKnown($changed) ? 'yes' : 'no') . "\n";
        // ⛔ On `alpha.89` this answers `no`, and that absence is the
        // measurement: the older build does not know the event, so rolling
        // back cannot leave a row it would reject.
        echo 'half_known=' . (method_exists($catalog, 'isKnown') && $catalog::isKnown($half) ? 'yes' : 'no') . "\n";
        foreach ([$changed => 'changed_rows', $half => 'half_rows'] as $type => $label) {
            echo $label . '=' . (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$audit}` WHERE event_type = %s AND object_id = %s",
                $type,
                (string) $id
            )) . "\n";
        }
        break;
    }

    case 'products':
        echo 'posts_named=' . $postsNamed() . "\n";
        break;

    case 'reset': {
        $id = $rowId();
        if ($id > 0) {
            $wc = $wcIdOf($id);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$audit}` WHERE object_type = 'product' AND object_id = %s",
                (string) $id
            ));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$products}` WHERE id = %d", $id));
            if ($wc > 0) {
                wp_delete_post($wc, true);
            }
        }
        // Every post this fixture ever created, by title — so a run that died
        // half way does not leave a second one behind to be counted next time
        // (`alpha.33`).
        $leftover = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'product' AND post_title = %s",
            TMC_U90_TITLE
        ));
        foreach ($leftover as $post) {
            wp_delete_post((int) $post, true);
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$products}` WHERE vendor_user_id = %d",
            TMC_U90_VENDOR
        ));
        echo 'reset=done' . "\n";
        echo 'posts_named=' . $postsNamed() . "\n";
        break;
    }

    default:
        echo "unknown_verb=1\n";
}
