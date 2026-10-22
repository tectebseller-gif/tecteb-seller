<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;

/**
 * The ledger on real MariaDB, where the constraints do the work.
 *
 * The idempotency of FIN-03 is not a PHP check here: it is a unique index, so
 * a second writer fails at the database even if it never saw the first.
 */
final class LedgerTest extends DatabaseTestCase
{
    private const VENDOR = 21;

    private DbLedgerRepository $ledger;
    private DbCommissionRuleRepository $rules;
    private RecordCommission $accrual;
    /** Kept so one test can rebuild the service over a database that refuses. */
    private WpDatabase $db;
    private SystemClock $clock;
    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach (M0004CreateFinanceTables::TABLES as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $this->db = $db;
        $this->clock = $clock;
        $this->logger = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->rules = new DbCommissionRuleRepository($db, $clock);
        $this->accrual = new RecordCommission(
            $this->ledger,
            new ResolveCommissionRate($this->rules),
            new CommissionCalculator(),
            $this->logger
        );
    }

    /**
     * ⛔ THE OWNER'S OWN CONTROL FOR §2: ۱۰۰ تومان در exponent 0 به‌علاوهٔ
     * ۸۸٫۳۷۵ تومان در exponent 4 دقیقاً ۱۸۸٫۳۷۵ تومان است.
     *
     * `alpha.67` summed `amount_minor` grouped by account alone and answered
     * `883850` — which is neither figure in any unit. Measured on its own
     * bytes: `rows_at_e0=100`, `rows_at_e4=883750`, `vendor_earning_raw` the
     * naive sum. The exact answer at the finer unit is `1883750`.
     *
     * The two rows are written straight into the table rather than through an
     * accrual, because the state being measured is a vendor who traded BEFORE
     * the upgrade and after it — and no single code path produces both halves.
     */
    public function testTwoExponentsInOneAccountAddUpExactly(): void
    {
        $this->writeEntry('alpha68:e0', LedgerAccount::VendorEarning, 100, 'IRT', 0);
        $this->writeEntry('alpha68:e4', LedgerAccount::VendorEarning, 883750, 'IRT', 4);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('', $figure['refused'], 'one currency, two scales: that combines');
        self::assertSame(4, $figure['exponent'], 'the answer is in the finest unit present');
        self::assertSame(1883750, $figure['minor'], '۱۰۰ + ۸۸٫۳۷۵ = ۱۸۸٫۳۷۵, exactly');
        self::assertSame('۱۸۸٫۳۷۵', MoneyText::format((int) $figure['minor'], (int) $figure['exponent']));
        self::assertTrue($figure['exact']);
        // AND THE NAIVE ANSWER IS NOT THIS ANSWER, said as a number so the
        // test cannot pass by accident if the grouping is dropped.
        self::assertNotSame(100 + 883750, $figure['minor']);
        self::assertCount(2, $figure['groups'], 'and the per-unit truth is still there');
    }

    /**
     * TWO CURRENCIES ARE NOT COMBINED, IN EITHER DIRECTION.
     *
     * `alpha.67` added a USD row straight into an IRT total — measured on its
     * own bytes, `two_currencies_answer=summed:-5191180`. There is no one
     * total across two kinds of money, so the honest answer is to say which
     * two and give no figure. Nothing is converted.
     */
    public function testTwoCurrenciesInOneAccountAreRefusedRatherThanAveraged(): void
    {
        $this->writeEntry('alpha68:irt', LedgerAccount::VendorEarning, 100, 'IRT', 0);
        $this->writeEntry('alpha68:usd', LedgerAccount::VendorEarning, 500, 'USD', 2);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('mixed_currencies', $figure['refused']);
        self::assertNull($figure['minor'], 'no figure, because there is not one');
        self::assertNull($figure['exponent']);
        self::assertFalse($figure['exact']);
        self::assertSame('IRT,USD', $figure['currencies'], 'and both are named');
        self::assertCount(2, $figure['groups']);
    }

