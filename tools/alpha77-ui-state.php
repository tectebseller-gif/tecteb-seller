<?php
/**
 * Dokan-SHAPED records on the disposable WordPress, for the browser run.
 *
 * ⛔ **Dokan itself cannot run here and this does not pretend otherwise.** Its
 * `abzarwp/` layer is SourceGuardian-encoded and the loader is absent
 * (`alpha.53`), so what this builds is the DATA Dokan would leave behind —
 * `wp_dokan_vendor_balance`, `wp_dokan_withdraw`, an empty `wp_dokan_orders`
 * so `WpDokanReader::isAvailable()` is satisfied, and staff as ordinary
 * WordPress users carrying `_dokan_vendor_id`. The reader under measurement is
 * the REAL one, reading real tables.
 *
 * Both shops are made «imported» the way staging's are: a marketplace row
 * pointing at an existing WooCommerce product and carrying an import-run
 * stamp. Neither has one record, which is the state the owner is in.
 *
 * ⛔ Disposable environment only, and on BOTH halves like every other state
 * tool here: the database name AND a loopback `home_url()`. The name alone
 * would pass on a second disposable database served over a public hostname.
 *
 *   wp eval-file tools/alpha77-ui-state.php seed|report|reset
 */

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    echo "refused=1\n";
    return;
}

global $wpdb;
$verb = $args[0] ?? 'report';
$out = [];
$say = static function (string $k, $v) use (&$out): void {
    $out[] = $k . '=' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
};

const TMC77_RUN = 'dokan-20261024-ui';
const TMC77_SHOPS = [
    ['login' => 'tmc77shopa', 'name' => 'داروخانهٔ الف', 'sku' => 'DK77-A'],
    ['login' => 'tmc77shopb', 'name' => 'داروخانهٔ ب', 'sku' => 'DK77-B'],
];

$shopIds = static function (): array {
    $ids = [];
    foreach (TMC77_SHOPS as $shop) {
        $user = get_user_by('login', $shop['login']);
        $ids[$shop['login']] = $user ? (int) $user->ID : 0;
    }
    return $ids;
};

$table = static fn (string $suffix): string => $GLOBALS['wpdb']->prefix . $suffix;

if ($verb === 'seed') {
    // 1. The table that makes the real reader available.
    foreach ([
        'dokan_orders' => "CREATE TABLE IF NOT EXISTS `%s` (
            `order_id` BIGINT UNSIGNED NOT NULL,
            `seller_id` BIGINT UNSIGNED NOT NULL,
            `order_total` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `net_amount` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `order_status` VARCHAR(30) NOT NULL DEFAULT '',
            PRIMARY KEY (`order_id`)
        ) DEFAULT CHARSET=utf8mb4",
        'dokan_vendor_balance' => "CREATE TABLE IF NOT EXISTS `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_id` BIGINT UNSIGNED NOT NULL,
            `trn_id` BIGINT UNSIGNED NOT NULL,
            `trn_type` VARCHAR(30) NOT NULL DEFAULT '',
            `perticulars` TEXT NULL,
            `debit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `credit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `status` VARCHAR(30) NOT NULL DEFAULT '',
            `trn_date` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8mb4",
        'dokan_withdraw' => "CREATE TABLE IF NOT EXISTS `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `amount` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `status` TINYINT NOT NULL DEFAULT 0,
            `method` VARCHAR(64) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `date` DATETIME NULL,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8mb4",
    ] as $suffix => $ddl) {
        $wpdb->query(sprintf($ddl, $table($suffix)));
    }

    /** @var ProductRepositoryInterface $products */
    $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
    $wcId = 77100;
    foreach (TMC77_SHOPS as $shop) {
        $user = get_user_by('login', $shop['login']);
        if (!$user) {
            $id = wp_insert_user([
                'user_login' => $shop['login'],
                'user_pass' => wp_generate_password(20),
                'user_email' => $shop['login'] . '@example.test',
                'display_name' => $shop['name'],
                'role' => 'seller',
            ]);
            if (is_wp_error($id)) {
                $say('user_failed_' . $shop['login'], $id->get_error_message());
                continue;
            }
            $user = get_userdata((int) $id);
        }
        $shopId = (int) $user->ID;
        // Dokan's own marker for a seller, so `vendorsAfter()` finds them.
        update_user_meta($shopId, 'dokan_enable_selling', 'yes');

        // A WooCommerce product that already exists, and a marketplace row
        // pointing at it with this run's stamp — «imported», with NO record.
        $wcId++;
        if (get_post($wcId) === null) {
            $wpdb->insert($wpdb->posts, [
                'ID' => $wcId,
                'post_author' => $shopId,
                'post_title' => 'کالای ' . $shop['name'],
                'post_status' => 'publish',
                'post_type' => 'product',
                'post_date' => current_time('mysql'),
                'post_date_gmt' => current_time('mysql', true),
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ]);
        }
        if ($products->findObservedByWcProduct($wcId) === null && $products->findByWcProduct($wcId) === null) {
            $products->create(
                $shopId,
                new ProductDetails(title: 'کالای ' . $shop['name'], sku: $shop['sku'], priceMinor: 450000, stock: 2),
                ProductStatus::Draft,
                LinkOwnership::Observed,
                $wcId,
                TMC77_RUN
            );
        }
        $say('shop_' . $shop['login'], $shopId);
    }

    $ids = $shopIds();
    $a = $ids['tmc77shopa'];
    $b = $ids['tmc77shopb'];

    // Shop A: a balance ledger and a withdrawal, and one staff member.
    $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . $table('dokan_vendor_balance') . '` WHERE vendor_id IN (%d, %d)',
        $a,
        $b
    ));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . $table('dokan_withdraw') . '` WHERE user_id IN (%d, %d)',
        $a,
        $b
    ));
    foreach ([
        [$a, 5501, 'dokan_orders', '700000.0000', '0.0000', 'approved'],
        [$a, 9001, 'dokan_withdraw', '0.0000', '250000.0000', 'approved'],
        [$b, 5502, 'dokan_orders', '125000.5000', '0.0000', 'approved'],
    ] as [$vendor, $trn, $type, $debit, $credit, $status]) {
        $wpdb->insert($table('dokan_vendor_balance'), [
            'vendor_id' => $vendor,
            'trn_id' => $trn,
            'trn_type' => $type,
            'perticulars' => '',
            'debit' => $debit,
            'credit' => $credit,
            'status' => $status,
            'trn_date' => current_time('mysql'),
        ]);
    }
    foreach ([[$a, '250000.0000', 1], [$b, '10000.0000', 0]] as [$vendor, $amount, $status]) {
        $wpdb->insert($table('dokan_withdraw'), [
            'user_id' => $vendor,
            'amount' => $amount,
            'status' => $status,
            'method' => 'bank',
            'note' => '',
            'date' => current_time('mysql'),
        ]);
    }

    // One staff member on shop A, Dokan Pro's shape: an ordinary user
    // carrying `_dokan_vendor_id`.
    $staff = get_user_by('login', 'tmc77staff');
    if (!$staff) {
        $staffId = wp_insert_user([
            'user_login' => 'tmc77staff',
            'user_pass' => wp_generate_password(20),
            'user_email' => 'tmc77staff@example.test',
            'display_name' => 'مریم رضایی',
            'role' => 'vendor_staff',
        ]);
        $staff = is_wp_error($staffId) ? null : get_userdata((int) $staffId);
    }
    if ($staff) {
        update_user_meta((int) $staff->ID, '_dokan_vendor_id', (string) $a);
        $say('staff', (int) $staff->ID);
    }
    $say('seeded', true);
}

