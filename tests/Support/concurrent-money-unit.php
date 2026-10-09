<?php
declare(strict_types=1);

/*
 * A first accrual from a process of its own, so «the first sale fixes the
 * unit» can be measured as a race rather than asserted as a design.
 *
 * `alpha.39` asked the LEDGER whether a unit was acceptable — and the ledger
 * holds many rows per vendor by design, so there is no index a second
 * concurrent writer can collide with. Two first captures both saw «no rows»
 * and both wrote, each in its own unit. No single-connection test can show
 * that: there is nothing to contend for.
 *
 * This is the second connection. It claims a unit through the real registry
 * and prints what the registry answered, so the caller can assert that exactly
 * one of two concurrent first claims agreed.
 *
 * Usage:
 *   php tests/Support/concurrent-money-unit.php claim <vendor-id> <currency> <exponent>
 *
 * Prints the claim's own state word: `agreed`, `unit_changed`, `books_mixed`,
 * `unit_unreadable` or `unit_unsupported`. Exits 0 for all of them — a refusal
 * is a result, not a failure.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "concurrent-money-unit: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "concurrent-money-unit: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

$verb = (string) ($argv[1] ?? '');
$vendorId = (int) ($argv[2] ?? 0);
$currency = (string) ($argv[3] ?? '');
$exponent = (int) ($argv[4] ?? 0);

if ($verb !== 'claim' || $vendorId <= 0 || $currency === '') {
    fwrite(STDERR, "usage: concurrent-money-unit.php claim <vendor-id> <currency> <exponent>\n");
    exit(1);
}

State::$optionsBackedByWpdb = true;
$GLOBALS['wpdb'] = new \wpdb();

try {
    $registry = new DbVendorMoneyUnitRegistry(new WpDatabase($GLOBALS['wpdb']), new SystemClock());
    echo $registry->claim($vendorId, $currency, $exponent)->state;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'concurrent-money-unit: ' . $e->getMessage() . "\n");
    exit(1);
}
