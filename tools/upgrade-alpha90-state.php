<?php
/**
 * The state verbs the `alpha.90 → alpha.91` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees, so anything this round adds is reached
 * through `class_exists()` and a verb about something the older build does
 * not have answers with that ABSENCE — which is the measurement, not a
 * failure (`alpha.32`).
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha90-state.php <verb>
 *
 * Verbs: arrange · state · confirm · revoke · rows · dokan-off · dokan-on
 *        unmigrate-37 · misshape · reset
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

const TMC_U91_SHOP = 9911;
const TMC_U91_RUN = 'upg91-run';
const TMC_U91_SKU = 'UPG91-REC';
const TMC_U91_TITLE = 'ارتقا UPG91-REC';
const TMC_U91_TRN = 55911;

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'state');

global $wpdb;
$products = $wpdb->prefix . 'tmc_products';
$balanceHistory = $wpdb->prefix . 'tmc_dokan_balance_history';
$audit = $wpdb->prefix . 'tmc_audit_events';
$dokanOrders = $wpdb->prefix . 'dokan_orders';
$dokanBalance = $wpdb->prefix . 'dokan_vendor_balance';
$versions = $wpdb->prefix . 'tmc_dokan_shop_version';
$confirmClass = 'Tecteb\\Marketplace\\Modules\\Migration\\Infrastructure\\Migrations\\M0037ShopRecordConfirmation';

/** The confirmation table's name, WITHOUT needing the class that names it. */
$confirmTable = $wpdb->prefix . 'tmc_dokan_record_confirmation';