    /**
     * ⛔ A SUM WIDER THAN A PHP INTEGER IS REFUSED, not saturated.
     *
     * `SUM()` over a `BIGINT` column is an exact DECIMAL and can be wider
     * than `PHP_INT_MAX`. `alpha.68` read it with `(int) $row['total']`, and
     * that is not a cast that fails: PHP SATURATES an over-long numeric
     * string to `PHP_INT_MAX`, so the answer was a plausible-looking figure
     * that was not the total — with `exact => true` on it. Measured here with
     * two rows the database adds to `18446744073709551614`.
     */
    public function testASumWiderThanAPhpIntegerIsRefusedRatherThanSaturated(): void
    {
        $this->writeEntry('alpha69:big-a', LedgerAccount::VendorEarning, PHP_INT_MAX, 'IRT', 0);
        $this->writeEntry('alpha69:big-b', LedgerAccount::VendorEarning, PHP_INT_MAX, 'IRT', 0);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('total_exceeds_integer_range', $figure['refused']);
        self::assertNull($figure['minor'], 'no figure that could be read as a total');
        self::assertFalse($figure['exact'], 'and never exact');
        self::assertNull($figure['exponent']);
        // The saturated answer `alpha.68` would have given, named so this
        // test cannot quietly start passing for the wrong reason.
        self::assertNotSame(PHP_INT_MAX, $figure['minor']);
    }

    /**
     * ⛔ THE PRODUCTS EACH FIT AND THEIR SUM DOES NOT — the half of the guard
     * `alpha.68` did not have.
     *
     * One group at the finest exponent needs no rescaling at all, so the
     * multiplication guard has nothing to say about it; a second group one
     * exponent coarser scales by ten, fits, and pushes the running total past
     * the end of the range. Half a guard reads exactly like a guard.
     */
    public function testProductsThatEachFitButDoNotAddUpAreRefused(): void
    {
        $this->writeEntry('alpha69:add-a', LedgerAccount::VendorEarning, PHP_INT_MAX - 10, 'IRT', 4);
        $this->writeEntry('alpha69:add-b', LedgerAccount::VendorEarning, 5, 'IRT', 3);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('total_exceeds_integer_range', $figure['refused']);
        self::assertNull($figure['minor']);
        self::assertFalse($figure['exact']);
    }

    /** And the same in the other direction, which is a separate branch. */
    public function testATotalBelowTheRangeIsRefusedToo(): void
    {
        $this->writeEntry('alpha69:neg-a', LedgerAccount::VendorEarning, PHP_INT_MIN + 10, 'IRT', 4);
        $this->writeEntry('alpha69:neg-b', LedgerAccount::VendorEarning, -5, 'IRT', 3);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('total_exceeds_integer_range', $figure['refused']);
        self::assertNull($figure['minor']);
        self::assertFalse($figure['exact']);
    }

    /**
     * A LARGE NEGATIVE GROUP IS REFUSED BY NAME — and this one is a CONTROL.
     *
     * Measured, not assumed: `alpha.68` answers this case correctly too,
     * because `abs(PHP_INT_MIN + 1)` is an ordinary integer. The run proved
     * it — this test passed on both trees while three others failed — so it
     * is named a control rather than left looking like a fix. What `alpha.68`
     * could not survive is `PHP_INT_MIN` itself, which the next test
     * measures.
     */
    public function testTheMostNegativeAmountIsRefusedByNameRatherThanThrowing(): void
    {
        // `PHP_INT_MIN + 1` is representable both ways, so this really does
        // reach the lower-bound comparison rather than being turned away by
        // the text conversion.
        $this->writeEntry('alpha69:min-a', LedgerAccount::VendorEarning, PHP_INT_MIN + 1, 'IRT', 0);
        $this->writeEntry('alpha69:min-b', LedgerAccount::VendorEarning, 1, 'IRT', 4);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('scale_exceeds_integer_range', $figure['refused']);
        self::assertNull($figure['minor']);
        self::assertFalse($figure['exact']);
    }

    /**
     * ⛔ AND `PHP_INT_MIN` ITSELF CRASHED THE OLD GUARD — measured.
     *
     * `alpha.68` asked `intdiv(PHP_INT_MAX, abs($minor))`, and
     * `abs(PHP_INT_MIN)` has no integer answer: PHP returns a FLOAT, and
     * `intdiv()` with a float argument is a `TypeError`. Run against
     * `alpha.68`'s bytes this test ERRORS — the guard written to prevent a
     * wrong figure took the page down instead, on a value the column can
     * hold. Comparing against the two bounds needs no negation at all.
     *
     * Here it is refused by name: the digit round-trip
     * `DecimalAmount::toMinor()` uses cannot confirm a magnitude with no
     * positive counterpart, so it answers `null` and the group is reported
     * out of range instead of summed. One representable value refused is the
     * conservative direction — a refusal is recoverable, a wrong total is
     * not — and no row this plugin writes can hold it.
     */
    public function testTheExtremeNegativeValueIsReportedOutOfRangeNotSummed(): void
    {
        $this->writeEntry('alpha69:floor', LedgerAccount::VendorEarning, PHP_INT_MIN, 'IRT', 0);

        $figure = $this->ledger->balances(self::VENDOR)[LedgerAccount::VendorEarning->value];

        self::assertSame('total_exceeds_integer_range', $figure['refused']);
        self::assertNull($figure['minor']);
        self::assertFalse($figure['exact']);
    }

