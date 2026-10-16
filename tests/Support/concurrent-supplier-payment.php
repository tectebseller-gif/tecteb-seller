<?php
/**
 * A SECOND ACTOR on one order line, in its own process and on its own
 * connection.
 *
 * The two paths that can settle a line — the vendor's withdrawal cycle and a
 * manual supplier payment — are two REQUESTS by definition, and a race between
 * them cannot be modelled from inside one of them. `InterferingDatabase` writes
 * on the connection the code under test is using, so the moment that code
 * holds a row lock open it stops modelling another actor at all (`alpha.40`'s
 * rule). This is the other half.
 *
 * Two modes, and the difference is which side waits:
 *
 *   claim <line> <amount>        one `reserve()`, committed immediately.
 *                                Blocks while somebody else holds the row,
 *                                then either claims it or is refused.
 *   hold <line> <amount> <fifo>  take the line `FOR UPDATE` and HOLD the
 *                                transaction open until a line arrives on the
 *                                FIFO. No `sleep` anywhere: the wait is a
 *                                blocking read, so the other side decides when
 *                                this one lets go.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tests/Support/concurrent-supplier-payment.php claim <line> <amount>
 *   wp eval-file tests/Support/concurrent-supplier-payment.php hold <line> <fifo>
 */

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as OrderTables;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('actor', 'ran');

$mode = (string) ($args[0] ?? 'claim');
$line = (int) ($args[1] ?? 0);
if ($line <= 0) {
    $say('refused', 'no_line');
    return;
}

global $wpdb;
$db = new WpDatabase($wpdb);
$table = OrderTables::table($db, OrderTables::ORDER_ITEMS);

if ($mode === 'hold') {
    $fifo = (string) ($args[2] ?? '');
    if ($fifo === '') {
        $say('refused', 'no_fifo');
        return;
    }
    $wpdb->query('SET SESSION innodb_lock_wait_timeout = 3');
    $wpdb->query('START TRANSACTION');
    $wpdb->get_results($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d FOR UPDATE", $line));
    $say('holding', $line);
    // A blocking read: the other side says when to let go.
    $handle = @fopen($fifo, 'r');
    if ($handle !== false) {
        fgets($handle);
        fclose($handle);
    }
    $wpdb->query('ROLLBACK');
    $say('released', $line);
    return;
}

$amount = (int) ($args[2] ?? 0);
$wpdb->query('SET SESSION innodb_lock_wait_timeout = 5');
$withdrawals = Bootstrap::container()->get(WithdrawalRepositoryInterface::class);
$vendor = (int) $wpdb->get_var($wpdb->prepare("SELECT vendor_user_id FROM `{$table}` WHERE id = %d", $line));
$result = $withdrawals->reserve($vendor, [$line], $amount, 'IR000000000000000000000000', 'x');
$say('reserve', $result);
$say('claim_on_line', (string) ($wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(withdrawal_id, 0) FROM `{$table}` WHERE id = %d",
    $line
)) ?? '0'));
