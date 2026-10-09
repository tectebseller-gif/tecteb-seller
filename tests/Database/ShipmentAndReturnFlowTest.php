<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
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
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Application\RefundScope;
use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbShipmentRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0008ShipmentsAndReturns;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;
use Tecteb\Marketplace\Tests\Support\SpyRefundRecorder;

/**
 * Half a line on its way, and some of it coming back — on real MariaDB.
 *
 * The four properties under test are the ones the owner named: a shipped
 * quantity per line with its own tracking, never more than remains, no second
 * refund, a stock correction, and a ledger that gains reversing lines without
 * losing the originals.
 */
final class ShipmentAndReturnFlowTest extends DatabaseTestCase
{
    private const VENDOR = 71;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbOrderItemRepository $orderItems;
    private DbShipmentRepository $shipments;
    private DbLedgerRepository $ledger;
    private DbCommissionRuleRepository $rules;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private CaptureOrder $capture;
    private ManageOrderItems $orders;
    private ShipItems $ship;
    private ManageReturns $returns;
    private FakeCatalogProjector $storefront;
    private FakeProductImages $images;

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
            ...M0007SettlementTables::TABLES,
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
        $vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->orderItems = new DbOrderItemRepository($db, $clock);
        $this->shipments = new DbShipmentRepository($db, $clock);
        $this->rules = new DbCommissionRuleRepository($db, $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->images = new FakeProductImages();
        $this->storefront = new FakeCatalogProjector();

        $access = new StaffAccess($staff, $vendors);
        $readiness = new ProductReadiness($templates, $variations);
        $catalog = new SyncCatalog($this->products, $variations, $this->storefront, $audit);
        $rates = new ResolveCommissionRate($this->rules);
        $gate = new OrderOperationsGate($rates, $this->ledger, new FakeTrialUnlock(true));
        $manager = new FakeCapabilityChecker(self::MANAGER, [
            Capabilities::REVIEW_PRODUCTS,
            Capabilities::REVIEW_WITHDRAWALS,
        ]);

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $revisions,
            $readiness,
            $catalog,
            $this->images,
            $access,
            new ProductPublishPolicy($options),
            new ProductStateMachine(),
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $revisions,
            $templates,
            $readiness,
            $catalog,
            new ProductPublishPolicy($options),
            new ProductStateMachine(),
            $audit,
            $manager
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
            new OrderItemStateMachine(),
            $manager
        );
        $this->ship = new ShipItems($this->orderItems, $this->shipments, $access, $audit, $clock);
        $this->returns = new ManageReturns(
            $this->orderItems,
            $this->shipments,
            $this->ledger,
            $access,
            $audit,
            $clock,
            new ReturnStateMachine(),
            new ReturnTerms(),
            new RefundScope(),
            // No WooCommerce in the database suite, so there is nothing to
            // record a refund against. Null is the honest argument: the
            // service refuses rather than pretending.
            null,
            $this->products,
            $this->storefront,
            $manager
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه ارسال', true, false);
        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
    }

    // --- partial shipment ---------------------------------------------------

    public function testALineOfThreeShipsInTwoParcelsWithItsOwnTrackingEach(): void
    {
        $itemId = $this->sell(3, 300000);

        $first = $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 2, 'post', 'TRK-1');
        self::assertTrue($first->ok, $first->code);
        self::assertSame(1, $first->context['remaining']);
        self::assertSame(
            OrderItemStatus::PartiallyShipped,
            $this->orderItems->find($itemId)?->status,
            'two of three is not «ارسال‌شده»'
        );