    /** One ledger row, in a named unit. */
    private function writeEntry(
        string $eventKey,
        LedgerAccount $account,
        int $minor,
        string $currency,
        int $exponent
    ): void {
        $table = M0004CreateFinanceTables::table($this->db, M0004CreateFinanceTables::LEDGER);
        $written = $this->db->execute(
            'INSERT INTO `' . $table . '`
             (event_key, vendor_user_id, account, amount_minor, currency, exponent,
              order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
             VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, NULL, %s, %s)',
            [
                $eventKey,
                self::VENDOR,
                $account->value,
                $minor,
                $currency,
                $exponent,
                'alpha68',
                '',
                'test',
                '{}',
                $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
        self::assertNotNull($written, 'the fixture row has to land before anything is measured');
    }

    public function testARuleWithNoRateIsNotARuleWithZero(): void
    {
        self::assertFalse($this->rules->rate(RateScope::General, 'general')->isSet());

        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(0));
        $stored = $this->rules->rate(RateScope::General, 'general');
        self::assertTrue($stored->isSet());
        self::assertTrue($stored->isZero());

        $this->rules->clearRate(RateScope::General, 'general');
        self::assertFalse($this->rules->rate(RateScope::General, 'general')->isSet(), 'clearing restores inheritance');
    }

    public function testTheNarrowestRuleWinsAndTheSourceIsRecorded(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $this->rules->setRate(RateScope::Vendor, (string) self::VENDOR, CommissionRate::ofBasisPoints(800));
        $this->rules->setRate(RateScope::Product, '55', CommissionRate::ofBasisPoints(500));
        $resolver = new ResolveCommissionRate($this->rules);

        $product = $resolver->forItem(['product' => '55', 'vendor' => (string) self::VENDOR]);
        self::assertSame(500, $product['rate']->basisPoints);
        self::assertSame('product', $product['source']);

        $vendor = $resolver->forItem(['product' => '56', 'vendor' => (string) self::VENDOR]);
        self::assertSame(800, $vendor['rate']->basisPoints);
        self::assertSame('vendor', $vendor['source']);

        $general = $resolver->forItem(['product' => '56', 'vendor' => '99']);
        self::assertSame(1000, $general['rate']->basisPoints);
        self::assertSame('general', $general['source']);
    }

    public function testWithNoRuleAnywhereNothingIsRecordedAtAll(): void
    {
        $outcome = $this->accrual->accrue('evt-none', self::VENDOR, 'order-1', 'item-1', Money::of(900000), ['vendor' => (string) self::VENDOR]);

        self::assertFalse($outcome->isCalculated());
        self::assertSame('rate_unset', $outcome->reason);
        self::assertSame([], $this->ledger->forEvent('evt-none'), 'an unconfigured sale writes no ledger line');
    }

    public function testOnePaidItemBecomesFourBalancedLines(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));

        $outcome = $this->accrual->accrue(
            'order-1:item-1:paid',
            self::VENDOR,
            'order-1',
            'item-1',
            Money::of(900000),
            ['vendor' => (string) self::VENDOR],
            Money::of(81000)
        );