if ($verb === 'reset') {
    // ⛔ Give back everything this tool spends, and only what it made.
    foreach (['dokan_vendor_balance', 'dokan_withdraw', 'dokan_orders'] as $suffix) {
        $wpdb->query('DROP TABLE IF EXISTS `' . $table($suffix) . '`');
    }
    $ids = $shopIds();
    foreach ($ids as $shopId) {
        if ($shopId <= 0) {
            continue;
        }
        foreach (['tmc_dokan_staff_history', 'tmc_dokan_balance_history', 'tmc_dokan_withdraw_history'] as $suffix) {
            $wpdb->query($wpdb->prepare('DELETE FROM `' . $table($suffix) . '` WHERE vendor_user_id = %d', $shopId));
        }
        // ⛔ The table name comes from the migration that creates it, not
        // from memory: the first version guessed `tmc_vendor_records_version`
        // and printed a «table doesn't exist» error on every reset
        // (`alpha.10`).
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . $table(\Tecteb\Marketplace\Core\Migration\Migrations\M0018HandoverRowsAndRecordVersion::VERSION_TABLE) . '` WHERE vendor_user_id = %d',
            $shopId
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . $table('tmc_products') . '` WHERE vendor_user_id = %d', $shopId));
    }
    $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . $table('tmc_jobs') . '` WHERE job_type = %s',
        'dokan_records'
    ));
    for ($wcId = 77101; $wcId <= 77102; $wcId++) {
        if (get_post($wcId) !== null) {
            wp_delete_post($wcId, true);
        }
    }
    $staff = get_user_by('login', 'tmc77staff');
    if ($staff) {
        wp_delete_user((int) $staff->ID);
    }
    foreach (TMC77_SHOPS as $shop) {
        $user = get_user_by('login', $shop['login']);
        if ($user) {
            wp_delete_user((int) $user->ID);
        }
    }
    $say('reset', true);
}

$ids = $shopIds();
$say('shop_a', $ids['tmc77shopa']);
$say('shop_b', $ids['tmc77shopb']);
foreach (['tmc_dokan_staff_history' => 'our_staff', 'tmc_dokan_balance_history' => 'our_balance', 'tmc_dokan_withdraw_history' => 'our_withdrawals'] as $suffix => $key) {
    $say($key, (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $table($suffix) . '`'));
}
$say('record_jobs', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM `' . $table('tmc_jobs') . '` WHERE job_type = %s',
    'dokan_records'
)));

echo implode("\n", $out), "\n";