$tableExists = static function (string $name) use ($wpdb): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $name
    )) > 0;
};
$service = static function () {
    $container = Bootstrap::container();
    $port = 'Tecteb\\Marketplace\\Modules\\Migration\\Application\\ConfirmShopRecords';
    return $container->has($port) ? $container->get($port) : null;
};
$completeness = static function () {
    return Bootstrap::container()->get(
        'Tecteb\\Marketplace\\Modules\\Migration\\Application\\MigrationCompleteness'
    );
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
        // ⛔ Dokan-SHAPED tables, read by the REAL `WpDokanReader`. Dokan
        // itself cannot run in this environment (`alpha.53`), so the source
        // side is data in Dokan's own table names and the reader under
        // measurement is the production one.
        $collate = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$dokanOrders}` (
            `order_id` BIGINT UNSIGNED NOT NULL,
            `seller_id` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`order_id`)
        ) {$collate}");
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$dokanBalance}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id` BIGINT UNSIGNED NOT NULL,
            `trn_id` BIGINT UNSIGNED NOT NULL,
            `trn_type` VARCHAR(30) NOT NULL DEFAULT '',
            `perticulars` TEXT NULL,
            `debit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `credit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `status` VARCHAR(30) NOT NULL DEFAULT '',
            `trn_date` DATETIME NULL,
            `balance_date` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) {$collate}");
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$dokanBalance}`
               (id, vendor_id, trn_id, trn_type, perticulars, debit, credit, status, trn_date, balance_date)
             VALUES (%d, %d, %d, 'dokan_orders', '', '0.0000', '5.0000', 'approved', %s, %s)",
            TMC_U91_TRN,
            TMC_U91_SHOP,
            TMC_U91_TRN,
            '2026-01-02 00:00:00',
            '2026-01-02 00:00:00'
        ));

        // One imported product, so this id is a SHOP rather than a record
        // owner (`MigrationCompleteness::shopVendorUserIds()`).
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$products}`
               (vendor_user_id, wc_product_id, title, title_sort, sku, status,
                price_minor, stock, category_key, link_ownership, import_run_id,
                created_at, updated_at)
             VALUES (%d, NULL, %s, %s, %s, 'published', 450000, 3, '', 'observed', %s, %s, %s)",
            TMC_U91_SHOP,
            TMC_U91_TITLE,
            TMC_U91_SKU,
            TMC_U91_SKU,
            TMC_U91_RUN,
            $now,
            $now
        ));

        // And the matching archive row, through the repository that bumps the
        // version counter — so the confirmation is taken against a REAL
        // version rather than against zero.
        $records = Bootstrap::container()->get(
            'Tecteb\\Marketplace\\Modules\\Migration\\Application\\ShopRecordRepositoryInterface'
        );
        $code = $records->recordBalance(TMC_U91_RUN, [
            'trn_id' => TMC_U91_TRN,
            'vendor_user_id' => TMC_U91_SHOP,
            'debit' => '0.0000',
            'credit' => '5.0000',
            'trn_type' => 'dokan_orders',
            'trn_date' => '2026-01-02 00:00:00',
            'particulars' => '',
            'status' => 'approved',
        ]);
        echo 'archive=' . $code . "\n";
        echo 'dokan_orders=' . ($tableExists($dokanOrders) ? 'yes' : 'no') . "\n";
        echo 'dokan_balance_rows=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$dokanBalance}` WHERE vendor_id = %d",
            TMC_U91_SHOP
        )) . "\n";
        echo 'held=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$balanceHistory}` WHERE vendor_user_id = %d",
            TMC_U91_SHOP
        )) . "\n";
        echo 'version=' . (int) $records->recordsVersion(TMC_U91_SHOP) . "\n";
        break;
    }

    case 'state': {
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        echo 'table_present=' . ($tableExists($confirmTable) ? 'yes' : 'no') . "\n";
        echo 'rows=' . ($tableExists($confirmTable)
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$confirmTable}`")
            : 0) . "\n";
        echo 'live_rows=' . ($tableExists($confirmTable)
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$confirmTable}` WHERE live_vendor_user_id IS NOT NULL")
            : 0) . "\n";
        echo 'dokan_present=' . ($tableExists($dokanOrders) ? 'yes' : 'no') . "\n";
        // ⛔ The older build has no `recordsState()` arm for a confirmation,
        // so this verb prints what THAT build says — which is the rollback
        // cost, named rather than argued.
        $gate = $completeness()->forVendor(TMC_U91_SHOP);
        echo 'records_state=' . (string) ($gate['detail']['records_state'] ?? '__absent__') . "\n";
        echo 'gate_records=' . (($gate['gates']['records_imported'] ?? false) ? 'open' : 'closed') . "\n";
        // ⛔ On `alpha.90` this answers `no`, and that ABSENCE is the
        // measurement: the older build does not know the step, so rolling
        // back cannot run it and cannot read a confirmation either.
        echo 'confirmed_known=' . (class_exists($confirmClass) ? 'yes' : 'no') . "\n";
        break;
    }

    case 'confirm':
    case 'revoke': {
        $s = $service();
        if ($s === null) {
            echo "service=absent\n";
            break;
        }
        echo 'actor=' . $asManager() . "\n";
        $result = $verb === 'confirm' ? $s->confirm(TMC_U91_SHOP) : $s->revoke(TMC_U91_SHOP);
        echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
        echo 'code=' . $result->code . "\n";
        break;
    }

    case 'rows': {
        if (!$tableExists($confirmTable)) {
            echo "table_present=no\n";
            break;
        }
        $rows = $wpdb->get_results("SELECT * FROM `{$confirmTable}` ORDER BY id ASC", ARRAY_A);
        echo 'rows=' . count($rows) . "\n";
        foreach ($rows as $i => $row) {
            echo 'row' . $i . '_live=' . ($row['live_vendor_user_id'] === null ? '__null__' : $row['live_vendor_user_id']) . "\n";
            echo 'row' . $i . '_version=' . (int) $row['records_version'] . "\n";
            echo 'row' . $i . '_reconciliation=' . (string) $row['reconciliation'] . "\n";
            echo 'row' . $i . '_revoked=' . ($row['revoked_at'] === null ? '__null__' : 'yes') . "\n";
        }
        echo 'audit_confirmed=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$audit}` WHERE event_type = %s AND object_id = %s",
            'migration.dokan_records_confirmed',
            (string) TMC_U91_SHOP
        )) . "\n";
        echo 'audit_revoked=' . (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$audit}` WHERE event_type = %s AND object_id = %s",
            'migration.dokan_records_confirmation_revoked',
            (string) TMC_U91_SHOP
        )) . "\n";
        break;
    }

    case 'dokan-off':
        // Only the detection table goes; the archive and the Dokan-shaped
        // balance table stay exactly where they are, because the point is
        // «Dokan cannot be asked», not «the data was deleted».
        $wpdb->query("DROP TABLE IF EXISTS `{$dokanOrders}`");
        echo 'dokan_present=' . ($tableExists($dokanOrders) ? 'yes' : 'no') . "\n";
        break;

    case 'dokan-on': {
        $collate = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$dokanOrders}` (
            `order_id` BIGINT UNSIGNED NOT NULL,
            `seller_id` BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`order_id`)
        ) {$collate}");
        echo 'dokan_present=' . ($tableExists($dokanOrders) ? 'yes' : 'no') . "\n";
        break;
    }

    case 'unmigrate-37':
        // ⛔ Installing the older package does NOT lower the structure number
        // — that is the rollback promise — so putting the site genuinely on
        // 36 is its own verb (`alpha.70`).
        $wpdb->query("DROP TABLE IF EXISTS `{$confirmTable}`");
        update_option('tmc_schema_version', 36);
        echo 'schema_version=' . (int) get_option('tmc_schema_version', 0) . "\n";
        echo 'table_present=' . ($tableExists($confirmTable) ? 'yes' : 'no') . "\n";
        break;

    case 'misshape': {
        // A table of the same NAME and the wrong SHAPE.
        // `CREATE TABLE IF NOT EXISTS` is satisfied by it, so only `verify()`
        // can refuse — the `alpha.51` trap from the other side.
        $collate = $wpdb->get_charset_collate();
        $wpdb->query("DROP TABLE IF EXISTS `{$confirmTable}`");
        $wpdb->query("CREATE TABLE `{$confirmTable}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            PRIMARY KEY (`id`)
        ) {$collate}");
        update_option('tmc_schema_version', 36);
        echo 'table_present=' . ($tableExists($confirmTable) ? 'yes' : 'no') . "\n";
        echo 'columns=' . (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $confirmTable
        )) . "\n";
        break;
    }

    case 'reset':
        $wpdb->query("DROP TABLE IF EXISTS `{$dokanOrders}`");
        $wpdb->query("DROP TABLE IF EXISTS `{$dokanBalance}`");
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$balanceHistory}` WHERE vendor_user_id = %d",
            TMC_U91_SHOP
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$products}` WHERE vendor_user_id = %d",
            TMC_U91_SHOP
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$audit}` WHERE object_type = 'vendor' AND object_id = %s",
            (string) TMC_U91_SHOP
        ));
        if ($tableExists($confirmTable)) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$confirmTable}` WHERE vendor_user_id = %d",
                TMC_U91_SHOP
            ));
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$versions}` WHERE vendor_user_id = %d",
            TMC_U91_SHOP
        ));
        echo "reset=done\n";
        break;

    default:
        echo "unknown_verb=1\n";
}
