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

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach (M0004CreateFinanceTables::TABLES as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->rules = new DbCommissionRuleRepository($db, $clock);
        $this->accrual = new RecordCommission(
            $this->ledger,
            new ResolveCommissionRate($this->rules),
            new CommissionCalculator(),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock)
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
        self::assertFalse($second->isCalculated(), 'a repeated callback must not double the money');
        self::assertSame('already_recorded', $second->reason);
        self::assertCount(3, $this->ledger->forEvent('order-2:item-1:paid'));
        self::assertSame(-50000, $this->ledger->balances(self::VENDOR)[LedgerAccount::Commission->value]);
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
