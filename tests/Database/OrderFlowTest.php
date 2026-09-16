<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
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
    private ShipItems $ship;
    private PurchasePolicy $purchase;
    private DbVendorRepository $vendors;
    private FakeCatalogProjector $storefront;
    private FakeProductImages $images;
    private FakeTrialUnlock $trial;

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
        $readiness = new ProductReadiness($templates, $variations);
        $catalog = new SyncCatalog($this->products, $variations, $this->storefront, $audit);
        $publishing = new ProductPublishPolicy($options);
        $rates = new ResolveCommissionRate($this->rules);
        $gate = new OrderOperationsGate($rates, $this->ledger, $this->trial);

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
            $audit
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
        self::assertSame(400000, $linesA[0]->baseMinor);
        self::assertSame(40000, $linesA[0]->commissionMinor, 'ten percent of the line, after discount, before tax');
        self::assertSame(360000, $linesA[0]->vendorShareMinor);
        self::assertSame(1000, $linesA[0]->rateBasisPoints, 'the rate is snapshotted with the sale');
        self::assertSame(15000, $linesB[0]->commissionMinor);
    }

    public function testTheLedgerHoldsTheMoneyAndARetryWritesNothingTwice(): void
    {
        $a = $this->publish(self::VENDOR_A, 'دستکش لاتکس', 'A-1');
        $lines = [$this->line(21, $a, 1, 500000)];

        $this->capture->capture(5002, $lines);
        $first = $this->ledger->forEvent(CaptureOrder::eventKey(5002, 21));
        self::assertNotSame([], $first, 'the sale reached the ledger');

        $balances = $this->ledger->balances(self::VENDOR_A);
        self::assertSame(-450000, $balances[LedgerAccount::VendorEarning->value] ?? 0);
        self::assertSame(-50000, $balances[LedgerAccount::Commission->value] ?? 0);

        // The checkout hook fires again — a retried payment callback, a second
        // WooCommerce hook for the same order.
        $again = $this->capture->capture(5002, $lines);
        self::assertSame(0, $again['captured'], 'nothing is captured twice');
        self::assertCount(count($first), $this->ledger->forEvent(CaptureOrder::eventKey(5002, 21)));
        self::assertCount(1, $this->orderItems->forVendor(self::VENDOR_A));
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
        $this->manage->updateInventory(self::VENDOR_A, self::VENDOR_A, $a, 0, 'A-1', 1, null);
        $fresh = new PurchasePolicy($this->products, new StaffAccess($this->staff, $this->vendors), new OrderOperationsGate(
            new ResolveCommissionRate($this->rules),
            $this->ledger,
            $this->trial
        ));
        self::assertSame(PurchasePolicy::OUT_OF_STOCK, $fresh->decide($wcId)['decision']);

        // Suspending the shop stops everything it sells, at once.
        $this->manage->updateInventory(self::VENDOR_A, self::VENDOR_A, $a, 9, 'A-1', 1, null);
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
        self::assertSame(300000, $totals['base']);
        self::assertSame(30000, $totals['commission']);
        self::assertSame(270000, $totals['share']);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{order_item_id:int, wc_product_id:int, variation_id:null, title:string, sku:string, quantity:int, line_total_minor:int, line_tax_minor:int} */
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
