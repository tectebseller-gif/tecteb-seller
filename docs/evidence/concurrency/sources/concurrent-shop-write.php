<?php
declare(strict_types=1);

/*
 * A second writer, in a process of its own.
 *
 * ### Why not pcntl_fork()
 *
 * The other concurrency tests fork, and before forking they call
 * `$wpdb->disconnect()` — because a child inherits the parent's socket and
 * closes it on exit, which would leave the parent talking to nothing.
 *
 * That works right up until the parent needs a TRANSACTION to stay open
 * across the fork. Dropping the PDO handle ends the transaction, so the
 * parent silently resumes on a fresh connection with a fresh read view — and
 * a test written to prove that a stale read view causes a bug then proves
 * nothing at all, because the staleness was thrown away with the socket.
 * (That is exactly how the first version of
 * `testARollbackMovesTheVersionOfEveryShopWhoseRowsItRemoves` passed against
 * code that had the defect.)
 *
 * A separate process has its own connection from the start. Nothing is
 * inherited, so nothing has to be disconnected, and the parent's transaction
 * is untouched.
 *
 * Usage (from a database test):
 *   php tests/Support/concurrent-shop-write.php <verb> <run-id> <vendor-id> [trn-id] [credit]
 *
 * Verbs:
 *   balance   record one balance row for that shop under that run
 *
 * Prints the repository's own outcome word (`recorded` / `already` /
 * `failed`) and exits 0, or prints the error and exits 1.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "concurrent-shop-write: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "concurrent-shop-write: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

$verb = $argv[1] ?? '';
$runId = $argv[2] ?? '';
$vendorId = (int) ($argv[3] ?? 0);
$trnId = (int) ($argv[4] ?? 1);
$credit = (string) ($argv[5] ?? '100000');

if ($verb !== 'balance' || $runId === '' || $vendorId <= 0) {
    fwrite(STDERR, "usage: concurrent-shop-write.php balance <run-id> <vendor-id> [trn-id] [credit]\n");
    exit(1);
}

// Same single storage layer the suite uses, so this writer is not a
// simplified stand-in that could succeed where the real one would not.
State::$optionsBackedByWpdb = true;
$GLOBALS['wpdb'] = new \wpdb();

try {
    $records = new DbShopRecordRepository(new WpDatabase($GLOBALS['wpdb']), new SystemClock());
    echo $records->recordBalance($runId, [
        'trn_id' => $trnId,
        'vendor_user_id' => $vendorId,
        'trn_type' => 'order',
        'particulars' => 'نوشتن هم‌زمان از پروسهٔ دیگر',
        'debit' => '0',
        'credit' => $credit,
        'status' => 'approved',
        'trn_date' => '2026-02-02 00:00:00',
    ]);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'concurrent-shop-write: ' . $e->getMessage() . "\n");
    exit(1);
}
