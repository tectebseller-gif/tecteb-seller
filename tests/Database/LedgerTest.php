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

        $balances = $this->ledger->balances(self::VENDOR);
        self::assertSame(981000, $balances[LedgerAccount::CentralPayment->value], 'goods plus tax came in centrally');
        self::assertSame(-90000, $balances[LedgerAccount::Commission->value]);
        self::assertSame(-810000, $balances[LedgerAccount::VendorEarning->value]);
        self::assertSame(-81000, $balances[LedgerAccount::TaxCollected->value], 'tax is never inside the commission base');
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
        self::assertSame(-50000, $this->ledger->balances(self::VENDOR)[LedgerAccount::Commission->value]);

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