        $second = $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 1, 'tipax', 'TRK-2');
        self::assertTrue($second->ok, $second->code);
        self::assertSame(0, $second->context['remaining']);
        self::assertSame(OrderItemStatus::Shipped, $this->orderItems->find($itemId)?->status);

        $parcels = $this->shipments->shipmentsFor($itemId);
        self::assertCount(2, $parcels);
        self::assertSame([2, 1], array_map(static fn ($p): int => $p->quantity, $parcels));
        self::assertSame(['TRK-1', 'TRK-2'], array_map(static fn ($p): string => $p->trackingCode, $parcels));
        self::assertSame(['post', 'tipax'], array_map(static fn ($p): string => $p->carrier, $parcels));
    }

    public function testAVendorCannotShipMoreThanIsLeft(): void
    {
        $itemId = $this->sell(3, 300000);
        self::assertTrue($this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 3, 'post')->ok);

        $tooMany = $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 1, 'post');
        self::assertFalse($tooMany->ok);
        self::assertSame('quantity_exceeds_remaining', $tooMany->code);
        self::assertSame(0, $tooMany->context['remaining']);
        self::assertCount(1, $this->shipments->shipmentsFor($itemId), 'and nothing was written');
    }

    public function testShippingNeedsACarrierAndAtLeastOneUnit(): void
    {
        $itemId = $this->sell(2, 200000);
        self::assertSame('carrier_required', $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 1, ' ')->code);
        self::assertSame('quantity_required', $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 0, 'post')->code);
        self::assertSame([], $this->shipments->shipmentsFor($itemId));
    }

    /** The status is derived, so it cannot be claimed without a parcel. */
    public function testMarkingALineShippedByHandIsRefusedAndPointsAtTheParcel(): void
    {
        $itemId = $this->sell(2, 200000);
        $result = $this->orders->move(self::VENDOR, self::VENDOR, $itemId, OrderItemStatus::Shipped);
        self::assertFalse($result->ok);
        self::assertSame('use_shipment', $result->code);
        self::assertSame(OrderItemStatus::Placed, $this->orderItems->find($itemId)?->status);
    }

    public function testAnotherShopsLineIsIndistinguishableFromOneThatDoesNotExist(): void
    {
        $itemId = $this->sell(2, 200000);
        $result = $this->ship->ship(self::VENDOR + 1, self::VENDOR + 1, $itemId, 1, 'post');
        self::assertFalse($result->ok);
        self::assertContains($result->code, ['forbidden', 'not_found']);
        self::assertSame([], $this->shipments->shipmentsFor($itemId));
    }

    // --- returns and refunds ------------------------------------------------

    public function testAReturnNeverExceedsTheLineAndIsNeverDecidedAutomatically(): void
    {
        $itemId = $this->sell(3, 300000);
        $this->ship->ship(self::VENDOR, self::VENDOR, $itemId, 3, 'post', 'TRK');

        $opened = $this->returns->open(self::VENDOR, self::VENDOR, $itemId, 2, 'کالای معیوب');
        self::assertTrue($opened->ok, $opened->code);
        $returnId = (int) $opened->context['return_id'];
        self::assertSame(
            ReturnStatus::Requested,
            $this->shipments->findReturn($returnId)?->status,
            'opening decides nothing: the terms are not set'
        );
        self::assertSame(ReturnTerms::OPEN_TERMS, (new ReturnTerms())->openTerms());
        self::assertFalse((new ReturnTerms())->decidesAutomatically());

        $tooMany = $this->returns->open(self::VENDOR, self::VENDOR, $itemId, 2, '');
        self::assertFalse($tooMany->ok);
        self::assertSame('quantity_exceeds_returnable', $tooMany->code);
        self::assertSame(1, $tooMany->context['returnable']);
    }

    public function testAReceivedReturnPutsTheGoodsBackOnTheShelf(): void
    {
        $itemId = $this->sell(3, 300000);
        $productId = (int) $this->orderItems->find($itemId)?->productId;
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        $before = $this->storefront->stock[$wcId] ?? 0;

        $returnId = (int) $this->returns->open(self::VENDOR, self::VENDOR, $itemId, 2, 'اشتباه سفارش')->context['return_id'];
        self::assertTrue($this->returns->decide(self::MANAGER, $returnId, ReturnStatus::Approved)->ok);
        $received = $this->returns->decide(self::MANAGER, $returnId, ReturnStatus::Received, '', true);

        self::assertTrue($received->ok, $received->code);
        self::assertSame(2, $received->context['restocked']);
        self::assertSame($before + 2, $this->storefront->stock[$wcId], 'WooCommerce holds the stock (ADR-008)');
        self::assertSame(2, $this->shipments->findReturn($returnId)?->restockedQuantity);
    }

    public function testARefundReversesTheLedgerWithoutTouchingTheOriginalLines(): void
    {
        $itemId = $this->sell(2, 200000);
        $item = $this->orderItems->find($itemId);
        $originalLines = count($this->ledger->forVendor(self::VENDOR, 100));

        $returnId = $this->receivedReturn($itemId, 1);
        $refunded = $this->returns->refund(self::MANAGER, $returnId);

        self::assertTrue($refunded->ok, $refunded->code);
        self::assertSame(100000, $refunded->context['refund_minor'], 'half of a two-unit line');

        $after = $this->ledger->forVendor(self::VENDOR, 100);
        self::assertGreaterThan($originalLines, count($after), 'reversing lines were ADDED');
        // Every original line is still there, unedited: the accrual event still
        // carries its four lines with the amounts the sale recorded.
        $accrual = $this->ledger->forEvent((string) $item?->ledgerEvent);
        self::assertCount(4, $accrual);
        self::assertSame(
            (int) $item?->baseMinor + (int) $item?->taxMinor,
            (int) array_sum(array_map(
                static fn ($e): int => $e->account === LedgerAccount::CentralPayment ? $e->amount->minor : 0,
                $accrual
            )),
            'the original accrual is untouched'
        );

        $reversal = $this->ledger->forEvent('return:' . $itemId . ':' . $returnId);
        self::assertCount(4, $reversal);
        self::assertSame(0, array_sum(array_map(static fn ($e): int => $e->amount->minor, $reversal)), 'and it balances');
        foreach ($reversal as $entry) {
            self::assertTrue($entry->isReversal(), 'each line names the accrual line it undoes');
        }
    }

    public function testTheSameReturnCannotBeRefundedTwice(): void
    {
        $itemId = $this->sell(2, 200000);
        $returnId = $this->receivedReturn($itemId, 1);

        self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);
        $again = $this->returns->refund(self::MANAGER, $returnId);

        self::assertFalse($again->ok);
        self::assertSame('already_refunded', $again->code);
        self::assertCount(
            4,
            $this->ledger->forEvent('return:' . $itemId . ':' . $returnId),
            'still exactly one set of reversing lines'
        );
    }

    /**
     * A line refunded unit by unit gives back exactly what it accrued.
     *
     * The rounding of the middle returns is allowed to be inexact; the LAST
     * one closes the line, so the totals have to meet to the currency unit.
     */
    public function testRefundingAThreeUnitLineOneAtATimeAddsUpExactly(): void
    {
        $itemId = $this->sell(3, 100000);       // 33333.33 per unit: not divisible
        $item = $this->orderItems->find($itemId);

        $total = 0;
        for ($i = 0; $i < 3; $i++) {
            $returnId = $this->receivedReturn($itemId, 1);
            $result = $this->returns->refund(self::MANAGER, $returnId);
            self::assertTrue($result->ok, $result->code);
            $total += (int) $result->context['refund_minor'];
        }
        self::assertSame((int) $item?->baseMinor, $total, 'no unit of currency lost or invented');

        $commissionBack = 0;
        foreach ($this->ledger->forVendor(self::VENDOR, 200) as $entry) {
            if ($entry->account === LedgerAccount::Commission && $entry->amount->minor > 0) {
                $commissionBack += $entry->amount->minor;
            }
        }
        self::assertSame((int) $item?->commissionMinor, $commissionBack, 'and the commission came back whole');
    }

    /**
     * A refund that gets half way does not pretend it finished.
     *
     * The row is claimed first — that is what makes a second refund
     * impossible — and if the ledger then refuses the reversing transaction,
     * the two stores disagree. Parking the return in «نیازمند تطبیق» is the
     * only honest outcome: retrying automatically would be guessing which
     * store is right, which is the same mistake FIN-05 forbids for a transfer
     * whose outcome is unknown.
     */
    public function testARefundWhoseLedgerWriteFailsIsParkedForAPersonRatherThanRetried(): void
    {
        $itemId = $this->sell(2, 200000);
        $returnId = $this->receivedReturn($itemId, 1);

        // The ledger already holds this exact event, so record() will refuse —
        // the shape of "the row went through and the books did not".
        $eventKey = 'return:' . $itemId . ':' . $returnId;
        $this->ledger->record(
            (new LedgerTransaction($eventKey, self::VENDOR, '0', (string) $itemId))
                ->add(LedgerAccount::CentralPayment, Money::of(1000), 'squatter')
                ->add(LedgerAccount::Commission, Money::of(-1000), 'squatter')
        );

        $result = $this->returns->refund(self::MANAGER, $returnId);

        self::assertFalse($result->ok);
        self::assertSame('ledger_already_recorded', $result->code);
        self::assertSame(
            ReturnStatus::ReconciliationRequired,
            $this->shipments->findReturn($returnId)?->status,
            'parked, not left claiming a reversal that is not in the books'
        );
        // …and the quantity is still held against the line, because those
        // goods are back whatever the books say.
        self::assertSame(1, $this->shipments->returnedQuantity($itemId));
    }

    /**
     * Two refunds at the same moment: exactly one writes.
     *
     * Not a re-run of the sequential test — this calls through two SEPARATE
     * service graphs over their own repository instances, which is the closest
     * a single process gets to two requests racing. What decides the winner is
     * the database: `recordReversal()` is an UPDATE guarded by
     * `reversal_event_key IS NULL` behind a unique index, so the loser affects
     * zero rows rather than overwriting the winner's numbers.
     */
    public function testTwoRefundsRacingOnTheSameReturnProduceExactlyOneReversal(): void
    {
        $itemId = $this->sell(2, 200000);
        $returnId = $this->receivedReturn($itemId, 1);

        $second = $this->secondRefundService();
        $first = $this->returns->refund(self::MANAGER, $returnId);
        $other = $second->refund(self::MANAGER, $returnId);

        $wins = array_filter([$first, $other], static fn ($r): bool => $r->ok);
        self::assertCount(1, $wins, 'exactly one of the two wrote the refund');
        $loser = $first->ok ? $other : $first;
        self::assertSame('already_refunded', $loser->code);

        self::assertCount(
            4,
            $this->ledger->forEvent('return:' . $itemId . ':' . $returnId),
            'one set of reversing lines, not two'
        );
        $row = $this->shipments->findReturn($returnId);
        self::assertSame(ReturnStatus::Refunded, $row?->status);
        self::assertSame(100000, $row?->refundMinor, 'and the amount is the winner\'s, unedited');
    }

    /** The refund names what it did and what it did not. */
    public function testARefundSaysWhichOfItsFourPartsActuallyHappened(): void
    {
        $itemId = $this->sell(2, 200000);
        $returnId = $this->receivedReturn($itemId, 1);

        $result = $this->returns->refund(self::MANAGER, $returnId);

        self::assertTrue($result->ok);
        self::assertTrue($result->context['did_ledger'], 'the books are this plugin\'s job');
        self::assertFalse($result->context['did_money'], 'moving money is not, and cannot be');
        self::assertFalse($result->context['did_wc_refund'], 'nor is creating a WooCommerce refund');
        self::assertFalse((new RefundScope())->canTransferMoney(), 'there is no gateway in this build');
    }

    public function testALineWithNoRecordedShareHasNothingToReverse(): void
    {
        // No rate at all: FIN-02 says record nothing rather than zero.
        $this->rules->clearRate(RateScope::General, 'general');
        $itemId = $this->sell(1, 100000, false);
        $returnId = $this->receivedReturn($itemId, 1);

        $result = $this->returns->refund(self::MANAGER, $returnId);
        self::assertFalse($result->ok);
        self::assertSame('nothing_recorded', $result->code);
    }

    public function testAVendorCannotRefundTheirOwnSale(): void
    {
        $itemId = $this->sell(2, 200000);
        $returnId = $this->receivedReturn($itemId, 1);

        $vendorSide = new ManageReturns(
            $this->orderItems,
            $this->shipments,
            $this->ledger,
            new StaffAccess(
                new DbStaffRepository(new WpDatabase($this->wpdb), new SystemClock()),
                new DbVendorRepository(new WpDatabase($this->wpdb), new SystemClock())
            ),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new SystemClock(),
            new ReturnStateMachine(),
            new ReturnTerms(),
            new RefundScope(),
            // No WooCommerce in the database suite, so there is nothing to
            // record a refund against. Null is the honest argument: the
            // service refuses rather than pretending.
            null,
            $this->products,
            $this->storefront,
            new FakeCapabilityChecker(self::VENDOR, [])
        );
        $result = $vendorSide->refund(self::VENDOR, $returnId);
        self::assertFalse($result->ok);
        self::assertSame('forbidden', $result->code);
    }

    /** A second, independent service graph — two requests, not one retried. */
    /**
     * The same service, with a recorder that remembers what it was handed.
     *
     * The capability is `REVIEW_VENDOR`, because that is what
     * `recordWooCommerceRefund()` asks for — a different permission from the
     * ledger reversal above it, on purpose.
     */
    private function refundServiceWith(SpyRefundRecorder $recorder): ManageReturns
    {
        $db = new WpDatabase($this->wpdb);
        $clock = new SystemClock();
        return new ManageReturns(
            new DbOrderItemRepository($db, $clock),
            new DbShipmentRepository($db, $clock),
            new DbLedgerRepository($db, $clock),
            new StaffAccess(new DbStaffRepository($db, $clock), new DbVendorRepository($db, $clock)),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new ReturnStateMachine(),
            new ReturnTerms(),
            new RefundScope(),
            $recorder,
            new DbProductRepository($db, $clock),
            $this->storefront,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR, Capabilities::REVIEW_WITHDRAWALS])
        );
    }

    private function secondRefundService(): ManageReturns
    {
        $db = new WpDatabase($this->wpdb);
        $clock = new SystemClock();
        return new ManageReturns(
            new DbOrderItemRepository($db, $clock),
            new DbShipmentRepository($db, $clock),
            new DbLedgerRepository($db, $clock),
            new StaffAccess(new DbStaffRepository($db, $clock), new DbVendorRepository($db, $clock)),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new ReturnStateMachine(),
            new ReturnTerms(),
            new RefundScope(),
            // No WooCommerce in the database suite, so there is nothing to
            // record a refund against. Null is the honest argument: the
            // service refuses rather than pretending.
            null,
            new DbProductRepository($db, $clock),
            $this->storefront,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );
    }

    /**
     * WHAT THIS PROVES: the amount handed to WooCommerce is the amount that
     * was recorded — and for a ریال order of ۱۰۰٬۰۰۰ that is `'100000'`, not
     * `'1000'`.
     *
     * **The defect this pins down.** `alpha.38`'s
     * `recordWooCommerceRefund()` computed
     * `(refundMinor + taxRefundMinor) / 100`. Nothing else in the plugin
     * scales money by a hundred: orders are captured at `exponent = 0` because
     * ریال and تومان have no minor unit, so a line stored as `100000` IS one
     * hundred thousand. Every refund this build recorded was a hundredth of
     * its value, and no test looked — every returns test passed `null` for the
     * recorder, which is honest about WooCommerce and silent about the
     * conversion.
     *
     * A double stands in for WooCommerce and is not presented as WooCommerce:
     * what is asserted is the argument this plugin hands over.
     */
    public function testTheRefundAmountIsTheRecordedAmountAndNotAHundredthOfIt(): void
    {
        $itemId = $this->sell(1, 100000);
        $returnId = $this->receivedReturn($itemId, 1);
        self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);

        $spy = new SpyRefundRecorder();
        $result = $this->refundServiceWith($spy)->recordWooCommerceRefund(self::MANAGER, $returnId);

        self::assertTrue($result->ok, $result->code);
        self::assertCount(1, $spy->calls, 'exactly one refund record');
        // 100,000 base + 10,000 tax, at exponent 0.
        self::assertSame('110000', $spy->lastAmount(), 'the recorded amount, in the order own unit');
        self::assertNotSame('1100', $spy->lastAmount(), 'and not the hundredth alpha.38 sent');
    }

    /**
     * WHAT THIS PROVES: a line whose unit was never recorded is REFUSED by
     * name rather than converted on a hunch.
     *
     * «اگر اطلاعات واحد برای رکورد قدیمی کافی نیست، وضعیت و راه بازیابی روشن
     * باشد». A line captured while no commission rate resolved carries no
     * ledger event, so no stored unit exists for it — and the refusal names
     * the line and the empty event so a person can act.
     */
    public function testALineWithNoRecordedUnitIsRefusedRatherThanGuessed(): void
    {
        $itemId = $this->sell(1, 100000);
        $returnId = $this->receivedReturn($itemId, 1);
        self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);

        // The legacy shape, made on purpose: a line whose amounts are stored
        // and whose LEDGER EVENT is not. Rows like this exist — a line
        // captured while no rate resolved keeps its amounts and records no
        // event — and until `alpha.39` the refund path did not need a unit
        // from anywhere, so it never noticed. Written straight to the column
        // because no service produces this state on purpose; the point is to
        // meet it, not to make it.
        $table = $this->wpdb->prefix . M0006CatalogAndOrders::ORDER_ITEMS;
        $this->wpdb->pdo()->exec("UPDATE `{$table}` SET ledger_event = '' WHERE id = {$itemId}");

        $spy = new SpyRefundRecorder();
        $result = $this->refundServiceWith($spy)->recordWooCommerceRefund(self::MANAGER, $returnId);

        self::assertFalse($result->ok, 'a unit nobody recorded is not a unit to convert with');
        self::assertSame('refund_unit_unknown', $result->code);
        self::assertSame([], $spy->calls, 'nothing was handed over at all');
        // And the refusal names what a person has to look at.
        self::assertSame($itemId, (int) $result->context['order_item_id']);
        self::assertSame('', (string) $result->context['ledger_event']);
    }

    /**
     * WHAT THIS PROVES: partial refunds of one line add up to the line and
     * never past it, with every amount in the recorded unit.
     *
     * Three units sold, refunded one at a time: three records, each the third
     * of the line it reverses, and their sum exactly the line.
     */
    public function testPartialRefundsAddUpToTheLineAndNoFurther(): void
    {
        $itemId = $this->sell(3, 300000);
        $spy = new SpyRefundRecorder();
        $service = $this->refundServiceWith($spy);

        $total = 0;
        for ($i = 0; $i < 3; $i++) {
            $returnId = $this->receivedReturn($itemId, 1);
            self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);
            $result = $service->recordWooCommerceRefund(self::MANAGER, $returnId);
            self::assertTrue($result->ok, $result->code);
            $total += (int) $spy->lastAmount();
        }

        self::assertCount(3, $spy->calls);
        // 300,000 base + 30,000 tax, reversed in three parts with nothing lost
        // to rounding and nothing invented.
        self::assertSame(330000, $total, 'the three parts are exactly the line');
        foreach ($spy->calls as $call) {
            self::assertSame('110000', $call['amount']);
            self::assertSame(1, $call['quantity']);
        }
    }

    // --- helpers ------------------------------------------------------------

    /** Publishes a product, sells `$quantity` of it, returns our line id. */
    /**
     * WHAT THIS PROVES: the LEDGER reversal is written in the unit the sale
     * was recorded in — for every unit, and without the caller saying so.
     *
     * **The defect.** `alpha.39` read the unit off the accrual for the
     * WooCommerce record and left `refund()` itself with
     * `string $currency = 'IRR', int $exponent = 0` as DEFAULT PARAMETERS
     * which `ReturnsPage` — its only caller — never passed. So on a site whose
     * orders are `IRT` or `USD`, every reversal line went into the books as
     * `IRR`/0: a reversal that balances arithmetically and describes a
     * different currency from the sale it undoes. `balances()` could not catch
     * it, because all four reversal lines shared the wrong unit.
     *
     * Three units, one per run of this data provider, each asserted against
     * the accrual's own unit rather than against a constant — so the test
     * cannot drift to agreeing with a new wrong default.
     *
     * @dataProvider recordedUnits
     */
    public function testTheLedgerReversalCarriesTheUnitOfTheSaleItUndoes(
        string $currency,
        int $exponent,
        int $totalMinor,
        int $taxMinor
    ): void {
        $sold = $this->sellIn($currency, $exponent, 1, $totalMinor, $taxMinor);
        $item = $this->orderItems->find($sold['item']);
        self::assertNotNull($item);
        self::assertSame(
            [$currency . '/' . $exponent],
            $this->unitsOfEvent($item->ledgerEvent),
            'the fixture must have recorded the sale in exactly this unit'
        );

        $returnId = $this->receivedReturn($sold['item'], 1);
        self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);

        // The reversal, asked of the ledger itself.
        $reversal = $this->ledger->forEvent('return:' . $sold['item'] . ':' . $returnId);
        self::assertCount(4, $reversal, 'four lines reverse four lines');
        self::assertSame(
            [$currency . '/' . $exponent],
            $this->unitsOfEvent('return:' . $sold['item'] . ':' . $returnId),
            'and every one of them is in the unit of the sale'
        );

        // And the vendor's books hold ONE unit afterwards, which is the point:
        // a mixed ledger is a balance nobody can add up.
        $booked = [];
        foreach ($this->ledger->forVendor(self::VENDOR, 500) as $entry) {
            $booked[$entry->amount->currency . '/' . $entry->amount->exponent] = true;
        }
        self::assertSame([$currency . '/' . $exponent], array_keys($booked));
    }

    /** @return array<string,array{0:string,1:int,2:int,3:int}> */
    public static function recordedUnits(): array
    {
        return [
            // تومان: no minor unit, so 110,000 IS one hundred and ten thousand.
            'IRT/0' => ['IRT', 0, 100000, 10000],
            // ریال: the same scale, a different code, and neither is ten or a
            // hundred times the other as far as this plugin is concerned.
            'IRR/0' => ['IRR', 0, 100000, 10000],
            // A decimal currency, which is the case the IRR/0 default could
            // never have been right for.
            'USD/2' => ['USD', 2, 10050, 500],
        ];
    }

    /**
     * WHAT THIS PROVES: an accrual whose own lines disagree about the unit is
     * refused by name, with NOTHING written.
     *
     * `alpha.39`'s `refundUnit()` read `forEvent()[0]` and stopped, so an
     * event like this one answered «USD/2» and the other three lines were
     * reversed in it. The read now covers every line, and this is the one
     * state where no answer is the right answer: converting would need an
     * exchange rate nobody recorded.
     *
     * The mixed row is written with SQL on purpose — no service produces this
     * state, and the point is to MEET it rather than make it.
     */
    public function testAnAccrualWhoseLinesDisagreeAboutTheUnitIsRefused(): void
    {
        $sold = $this->sellIn('IRT', 0, 1, 100000, 10000);
        $item = $this->orderItems->find($sold['item']);
        self::assertNotNull($item);
        $returnId = $this->receivedReturn($sold['item'], 1);

        $ledgerTable = $this->wpdb->prefix . M0004CreateFinanceTables::LEDGER;
        $one = (int) $this->wpdb->pdo()
            ->query("SELECT MIN(id) FROM `{$ledgerTable}` WHERE event_key = '" . $item->ledgerEvent . "'")
            ->fetchColumn();
        $this->wpdb->pdo()->exec(
            "UPDATE `{$ledgerTable}` SET currency = 'USD', exponent = 2 WHERE id = {$one}"
        );
        self::assertCount(2, $this->unitsOfEvent($item->ledgerEvent), 'the event is really mixed now');

        $before = $this->shipments->findReturn($returnId);
        $result = $this->returns->refund(self::MANAGER, $returnId);

        self::assertFalse($result->ok);
        self::assertSame('refund_unit_inconsistent', $result->code);
        self::assertSame($sold['item'], (int) $result->context['item_id']);
        self::assertSame($item->ledgerEvent, (string) $result->context['event_key']);

        // NOTHING written: no reversal event, and the return exactly where it
        // was. The unit is read before `recordReversal()` for this reason —
        // a row with no ledger entry behind it is the state FIN-03 exists to
        // prevent.
        self::assertSame([], $this->ledger->forEvent('return:' . $sold['item'] . ':' . $returnId));
        $after = $this->shipments->findReturn($returnId);
        self::assertSame($before?->status, $after?->status);
        self::assertSame($before?->reversalEventKey, $after?->reversalEventKey);
    }

    /**
     * WHAT THIS PROVES: the amount AND the tax split handed to WooCommerce are
     * the recorded figures in the recorded unit — for a decimal currency,
     * where a wrong scale is visible in the string itself.
     */
    public function testTheWooCommerceArgumentsCarryTheAmountAndTheTaxInTheRecordedUnit(): void
    {
        // 100.50 of goods and 5.00 of tax, at exponent 2.
        $sold = $this->sellIn('USD', 2, 1, 10050, 500);
        $returnId = $this->receivedReturn($sold['item'], 1);
        self::assertTrue($this->returns->refund(self::MANAGER, $returnId)->ok);

        $request = $this->shipments->findReturn($returnId);
        self::assertSame(10050, (int) $request?->refundMinor, 'the goods, in minor units');
        self::assertSame(500, (int) $request?->taxRefundMinor, 'and the tax, separately');

        $spy = new SpyRefundRecorder();
        $result = $this->refundServiceWith($spy)->recordWooCommerceRefund(self::MANAGER, $returnId);

        self::assertTrue($result->ok, $result->code);
        self::assertCount(1, $spy->calls);
        // 10050 + 500 = 10550 minor units at exponent 2 → «105.50».
        self::assertSame('105.50', $spy->lastAmount(), 'goods plus tax, at the recorded scale');
        self::assertNotSame('10550', $spy->lastAmount(), 'not the minor units as if they were major');
        self::assertNotSame('105.5', $spy->lastAmount(), 'and the scale is carried, not trimmed');
        self::assertSame($sold['order'], $spy->calls[0]['order']);
        self::assertSame($sold['order_item'], $spy->calls[0]['item']);
        self::assertSame(1, $spy->calls[0]['quantity']);
        self::assertSame($returnId, $spy->calls[0]['return']);
    }

    /**
     * WHAT THIS PROVES: `refund()` has no unit to pass, so no caller can
     * change the historical unit from a form.
     *
     * «تنظیم فعلی فروشگاه یا ورودی caller نباید واحد تاریخی را عوض کند» — the
     * strongest form of that is a method that cannot be told. Asserted by
     * reflection rather than by reading the file, so a parameter added back
     * with any name or default fails here.
     */
    public function testRefundCannotBeToldWhichUnitToUse(): void
    {
        $parameters = (new \ReflectionMethod(ManageReturns::class, 'refund'))->getParameters();
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);
        self::assertSame(['actorId', 'returnId', 'wcRefundId', 'note'], $names);
        foreach ($parameters as $parameter) {
            $type = (string) $parameter->getType();
            self::assertStringNotContainsStringIgnoringCase(
                'Money',
                $type,
                'the unit is read off the accrual, never handed in'
            );
        }
    }

    /**
     * The same sale, in a unit this test chooses.
     *
     * `capture()` has taken the order's currency and exponent since
     * `alpha.39`; this hands them over so a test can ask what a reversal of a
     * `USD`/2 or `IRT`/0 sale is written in. One unit per test method, because
     * `CaptureOrder` refuses a second unit in one vendor's books on purpose
     * and each method gets a fresh schema.
     *
     * @return array{item:int, order:int, order_item:int}
     */
    private function sellIn(string $currency, int $exponent, int $quantity, int $totalMinor, int $taxMinor): array
    {
        static $seq = 0;
        $seq++;
        $productId = $this->publish('کالای واحد ' . $seq, 'UNIT-' . $seq);
        $product = $this->products->find($productId);
        $orderId = 7500 + $seq;
        $orderItemId = 8500 + $seq;
        $report = $this->capture->capture($orderId, [[
            'order_item_id' => $orderItemId,
            'wc_product_id' => (int) $product?->wcProductId,
            'variation_id' => null,
            'title' => (string) $product?->details->title,
            'sku' => (string) $product?->details->sku,
            'quantity' => $quantity,
            'line_total_minor' => $totalMinor,
            'line_tax_minor' => $taxMinor,
        ]], $currency, $exponent);
        self::assertSame(1, $report['captured'], 'reasons: ' . implode(',', $report['reasons']));
        $line = $this->orderItems->findByOrderItem($orderItemId);
        self::assertNotNull($line);
        return ['item' => $line->id, 'order' => $orderId, 'order_item' => $orderItemId];
    }

    /** Every distinct unit the ledger holds for this event, as `CUR/exp`. */
    private function unitsOfEvent(string $eventKey): array
    {
        $units = [];
        foreach ($this->ledger->forEvent($eventKey) as $entry) {
            $units[$entry->amount->currency . '/' . $entry->amount->exponent] = true;
        }
        return array_keys($units);
    }

    private function sell(int $quantity, int $totalMinor, bool $expectRecorded = true): int
    {
        static $seq = 0;
        $seq++;
        $productId = $this->publish('کالای ' . $seq, 'SKU-' . $seq);
        $product = $this->products->find($productId);
        $report = $this->capture->capture(7000 + $seq, [[
            'order_item_id' => 8000 + $seq,
            'wc_product_id' => (int) $product?->wcProductId,
            'variation_id' => null,
            'title' => (string) $product?->details->title,
            'sku' => (string) $product?->details->sku,
            'quantity' => $quantity,
            'line_total_minor' => $totalMinor,
            // Tax on purpose: it is a separate ledger account and a separate
            // share of the refund, and a fixture with zero tax would let a
            // missing tax reversal pass unnoticed.
            'line_tax_minor' => intdiv($totalMinor, 10),
        ]]);
        self::assertSame(1, $report['captured']);
        self::assertSame($expectRecorded ? 0 : 1, $report['unrecorded']);
        $line = $this->orderItems->findByOrderItem(8000 + $seq);
        self::assertNotNull($line);
        return $line->id;
    }

    /** A return of `$quantity`, approved and received, ready to be refunded. */
    private function receivedReturn(int $itemId, int $quantity): int
    {
        $opened = $this->returns->open(self::VENDOR, self::VENDOR, $itemId, $quantity, 'آزمون');
        self::assertTrue($opened->ok, $opened->code);
        $returnId = (int) $opened->context['return_id'];
        self::assertTrue($this->returns->decide(self::MANAGER, $returnId, ReturnStatus::Approved)->ok);
        self::assertTrue($this->returns->decide(self::MANAGER, $returnId, ReturnStatus::Received)->ok);
        return $returnId;
    }

    private function publish(string $title, string $sku): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 20
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        $mediaId = 900 + $productId;
        $this->images->give($mediaId, self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 20
        ), [], [$mediaId], $mediaId, $this->stampOf($productId));
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);
        return $productId;
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
