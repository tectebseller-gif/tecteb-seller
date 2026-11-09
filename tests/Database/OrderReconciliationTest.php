<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderLineReaderInterface;
use Tecteb\Marketplace\Modules\Order\Application\ReconcileOrders;
use Tecteb\Marketplace\Modules\Order\Application\SyncOrderLifecycle;
use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;

/**
 * WHAT THIS PROVES: the owner's §5, on real tables — every one of the seven
 * classes found, a sound marketplace reported clean, and a read that failed
 * reported as a read that failed.
 *
 * ⛔ **THE DIRECTION THAT MATTERS.** «یک کنترل تطبیق fail-closed» — a
 * reconciliation that says «nothing wrong» because it could not look is the
 * one answer this control must never give, and `alpha.97` paid for exactly
 * that mistake on a readiness gate: `staff_decided` reported «done» off a
 * failed read for nine releases. So two of the tests here are about failure
 * and one is about a check that could not run at all.
 */
final class OrderReconciliationTest extends DatabaseTestCase
{
    private const VENDOR = 102;
    private const OTHER = 40;

    private OrderItemRepositoryInterface $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPlugin(true);
        $this->loginAdmin();
        Bootstrap::container()->get(MigrationRunner::class)->run();
        $db = new WpDatabase($this->wpdb);
        // ⛔ THE PRODUCT TABLE GOES TOO, and the reason is a measured one: the
        // vendor-mismatch check joins order lines to product rows, so this
        // class writes products — and `wc_product_id` carries a UNIQUE key.
        // Rows left by an earlier run made `create()` answer `0` on the fifth
        // test of the class and only on that one, which looked like a defect
        // in the fixture rather than in the leftovers. A fixture that spends
        // something has to give it back (`alpha.33`).
        foreach ([
            M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS),
            $db->prefix() . 'tmc_ledger_entries',
            M0005CreateProductTables::table($db, M0005CreateProductTables::PRODUCTS),
        ] as $table) {
            $this->wpdb->pdo()->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $vendors = Bootstrap::container()->get(VendorRepositoryInterface::class);
        $vendors->upsertProfile(self::VENDOR, 'فروشگاه ۱۰۲', true, false);
        $vendors->upsertProfile(self::OTHER, 'فروشگاه ۴۰', true, false);
        $this->items = Bootstrap::container()->get(OrderItemRepositoryInterface::class);
    }

    /**
     * ⛔ THE CONTROL COMES FIRST. A reconciliation that reports something
     * about every line is a reconciliation nobody reads — and the 184 rows
     * staging carries are, as far as this control is concerned, ordinary.
     */
    public function testASoundMarketplaceReportsNothing(): void
    {
        $this->soundLine(12299, 206010000, 0);
        $this->soundLine(12298, 100000, 10000);

        $answer = $this->reconcile()->pass();
        self::assertTrue($answer['allowed']);
        self::assertTrue($answer['readable']);
        self::assertSame(2, $answer['examined']);
        self::assertSame([], $answer['findings'], 'nothing to report');
        self::assertSame(0, array_sum($answer['counts']));
        self::assertTrue($answer['finished']);
    }

    /**
     * And a line with nothing resolved and nothing recorded is sound too:
     * FIN-02's documented state, which 184 rows could legitimately be in.
     */
    public function testALineWithNoRateResolvedIsNotAFinding(): void
    {
        $this->line(12310, 100000, [
            'commission' => null, 'share' => null, 'rate_bp' => null, 'ledger_event' => '',
        ]);
        $answer = $this->reconcile()->pass();
        self::assertSame([], $answer['findings'], 'FIN-02: nothing resolved records nothing');
    }

    public function testALineWithAShareAndNoDocumentIsFound(): void
    {
        $this->line(12320, 100000, ['ledger_event' => '']);
        $this->assertFindings([OrderInconsistency::LineWithoutAccrual->value => 1]);
    }

    public function testALineNamingADocumentTheLedgerDoesNotHaveIsFound(): void
    {
        $this->line(12330, 100000, ['ledger_event' => 'order:12330:item:ghost']);
        $this->assertFindings([OrderInconsistency::LineWithoutAccrual->value => 1]);
    }

    public function testTwoLinesClaimingOneDocumentAreBothFound(): void
    {
        $this->accrue('order:shared', 100000, 10000);
        $this->line(12340, 100000, ['ledger_event' => 'order:shared']);
        $this->line(12341, 100000, ['ledger_event' => 'order:shared']);
        // Both lines report it, because both are suspect and a manager has to
        // look at the pair rather than at one of them.
        $this->assertFindings([OrderInconsistency::DuplicateAccrual->value => 2]);
    }

    /**
     * ⛔ THE STATE EVERY ROW CANCELLED BEFORE `alpha.99` IS IN. That release
     * was the first to write a reversing document, so this finding is
     * expected on the owner's data and the advice on the screen says so.
     */
    public function testACancelledLineWithNoReversalIsFound(): void
    {
        $this->accrue('order:12350:item:12350', 100000, 10000);
        $this->line(12350, 100000, ['status' => OrderItemStatus::Cancelled]);
        $this->assertFindings([OrderInconsistency::CancelledWithoutReversal->value => 1]);
    }

    /** And a cancelled line that WAS reversed is clean. */
    public function testACancelledLineThatWasReversedIsClean(): void
    {
        $this->accrue('order:12360:item:12360', 100000, 10000);
        $this->accrue(SyncOrderLifecycle::voidEventKey(12360, 12360), -100000, -10000);
        $this->line(12360, 100000, ['status' => OrderItemStatus::Cancelled]);
        $answer = $this->reconcile()->pass();
        self::assertSame([], $answer['findings'], 'cancelled and reversed is the end state');
    }

    /**
     * The opposite, which no code path produces — nothing un-cancels — so it
     * means a hand edit, and the reports are counting a share the ledger has
     * already given back.
     */
    public function testAReversalBesideALineThatIsNotCancelledIsFound(): void
    {
        $this->accrue('order:12370:item:12370', 100000, 10000);
        $this->accrue(SyncOrderLifecycle::voidEventKey(12370, 12370), -100000, -10000);
        $this->line(12370, 100000);
        $this->assertFindings([OrderInconsistency::ReversalWithoutCancel->value => 1]);
    }

    public function testADocumentThatDoesNotSumToNoughtIsFound(): void
    {
        // Written row by row, because `LedgerTransaction` refuses to record
        // an unbalanced event — which is right, and is why this state can
        // only be reached by a hand edit or a half-written insert.
        $this->rawLedgerRow('order:12380:item:12380', LedgerAccount::Commission, -10000, 'IRT', 0);
        $this->line(12380, 100000, ['ledger_event' => 'order:12380:item:12380']);
        $this->assertFindings([OrderInconsistency::UnbalancedEvent->value => 1]);
    }

    /** Two units inside one document, which is a number in no unit. */
    public function testADocumentInTwoUnitsIsFoundEvenWhenItSumsToNought(): void
    {
        $this->rawLedgerRow('order:12390:item:12390', LedgerAccount::CentralPayment, 100000, 'IRT', 0);
        $this->rawLedgerRow('order:12390:item:12390', LedgerAccount::VendorEarning, -100000, 'IRT', 4);
        $this->line(12390, 100000, ['ledger_event' => 'order:12390:item:12390']);
        $this->assertFindings([OrderInconsistency::UnbalancedEvent->value => 1]);
    }

    /**
     * ⛔ A SHARE PAID TO THE WRONG SHOP LOOKS LIKE NOTHING, which is why this
     * one is worth a query of its own.
     */
    public function testALineWhoseVendorTheProductRowDoesNotNameIsFound(): void
    {
        $product = $this->productOwnedBy(self::OTHER);
        $this->accrue('order:12400:item:12400', 100000, 10000);
        // Our own row id, pointing at a product another shop owns.
        $this->line(12400, 100000, ['product' => $product['id']]);
        $this->assertFindings([OrderInconsistency::VendorOrStatusMismatch->value => 1]);
    }

    /** And the control: the same shape with the owner agreeing is clean. */
    public function testALineWhoseVendorAgreesWithItsProductIsClean(): void
    {
        $product = $this->productOwnedBy(self::VENDOR);
        $this->accrue('order:12410:item:12410', 100000, 10000);
        $this->line(12410, 100000, ['product' => $product['id']]);
        self::assertSame([], $this->reconcile()->pass()['findings']);
    }

    /** A stored status this release does not map is a finding. */
    public function testAStatusThisReleaseDoesNotKnowIsFound(): void
    {
        $this->accrue('order:12420:item:12420', 100000, 10000);
        $id = $this->line(12420, 100000);
        $db = new WpDatabase($this->wpdb);
        self::assertNotNull($db->execute(
            'UPDATE `' . M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS)
            . '` SET status = %s WHERE id = %d',
            ['invented_status', $id]
        ));
        $this->assertFindings([OrderInconsistency::VendorOrStatusMismatch->value => 1]);
    }

    /**
     * ⛔ A WooCommerce ORDER WITH A MARKETPLACE PRODUCT AND NO LINE — the
     * seventh class, and the one that is about the swallow in the hook.
     */
    public function testAWooCommerceOrderWithOneOfOurProductsAndNoLineIsFound(): void
    {
        $product = $this->productOwnedBy(self::VENDOR);
        $reader = new class ($product['wc']) implements OrderLineReaderInterface {
            public function __construct(private readonly int $productId)
            {
            }

            public function recentOrderIds(int $limit): ?array
            {
                return [99001];
            }

            public function productIdsIn(int $wcOrderId): ?array
            {
                return $wcOrderId === 99001 ? [$this->productId] : [];
            }
        };
        $answer = $this->reconcile($reader)->pass();
        self::assertTrue($answer['order_scan']['checked']);
        self::assertSame(
            1,
            $answer['counts'][OrderInconsistency::OrderWithoutLine->value],
            'the order nobody recorded'
        );
        self::assertSame(99001, $answer['findings'][0]['wc_order_id']);
    }

    /** An order holding nothing of ours is not our business. */
    public function testAnOrderWithNoneOfOurProductsIsNotAFinding(): void
    {
        $reader = new class implements OrderLineReaderInterface {
            public function recentOrderIds(int $limit): ?array
            {
                return [99002];
            }

            public function productIdsIn(int $wcOrderId): ?array
            {
                return [888888];
            }
        };
        $answer = $this->reconcile($reader)->pass();
        self::assertTrue($answer['order_scan']['checked']);
        self::assertSame(0, array_sum($answer['counts']));
    }

    /** And an order we DID record is the normal case. */
    public function testAnOrderWeRecordedIsNotAFinding(): void
    {
        $product = $this->productOwnedBy(self::VENDOR);
        $this->accrue('order:99003:item:99003', 100000, 10000);
        $this->line(99003, 100000, ['product' => $product['id']]);
        $reader = new class ($product['wc']) implements OrderLineReaderInterface {
            public function __construct(private readonly int $productId)
            {
            }

            public function recentOrderIds(int $limit): ?array
            {
                return [99003];
            }

            public function productIdsIn(int $wcOrderId): ?array
            {
                return [$this->productId];
            }
        };
        $answer = $this->reconcile($reader)->pass();
        self::assertSame(0, array_sum($answer['counts']));
    }

    /**
     * ⛔ WITHOUT A READER THE CHECK IS `unchecked` BY NAME. Not clean, not
     * absent from the report: named, so a window with no findings can never
     * be read as a clean marketplace.
     */
    public function testWithoutAWooCommerceReaderTheOrderCheckIsNamedAsUnchecked(): void
    {
        $this->soundLine(12430, 100000, 10000);
        $answer = $this->reconcile(null, false)->pass();
        self::assertTrue($answer['readable']);
        self::assertSame([], $answer['findings']);
        self::assertFalse($answer['order_scan']['checked']);
        self::assertSame('no_reader', $answer['order_scan']['reason']);
        self::assertSame(
            [OrderInconsistency::OrderWithoutLine->value],
            $answer['unchecked'],
            'the skipped check, by name'
        );
    }

    /** A reader that cannot enumerate is the same answer with its own reason. */
    public function testAReaderThatCannotEnumerateIsUnchecked(): void
    {
        $reader = new class implements OrderLineReaderInterface {
            public function recentOrderIds(int $limit): ?array
            {
                return null;
            }

            public function productIdsIn(int $wcOrderId): ?array
            {
                return null;
            }
        };
        $answer = $this->reconcile($reader)->pass();
        self::assertFalse($answer['order_scan']['checked']);
        self::assertSame('woocommerce_absent', $answer['order_scan']['reason']);
    }

    /**
     * ⛔ ONE UNREADABLE ORDER MAKES THE WHOLE SCAN UNCHECKED. A partial scan
     * reported as a scan is the `alpha.97` mistake, so it is all or nothing.
     */
    public function testOneUnreadableOrderMakesTheWholeScanUnchecked(): void
    {
        $reader = new class implements OrderLineReaderInterface {
            public function recentOrderIds(int $limit): ?array
            {
                return [99010, 99011];
            }

            public function productIdsIn(int $wcOrderId): ?array
            {
                return $wcOrderId === 99011 ? null : [];
            }
        };
        $answer = $this->reconcile($reader)->pass();
        self::assertFalse($answer['order_scan']['checked']);
        self::assertSame('order_unreadable', $answer['order_scan']['reason']);
    }

    /**
     * ⛔ AND A WINDOW THAT COULD NOT BE COUNTED REPORTS NOTHING EXAMINED —
     * never a clean bill. `getResults()` answers `[]` both for «no rows» and
     * for «the statement failed» (`alpha.8`).
     */
    public function testAWindowThatCouldNotBeReadIsNotACleanBill(): void
    {
        $this->soundLine(12440, 100000, 10000);
        $db = new FailingDatabase(new WpDatabase($this->wpdb));
        $db->failReadWhen(['COUNT(*)', 'tmc_order_items']);
        $blind = new ReconcileOrders(
            new DbOrderItemRepository($db, new SystemClock()),
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            new FakeCapabilityChecker(1, [Capabilities::VIEW_VENDOR_FINANCE])
        );
        $answer = $blind->pass();
        self::assertTrue($answer['allowed']);
        self::assertFalse($answer['readable'], 'could not look');
        self::assertSame(0, $answer['examined']);
        self::assertSame([], $answer['findings']);
        self::assertFalse($answer['finished'], 'and it is not finished either');
        self::assertContains(OrderInconsistency::Unchecked->value, $answer['unchecked']);
    }

    /** The audit needs the finance capability, and refuses before reading. */
    public function testTheAuditNeedsTheFinanceCapability(): void
    {
        $this->line(12450, 100000, ['ledger_event' => '']);
        $refused = new ReconcileOrders(
            $this->items,
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            new FakeCapabilityChecker(1, [Capabilities::VIEW_VENDOR_SHOPS])
        );
        $answer = $refused->pass();
        self::assertFalse($answer['allowed']);
        self::assertSame([], $answer['findings']);
        self::assertSame(0, array_sum($answer['counts']));
    }

    /** With no capability checker at all it refuses — the conservative way. */
    public function testWithNoCapabilityCheckerItRefuses(): void
    {
        $answer = (new ReconcileOrders(
            $this->items,
            Bootstrap::container()->get(LedgerRepositoryInterface::class)
        ))->pass();
        self::assertFalse($answer['allowed']);
    }

    /** Narrowing to one shop leaves the other shop's lines alone. */
    public function testNarrowingToOneShopExaminesOnlyThatShop(): void
    {
        $this->line(12460, 100000, ['ledger_event' => '']);
        $this->line(12461, 100000, ['vendor' => self::OTHER, 'ledger_event' => '']);

        $all = $this->reconcile()->pass();
        self::assertSame(2, $all['examined']);

        $one = $this->reconcile()->pass(0, self::VENDOR);
        self::assertSame(1, $one['examined']);
        self::assertSame(self::VENDOR, $one['findings'][0]['vendor_user_id']);
    }

    /**
     * The cursor moves and a short window says it finished — the reading
     * every cursor in this plugin uses.
     */
    public function testTheCursorMovesAndAShortWindowIsFinished(): void
    {
        $first = $this->soundLine(12470, 100000, 10000);
        $second = $this->soundLine(12471, 100000, 10000);

        $answer = $this->reconcile()->pass();
        self::assertSame(max($first, $second), $answer['cursor']);
        self::assertTrue($answer['finished']);

        $after = $this->reconcile()->pass($answer['cursor']);
        self::assertSame(0, $after['examined'], 'nothing left after the cursor');
        self::assertTrue($after['finished']);
    }

    /**
     * ⛔ AND THE COST DOES NOT GROW WITH THE WINDOW. Six statements plus a
     * count, whether the window holds two rows or two hundred — asked per row
     * this would be six per line (`alpha.96`).
     */
    public function testTheCostIsFixedWhateverTheWindowHolds(): void
    {
        $this->soundLine(12480, 100000, 10000);
        $small = $this->statementsFor(fn () => $this->reconcile()->pass());

        for ($i = 0; $i < 20; $i++) {
            $this->soundLine(12500 + $i, 100000, 10000);
        }
        $large = $this->statementsFor(fn () => $this->reconcile()->pass());

        self::assertSame(
            $small,
            $large,
            'one line and twenty-one lines must cost the same number of statements'
        );
    }

    /** @param array<string,int> $expected */
    private function assertFindings(array $expected): void
    {
        $answer = $this->reconcile()->pass();
        self::assertTrue($answer['readable']);
        $actual = [];
        foreach ($answer['counts'] as $class => $count) {
            if ($count > 0) {
                $actual[$class] = $count;
            }
        }
        self::assertSame($expected, $actual);
    }

    private function reconcile(?OrderLineReaderInterface $reader = null, bool $withProducts = true): ReconcileOrders
    {
        return new ReconcileOrders(
            $this->items,
            Bootstrap::container()->get(LedgerRepositoryInterface::class),
            new FakeCapabilityChecker(1, [Capabilities::VIEW_VENDOR_FINANCE]),
            $reader,
            $withProducts ? Bootstrap::container()->get(ProductRepositoryInterface::class) : null
        );
    }

    /** How many statements a closure costs, read from MySQL's own counter. */
    private function statementsFor(callable $fn): int
    {
        $before = $this->wpdb->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();
        $fn();
        $after = $this->wpdb->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();
        // Minus the two `SHOW` statements this measurement itself costs.
        return ((int) $after['Value']) - ((int) $before['Value']) - 2;
    }

    private function soundLine(int $orderId, int $baseMinor, int $commission): int
    {
        $key = 'order:' . $orderId . ':item:' . $orderId;
        $this->accrue($key, $baseMinor, $commission);
        return $this->line($orderId, $baseMinor, ['ledger_event' => $key, 'commission' => $commission]);
    }

    /** A balanced accrual, written through the real ledger. */
    private function accrue(string $eventKey, int $baseMinor, int $commissionMinor): void
    {
        $transaction = new LedgerTransaction($eventKey, self::VENDOR, '0', '0', []);
        $transaction->add(LedgerAccount::CentralPayment, Money::of($baseMinor, 'IRT', 0), 'fixture');
        $transaction->add(LedgerAccount::Commission, Money::of(-$commissionMinor, 'IRT', 0), 'fixture');
        $transaction->add(
            LedgerAccount::VendorEarning,
            Money::of(-($baseMinor - $commissionMinor), 'IRT', 0),
            'fixture'
        );
        self::assertTrue(
            Bootstrap::container()->get(LedgerRepositoryInterface::class)->record($transaction),
            'the fixture accrual must be written'
        );
    }

    /** One ledger row, bypassing the balance rule the domain enforces. */
    private function rawLedgerRow(
        string $eventKey,
        LedgerAccount $account,
        int $minor,
        string $currency,
        int $exponent
    ): void {
        $db = new WpDatabase($this->wpdb);
        self::assertNotNull($db->execute(
            'INSERT INTO `' . $db->prefix() . 'tmc_ledger_entries`
              (event_key, vendor_user_id, account, amount_minor, currency, exponent,
               order_ref, item_ref, reason, created_at)
             VALUES (%s, %d, %s, %d, %s, %d, %s, %s, %s, %s)',
            [$eventKey, self::VENDOR, $account->value, $minor, $currency, $exponent,
                '0', '0', 'fixture', '2026-11-09 09:00:00']
        ));
    }

    /**
     * A marketplace product row owned by one shop.
     *
     * ⛔ RETURNS BOTH IDS, because two of them matter here and they are not
     * the same number: `product_id` on an order line is OUR row's id, and the
     * WooCommerce order reader speaks in WooCommerce product ids. A fixture
     * that returned one of them would quietly answer about the other.
     *
     * @return array{id:int, wc:int}
     */
    private function productOwnedBy(int $vendorUserId): array
    {
        $products = Bootstrap::container()->get(ProductRepositoryInterface::class);
        static $next = 70000;
        $next++;
        $id = $products->create(
            $vendorUserId,
            new ProductDetails(
                title: 'کالای تطبیق',
                sku: 'REC-' . $next,
                priceMinor: 100000
            ),
            ProductStatus::Draft,
            LinkOwnership::Marketplace,
            $next
        );
        self::assertGreaterThan(0, $id, 'the fixture product must be written');
        return ['id' => $id, 'wc' => $next];
    }

    /** @param array<string,mixed> $overrides */
    private function line(int $orderId, int $baseMinor, array $overrides = []): int
    {
        $id = $this->items->record(new VendorOrderItem(
            0,
            $orderId,
            $orderId,
            (int) ($overrides['product'] ?? 5000 + $orderId),
            (int) ($overrides['vendor'] ?? self::VENDOR),
            'کالای آزمایشی',
            'SKU-' . $orderId,
            1,
            null,
            $baseMinor,
            0,
            array_key_exists('commission', $overrides) ? $overrides['commission'] : intdiv($baseMinor, 10),
            array_key_exists('share', $overrides) ? $overrides['share'] : $baseMinor - intdiv($baseMinor, 10),
            array_key_exists('rate_bp', $overrides) ? $overrides['rate_bp'] : 1000,
            'general',
            array_key_exists('ledger_event', $overrides)
                ? (string) $overrides['ledger_event']
                : 'order:' . $orderId . ':item:' . $orderId,
            $overrides['status'] ?? OrderItemStatus::Placed,
            '',
            '',
            null,
            null,
            5000 + $orderId,
            '',
            'IRT',
            0
        ));
        self::assertGreaterThan(0, $id, 'the fixture row must be written');
        return $id;
    }
}
