<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows as MoneyUnit;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;
use Tecteb\Marketplace\Tests\Support\FakeTectebCommissionSource;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbShipmentRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0008ShipmentsAndReturns;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\PurchaseGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;
use Tecteb\Marketplace\Tests\Support\FakeWcProduct;
use Tecteb\Marketplace\Tests\Support\RecordingAuditRepository;

/**
 * A multi-vendor basket, on real MariaDB.
 *
 * What is asserted here is the sentence the owner wrote: an order in the new
 * module only becomes operational if the financial share and the ledger are
 * recorded correctly — and each vendor, with their staff, sees only their own
 * items.
 */
final class OrderFlowTest extends DatabaseTestCase
{
    private const VENDOR_A = 61;
    private const VENDOR_B = 62;
    private const STAFF_A = 63;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbOrderItemRepository $orderItems;
    private DbCommissionRuleRepository $rules;
    private DbLedgerRepository $ledger;
    private DbStaffRepository $staff;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private CaptureOrder $capture;
    private ManageOrderItems $orders;
    private StaffAccess $access;
    private ShipItems $ship;
    private PurchasePolicy $purchase;
    private DbVendorRepository $vendors;
    private FakeCatalogProjector $storefront;
    private FakeProductImages $images;
    private FakeTrialUnlock $trial;
    /** Kept so one test can rebuild the capture over a database that refuses. */
    private SyncCatalog $catalogService;
    private OrderOperationsGate $orderGate;
    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            ...M0008ShipmentsAndReturns::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);

        $this->products = new DbProductRepository($db, $clock);
        $variations = new DbVariationRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $revisions = new DbProductRevisionRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->staff = new DbStaffRepository($db, $clock);
        $this->orderItems = new DbOrderItemRepository($db, $clock);
        $this->rules = new DbCommissionRuleRepository($db, $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->images = new FakeProductImages();
        $this->storefront = new FakeCatalogProjector();
        $this->trial = new FakeTrialUnlock(true);

        $access = new StaffAccess($this->staff, $this->vendors);
        $this->access = $access;
        $readiness = new ProductReadiness($templates, $variations);
        $catalog = new SyncCatalog($this->products, $variations, $this->storefront, $audit);
        $this->catalogService = $catalog;
        $this->logger = $audit;
        $publishing = new ProductPublishPolicy($options);
        $rates = new ResolveCommissionRate($this->rules);
        $gate = new OrderOperationsGate($rates, $this->ledger, $this->trial);
        $this->orderGate = $gate;

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $revisions,
            $readiness,
            $catalog,
            $this->images,
            $access,
            $publishing,
            new ProductStateMachine(),
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $revisions,
            $templates,
            $readiness,
            $catalog,
            $publishing,
            new ProductStateMachine(),
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS])
        );
        $this->capture = new CaptureOrder(
            $this->orderItems,
            $this->products,
            new RecordCommission($this->ledger, $rates, new CommissionCalculator(), $audit),
            $catalog,
            $gate,
            $audit,
            // The vendor money unit, recorded against a primary key: a capture
            // without it refuses every line as `unit_unreadable` rather than
            // assuming, which is the posture `alpha.40` chose.
            new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), new SystemClock()),
            // The unit of work the claim and the accrual share.
            new WpDatabase($this->wpdb)
        );
        $this->orders = new ManageOrderItems(
            $this->orderItems,
            $access,
            $audit,
            $clock,
            new OrderItemStateMachine()
        );
        $this->ship = new ShipItems(
            $this->orderItems,
            new DbShipmentRepository($db, $clock),
            $access,
            $audit,
            $clock
        );
        $this->purchase = new PurchasePolicy($this->products, $access, $gate);

        $this->vendors->upsertProfile(self::VENDOR_A, 'داروخانه یک', true, false);
        $this->vendors->upsertProfile(self::VENDOR_B, 'داروخانه دو', true, false);
        // A sample rate, so the trial can run the whole path (FIN-02 forbids
        // guessing one; this one is set on purpose, in a disposable database).
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
    }

    public function testAMultiVendorBasketBecomesOneLinePerVendorWithItsOwnShare(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $b = $this->publish(self::VENDOR_B, 'ماسک سه‌لایه', 'B-1');

        $report = $this->capture->capture(5001, [
            $this->line(11, $a, 2, 400000),
            $this->line(12, $b, 1, 150000),
            // A product the marketplace does not own: the shop's own, or
            // Dokan's. It must be skipped, not recorded and not touched.
            ['order_item_id' => 13, 'wc_product_id' => 999999, 'variation_id' => null, 'title' => 'کالای فروشگاه', 'sku' => 'SHOP-1', 'quantity' => 1, 'line_total_minor' => 90000, 'line_tax_minor' => 0],
        ]);

        self::assertSame(2, $report['captured']);
        self::assertSame(1, $report['skipped'], 'a non-marketplace line is none of our business');
        self::assertSame(0, $report['unrecorded']);

        $linesA = $this->orderItems->forVendor(self::VENDOR_A);
        $linesB = $this->orderItems->forVendor(self::VENDOR_B);
        self::assertCount(1, $linesA);
        self::assertCount(1, $linesB);
        self::assertSame(4000000000, $linesA[0]->baseMinor);
        self::assertSame(400000000, $linesA[0]->commissionMinor, 'ten percent of the line, after discount, before tax');
        self::assertSame(3600000000, $linesA[0]->vendorShareMinor);
        self::assertSame(1000, $linesA[0]->rateBasisPoints, 'the rate is snapshotted with the sale');
        self::assertSame(150000000, $linesB[0]->commissionMinor);
    }

    public function testTheLedgerHoldsTheMoneyAndARetryWritesNothingTwice(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $lines = [$this->line(21, $a, 1, 500000)];

        $this->capture->capture(5002, $lines);
        $first = $this->ledger->forEvent(CaptureOrder::eventKey(5002, 21));
        self::assertNotSame([], $first, 'the sale reached the ledger');

        // `balances()` answers per account with the unit beside the figure
        // from `alpha.68`: a bare integer could not say which of a vendor's
        // two exponents it was in (§2).
        $balances = $this->ledger->balances(self::VENDOR_A);
        self::assertSame(-4500000000, $balances[LedgerAccount::VendorEarning->value]['minor'] ?? 0);
        self::assertSame(-500000000, $balances[LedgerAccount::Commission->value]['minor'] ?? 0);

        // The checkout hook fires again — a retried payment callback, a second
        // WooCommerce hook for the same order.
        $again = $this->capture->capture(5002, $lines);
        self::assertSame(0, $again['captured'], 'nothing is captured twice');
        self::assertCount(count($first), $this->ledger->forEvent(CaptureOrder::eventKey(5002, 21)));
        self::assertCount(1, $this->orderItems->forVendor(self::VENDOR_A));
    }

    /**
     * WHAT THIS PROVES: the ledger write succeeds, storing the LINE fails, and
     * the retry ends with exactly one financial event and one line that
     * carries its share, its rate and its event reference.
     *
     * **The trap this closes.** On `alpha.38` the sequence was:
     *
     *   1. the commission reaches the ledger;
     *   2. `items->record()` fails — and `CaptureOrder` never looked at the
     *      answer, so `captured` counted the sale anyway;
     *   3. the retry's ledger write is refused by the unique index;
     *   4. `RecordCommission` called that `needsConfiguration('already_recorded')`;
     *   5. so the line was written with a NULL commission, a NULL share and an
     *      empty event reference;
     *   6. and every later attempt skipped the line, because a row existed.
     *
     * The money was in the ledger and the line said it was unknown — for ever.
     * Four separate answers had to become four separate answers for this to
     * work, and the end state is what this asserts.
     */
    public function testALedgerWriteThatSucceedsSurvivesAFailedLineWriteAndItsRetry(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $lines = [$this->line(41, $a, 1, 500000)];
        $eventKey = CaptureOrder::eventKey(5010, 41);

        // Step 1 and 2: the ledger takes it, the line does not.
        $gate = new FailingDatabase(new WpDatabase($this->wpdb));
        $gate->failWhen(['INSERT INTO', M0006CatalogAndOrders::ORDER_ITEMS]);
        $report = $this->captureOver($gate)->capture(5010, $lines);

        self::assertNotSame([], $gate->refused, 'the injected failure never fired: this test proved nothing');
        self::assertSame(0, $report['captured'], 'a line that was not stored is not a captured sale');
        self::assertSame(1, $report['failed'], 'and the count says which way it went');
        self::assertContains('item_not_stored', $report['reasons']);
        self::assertNotSame([], $this->ledger->forEvent($eventKey), 'the money IS recorded');
        self::assertSame([], $this->orderItems->forVendor(self::VENDOR_A), 'and the line is not');

        // Step 3 to 6: the retry. The ledger refuses a duplicate; the figures
        // are recovered off the event that is already there.
        $retry = $this->capture->capture(5010, $lines);

        self::assertSame(1, $retry['captured'], 'the retry is the thing that fixes it');
        self::assertSame(0, $retry['unrecorded'], 'the figures were recovered, not lost');
        self::assertSame(0, $retry['failed']);

        // Exactly one financial event, with nothing doubled.
        self::assertCount(3, $this->ledger->forEvent($eventKey));
        // `balances()` answers per account with the unit beside the figure
        // from `alpha.68`: a bare integer could not say which of a vendor's
        // two exponents it was in (§2).
        $balances = $this->ledger->balances(self::VENDOR_A);
        self::assertSame(-4500000000, $balances[LedgerAccount::VendorEarning->value]['minor'] ?? 0);
        self::assertSame(-500000000, $balances[LedgerAccount::Commission->value]['minor'] ?? 0);

        // And exactly one line, which knows what it is worth.
        $stored = $this->orderItems->forVendor(self::VENDOR_A);
        self::assertCount(1, $stored);
        self::assertSame(500000000, $stored[0]->commissionMinor, 'the commission recorded at the time');
        self::assertSame(4500000000, $stored[0]->vendorShareMinor);
        self::assertSame(1000, $stored[0]->rateBasisPoints, 'the rate recorded at the time, not resolved again');
        self::assertSame($eventKey, $stored[0]->ledgerEvent, 'and it points at its own event');
        self::assertTrue($stored[0]->isRecorded());
    }

    /**
     * WHAT THIS PROVES: a line whose unit the reader could not read exactly is
     * refused by name — nothing is written to the ledger and nothing to the
     * line.
     *
     * «اگر ارز یا مقیاسی پشتیبانی نمی‌شود، صریح و قابل تشخیص رد شود؛ با واحد
     * حدسی ثبت نشود.» The reader names it (`WcOrderReader`, and
     * `OrderMoneyUnitTest` covers that half); this is what the capture does
     * with the name.
     */
    public function testALineWhoseUnitCouldNotBeReadIsRefusedAndNothingIsWritten(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $line = $this->line(51, $a, 1, 500000);
        $line['unit_error'] = 'amount_not_exact';

        $report = $this->capture->capture(5011, [$line]);

        self::assertSame(0, $report['captured']);
        self::assertSame(1, $report['refused_unit']);
        self::assertSame(['amount_not_exact'], $report['reasons']);
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5011, 51)), 'no money was recorded');
        self::assertSame([], $this->orderItems->forVendor(self::VENDOR_A), 'and no line');
    }

    /**
     * WHAT THIS PROVES: a line that carries its OWN currency and exponent is
     * recorded in that unit, not in this method's default.
     */
    public function testALineRecordsTheUnitItCarries(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $line = $this->line(61, $a, 1, 1050);
        $line['currency'] = 'USD';
        $line['exponent'] = 2;

        self::assertSame(1, $this->capture->capture(5012, [$line])['captured']);

        $entries = $this->ledger->forEvent(CaptureOrder::eventKey(5012, 61));
        self::assertNotSame([], $entries);
        foreach ($entries as $entry) {
            self::assertSame('USD', $entry->amount->currency, 'the order own currency reached the ledger');
            // SIX, not two: the order's own two places plus the four an exact
            // commission needs (`CommissionPrecision::exponentFor(2)`), which
            // is exactly `Money`'s ceiling. The CURRENCY is the thing that
            // travels unchanged; the exponent is the arithmetic's.
            self::assertSame(6, $entry->amount->exponent);
        }
        // And the row says the same unit the ledger does.
        $line = $this->orderItems->findByOrderItem(61);
        self::assertSame('USD', $line?->currency);
        self::assertSame(6, $line?->exponent);
    }

    /**
     * WHAT THIS PROVES: a second unit of money is REFUSED, so a vendor's
     * balance is never the sum of two kinds of money.
     *
     * `DbLedgerRepository::balances()` sums `amount_minor` grouped by account
     * and nothing else, while `Money::assertSameUnit()` refuses to combine two
     * units one layer down. Until `alpha.39` the two could not disagree —
     * the capture forced `'IRR', 0` on every row, so the books were uniform
     * because the unit was ignored rather than because it was checked. Now the
     * order's real unit is recorded, and this is the check that keeps the
     * books in one kind of money.
     */
    public function testASecondUnitOfMoneyIsRefusedRatherThanAddedToTheBooks(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');

        // The first sale sets the unit.
        self::assertSame(1, $this->capture->capture(5020, [$this->line(71, $a, 1, 500000)])['captured']);
        self::assertSame(
            [['currency' => 'IRR', 'exponent' => 4]],
            $this->ledger->unitsFor(self::VENDOR_A)
        );

        // A second order in another unit — a currency switch, a multi-currency
        // plugin — is named and nothing is written for it.
        $other = $this->line(72, $a, 1, 1050);
        $other['currency'] = 'USD';
        $other['exponent'] = 2;
        $report = $this->capture->capture(5021, [$other]);

        self::assertSame(0, $report['captured']);
        self::assertSame(1, $report['refused_unit']);
        self::assertSame(['unit_changed'], $report['reasons']);
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5021, 72)));
        self::assertCount(1, $this->orderItems->forVendor(self::VENDOR_A), 'and no second line');
        // The books are still one unit, which is the whole point.
        self::assertSame(
            [['currency' => 'IRR', 'exponent' => 4]],
            $this->ledger->unitsFor(self::VENDOR_A)
        );
    }

    /**
     * WHAT THIS PROVES: a capture against books that already hold TWO units
     * refuses — including in a unit they hold — and names the reason
     * separately from «your books are in another unit».
     *
     * `alpha.39`'s check returned true if ANY stored unit matched, so a mixed
     * ledger went on accepting writes in either of them for ever and nothing
     * ever reported it. Two refusals with two names, because they are two
     * pieces of work: a mixed ledger needs a person, and a changed unit needs
     * the order looked at.
     */
    public function testACaptureAgainstMixedBooksIsRefusedWithItsOwnName(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-12');
        self::assertSame(1, $this->capture->capture(5030, [$this->line(81, $a, 1, 500000)])['captured']);

        // The mixed state, written straight to the ledger: `alpha.40` refuses
        // to create it, so the test has to MEET it.
        $ledgerTable = $this->wpdb->prefix . M0004CreateFinanceTables::LEDGER;
        $this->wpdb->pdo()->exec(
            "UPDATE `{$ledgerTable}` SET currency = 'USD', exponent = 2
              WHERE vendor_user_id = " . self::VENDOR_A . " ORDER BY id ASC LIMIT 1"
        );

        // Even the unit the books hold is refused now.
        $report = $this->capture->capture(5031, [$this->line(82, $a, 1, 300000)]);
        self::assertSame(0, $report['captured']);
        self::assertSame(1, $report['refused_unit']);
        self::assertSame(['books_mixed'], $report['reasons'], 'its own name, not `unit_changed`');
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5031, 82)));
        self::assertNull($this->orderItems->findByOrderItem(82), 'and no line was stored');

        // Reported, with the units named, so a manager meeting a blocked order
        // can see why. Nothing converts anything.
        $mixed = $this->ledger->vendorsWithMixedUnits();
        self::assertSame([self::VENDOR_A], array_column($mixed, 'vendor_user_id'));
        // `IRR/4` because an accrual is recorded at the finer unit now; the
        // report still lists each `currency/exponent` it meets, because a
        // person looking at a blocked order wants to see exactly that.
        self::assertSame('IRR/4,USD/2', $mixed[0]['units']);
    }

    /**
     * WHAT THIS PROVES: a capture that cannot ASK about the unit refuses, and
     * does not read the failure as «the books are empty».
     */
    public function testACaptureWhoseUnitReadFailsRefusesRatherThanAssuming(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-13');

        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        // The needle tracks the code: `alpha.67` rewrote that query to count
        // distinct CURRENCY, so the old needle stopped matching and the
        // injection fired nothing (`alpha.33`). The `$failing->refused`
        // assertion below is what makes a dead needle visible.
        $failing->failReadWhen(['COUNT(DISTINCT `currency`)']);
        $report = $this->captureOverUnits($failing)->capture(5032, [$this->line(83, $a, 1, 500000)]);

        self::assertSame(0, $report['captured']);
        self::assertSame(1, $report['refused_unit']);
        self::assertSame(['unit_unreadable'], $report['reasons']);
        self::assertNotSame([], $failing->refused, 'the injected failure has to have fired');
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5032, 83)));
        self::assertNull($this->orderItems->findByOrderItem(83));
        // And nothing was fixed as a side effect of the failed attempt.
        self::assertSame([], $this->ledger->unitsFor(self::VENDOR_A));
    }

    /**
     * WHAT THIS PROVES: repeated captures in the SAME unit are ordinary.
     *
     * The negative cases above all refuse; without this one, a claim that had
     * become impossible would satisfy every one of them.
     */
    public function testRepeatedCapturesInTheSameUnitAreAllRecorded(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-14');
        foreach ([84, 85, 86] as $seq => $orderItemId) {
            $report = $this->capture->capture(5040 + $seq, [$this->line($orderItemId, $a, 1, 500000)]);
            self::assertSame(1, $report['captured'], 'line ' . $orderItemId);
            self::assertSame(0, $report['refused_unit']);
        }
        self::assertCount(3, $this->orderItems->forVendor(self::VENDOR_A));
        self::assertSame([['currency' => 'IRR', 'exponent' => 4]], $this->ledger->unitsFor(self::VENDOR_A));
        self::assertSame([], $this->ledger->vendorsWithMixedUnits());

        // And the SAME order twice writes nothing new — the event key decides,
        // and the unit claim does not turn a duplicate into a refusal.
        $again = $this->capture->capture(5040, [$this->line(84, $a, 1, 500000)]);
        self::assertSame(0, $again['captured']);
        self::assertSame(0, $again['refused_unit']);
        self::assertSame(0, $again['incomplete'], 'a duplicate callback is not an incomplete line');
        self::assertCount(3, $this->orderItems->forVendor(self::VENDOR_A));
    }

    /**
     * WHAT THIS PROVES: a capture that records NO money does not fix the
     * vendor's currency — found by the automated security review of §4's
     * commit, not by a test.
     *
     * The claim is a write, and this hook runs at CHECKOUT, before anything is
     * paid. §4's first version claimed the unit before the accrual, so a line
     * whose rate cannot be resolved still fixed the vendor's unit for ever —
     * from an order that recorded nothing — and the vendor's first real sale
     * in another unit would then be refused with no way back. The claim and
     * the accrual are one unit of work now, so the claim goes back with it.
     */
    public function testALineThatRecordsNoMoneyDoesNotFixTheVendorsUnit(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-15');
        // No rate resolves, so nothing can be accrued (FIN-02).
        $this->rules->clearRate(RateScope::General, 'general');

        $report = $this->capture->capture(5050, [$this->line(91, $a, 1, 500000)]);
        self::assertSame(1, $report['unrecorded'], 'the line is reported, not dropped');
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5050, 91)));

        // The LINE is still stored — FIN-02: a vendor whose sale produced no
        // share is owed an answer, and the balance counts it.
        $line = $this->orderItems->findByOrderItem(91);
        self::assertNotNull($line, 'an unrecorded line is still a line');
        self::assertNull($line->vendorShareMinor);

        // And the unit is NOT claimed, so a later real sale in another unit is
        // free to set it.
        $units = new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), new SystemClock());
        self::assertNull($units->unitOf(self::VENDOR_A), 'nothing recorded, nothing claimed');

        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $other = $this->line(92, $a, 1, 10050);
        $other['currency'] = 'USD';
        $other['exponent'] = 2;
        self::assertSame(1, $this->capture->capture(5051, [$other])['captured'], 'the first REAL sale sets it');
        self::assertSame(['currency' => 'USD', 'exponent' => 2], $units->unitOf(self::VENDOR_A));
    }

    /**
     * WHAT THIS PROVES: a sound document stays recoverable when the rate that
     * produced it is changed or REMOVED.
     *
     * `alpha.39` resolved the rate, calculated, attempted the write and
     * recovered only when the unique index refused it — so a retry of a line
     * whose money was correctly recorded reported «rate unknown» the moment
     * somebody cleared the rate, about figures sitting in the ledger. The
     * existing event is asked FIRST now, before anything about today's
     * configuration is consulted.
     */
    public function testARecordedEventIsRecoverableAfterItsRateIsRemoved(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-16');
        $lines = [$this->line(93, $a, 1, 500000)];
        $eventKey = CaptureOrder::eventKey(5052, 93);

        // The ledger takes it; the line write fails.
        $gate = new FailingDatabase(new WpDatabase($this->wpdb));
        $gate->failWhen(['INSERT INTO', M0006CatalogAndOrders::ORDER_ITEMS]);
        self::assertSame(1, $this->captureOver($gate)->capture(5052, $lines)['failed']);
        self::assertCount(3, $this->ledger->forEvent($eventKey), 'the money IS recorded');
        self::assertSame([], $this->orderItems->forVendor(self::VENDOR_A));

        // And THEN the rate is cleared — a manager revisiting the commission
        // rules, with no idea a line is half-written.
        $this->rules->clearRate(RateScope::General, 'general');

        $retry = $this->capture->capture(5052, $lines);
        self::assertSame(1, $retry['captured'], 'the retry still works: reasons ' . implode(',', $retry['reasons']));
        self::assertSame(0, $retry['unrecorded'], 'and does not report «rate unknown» about recorded money');

        $stored = $this->orderItems->forVendor(self::VENDOR_A);
        self::assertCount(1, $stored);
        self::assertSame(4500000000, $stored[0]->vendorShareMinor);
        self::assertSame(500000000, $stored[0]->commissionMinor);
        self::assertSame(1000, $stored[0]->rateBasisPoints, 'the rate recorded at the time, not today(none)');
        self::assertSame($eventKey, $stored[0]->ledgerEvent);
        self::assertCount(3, $this->ledger->forEvent($eventKey), 'nothing doubled');
    }

    /**
     * WHAT THIS PROVES: a retry whose ORDER has changed is refused rather than
     * stored inconsistent with its own ledger entry.
     *
     * `alpha.39` took the share and the commission off the recovered event and
     * `base_minor`, `tax_minor` and `quantity` from the fresh callback. An
     * order edited between the failed line write and the retry — a quantity
     * corrected, a discount applied — produced a line whose base disagreed
     * with the entry it pointed at, and every total built from the two
     * disagreed with itself. `captured` counted 1 and nothing said a word.
     *
     * @dataProvider editsBetweenTheFailureAndTheRetry
     */
    public function testARetryWhoseOrderChangedIsRefusedRatherThanStoredInconsistent(
        int $quantity,
        int $totalMinor,
        int $taxMinor
    ): void {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-17');
        $original = $this->line(94, $a, 1, 500000);
        $original['line_tax_minor'] = 50000;
        $eventKey = CaptureOrder::eventKey(5053, 94);

        $gate = new FailingDatabase(new WpDatabase($this->wpdb));
        $gate->failWhen(['INSERT INTO', M0006CatalogAndOrders::ORDER_ITEMS]);
        self::assertSame(1, $this->captureOver($gate)->capture(5053, [$original])['failed']);
        self::assertCount(4, $this->ledger->forEvent($eventKey), 'base, commission, share and tax');

        // The order is edited, and the retry carries the NEW figures.
        $edited = $original;
        $edited['quantity'] = $quantity;
        $edited['line_total_minor'] = $totalMinor;
        $edited['line_tax_minor'] = $taxMinor;

        $retry = $this->capture->capture(5053, [$edited]);
        self::assertSame(0, $retry['captured'], 'an inconsistent line is not a captured sale');
        self::assertSame(1, $retry['incomplete']);
        self::assertContains('line_mismatch', $retry['reasons']);
        self::assertSame([], $this->orderItems->forVendor(self::VENDOR_A), 'and nothing was stored');
        self::assertCount(4, $this->ledger->forEvent($eventKey), 'the document is untouched');
    }

    /** @return array<string,array{0:int,1:int,2:int}> */
    public static function editsBetweenTheFailureAndTheRetry(): array
    {
        return [
            'the amount changed' => [1, 400000, 50000],
            'the tax changed' => [1, 500000, 40000],
            'the quantity changed with the amount' => [2, 1000000, 100000],
        ];
    }

    /**
     * The positive half: a retry carrying the SAME figures stores the line
     * from the event, and the line agrees with the ledger figure by figure.
     *
     * «خودِ ارقام قلم نهایی با دفترکل مقایسه شوند، نه فقط شمارندهٔ repaired».
     */
    public function testARetryCarryingTheSameFiguresStoresALineThatAgreesWithTheLedger(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-18');
        $lines = [$this->line(95, $a, 1, 500000)];
        $lines[0]['line_tax_minor'] = 50000;
        $eventKey = CaptureOrder::eventKey(5054, 95);

        $gate = new FailingDatabase(new WpDatabase($this->wpdb));
        $gate->failWhen(['INSERT INTO', M0006CatalogAndOrders::ORDER_ITEMS]);
        self::assertSame(1, $this->captureOver($gate)->capture(5054, $lines)['failed']);

        self::assertSame(1, $this->capture->capture(5054, $lines)['captured']);
        $line = $this->orderItems->findByOrderItem(95);
        self::assertNotNull($line);

        // Figure by figure against the ledger, not against the input.
        $byReason = [];
        foreach ($this->ledger->forEvent($eventKey) as $entry) {
            $byReason[$entry->reason] = $entry->amount->minor;
        }
        self::assertSame($byReason['item_paid'], $line->baseMinor + $line->taxMinor, 'base + tax is what was paid');
        self::assertSame(-$byReason['commission_due'], $line->commissionMinor);
        self::assertSame(-$byReason['vendor_earned'], $line->vendorShareMinor);
        self::assertSame(-$byReason['tax_collected'], $line->taxMinor);
        self::assertSame($eventKey, $line->ledgerEvent);
        self::assertSame(1, $line->quantity);
    }

    public function testWithoutAResolvableRateTheLineIsRecordedAsUNRECORDEDRatherThanAsZero(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $this->rules->clearRate(RateScope::General, 'general');

        $report = $this->capture->capture(5003, [$this->line(31, $a, 1, 300000)]);
        self::assertSame(1, $report['unrecorded'], 'FIN-02: no rate is not a zero rate');

        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertNull($line->commissionMinor);
        self::assertNull($line->vendorShareMinor);
        self::assertSame('', $line->ledgerEvent);
        self::assertFalse($line->isRecorded(), 'and it says so, rather than showing zero');
        self::assertSame([], $this->ledger->forEvent(CaptureOrder::eventKey(5003, 31)));
    }

    public function testEachVendorSeesOnlyTheirOwnItems(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $b = $this->publish(self::VENDOR_B, 'ماسک سه‌لایه', 'B-1');
        $this->capture->capture(5004, [$this->line(41, $a, 1, 100000), $this->line(42, $b, 1, 200000)]);

        $seenByA = $this->orders->listFor(self::VENDOR_A, self::VENDOR_A);
        self::assertCount(1, $seenByA);
        self::assertSame('دستکش لاتکس', $seenByA[0]->title);

        // Shop A asking about shop B's shelf gets nothing, not an error page.
        self::assertSame([], $this->orders->listFor(self::VENDOR_A, self::VENDOR_B));

        // And an id from the other shop cannot be moved, either.
        $bItemId = $this->orderItems->forVendor(self::VENDOR_B)[0]->id;
        $stolen = $this->orders->move(self::VENDOR_A, self::VENDOR_A, $bItemId, OrderItemStatus::Preparing);
        self::assertFalse($stolen->ok);
        self::assertSame('not_found', $stolen->code);
    }

    public function testStaffSeeTheirShopsOrdersOnlyWithinTheirOwnRole(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $this->capture->capture(5005, [$this->line(51, $a, 1, 100000)]);
        $itemId = $this->orderItems->forVendor(self::VENDOR_A)[0]->id;

        // Order and shipping: may see and may act.
        $staffId = $this->addStaff(StaffRolePreset::OrderAndShipping);
        $this->staff->activate($staffId);
        self::assertCount(1, $this->orders->listFor(self::STAFF_A, self::VENDOR_A));
        self::assertTrue($this->orders->move(self::STAFF_A, self::VENDOR_A, $itemId, OrderItemStatus::Preparing)->ok);

        // Support: may see and answer, may NOT ship (Master Spec §3.1).
        $this->staff->updateRole($staffId, StaffRolePreset::CustomerSupport, StaffRolePreset::CustomerSupport->permissions());
        self::assertCount(1, $this->orders->listFor(self::STAFF_A, self::VENDOR_A), 'support still sees the order');
        $refused = $this->orders->move(self::STAFF_A, self::VENDOR_A, $itemId, OrderItemStatus::Shipped, 'post');
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);

        // Product and inventory: no order rights at all.
        $this->staff->updateRole($staffId, StaffRolePreset::ProductAndInventory, StaffRolePreset::ProductAndInventory->permissions());
        self::assertSame([], $this->orders->listFor(self::STAFF_A, self::VENDOR_A));
    }

    public function testShippingNeedsACarrierAndTheStatusOnlyMovesForward(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $this->capture->capture(5006, [$this->line(61, $a, 1, 100000)]);
        $itemId = $this->orderItems->forVendor(self::VENDOR_A)[0]->id;

        self::assertSame('invalid_transition', $this->orders->move(self::VENDOR_A, self::VENDOR_A, $itemId, OrderItemStatus::Delivered)->code);
        self::assertTrue($this->orders->move(self::VENDOR_A, self::VENDOR_A, $itemId, OrderItemStatus::Preparing)->ok);

        // Shipping is a quantity, so it goes through ShipItems; the status
        // move refuses and says where to go instead.
        self::assertSame(
            'use_shipment',
            $this->orders->move(self::VENDOR_A, self::VENDOR_A, $itemId, OrderItemStatus::Shipped)->code
        );
        $noCarrier = $this->ship->ship(self::VENDOR_A, self::VENDOR_A, $itemId, 1, '');
        self::assertFalse($noCarrier->ok);
        self::assertSame('carrier_required', $noCarrier->code);

        self::assertTrue($this->ship->ship(self::VENDOR_A, self::VENDOR_A, $itemId, 1, 'post', 'TRK-1')->ok);
        $shipped = $this->orderItems->find($itemId);
        self::assertSame('post', $shipped?->carrier);
        self::assertSame('TRK-1', $shipped?->trackingCode);
        self::assertNotNull($shipped?->shippedAt);

        // A shipped item cannot be cancelled: the package is already gone.
        self::assertSame('invalid_transition', $this->orders->move(self::VENDOR_A, self::VENDOR_A, $itemId, OrderItemStatus::Cancelled)->code);
        self::assertTrue($this->orders->move(self::VENDOR_A, self::VENDOR_A, $itemId, OrderItemStatus::Delivered)->ok);
    }

    public function testASuspendedShopAndAnEmptyShelfBothStopTheSale(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $wcId = (int) $this->products->find($a)?->wcProductId;
        self::assertTrue($this->purchase->allows($wcId), 'a live product of a trading shop sells');

        // Zero stock: not «موجودی کم», not a warning — it stops the purchase.
        //
        // Drained through the SHOP rather than through the vendor's form. From
        // `alpha.48` a live product's stock edit is a proposal the manager
        // approves, and the thing under test here is the purchase policy, not
        // who may change a number: the shelf emptying because the last units
        // sold is WooCommerce's own cycle, which never waits for anybody.
        $this->storefront->restock($a, 0);
        self::assertSame(0, $this->catalogService->pullStock($a));
        $fresh = new PurchasePolicy($this->products, new StaffAccess($this->staff, $this->vendors), new OrderOperationsGate(
            new ResolveCommissionRate($this->rules),
            $this->ledger,
            $this->trial
        ));
        self::assertSame(PurchasePolicy::OUT_OF_STOCK, $fresh->decide($wcId)['decision']);

        // Suspending the shop stops everything it sells, at once. (Restocked
        // the same way it was drained, and for the same reason.)
        $this->storefront->restock($a, 9);
        self::assertSame(9, $this->catalogService->pullStock($a));
        $this->vendors->upsertProfile(self::VENDOR_A, 'داروخانه یک', false, false);
        $afterSuspension = new PurchasePolicy($this->products, new StaffAccess($this->staff, $this->vendors), new OrderOperationsGate(
            new ResolveCommissionRate($this->rules),
            $this->ledger,
            $this->trial
        ));
        self::assertSame(PurchasePolicy::VENDOR_STOPPED, $afterSuspension->decide($wcId)['decision']);
    }

    public function testWhenTheMarketplaceMayNotSellItsOwnProductsStopButNobodyElsesDo(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $wcId = (int) $this->products->find($a)?->wcProductId;

        // The trial switch goes off: the decisions are open again.
        $blocked = new PurchasePolicy($this->products, new StaffAccess($this->staff, $this->vendors), new OrderOperationsGate(
            new ResolveCommissionRate($this->rules),
            $this->ledger,
            new FakeTrialUnlock(false)
        ));
        self::assertSame(PurchasePolicy::ORDERS_BLOCKED, $blocked->decide($wcId)['decision']);

        // And a product that is not the marketplace's is unaffected — the
        // answer is NOT_OURS, which the guard turns into "change nothing".
        self::assertSame(PurchasePolicy::NOT_OURS, $blocked->decide(999999)['decision']);
        self::assertFalse($blocked->isOurs(999999));
    }

    /**
     * The refusal has to reach the shopper in the marketplace's own words.
     *
     * WooCommerce checks `is_purchasable()` ITSELF, before it ever runs the
     * add-to-cart validation filter, and throws its own generic English
     * sentence. Found by adding a refused product to a basket in a browser:
     * a Persian marketplace told the shopper «Sorry, this product cannot be
     * purchased» and named no reason. The two message filters fix that — and
     * must stay silent about everybody else's catalogue.
     */
    public function testTheRefusalSentenceIsOursForOurProductsAndNobodyElsesEver(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $wcId = (int) $this->products->find($a)?->wcProductId;

        $container = new Container();
        $container->bind(PurchasePolicy::class, fn () => new PurchasePolicy(
            $this->products,
            new StaffAccess($this->staff, $this->vendors),
            new OrderOperationsGate(new ResolveCommissionRate($this->rules), $this->ledger, new FakeTrialUnlock(false))
        ));
        $container->bind(AuditLogger::class, fn () => new AuditLogger(new RecordingAuditRepository(), new SystemClock()));
        PurchaseGuard::register($container);

        $wooCommercesOwnWords = 'Sorry, this product cannot be purchased.';
        $ours = apply_filters('woocommerce_cart_product_cannot_be_purchased_message', $wooCommercesOwnWords, new FakeWcProduct($wcId));
        self::assertSame(PurchaseMessages::shopper(PurchasePolicy::ORDERS_BLOCKED), (string) $ours);

        // …and it is SHORT, and says nothing about the marketplace's own
        // affairs. The rate, the gate and the open decisions are the
        // manager's to see; a shopper who is told which DEC is unresolved has
        // been handed somebody else's problem.
        foreach (['DEC-', 'کمیسیون', 'دفترکل', 'نرخ'] as $internal) {
            self::assertStringNotContainsString($internal, (string) $ours, 'the shopper is not told about ' . $internal);
        }
        self::assertLessThanOrEqual(60, mb_strlen((string) $ours), 'one short sentence');

        // The manager's version of the same event names the reason.
        self::assertStringContainsString(
            'کمیسیون',
            PurchaseMessages::manager(PurchasePolicy::ORDERS_BLOCKED),
            'the manager is told what the shopper was not'
        );

        // The one decision with nothing to say says nothing. Every caller
        // checks for ALLOWED before asking — but a catch-all that answered
        // «این کالا قابل خرید نیست» for a product that MAY be bought is a
        // sentence one forgotten guard away from a shopper's screen.
        self::assertSame('', PurchaseMessages::shopper(PurchasePolicy::ALLOWED));
        self::assertNotSame(
            '',
            PurchaseMessages::shopper('something_this_version_does_not_know'),
            'an unknown code still gets an answer; silence is only for «allowed»'
        );

        // The shop's own product and Dokan's keep the message they had.
        $theirs = apply_filters('woocommerce_cart_product_cannot_be_purchased_message', $wooCommercesOwnWords, new FakeWcProduct(999999));
        self::assertSame($wooCommercesOwnWords, $theirs);

        $theirStock = apply_filters('woocommerce_cart_product_out_of_stock_message', 'out of stock', new FakeWcProduct(999999));
        self::assertSame('out of stock', $theirStock);
    }

    public function testTheVendorsTotalsCountOnlyWhatWasActuallyRecorded(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $this->capture->capture(5008, [$this->line(81, $a, 1, 100000), $this->line(82, $a, 1, 200000)]);

        $totals = $this->orders->totals(self::VENDOR_A, self::VENDOR_A);
        self::assertSame(2, $totals['lines']);
        self::assertSame(3000000000, $totals['base']);
        self::assertSame(300000000, $totals['commission']);
        self::assertSame(2700000000, $totals['share']);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{order_item_id:int, wc_product_id:int, variation_id:null, title:string, sku:string, quantity:int, line_total_minor:int, line_tax_minor:int} */
    /**
     * The same capture, with ORDER-ITEM storage pointed at a database that
     * refuses — and the ledger left on the real one.
     *
     * That asymmetry is the test: «the money was recorded and the line was
     * not» is the state `alpha.38` could not recover from, and it cannot be
     * built by failing everything.
     */
    /**
     * The row `alpha.38` could leave on disk, and the remedy for it.
     *
     * A retry fixes the half-written case where the LINE was never stored
     * (`testALedgerWriteThatSucceedsSurvivesAFailedLineWriteAndItsRetry`). It
     * cannot fix the other half: a line that WAS stored, with a null share and
     * an empty event reference, beside a perfectly good ledger event. Every
     * later callback skipped it because the row existed — «رکوردهای نیمه‌تمام
     * موجود نباید بی‌صدا نادیده گرفته شوند».
     *
     * This builds that row the way `alpha.38` built it — the ledger first, the
     * line with its figures nulled — and then fires the hook again.
     */
    public function testAHalfWrittenLineIsRepairedFromItsOwnLedgerEventAndNotFromTodaysRate(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-7');
        $lines = [$this->line(47, $a, 1, 500000)];
        $eventKey = CaptureOrder::eventKey(5016, 47);

        // The accrual lands.
        self::assertSame(1, $this->capture->capture(5016, $lines)['captured']);
        $stored = $this->orderItems->forVendor(self::VENDOR_A);
        self::assertCount(1, $stored);
        $itemId = $stored[0]->id;

        // And then the row is damaged into exactly `alpha.38`'s end state.
        // Written with SQL on purpose: this is MEETING a legacy state, not
        // making one — no code in `alpha.39` can produce it any more, so there
        // is no service path that would.
        $this->wpdb->pdo()->exec(
            'UPDATE `' . $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS . "`
             SET commission_minor = NULL, vendor_share_minor = NULL, rate_bp = NULL,
                 rate_source = '', ledger_event = '' WHERE id = {$itemId}"
        );
        self::assertNull($this->orderItems->find($itemId)?->vendorShareMinor, 'the legacy state is in place');

        // The rate changes in between — so a repair that RESOLVED a rate would
        // be visibly wrong, and a repair that READ the event would not.
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(2500));

        $again = $this->capture->capture(5016, $lines);
        self::assertSame(0, $again['captured'], 'nothing new was captured');
        self::assertSame(1, $again['repaired'], 'the existing line was finished');
        self::assertSame(0, $again['incomplete']);
        self::assertContains('line_repaired', $again['reasons']);

        $fixed = $this->orderItems->find($itemId);
        self::assertSame(4500000000, $fixed?->vendorShareMinor, 'the share recorded at the time');
        self::assertSame(500000000, $fixed?->commissionMinor);
        self::assertSame(1000, $fixed?->rateBasisPoints, 'the OLD rate: history is not rewritten');
        self::assertSame($eventKey, $fixed?->ledgerEvent);
        self::assertTrue($fixed?->isRecorded());

        // One event, nothing doubled, and the vendor's balance is the one
        // figure the books hold.
        self::assertCount(3, $this->ledger->forEvent($eventKey));
        self::assertSame(
            -4500000000,
            $this->ledger->balances(self::VENDOR_A)[LedgerAccount::VendorEarning->value]['minor'] ?? 0
        );

        // A third callback has nothing left to do and says nothing.
        $third = $this->capture->capture(5016, $lines);
        self::assertSame(0, $third['repaired']);
        self::assertSame(0, $third['incomplete']);
        self::assertSame([], $third['reasons']);
    }

    public function testAHalfWrittenLineWithNoEventIsNamedRatherThanSkipped(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-8');
        $lines = [$this->line(48, $a, 1, 500000)];

        self::assertSame(1, $this->capture->capture(5017, $lines)['captured']);
        $itemId = (int) $this->orderItems->forVendor(self::VENDOR_A)[0]->id;

        // This time the LEDGER is emptied too: a line with nothing to recover
        // from. That is a decision for a person (FIN-02 forbids a guessed
        // rate), and the one thing it must not be is silence.
        $this->wpdb->pdo()->exec(
            'UPDATE `' . $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS . "`
             SET commission_minor = NULL, vendor_share_minor = NULL, rate_bp = NULL,
                 rate_source = '', ledger_event = '' WHERE id = {$itemId}"
        );
        $this->wpdb->pdo()->exec(
            'DELETE FROM `' . $this->wpdb->prefix . M0004CreateFinanceTables::LEDGER . '`'
        );

        $again = $this->capture->capture(5017, $lines);
        self::assertSame(0, $again['repaired']);
        self::assertSame(1, $again['incomplete']);
        self::assertContains('line_incomplete', $again['reasons']);
        self::assertNull($this->orderItems->find($itemId)?->vendorShareMinor, 'and nothing was invented');
    }

    public function testEveryHalfWrittenLineIsListedForAManagerWithItsRemedy(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-9');
        $recoverable = [$this->line(49, $a, 1, 500000)];
        $hopeless = [$this->line(50, $a, 1, 300000)];
        self::assertSame(1, $this->capture->capture(5018, $recoverable)['captured']);
        self::assertSame(1, $this->capture->capture(5019, $hopeless)['captured']);

        $ids = array_map(static fn ($i): int => $i->id, $this->orderItems->forVendor(self::VENDOR_A));
        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        foreach ($ids as $id) {
            $this->wpdb->pdo()->exec(
                "UPDATE `{$table}` SET vendor_share_minor = NULL, ledger_event = '' WHERE id = {$id}"
            );
        }
        // One of the two loses its event as well.
        $this->wpdb->pdo()->exec(
            'DELETE FROM `' . $this->wpdb->prefix . M0004CreateFinanceTables::LEDGER . "` WHERE event_key = '"
            . CaptureOrder::eventKey(5019, 50) . "'"
        );

        $commissions = new RecordCommission(
            $this->ledger,
            new ResolveCommissionRate($this->rules),
            new CommissionCalculator(),
            $this->logger
        );
        // A manager's view of it: `$this->orders` is built without a
        // capability checker, so the gate would refuse — which is itself the
        // right answer and is asserted below.
        $asManager = new ManageOrderItems(
            $this->orderItems,
            $this->access,
            $this->logger,
            new SystemClock(),
            new OrderItemStateMachine(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );
        $report = $asManager->incompleteCaptures($commissions);
        self::assertFalse($this->orders->incompleteCaptures($commissions)['allowed'], 'and not to anybody else');

        self::assertTrue($report['allowed']);
        self::assertCount(2, $report['lines']);
        $byItem = [];
        foreach ($report['lines'] as $line) {
            $byItem[$line['wc_order_item_id']] = $line['remedy'];
        }
        self::assertSame('recoverable', $byItem[49] ?? '', 'its figures are on the books');
        self::assertSame('rate_unknown', $byItem[50] ?? '', 'and this one needs a decision');
    }

    public function testARepairNeverOverwritesALineThatIsAlreadyComplete(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-10');
        self::assertSame(1, $this->capture->capture(5020, [$this->line(51, $a, 1, 500000)])['captured']);
        $item = $this->orderItems->forVendor(self::VENDOR_A)[0];

        // The guard is the incompleteness itself, so a complete row refuses
        // the write rather than taking the figures somebody passed in.
        self::assertFalse($this->orderItems->completeFinancials(
            $item->id,
            999999,
            1,
            9999,
            'invented',
            'order:0:item:0'
        ), 'a complete line is not repairable');
        $after = $this->orderItems->find($item->id);
        self::assertSame(4500000000, $after?->vendorShareMinor);
        self::assertSame(500000000, $after?->commissionMinor);
        self::assertSame(1000, $after?->rateBasisPoints);
    }

    /**
     * The OTHER legacy shape, and the one `alpha.39` wrote over.
     *
     * `alpha.38` could also leave a row holding its figures and its rate with
     * an EMPTY `ledger_event` — the link to the document was the half that did
     * not land. Until `alpha.40` the repair's guard was
     * `vendor_share_minor IS NULL OR ledger_event = ''`, so this row matched on
     * its second half and had its FIGURES overwritten on the way to writing the
     * link. That is recorded money being rewritten by a later pass, which is
     * exactly what «completeFinancials() دادهٔ معتبر موجود را بی‌قیدوشرط
     * بازنویسی نکند» forbids.
     *
     * Here the figures AGREE with the event, so both trees end up correct and
     * this is the control of the pair — stated rather than implied, because a
     * test that passes on `alpha.39` and on `alpha.40` is only useful when it
     * says which it is. The falsifier is the test below it, where the figures
     * disagree and the old guard rewrote recorded money.
     */
    public function testALineThatHasItsFiguresAndNotItsEventKeepsTheFigures(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-12');
        $lines = [$this->line(53, $a, 1, 500000)];
        $eventKey = CaptureOrder::eventKey(5022, 53);
        self::assertSame(1, $this->capture->capture(5022, $lines)['captured']);
        $itemId = (int) $this->orderItems->forVendor(self::VENDOR_A)[0]->id;

        // The link is removed and the rate is left as something the event does
        // NOT say, so an unconditional write would be visible.
        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        $this->wpdb->pdo()->exec(
            "UPDATE `{$table}` SET ledger_event = '', rate_bp = 1234 WHERE id = {$itemId}"
        );

        $again = $this->capture->capture(5022, $lines);
        self::assertSame(1, $again['repaired'], 'the link was written');
        self::assertSame(0, $again['incomplete']);
        self::assertContains('line_repaired', $again['reasons']);

        $fixed = $this->orderItems->find($itemId);
        self::assertSame($eventKey, $fixed?->ledgerEvent, 'the link now points at its own document');
        self::assertSame(4500000000, $fixed?->vendorShareMinor, 'and the recorded share is untouched');
        self::assertSame(500000000, $fixed?->commissionMinor);
        self::assertCount(3, $this->ledger->forEvent($eventKey), 'no second document');
    }

    /**
     * And when the two disagree, nobody here is allowed to pick a winner.
     *
     * A row carrying a share that the ledger does not say is not a row that
     * can be «repaired»: writing the event's figures over it discards recorded
     * money, and writing the link without them stores a line that contradicts
     * the document it points at. Both are the «successful but inconsistent
     * record» §5 forbids, so the capture names the case and changes nothing.
     */
    public function testALineWhoseFiguresContradictItsEventIsNamedForReconciliation(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-13');
        $lines = [$this->line(54, $a, 1, 500000)];
        self::assertSame(1, $this->capture->capture(5023, $lines)['captured']);
        $itemId = (int) $this->orderItems->forVendor(self::VENDOR_A)[0]->id;

        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        $this->wpdb->pdo()->exec(
            "UPDATE `{$table}` SET ledger_event = '', vendor_share_minor = 440000 WHERE id = {$itemId}"
        );

        $again = $this->capture->capture(5023, $lines);
        self::assertSame(0, $again['repaired'], 'this is not repairable');
        self::assertSame(1, $again['incomplete']);
        self::assertContains('line_reconcile_required', $again['reasons']);
        self::assertNotContains('line_repaired', $again['reasons']);

        // Nothing moved: not the share somebody will have to explain, and not
        // the link that would have made the line look settled.
        $after = $this->orderItems->find($itemId);
        self::assertSame(440000, $after?->vendorShareMinor, 'the stored figure is still there to be found');
        self::assertSame('', $after?->ledgerEvent);
        self::assertCount(3, $this->ledger->forEvent(CaptureOrder::eventKey(5023, 54)), 'and one document');
    }

    /**
     * The same guard at the repository, called directly: a figure that
     * disagrees is refused even when the row IS missing its link.
     */
    public function testCompleteFinancialsRefusesToChangeAFigureThatIsAlreadyThere(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-14');
        self::assertSame(1, $this->capture->capture(5024, [$this->line(55, $a, 1, 500000)])['captured']);
        $itemId = (int) $this->orderItems->forVendor(self::VENDOR_A)[0]->id;
        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        $this->wpdb->pdo()->exec("UPDATE `{$table}` SET ledger_event = '' WHERE id = {$itemId}");

        self::assertFalse($this->orderItems->completeFinancials(
            $itemId,
            60000,
            440000,
            1200,
            'invented',
            CaptureOrder::eventKey(5024, 55)
        ), 'a different share is not a repair');
        $after = $this->orderItems->find($itemId);
        self::assertSame(4500000000, $after?->vendorShareMinor);
        self::assertSame(500000000, $after?->commissionMinor);
        self::assertSame('', $after?->ledgerEvent, 'and the link was not written either');

        // The same call with the figures the row holds writes only the link.
        // The figures the row holds — at the unit the row holds them in.
        self::assertTrue($this->orderItems->completeFinancials(
            $itemId,
            500000000,
            4500000000,
            1000,
            'general',
            CaptureOrder::eventKey(5024, 55)
        ));
        self::assertSame(CaptureOrder::eventKey(5024, 55), $this->orderItems->find($itemId)?->ledgerEvent);
    }

    /**
     * And a line may never be repointed at a different document.
     */
    public function testARepairCannotRepointALineAtAnotherEvent(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-15');
        self::assertSame(1, $this->capture->capture(5025, [$this->line(56, $a, 1, 500000)])['captured']);
        $itemId = (int) $this->orderItems->forVendor(self::VENDOR_A)[0]->id;
        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        // Its figures are cleared, so the row is «incomplete» by the old
        // condition — but its link is intact and names a real document.
        $this->wpdb->pdo()->exec(
            "UPDATE `{$table}` SET vendor_share_minor = NULL, commission_minor = NULL WHERE id = {$itemId}"
        );

        self::assertFalse($this->orderItems->completeFinancials(
            $itemId,
            50000,
            450000,
            1000,
            'general',
            CaptureOrder::eventKey(9999, 1)
        ), 'another event is not this line\'s event');
        self::assertSame(CaptureOrder::eventKey(5025, 56), $this->orderItems->find($itemId)?->ledgerEvent);
        self::assertNull($this->orderItems->find($itemId)?->vendorShareMinor, 'and no figures were written');
    }

    /**
     * Two callbacks for the same order, interleaved at the line write.
     *
     * WooCommerce fires the same hooks more than once, and on a busy site two
     * requests can be inside the capture at the same moment. The question is
     * not whether one of them loses — it is whether losing leaves a duplicate
     * document or a line with no figures.
     *
     * Scheduled rather than raced for: the second capture runs, in this
     * process, immediately before the first one's `INSERT`. Its accrual and
     * its line both land, so the first one's insert meets a unique index on
     * `wc_order_item_id` — which is the guard being measured.
     */
    public function testTwoCallbacksForOneOrderLeaveOneDocumentAndOneCompleteLine(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-11');
        $lines = [$this->line(52, $a, 1, 500000)];
        $eventKey = CaptureOrder::eventKey(5021, 52);

        $interfering = new InterferingDatabase(new WpDatabase($this->wpdb));
        $interfering->before(['INSERT INTO', M0006CatalogAndOrders::ORDER_ITEMS], 1, function () use ($lines): void {
            // The other request, all the way through, on the plain gateway.
            self::assertSame(1, $this->capture->capture(5021, $lines)['captured']);
        });

        $mine = $this->captureThrough($interfering)->capture(5021, $lines);
        self::assertNotSame([], $interfering->fired, 'the second callback has to have run');

        // Exactly one of everything, and the line is complete.
        self::assertCount(3, $this->ledger->forEvent($eventKey), 'one document');
        $stored = $this->orderItems->forVendor(self::VENDOR_A);
        self::assertCount(1, $stored, 'one line');
        self::assertSame(4500000000, $stored[0]->vendorShareMinor);
        self::assertSame($eventKey, $stored[0]->ledgerEvent);
        self::assertSame(0, $mine['failed'], 'the loser did not report a storage failure');
        self::assertSame(0, $mine['incomplete']);
    }

    /**
     * WHAT THIS PROVES: a line captures the OWNER'S per-product commission,
     * records everything needed to explain it, and is not rewritten when the
     * product's rule changes afterwards (§5-ز).
     *
     * The fixed case is the one that needs the quantity: ۲۰٬۰۰۰ per unit over
     * three units is ۶۰٬۰۰۰, and ۶۰٬۰۰۰ alone cannot say whether it was that
     * or ۶۰٬۰۰۰ × ۱.
     */
    public function testAFixedCommissionIsCapturedPerUnitAndSurvivesALaterRuleChange(): void
    {
        $product = $this->publish(self::VENDOR_A, 'دستکش معاینه', 'SKU-COMM-1');
        $wcId = (int) $this->products->find($product)?->wcProductId;
        $rules = new FakeTectebCommissionSource();
        $rules->set($wcId, TectebCommission::fixed('20000'));

        $report = $this->captureWithCommission($rules)->capture(
            6101,
            [$this->line(601, $product, 3, 300000)]
        );
        self::assertSame(1, $report['captured']);

        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertSame(3000000000, $line->baseMinor);
        self::assertSame(600000000, $line->commissionMinor, '۲۰٬۰۰۰ × ۳');
        self::assertSame(2400000000, $line->vendorShareMinor);

        // The snapshot beside the ledger event carries the type, the quantity
        // and the per-unit amount, so the line is explainable a year later.
        $snapshot = $this->snapshotFor(CaptureOrder::eventKey(6101, 601));
        self::assertSame(TectebCommission::FIXED, $snapshot['type'] ?? null);
        self::assertSame(3, $snapshot['quantity'] ?? null);
        self::assertSame(200000000, $snapshot['unit_minor'] ?? null);
        self::assertSame('product', $snapshot['rate_source'] ?? null);

        // THE PAST IS NOT REWRITTEN. The rule changes, and the recorded line
        // does not move: «تغییر بعدی محصول نباید سفارش‌های گذشته را عوض کند».
        $rules->set($wcId, TectebCommission::percent(5000));
        $again = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertSame(600000000, $again->commissionMinor);
        self::assertSame(2400000000, $again->vendorShareMinor);
    }

    /**
     * WHAT THIS PROVES: a product with NO commission still sells, and its line
     * is recorded as a sale the vendor is owed the whole of.
     *
     * This is the half that used to be impossible. Until `alpha.57` an unset
     * rate was `rate_unset` — the order gate refused to operate and the line
     * was counted `unrecorded` with a null share — so «نبود نرخ مانع فروش
     * نیست» had to be measured here rather than argued.
     */
    public function testAProductWithNoCommissionIsCapturedWithTheWholeLineAsTheVendorsShare(): void
    {
        $product = $this->publish(self::VENDOR_A, 'باند کشی', 'SKU-COMM-2');
        $report = $this->captureWithCommission(new FakeTectebCommissionSource())->capture(
            6102,
            [$this->line(602, $product, 2, 450000)]
        );

        self::assertSame(1, $report['captured']);
        self::assertSame(0, $report['unrecorded'], 'no commission is not an unrecorded sale');

        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertSame(0, $line->commissionMinor);
        self::assertSame(4500000000, $line->vendorShareMinor);
        self::assertSame(0, $line->rateBasisPoints);
        self::assertSame('none', $line->rateSource, 'and the line says it came from nowhere');
    }

    /**
     * WHAT THIS PROVES: a percentage is taken on the amount WooCommerce says
     * the line was paid, with no second subtraction of the discount.
     *
     * §5-ط asks for the after-discount amount to come from real WooCommerce
     * data and for the discount not to be subtracted twice. `line_total_minor`
     * IS WooCommerce's after-discount total, so the proof is that the
     * commission is ten percent of THAT and the sum of the two halves is
     * exactly it — not ten percent of an undiscounted price.
     */
    public function testAPercentageIsTakenOnWooCommercesOwnAfterDiscountTotal(): void
    {
        $product = $this->publish(self::VENDOR_A, 'ماسک سه لایه', 'SKU-COMM-3');
        $wcId = (int) $this->products->find($product)?->wcProductId;
        $rules = new FakeTectebCommissionSource();
        $rules->set($wcId, TectebCommission::percent(1000));

        // 500,000 before a 50,000 discount: WooCommerce hands over 450,000.
        $line = $this->line(603, $product, 1, 450000) + [];
        $this->captureWithCommission($rules)->capture(6103, [$line]);

        $stored = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertSame(4500000000, $stored->baseMinor);
        self::assertSame(450000000, $stored->commissionMinor);
        self::assertSame(4050000000, $stored->vendorShareMinor);
        self::assertSame(
            $stored->baseMinor,
            $stored->commissionMinor + $stored->vendorShareMinor,
            'the share and the commission must add up to what was paid'
        );
    }

    /**
     * WHAT THIS PROVES: a fixed commission bigger than the line records
     * NOTHING and says so by name.
     *
     * The open question, measured rather than capped. A cap would invent a
     * rate the owner never set and a negative share would make a vendor owe
     * money for selling something, so the line is refused, counted, and
     * reported — and the question is in the delivery document.
     */
    public function testAFixedCommissionLargerThanTheLineRecordsNothing(): void
    {
        $product = $this->publish(self::VENDOR_A, 'چسب زخم', 'SKU-COMM-4');
        $wcId = (int) $this->products->find($product)?->wcProductId;
        $rules = new FakeTectebCommissionSource();
        $rules->set($wcId, TectebCommission::fixed('900000'));

        $report = $this->captureWithCommission($rules)->capture(
            6104,
            [$this->line(604, $product, 1, 450000)]
        );

        self::assertSame(1, $report['unrecorded']);
        self::assertContains(TectebCommissionCalculator::EXCEEDS_LINE, $report['reasons']);

        // The line is still STORED, with null figures, because FIN-02's rule
        // is that a sale which produced no share is owed an answer rather than
        // a silent omission.
        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertNull($line->commissionMinor);
        self::assertNull($line->vendorShareMinor);
        self::assertSame('', $line->ledgerEvent);
    }

    /**
     * WHAT THIS PROVES: the scoped rules of FIN-02 no longer decide a sale.
     *
     * A general rate is set — which this site has had since `alpha.6` — and a
     * product with no commission of its own is still captured with none:
     * «نرخ عمومی یا سطح فروشنده نباید بی‌صدا جای کمیسیون خالی محصول را بگیرد».
     */
    public function testAGeneralRateDoesNotStandInForAProductWithNoCommission(): void
    {
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(2500));
        $product = $this->publish(self::VENDOR_A, 'سرنگ ۵ سی‌سی', 'SKU-COMM-5');

        $this->captureWithCommission(new FakeTectebCommissionSource())->capture(
            6105,
            [$this->line(605, $product, 1, 400000)]
        );

        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        self::assertSame(0, $line->commissionMinor, 'the general 25% must not reach this line');
        self::assertSame(4000000000, $line->vendorShareMinor);
    }

    /**
     * THE OWNER'S WORKED EXAMPLE, end to end: order line, ledger, balance.
     *
     * «مبلغ قلم ۱۰۱ تومان، نرخ ۱۲٫۵٪، کمیسیون دقیقاً ۱۲٫۶۲۵ تومان و سهم
     * تأمین‌کننده دقیقاً ۸۸٫۳۷۵ تومان.» The point of asserting it HERE rather
     * than only in the unit suite is that the figures have to survive being
     * written and read back: an exact calculation stored in a column that
     * cannot hold it is a rounded figure with extra steps.
     */
    public function testTheOwnersWorkedExampleSurvivesTheRoundTripToTheRowAndTheLedger(): void
    {
        $product = $this->publish(self::VENDOR_A, 'نوار تست قند', 'SKU-EXACT-1');
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1250));

        $report = $this->captureWithCommission($rules)->capture(6200, [$this->line(620, $product, 1, 101)]);
        self::assertSame(1, $report['captured'], implode(',', $report['reasons']));

        $line = $this->orderItems->forVendor(self::VENDOR_A)[0];
        // THE UNIT IS ON THE ROW, and all five amounts are in it.
        self::assertSame(4, $line->exponent, 'four places, derived from the rate denominator');
        self::assertSame('IRR', $line->currency);
        self::assertSame(1010000, $line->baseMinor);
        self::assertSame(126250, $line->commissionMinor);
        self::assertSame(883750, $line->vendorShareMinor);
        self::assertSame(
            $line->baseMinor,
            (int) $line->commissionMinor + (int) $line->vendorShareMinor,
            'C + V must be B exactly, read back off the row'
        );
        // AND AS A PERSON READS THEM.
        self::assertSame('۱۲٫۶۲۵', MoneyText::format((int) $line->commissionMinor, (int) $line->exponent));
        self::assertSame('۸۸٫۳۷۵', MoneyText::format((int) $line->vendorShareMinor, (int) $line->exponent));

        // THE LEDGER, in the same unit, balancing to nought.
        $entries = $this->ledger->forEvent(CaptureOrder::eventKey(6200, 620));
        self::assertNotSame([], $entries);
        $sum = 0;
        foreach ($entries as $entry) {
            self::assertSame(4, $entry->amount->exponent, 'every line of the event in one unit');
            $sum += $entry->amount->minor;
        }
        self::assertSame(0, $sum, 'the event balances');

        // AND THE BALANCE, with the unit it is in.
        $balance = $this->balanceOf(self::VENDOR_A);
        self::assertSame(4, $balance['exponent']);
        self::assertSame(883750, $balance['earned']);
        self::assertSame('۸۸٫۳۷۵', MoneyText::format((int) $balance['earned'], (int) $balance['exponent']));
    }

    /**
     * THE WINDOW BETWEEN A FILE SWAP AND THE MIGRATION, both halves.
     *
     * Swapping the plugin's files runs nothing: `UpgradeGate` runs migration
     * 33 on the next admin request, and a failed step stays failed and is
     * retried every five minutes (`alpha.46`). So this release's code runs
     * against a database without its columns, and that was measured rather
     * than imagined — the real upgrade run printed
     * «Unknown column 'i.tmc_currency'» on the settlement read.
     *
     * Two different answers on purpose:
     *
     *   * a READ degrades to the legacy reading — no unit on any row, so the
     *     vendor's single money unit, which is what every release up to
     *     `alpha.66` assumed. Nothing is reinterpreted and no error is
     *     raised;
     *   * a WRITE that carries a finer unit REFUSES. Storing ۳۷٫۸۷۵ in a row
     *     that cannot say «four more places» leaves a figure that reads right
     *     today and ten thousand times too large the moment the column
     *     arrives.
     */
    public function testWithTheUnitColumnsMissingReadsDegradeAndWritesRefuse(): void
    {
        $product = $this->publish(self::VENDOR_A, 'گاز استریل', 'SKU-WINDOW-1');
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1000));
        $capture = $this->captureWithCommission($rules);
        $report = $capture->capture(6210, [$this->line(630, $product, 1, 100000)]);
        self::assertSame(1, (int) ($report['captured'] ?? 0), 'the line is captured before the columns go');

        $table = $this->wpdb->prefix . 'tmc_order_items';
        $this->wpdb->pdo()->exec("ALTER TABLE `{$table}` DROP COLUMN `tmc_currency`");
        $this->wpdb->pdo()->exec("ALTER TABLE `{$table}` DROP COLUMN `tmc_exponent`");
        // The memo in `unitColumnState()` is per instance; a fresh repository
        // is how a later request asks again.
        $items = new DbOrderItemRepository(new WpDatabase($this->wpdb), new SystemClock());

        try {
            // THE READS. Neither raises, and both answer about the money the
            // rows were written with.
            $view = $items->settlementView(self::VENDOR_A);
            self::assertNotSame([], $view, 'the settlement view still answers');
            foreach ($view as $row) {
                self::assertNull($row['exponent'], 'no row says what unit it is in');
                self::assertNull($row['currency']);
            }
            $totals = $items->totalsForVendor(self::VENDOR_A);
            self::assertSame(0, $totals['exponent'], 'read at the unit every earlier release assumed');
            // ⛔ AND THE COST, AS A NUMBER. This line was captured by THIS
            // release, so its five columns hold ۹٬۰۰۰ تومان of share written
            // at four extra places — `900000000`. With the unit column gone
            // the integer is all there is, and it reads as ۹۰۰٬۰۰۰٬۰۰۰. The
            // read does not invent a scale and does not raise; it answers
            // what is on disk. That is precisely why the WRITE below refuses,
            // and it is the rollback cost `docs/upgrade-and-rollback.md` §47
            // states: a release that records at the finer unit must not be
            // rolled back under an open till.
            self::assertSame(900000000, $totals['share'], 'the integer on disk, with no scale to read it by');

            // THE WRITE. A line that carries a unit is refused outright.
            $refused = $items->record(new VendorOrderItem(
                id: 0,
                orderId: 6211,
                orderItemId: 631,
                productId: $product,
                vendorUserId: self::VENDOR_A,
                title: 'قلم با واحد',
                sku: 'SKU-WINDOW-2',
                quantity: 1,
                unitPriceMinor: 1010000,
                baseMinor: 1010000,
                taxMinor: 0,
                commissionMinor: 126250,
                vendorShareMinor: 883750,
                rateBasisPoints: 1250,
                rateSource: 'product',
                ledgerEvent: 'order:6211:item:631',
                status: OrderItemStatus::Placed,
                currency: 'IRR',
                exponent: 4
            ));
            self::assertSame(0, $refused, 'an amount whose scale cannot be stored is not stored');
            $rows = (int) $this->wpdb->pdo()
                ->query("SELECT COUNT(*) FROM `{$table}` WHERE wc_order_item_id = 631")
                ->fetchColumn();
            self::assertSame(0, $rows, 'and no half-written row is left behind');

            // …while a line that carries NO unit still writes, because that
            // is what `alpha.66` wrote and it is not a reinterpretation.
            $written = $items->record(new VendorOrderItem(
                id: 0,
                orderId: 6212,
                orderItemId: 632,
                productId: $product,
                vendorUserId: self::VENDOR_A,
                title: 'قلم بی واحد',
                sku: 'SKU-WINDOW-3',
                quantity: 1,
                unitPriceMinor: 100000,
                baseMinor: 100000,
                taxMinor: 0,
                commissionMinor: 10000,
                vendorShareMinor: 90000,
                rateBasisPoints: 1000,
                rateSource: 'general',
                ledgerEvent: 'order:6212:item:632',
                status: OrderItemStatus::Placed
            ));
            self::assertGreaterThan(0, $written, 'the legacy shape is still writable');
        } finally {
            $this->wpdb->pdo()->exec(
                "ALTER TABLE `{$table}` ADD COLUMN `tmc_currency` CHAR(3) NULL DEFAULT NULL"
            );
            $this->wpdb->pdo()->exec(
                "ALTER TABLE `{$table}` ADD COLUMN `tmc_exponent` TINYINT UNSIGNED NULL DEFAULT NULL"
            );
        }
    }

    /**
     * SEVERAL LINES ADD UP, and the sum is exact rather than nearly right.
     *
     * Three lines whose shares each carry a fraction: ۸۸٫۳۷۵ + ۸۸٫۳۷۵ +
     * ۲۱۸٫۷۵ is ۳۹۵٫۵۰۰ and not ۳۹۵ or ۳۹۶. Asserted against both the
     * balance and `totalsForVendor()`, which compute it two different ways —
     * one in PHP over the settlement view, one grouped in SQL — so a
     * disagreement between them cannot hide.
     */
    public function testSeveralFractionalSharesAddUpExactlyInBothTotals(): void
    {
        $product = $this->publish(self::VENDOR_A, 'باند کشی', 'SKU-EXACT-2');
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1250));
        $capture = $this->captureWithCommission($rules);

        $capture->capture(6201, [$this->line(621, $product, 1, 101)]);
        $capture->capture(6202, [$this->line(622, $product, 1, 101)]);
        $capture->capture(6203, [$this->line(623, $product, 3, 250)]);

        $balance = $this->balanceOf(self::VENDOR_A);
        self::assertSame(4, $balance['exponent']);
        self::assertSame(883750 + 883750 + 2187500, $balance['earned']);
        self::assertSame('۳۹۵٫۵', MoneyText::format((int) $balance['earned'], 4));

        $totals = $this->orderItems->totalsForVendor(self::VENDOR_A);
        self::assertSame(4, $totals['exponent']);
        self::assertSame($balance['earned'], $totals['share'], 'two ways of adding, one answer');
        self::assertSame(
            $totals['base'],
            $totals['commission'] + $totals['share'],
            'the totals add up the way each line does'
        );
    }

    /**
     * A LINE RECORDED IN THE OLD SHAPE IS NOT REINTERPRETED.
     *
     * The row is written by hand exactly as every release up to `alpha.66`
     * wrote one: ۱۰۰٬۰۰۰ تومان of share, five money columns, and NO unit. It
     * has to keep meaning ۱۰۰٬۰۰۰ — read at the vendor's first-recorded unit
     * — and it has to add up with a new fractional line rather than one of
     * the two being wrong by ten thousand. «مبلغ و واحد تاریخی دفترکل و
     * سفارش‌های قبلی بازتفسیر یا بازنویسی نشوند.»
     */
    public function testALineWithNoRecordedUnitKeepsItsMeaningAndStillAddsUp(): void
    {
        // A first sale in the old shape, so the registry records exponent 0 —
        // which is what every existing site has.
        $product = $this->publish(self::VENDOR_A, 'چسب ضد حساسیت', 'SKU-EXACT-3');
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $this->capture->capture(6204, [$this->line(624, $product, 1, 100000)]);

        // That line's unit is now recorded, because `alpha.67` writes it. To
        // measure the LEGACY shape the two columns are cleared on it, which
        // is byte-for-byte what `alpha.66` left behind.
        $db = new WpDatabase($this->wpdb);
        $table = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
        $db->execute(
            'UPDATE `' . $table . '` SET `' . MoneyUnit::CURRENCY . '` = NULL, `'
            . MoneyUnit::EXPONENT . '` = NULL WHERE wc_order_item_id = %d',
            [624]
        );
        $legacy = $this->orderItems->findByOrderItem(624);
        self::assertNull($legacy?->exponent, 'the row is in the old shape now');
        $legacyShare = (int) $legacy?->vendorShareMinor;

        // A NEW line, at the finer unit.
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1250));
        $this->captureWithCommission($rules)->capture(6205, [$this->line(625, $product, 1, 101)]);

        $balance = $this->balanceOf(self::VENDOR_A);
        self::assertSame(4, $balance['exponent'], 'the finest unit present');
        self::assertSame(
            $legacyShare * 10000 + 883750,
            $balance['earned'],
            'the old row still means what it said, and the two add up exactly'
        );
        self::assertFalse($balance['unit_unreadable']);
    }

    /**
     * ⛔ THE PER-UNIT PRICE IS WRITTEN ONLY WHEN IT IS EXACT — §3's second
     * half, on the owner's own example.
     *
     * A line whose total is ۱۰۱ تومان for three units: `base_minor` is
     * `1010000` at exponent 4 and a third of it is ۳۳۶۶۶۶٫۶۶۶…, which is not
     * an integer at any finite exponent. `alpha.67` stored `336666` — and
     * `336666 × 3` is `1009998`, not `1010000`, so the column held a price
     * that did not reconstruct its own line. Migration 34 lets it say «no
     * whole per-unit price» instead.
     */
    public function testAUnitPriceThatIsNotWholeIsNotInvented(): void
    {
        $product = $this->publish(self::VENDOR_A, 'قلم تقسیم‌ناپذیر', 'SKU-UNITPRICE-1');
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1250));

        $this->captureWithCommission($rules)->capture(6301, [$this->line(641, $product, 3, 101)]);

        $line = $this->orderItems->findByOrderItem(641);
        self::assertNotNull($line);
        self::assertSame(1010000, $line->baseMinor, 'the line total, at the finer unit');
        self::assertNull($line->unitPriceMinor, 'a third of it is not a whole number, so none is claimed');
    }

    /** AND WHEN IT IS WHOLE IT IS STILL WRITTEN — the control. */
    public function testAUnitPriceThatIsWholeIsStillRecorded(): void
    {
        $product = $this->publish(self::VENDOR_A, 'قلم بخش‌پذیر', 'SKU-UNITPRICE-2');
        $rules = new FakeTectebCommissionSource();
        $rules->set((int) $this->products->find($product)?->wcProductId, TectebCommission::percent(1250));

        $this->captureWithCommission($rules)->capture(6302, [$this->line(642, $product, 3, 300)]);

        $line = $this->orderItems->findByOrderItem(642);
        self::assertNotNull($line);
        self::assertSame(3000000, $line->baseMinor);
        self::assertSame(1000000, $line->unitPriceMinor, '۱۰۰ تومان per unit, exactly');
        self::assertSame(
            $line->baseMinor,
            (int) $line->unitPriceMinor * $line->quantity,
            'and it reconstructs the line it came from'
        );
    }

    /**
     * The vendor's balance, wired the way the site wires it.
     *
     * The money-unit registry is included on purpose: without it the answer
     * says `unit_unreadable` and sums in whatever unit the rows name, which
     * is a true sentence about a build that cannot ask and the wrong thing
     * to measure the arithmetic against.
     *
     * @return array<string,mixed>
     */
    private function balanceOf(int $vendorUserId): array
    {
        $clock = new SystemClock();
        return (new VendorBalance(
            $this->orderItems,
            new SettingsService(new WpOptionStore()),
            $clock,
            null,
            null,
            new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), $clock)
        ))->of($vendorUserId);
    }

    /** The capture wired with the owner's per-product model. */
    private function captureWithCommission(FakeTectebCommissionSource $rules): CaptureOrder
    {
        $clock = new SystemClock();
        return new CaptureOrder(
            $this->orderItems,
            $this->products,
            new RecordCommission(
                $this->ledger,
                new ResolveCommissionRate($this->rules),
                new CommissionCalculator(),
                $this->logger,
                $rules,
                new TectebCommissionCalculator()
            ),
            $this->catalogService,
            $this->orderGate,
            $this->logger,
            new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), $clock),
            new WpDatabase($this->wpdb)
        );
    }

    /**
     * The snapshot stored beside one ledger event, decoded.
     *
     * @return array<string,mixed>
     */
    private function snapshotFor(string $eventKey): array
    {
        foreach ($this->ledger->forEvent($eventKey) as $entry) {
            if ($entry->snapshotJson === '') {
                continue;
            }
            $decoded = json_decode($entry->snapshotJson, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * A capture whose UNIT REGISTRY is on the given gateway and whose
     * everything else is real — so a failed unit read can be injected without
     * breaking the ledger or the order table.
     */
    private function captureOverUnits(DatabaseInterface $db): CaptureOrder
    {
        $clock = new SystemClock();
        return new CaptureOrder(
            $this->orderItems,
            $this->products,
            new RecordCommission(
                $this->ledger,
                new ResolveCommissionRate($this->rules),
                new CommissionCalculator(),
                $this->logger
            ),
            $this->catalogService,
            $this->orderGate,
            $this->logger,
            new DbVendorMoneyUnitRegistry($db, $clock),
            // The unit of work the claim and the accrual share.
            new WpDatabase($this->wpdb)
        );
    }

    private function captureThrough(InterferingDatabase $db): CaptureOrder
    {
        $clock = new SystemClock();
        return new CaptureOrder(
            new DbOrderItemRepository($db, $clock),
            $this->products,
            new RecordCommission(
                new DbLedgerRepository($db, $clock),
                new ResolveCommissionRate($this->rules),
                new CommissionCalculator(),
                $this->logger
            ),
            $this->catalogService,
            $this->orderGate,
            $this->logger,
            // The vendor money unit, recorded against a primary key: a capture
            // without it refuses every line as `unit_unreadable` rather than
            // assuming, which is the posture `alpha.40` chose.
            new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), new SystemClock()),
            // The unit of work the claim and the accrual share.
            new WpDatabase($this->wpdb)
        );
    }

    private function captureOver(FailingDatabase $failing): CaptureOrder
    {
        $clock = new SystemClock();
        return new CaptureOrder(
            new DbOrderItemRepository($failing, $clock),
            $this->products,
            new RecordCommission(
                $this->ledger,
                new ResolveCommissionRate($this->rules),
                new CommissionCalculator(),
                $this->logger
            ),
            $this->catalogService,
            $this->orderGate,
            $this->logger,
            // The vendor money unit, recorded against a primary key: a capture
            // without it refuses every line as `unit_unreadable` rather than
            // assuming, which is the posture `alpha.40` chose.
            new DbVendorMoneyUnitRegistry(new WpDatabase($this->wpdb), new SystemClock()),
            // The unit of work the claim and the accrual share.
            new WpDatabase($this->wpdb)
        );
    }

    private function line(int $orderItemId, int $productId, int $quantity, int $totalMinor): array
    {
        $product = $this->products->find($productId);
        return [
            'order_item_id' => $orderItemId,
            'wc_product_id' => (int) $product?->wcProductId,
            'variation_id' => null,
            'title' => (string) $product?->details->title,
            'sku' => (string) $product?->details->sku,
            'quantity' => $quantity,
            'line_total_minor' => $totalMinor,
            'line_tax_minor' => 0,
        ];
    }

    private function publish(int $vendorUserId, string $title, string $sku): int
    {
        $created = $this->manage->save($vendorUserId, $vendorUserId, 0, new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 20
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        $mediaId = 600 + $productId;
        $this->images->give($mediaId, $vendorUserId);
        $this->manage->save($vendorUserId, $vendorUserId, $productId, new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 20
        ), [], [$mediaId], $mediaId, $this->stampOf($productId));
        self::assertTrue($this->manage->submit($vendorUserId, $vendorUserId, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);
        return $productId;
    }

    private function addStaff(StaffRolePreset $preset): int
    {
        return $this->staff->add(
            self::VENDOR_A,
            self::STAFF_A,
            'همکار فروشگاه',
            'staffer',
            'staff@example.test',
            '09120000000',
            $preset,
            $preset->permissions(),
            hash('sha256', 'order-token'),
            '2030-01-01 00:00:00'
        );
    }

    /**
     * The stamp a real form would carry: the row's counter, read now.
     *
     * Every save of an EXISTING product goes through this, because from
     * alpha.15 a save with no usable version is refused rather than written
     * unguarded. A test that omitted it would be testing a path the product
     * form cannot reach.
     */
    private function stampOf(int $productId): string
    {
        return $this->products->find($productId)?->rowVersion ?? '';
    }

}
