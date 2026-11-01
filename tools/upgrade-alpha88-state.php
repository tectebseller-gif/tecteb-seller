<?php
/**
 * The state verbs the `alpha.88 → alpha.89` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so everything this round
 * adds is reached through `class_exists()` and a verb about something the
 * older build does not have answers with that ABSENCE — which is itself the
 * measurement, not a failure.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha88-state.php <verb> [args]
 *
 * Verbs:
 *   arrange · rows · waiting · table · carried · accept · live
 *   unmigrate-36 · misshape · reset
 */

use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0036DefaultCategoryAcceptance;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
// BOTH HALVES, and `DisposableOnlyToolsTest` is what says so: a guard with
// only the database half would run against a real site somebody had pointed
// at a copy of the disposable database (`alpha.22`).
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_U89_VENDOR = 9891;
const TMC_U89_RUN = 'upg89-run';
/**
 * Two fixture products, and both are the point.
 *
 * `UPG89-DEFAULT` is the shape the owner asked about: imported, no key, no
 * recorded set, and the ONLY `product_cat` term on it is the shop default.
 * `UPG89-REAL` carries a real category instead and is the control — it must
 * never be waiting, before or after the step, so a migration that moved the
 * predicate for everybody would be caught rather than looking tidy.
 */
const TMC_U89_FIXTURES = ['UPG89-DEFAULT', 'UPG89-REAL'];

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'rows');

global $wpdb;
$products = $wpdb->prefix . 'tmc_products';
$accept = $wpdb->prefix . 'tmc_product_default_category';

$tablePresent = static function (string $table) use ($wpdb): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    )) === 1;
};

$defaultTerm = static function () use ($wpdb): int {
    return (int) get_option('default_product_cat', 0);
};

