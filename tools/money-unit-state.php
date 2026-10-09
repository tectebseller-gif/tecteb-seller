<?php
/**
 * The two ledger shapes schema 22 has to be measured against, on a real install.
 *
 * `tmc_vendor_money_unit` is seeded from whatever the ledger already proves, and
 * the whole rule is «exactly one unit is a fact, two is a decision». So the
 * upgrade check needs a site where BOTH exist before the migration runs:
 *
 *  1. a vendor whose ledger holds one unit — a fact the seed may record;
 *  2. a vendor whose ledger already holds two — a decision it may not make.
 *
 * Both are written through `DbLedgerRepository`, the plugin's own service, and
 * never with an `INSERT` behind the code's back: that is the `alpha.17` rule,
 * and a seed measured against rows this file typed would be a measurement of
 * this file. The repository and the ledger table have existed unchanged since
 * migration 4, so the SAME call works on `alpha.39` and on `alpha.40` — which
 * is what lets stage 2 of the upgrade check run on the OLD build.
 *
 * It PRINTS what the shell asserts against, so the numbers live in one place
 * (the `alpha.22` rule) — including the vendor ids, which the shell reads out
 * rather than spelling.
 *
 *   wp eval-file tools/money-unit-state.php seed     # the two ledgers
 *   wp eval-file tools/money-unit-state.php report   # what the new build says
 *   wp eval-file tools/money-unit-state.php reset    # give back what it spent
 */

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;

// --- refuses to run anywhere but a disposable install ---------------------
//
// This file WRITES ledger rows. On the owner's site that is not a fixture, it
// is financial damage.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases. This tool writes ledger rows and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes ledger rows and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$command = (string) ($args[0] ?? 'report');

/** Two ids well clear of anything the other fixtures use. */
const TMC_UNIT_SINGLE = 7701;
const TMC_UNIT_MIXED  = 7702;

// AFTER the guard, and that order is asserted by `DisposableOnlyToolsTest`:
// a refusal placed after the first WordPress call is not a refusal.
global $wpdb;

$clock = new SystemClock();
$db = new WpDatabase($wpdb);
$ledger = new DbLedgerRepository($db, $clock);

/** One balanced accrual, in a stated unit. */
$accrue = static function (int $vendor, string $event, string $currency, int $exponent, int $base) use ($ledger): void {
    $amount = Money::of($base, $currency, $exponent);
    $ledger->record(
        (new LedgerTransaction($event, $vendor, 'order:' . $vendor, (string) $vendor))
            ->add(LedgerAccount::CentralPayment, $amount, 'item_paid')
            ->add(LedgerAccount::VendorEarning, $amount->negate(), 'vendor_earned')
    );
};

switch ($command) {
    case 'seed':
        // Idempotent by event key: the ledger refuses a duplicate event, so a
        // second run adds nothing and the counts stay the ones being measured.
        // A fixture that spends something on every run turns a measurement
        // into a countdown (`alpha.33`).
        $accrue(TMC_UNIT_SINGLE, 'unitfix:single:1', 'IRT', 0, 1000000);
        $accrue(TMC_UNIT_SINGLE, 'unitfix:single:2', 'IRT', 0, 2000000);
        // And the vendor whose books are ALREADY mixed. This is the legacy
        // state the migration must detect and refuse to resolve — it is not a
        // state `alpha.40` lets a capture create, which is why it is written
        // here through two separate accruals rather than through a capture.
        $accrue(TMC_UNIT_MIXED, 'unitfix:mixed:1', 'IRT', 0, 1000000);
        $accrue(TMC_UNIT_MIXED, 'unitfix:mixed:2', 'USD', 2, 5000);
        printf(
            "seeded single=%d mixed=%d single_units=%d mixed_units=%d\n",
            TMC_UNIT_SINGLE,
            TMC_UNIT_MIXED,
            count(tmc_unit_set($db, TMC_UNIT_SINGLE)),
            count(tmc_unit_set($db, TMC_UNIT_MIXED))
        );
        break;

    case 'report':
        // Only callable on a build that HAS the registry — the shell only calls
        // it after the upgrade, and this says so plainly rather than fataling
        // with a class-not-found somebody has to read a stack trace for.
        $registry = '\\Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\DbVendorMoneyUnitRegistry';
        if (!class_exists($registry)) {
            echo "unavailable=1 reason=this_build_has_no_unit_registry\n";
            break;
        }
        /** @var object $units */
        $units = new $registry($db, $clock);
        $mixed = $ledger->vendorsWithMixedUnits();
        $listed = [];
        foreach ($mixed as $row) {
            $listed[] = (int) $row['vendor_user_id'];
        }
        // A claim in the unit each vendor's books actually hold: the
        // single-unit vendor agrees, and the mixed one is refused by name.
        // Nothing is written for the mixed vendor — the claim refuses BEFORE
        // it touches the table, which is the half §4 is about.
        $single = $units->claim(TMC_UNIT_SINGLE, 'IRT', 0);
        $mixedClaim = $units->claim(TMC_UNIT_MIXED, 'IRT', 0);
        printf(
            "mixed_listed=%s single_claim=%s mixed_claim=%s rows=%d\n",
            $listed === [] ? 'none' : implode(',', $listed),
            $single->state,
            $mixedClaim->state,
            (int) $db->getVar(
                'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_vendor_money_unit`'
            )
        );
        break;

    case 'reset':
        // Gives back exactly what `seed` spent, and nothing else: the ledger is
        // append-only by design, so this is the one place allowed to delete
        // from it and it names its own event keys to do it.
        $removed = $db->execute(
            'DELETE FROM `' . $db->prefix() . "tmc_ledger_entries` WHERE event_key LIKE 'unitfix:%'"
        );
        $units = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$db->prefix() . 'tmc_vendor_money_unit']
        );
        if ((int) $units === 1) {
            $db->execute(
                'DELETE FROM `' . $db->prefix() . 'tmc_vendor_money_unit` WHERE vendor_user_id IN (%d, %d)',
                [TMC_UNIT_SINGLE, TMC_UNIT_MIXED]
            );
        }
        printf("reset ledger_rows_removed=%s\n", $removed === null ? 'failed' : (string) $removed);
        break;

    default:
        echo "usage: seed|report|reset\n";
}

/**
 * The distinct units one vendor's ledger holds, as `CUR/EXP` strings.
 *
 * Read with `getResults()` and counted here rather than with a `COUNT(DISTINCT)`
 * the shell would have to trust: the shell asks the database the same question
 * its own way, and two readings that agree are worth more than one.
 *
 * @return list<string>
 */
function tmc_unit_set(WpDatabase $db, int $vendorUserId): array
{
    $rows = $db->getResults(
        'SELECT DISTINCT `currency`, `exponent` FROM `' . $db->prefix() . 'tmc_ledger_entries`
         WHERE `vendor_user_id` = %d',
        [$vendorUserId]
    );
    return array_map(
        static fn (array $row): string => (string) $row['currency'] . '/' . (int) $row['exponent'],
        $rows
    );
}