        self::assertTrue($outcome->isCalculated(), $outcome->reason);
        $lines = $this->ledger->forEvent('order-1:item-1:paid');
        self::assertCount(4, $lines);

        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amount->minor;
        }
        self::assertSame(0, $total, 'the accounts of one event must cancel');

        // AT THE FINER UNIT. From `alpha.67` an accrual is recorded at the
        // exponent an exact commission needs — four places past the order's
        // own — so every figure here is the same money times ten thousand.
        // The tax is rescaled into that unit with the base rather than mixed
        // with it, which is what `Money::assertSameUnit()` refused when it
        // was not.
        self::assertSame(4, $lines[0]->amount->exponent, 'one unit for the whole event');
        // `balances()` answers per account with the UNIT beside the figure
        // from `alpha.68`: a bare integer could not say which of a vendor's
        // two exponents it was in (§2).
        $balances = $this->ledger->balances(self::VENDOR);
        $minor = static fn (string $account): int => (int) ($balances[$account]['minor'] ?? 0);
        self::assertSame(9810000000, $minor(LedgerAccount::CentralPayment->value), 'goods plus tax came in centrally');
        self::assertSame(-900000000, $minor(LedgerAccount::Commission->value));
        self::assertSame(-8100000000, $minor(LedgerAccount::VendorEarning->value));
        self::assertSame(-810000000, $minor(LedgerAccount::TaxCollected->value), 'tax is never inside the commission base');
        self::assertSame(4, $balances[LedgerAccount::Commission->value]['exponent'], 'and the unit is stated');
        self::assertSame('', $balances[LedgerAccount::Commission->value]['refused']);
    }

    public function testTheSameEventTwiceRecordsOnce(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $args = ['order-2:item-1:paid', self::VENDOR, 'order-2', 'item-1', Money::of(500000), ['vendor' => (string) self::VENDOR]];

        $first = $this->accrual->accrue(...$args);
        $second = $this->accrual->accrue(...$args);

        self::assertTrue($first->isCalculated());
        // The money is recorded ONCE — that is the whole claim and it is
        // unchanged: three lines, one commission balance.
        self::assertCount(3, $this->ledger->forEvent('order-2:item-1:paid'));
        self::assertSame(-500000000, $this->ledger->balances(self::VENDOR)[LedgerAccount::Commission->value]['minor']);

        // What changed in `alpha.39` is the ANSWER to the second call. It used
        // to be `needsConfiguration('already_recorded')` — not calculated —
        // and `CaptureOrder` read «not calculated» as «no figures», wrote the
        // order line with a null commission, a null share and an empty event
        // reference, and then skipped that line for ever because a row
        // existed. The ledger held the money and the line said it did not.
        //
        // Now the second call RECOVERS the figures off the event that is
        // already there, so a retry can write a correct line. The reason still
        // says where they came from, and the figures are byte-for-byte the
        // first call's — recovered, never recomputed at today's rate.
        self::assertTrue($second->isCalculated(), 'the figures are on disk; the answer must carry them');
        self::assertTrue($second->isRecovered(), 'and must say it read them rather than calculating them');
        self::assertSame(CommissionOutcome::ALREADY_RECORDED, $second->reason);
        self::assertSame($first->base?->minor, $second->base?->minor, 'base recovered from item_paid minus tax');
        self::assertSame($first->commission?->minor, $second->commission?->minor);
        self::assertSame($first->vendorShare?->minor, $second->vendorShare?->minor);
        self::assertSame($first->base?->currency, $second->base?->currency);
        self::assertSame($first->base?->exponent, $second->base?->exponent);
        // And the rate behind them, because the snapshot column is written now.
        self::assertSame(1000, $second->snapshot?->rateBasisPoints, 'the recorded rate, not a rate resolved today');
    }

    /**
     * WHAT THIS PROVES: a ledger write that genuinely FAILED is not reported
     * as «already recorded».
     *
     * The two were one answer until `alpha.39`, and they call for opposite
     * actions: one means «your figures are safe, use them», the other means
     * «nothing was written, do not pretend a sale was captured».
     */
    public function testAFailedLedgerWriteIsNotReportedAsAlreadyRecorded(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $gate = new \Tecteb\Marketplace\Tests\Support\FailingDatabase($this->db);
        $gate->failWhen(['INSERT INTO', 'tmc_ledger']);
        // The rate still comes from the real database — only the ledger write
        // is refused, which is the one statement this test is about.
        $accrual = new RecordCommission(
            new DbLedgerRepository($gate, $this->clock),
            new ResolveCommissionRate($this->rules),
            new CommissionCalculator(),
            $this->logger
        );

        $outcome = $accrual->accrue('order-9:item-1:paid', self::VENDOR, 'order-9', 'item-1', Money::of(500000), ['vendor' => (string) self::VENDOR]);

        self::assertNotSame([], $gate->refused, 'the injected failure never fired: this test proved nothing');
        self::assertFalse($outcome->isCalculated());
        self::assertSame('ledger_unwritable', $outcome->reason, 'not «already_recorded»: nothing is recorded');
        self::assertSame([], $this->ledger->forEvent('order-9:item-1:paid'));
    }

    public function testAnEventKeyIsRequired(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));

        $outcome = $this->accrual->accrue('  ', self::VENDOR, 'order-3', 'item-1', Money::of(1000), []);

        self::assertFalse($outcome->isCalculated());
        self::assertSame('event_key_required', $outcome->reason);
    }

    public function testLedgerRowsAreOnlyEverAdded(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $this->accrual->accrue('order-4:item-1:paid', self::VENDOR, 'order-4', 'item-1', Money::of(100000), []);

        // The repository exposes no update and no delete — the correction path
        // is another row, which is what §4.4 requires.
        $methods = get_class_methods(DbLedgerRepository::class);
        self::assertSame([], array_values(array_filter(
            $methods,
            static fn (string $m): bool => str_contains(strtolower($m), 'update') || str_contains(strtolower($m), 'delete')
        )));
    }
}