/** @return array<string,int> sku => product id */
$fixtureIds = static function () use ($wpdb, $products): array {
    $out = [];
    foreach (TMC_U89_FIXTURES as $sku) {
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$products}` WHERE vendor_user_id = %d AND sku = %s",
            TMC_U89_VENDOR,
            $sku
        ));
        if ($id !== null) {
            $out[$sku] = (int) $id;
        }
    }
    return $out;
};

/**
 * How many imported rows THIS VENDOR has that are still waiting for a
 * category — asked through the repository's own per-shop counter, which is
 * the one `MigrationCompleteness` reads. So the number printed here is the
 * number the gate reads, not a second derivation of it (`alpha.78`).
 *
 * ⛔ Scoped to the fixture vendor on purpose: the disposable catalogue has
 * hundreds of waiting rows and more than one page of them, so intersecting a
 * LISTING with the fixture ids answered about whichever page they fell on.
 */
$waitingFor = static function (): int {
    $c = \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
    $port = 'Tecteb\\Marketplace\\Modules\\Product\\Application\\ProductRepositoryInterface';
    if (!$c->has($port)) {
        return -2;
    }
    return $c->get($port)->countImportedWithoutCategory(TMC_U89_VENDOR);
};

switch ($verb) {
    case 'arrange': {
        $term = $defaultTerm();
        // wp_insert_term returns an ARRAY on success, so the id is asked
        // for by name either way — including when the term is already there
        // from a previous run.
        wp_insert_term('دستهٔ واقعی ' . TMC_U89_VENDOR, 'product_cat');
        $existing = get_term_by('name', 'دستهٔ واقعی ' . TMC_U89_VENDOR, 'product_cat');
        $real = $existing ? (int) $existing->term_id : 0;
        $now = current_time('mysql', true);
        foreach (TMC_U89_FIXTURES as $sku) {
            $wc = wp_insert_post([
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_title' => 'ارتقا ' . $sku,
            ]);
            if (!$wc) {
                continue;
            }
            // The shop side: the default term alone for one, a real category
            // for the other. Nothing else on this site is touched.
            wp_set_object_terms((int) $wc, $sku === 'UPG89-REAL' ? [$real] : [$term], 'product_cat');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$products}`
                   (vendor_user_id, wc_product_id, title, title_sort, sku, status,
                    price_minor, stock, category_key, link_ownership, import_run_id,
                    created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, 'published', 100, 1, '', 'observed', %s, %s, %s)",
                TMC_U89_VENDOR,
                (int) $wc,
                'ارتقا ' . $sku,
                $sku,
                $sku,
                TMC_U89_RUN,
                $now,
                $now
            ));
        }
        $ids = $fixtureIds();
        echo 'arranged=' . (count($ids) === count(TMC_U89_FIXTURES) ? 'yes' : 'no') . "\n";
        echo 'default_term=' . $term . "\n";
        echo 'real_term=' . $real . "\n";
        foreach ($ids as $sku => $id) {
            echo 'row_' . strtolower(str_replace('UPG89-', '', $sku)) . '=' . $id . "\n";
            echo 'wc_' . strtolower(str_replace('UPG89-', '', $sku)) . '='
                . (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT wc_product_id FROM `{$products}` WHERE id = %d",
                    $id
                )) . "\n";
        }
        break;
    }

    case 'rows': {
        $ids = $fixtureIds();
        foreach ($ids as $sku => $id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT category_key, link_ownership, import_run_id, price_minor, stock
                   FROM `{$products}` WHERE id = %d",
                $id
            ), ARRAY_A);
            $slug = strtolower(str_replace('UPG89-', '', $sku));
            echo 'key_' . $slug . '=' . (($row['category_key'] ?? '') === '' ? '__empty__' : $row['category_key']) . "\n";
            echo 'owner_' . $slug . '=' . ($row['link_ownership'] ?? '__absent__') . "\n";
            echo 'run_' . $slug . '=' . ($row['import_run_id'] ?? '__absent__') . "\n";
            echo 'price_' . $slug . '=' . ($row['price_minor'] ?? '__absent__') . "\n";
            // The WooCommerce side, read from the relationship rather than
            // from anything this plugin stores.
            $wc = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT wc_product_id FROM `{$products}` WHERE id = %d",
                $id
            ));
            $terms = wp_get_object_terms($wc, 'product_cat', ['fields' => 'ids']);
            $terms = is_wp_error($terms) ? [] : array_map('intval', $terms);
            sort($terms);
            echo 'terms_' . $slug . '=' . ($terms === [] ? '__none__' : implode(',', $terms)) . "\n";
        }
        $linkTable = $wpdb->prefix . 'tmc_product_categories';
        if ($tablePresent($linkTable) && $ids !== []) {
            $in = implode(',', array_map('intval', array_values($ids)));
            echo 'link_rows_for_fixtures='
                . (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$linkTable}` WHERE product_id IN ({$in})") . "\n";
        } else {
            echo "link_rows_for_fixtures=0\n";
        }
        break;
    }

    case 'waiting':
        echo 'waiting=' . $waitingFor() . "\n";
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        break;

    case 'table': {
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        echo 'table_present=' . ($tablePresent($accept) ? 'yes' : 'no') . "\n";
        if ($tablePresent($accept)) {
            $cols = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                  ORDER BY ORDINAL_POSITION',
                $accept
            ));
            echo 'columns=' . implode(',', $cols ?: []) . "\n";
            $uniq = $wpdb->get_col($wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                    AND NON_UNIQUE = 0 AND INDEX_NAME <> %s
                  ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                $accept,
                'PRIMARY'
            ));
            echo 'unique_on=' . implode(',', $uniq ?: []) . "\n";
            // ⛔ THE ROW COUNT, which is where «افزایشی و بدون تخریب» either
            // holds or does not: the step creates the table and backfills
            // NOTHING, so a fresh one has to be empty.
            echo 'accept_rows=' . (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$accept}`") . "\n";
        } else {
            echo "columns=__none__\n";
            echo "unique_on=__none__\n";
            echo "accept_rows=__none__\n";
        }
        break;
    }

    case 'carried':
        if (!class_exists(M0036DefaultCategoryAcceptance::class)) {
            echo "step_class=absent\n";
            echo "carried=__unknown__\n";
            break;
        }
        echo "step_class=present\n";
        $carried = M0036DefaultCategoryAcceptance::carriedBy(new WpDatabase($wpdb));
        echo 'carried=' . ($carried === null ? '__null__' : ($carried ? 'yes' : 'no')) . "\n";
        echo 'step_version=' . (new M0036DefaultCategoryAcceptance())->version() . "\n";
        break;

    case 'accept': {
        // The manager's own path, through the service — no direct insert, so
        // what is measured is the decision this round added, not a row a
        // script wrote (`alpha.86`).
        $serviceClass = 'Tecteb\\Marketplace\\Modules\\Product\\Application\\AcceptDefaultCategory';
        if (!class_exists($serviceClass)) {
            echo "accepted=__absent__\n";
            break;
        }
        $ids = $fixtureIds();
        $id = (int) ($ids['UPG89-DEFAULT'] ?? 0);
        $term = $defaultTerm();
        // The service asks for `tmc_review_vendor`, and WP-CLI has nobody
        // logged in. The first administrator is named rather than queried
        // for by role — `get_users(['role' => …, 'number' => 1])` answers
        // about whoever happens to be first (`alpha.36`).
        $admin = get_user_by('login', 'tmcowner');
        if ($admin === false) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => ['ID']]);
            $admin = $admins === [] ? null : get_user_by('id', (int) $admins[0]->ID);
        }
        if ($admin === null || $admin === false) {
            echo "accepted=__no_admin__\n";
            break;
        }
        wp_set_current_user((int) $admin->ID);
        $c = \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container();
        if ($c === null || !$c->has($serviceClass)) {
            echo "accepted=__unbound__\n";
            break;
        }
        $out = $c->get($serviceClass)->accept($id, $term, (int) $admin->ID);
        echo 'accepted=' . ($out['ok'] ? 'yes' : 'no') . "\n";
        echo 'refused=' . ($out['ok'] ? '__none__' : $out['code']) . "\n";
        echo 'outcome=' . $out['code'] . "\n";
        echo 'term=' . $out['term_id'] . "\n";
        break;
    }

    case 'live': {
        if (!$tablePresent($accept)) {
            echo "live=__no_table__\n";
            break;
        }
        $ids = $fixtureIds();
        foreach ($ids as $sku => $id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT term_id, live_product_id, revoked_at FROM `{$accept}`
                  WHERE product_id = %d ORDER BY id DESC LIMIT 1",
                $id
            ), ARRAY_A);
            $slug = strtolower(str_replace('UPG89-', '', $sku));
            echo 'live_' . $slug . '=' . ($row === null ? '__none__' : (string) ($row['live_product_id'] ?? '__null__')) . "\n";
            echo 'term_' . $slug . '=' . ($row === null ? '__none__' : (string) $row['term_id']) . "\n";
        }
        echo 'accept_rows=' . (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$accept}`") . "\n";
        break;
    }

    case 'unmigrate-36':
        // A site GENUINELY on 35. Without this the step reports «already
        // done» and nothing is measured (`alpha.70`).
        $wpdb->query("DROP TABLE IF EXISTS `{$accept}`");
        update_option('tmc_schema_version', 35, false);
        delete_option('tmc_migration_last_error');
        echo 'table_present=' . ($tablePresent($accept) ? 'yes' : 'no') . "\n";
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        break;

    case 'misshape':
        // A table of the same NAME and the wrong SHAPE. `CREATE TABLE IF NOT
        // EXISTS` is satisfied by it, so only `verify()` can refuse — the
        // `alpha.51` trap from the other side.
        $wpdb->query("DROP TABLE IF EXISTS `{$accept}`");
        $wpdb->query("CREATE TABLE `{$accept}` (product_id BIGINT UNSIGNED NOT NULL)");
        update_option('tmc_schema_version', 35, false);
        delete_option('tmc_migration_last_error');
        $cols = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY ORDINAL_POSITION',
            $accept
        ));
        echo 'columns=' . implode(',', $cols ?: []) . "\n";
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        break;

    case 'reset': {
        // Everything this script spent, given back: the two product rows,
        // their WooCommerce posts, the term it created, and the acceptance
        // rows for those ids. Nothing else is swept (`alpha.33`).
        $ids = $fixtureIds();
        foreach ($ids as $id) {
            $wc = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT wc_product_id FROM `{$products}` WHERE id = %d",
                $id
            ));
            if ($tablePresent($accept)) {
                $wpdb->query($wpdb->prepare("DELETE FROM `{$accept}` WHERE product_id = %d", $id));
            }
            $linkTable = $wpdb->prefix . 'tmc_product_categories';
            if ($tablePresent($linkTable)) {
                $wpdb->query($wpdb->prepare("DELETE FROM `{$linkTable}` WHERE product_id = %d", $id));
            }
            $wpdb->query($wpdb->prepare("DELETE FROM `{$products}` WHERE id = %d", $id));
            if ($wc > 0) {
                wp_delete_post($wc, true);
            }
        }
        $term = get_term_by('name', 'دستهٔ واقعی ' . TMC_U89_VENDOR, 'product_cat');
        if ($term) {
            wp_delete_term((int) $term->term_id, 'product_cat');
        }
        echo 'reset=yes' . "\n";
        echo 'rows_left=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$products}` WHERE vendor_user_id = %d",
            TMC_U89_VENDOR
        )) . "\n";
        break;
    }

    default:
        echo "unknown_verb=$verb\n";
}
