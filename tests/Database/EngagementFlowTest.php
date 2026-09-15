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
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Coupon;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\DbEngagementRepository;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0009EngagementTables;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

/**
 * The phase-7 rules, on real MariaDB.
 *
 * Each test is one approved sentence, or one thing this project refuses to
 * decide on the owner's behalf.
 */
final class EngagementFlowTest extends DatabaseTestCase
{
    private const VENDOR = 81;
    private const OTHER_VENDOR = 82;
    private const MANAGER = 9;
    private const BUYER = 700;

    private DbEngagementRepository $repository;
    private DbProductRepository $products;
    private ManageCoupons $coupons;
    private ManageWholesale $wholesale;
    private ManageTickets $tickets;
    private ManageProducts $manage;
    private FakeProductImages $images;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            ...M0009EngagementTables::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        (new M0001CreateAuditTable())->up($db);
        (new M0002CreateVendorTables())->up($db);
        (new M0003CreateStoreAndStaffTables())->up($db);
        (new M0005CreateProductTables())->up($db);
        (new M0006CatalogAndOrders())->up($db);
        (new M0009EngagementTables())->up($db);

        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->repository = new DbEngagementRepository($db, $clock);
        $this->products = new DbProductRepository($db, $clock);
        $vendors = new DbVendorRepository($db, $clock);
        $access = new StaffAccess(new DbStaffRepository($db, $clock), $vendors);
        $manager = new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]);
        $this->images = new FakeProductImages();

        $this->coupons = new ManageCoupons($this->repository, $access, $audit, $clock, $manager);
        $this->wholesale = new ManageWholesale($this->repository, $this->products, $access, $audit, $manager);
        $this->tickets = new ManageTickets($this->repository, $access, $audit, $clock, $manager);

        $variations = new DbVariationRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            new DbProductRevisionRepository($db, $clock),
            new ProductReadiness($templates, $variations),
            new SyncCatalog($this->products, $variations, new FakeCatalogProjector(), $audit),
            $this->images,
            $access,
            new ProductPublishPolicy(new WpOptionStore()),
            new ProductStateMachine(),
            $audit
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه کوپن', true, false);
        $vendors->upsertProfile(self::OTHER_VENDOR, 'داروخانه دیگر', true, false);
    }

    // --- coupons ------------------------------------------------------------

    public function testAVendorsCodeDiscountsTheirOwnBasketAndNobodyElses(): void
    {
        $created = $this->coupons->create(self::VENDOR, self::VENDOR, 'bahar10', Coupon::PERCENT, 1000);
        self::assertTrue($created->ok, $created->code);
        self::assertSame('BAHAR10', $created->context['code'], 'stored upper-case, so case cannot split a code');

        $mine = $this->coupons->quote('BAHAR10', self::VENDOR, 200000);
        self::assertTrue($mine['ok']);
        self::assertSame(20000, $mine['discount_minor'], 'ten percent of this shop\'s subtotal');

        $theirs = $this->coupons->quote('BAHAR10', self::OTHER_VENDOR, 200000);
        self::assertFalse($theirs['ok']);
        self::assertSame('coupon_other_vendor', $theirs['reason']);
        self::assertSame(0, $theirs['discount_minor']);
    }

    /** «تخفیف سراسری فقط با مدیر است» — and who funds it is DEC-04, so: not yet. */
    public function testAMarketplaceWideCodeIsRefusedAndNamesTheDecision(): void
    {
        $result = $this->coupons->create(self::MANAGER, 0, 'NOWRUZ', Coupon::PERCENT, 1000);
        self::assertFalse($result->ok);
        self::assertSame(ManageCoupons::GLOBAL_UNDECIDED, $result->code);
        self::assertSame(ManageCoupons::GLOBAL_DECISION, $result->context['decision']);
        self::assertNull($this->repository->findCouponByCode('NOWRUZ'), 'and nothing was written');
        self::assertFalse($this->coupons->globalCouponsAvailable());
    }

    public function testACodeIsCountedOncePerOrderHoweverOftenTheCallbackFires(): void
    {
        $couponId = (int) $this->coupons->create(self::VENDOR, self::VENDOR, 'ONCE', Coupon::FIXED, 5000)->context['coupon_id'];

        self::assertTrue($this->coupons->recordUse($couponId, 9001, self::BUYER, 5000));
        self::assertFalse($this->coupons->recordUse($couponId, 9001, self::BUYER, 5000), 'the same order again');
        self::assertSame(1, $this->repository->couponUseCount($couponId));

        self::assertTrue($this->coupons->recordUse($couponId, 9002, self::BUYER, 5000), 'a different order does count');
        self::assertSame(2, $this->repository->couponUseCount($couponId));
    }

    public function testALimitIsCheckedAgainstTheRowsAndStopsTheNextUse(): void
    {
        $couponId = (int) $this->coupons->create(
            self::VENDOR, self::VENDOR, 'LIMITED', Coupon::FIXED, 5000, 0, null, 1
        )->context['coupon_id'];

        self::assertTrue($this->coupons->quote('LIMITED', self::VENDOR, 100000)['ok']);
        $this->coupons->recordUse($couponId, 9100, self::BUYER, 5000);

        $after = $this->coupons->quote('LIMITED', self::VENDOR, 100000);
        self::assertFalse($after['ok']);
        self::assertSame('coupon_exhausted', $after['reason']);
    }

    public function testACodeCannotTakeMoreThanTheBasketOrBeWorthNothing(): void
    {
        $this->coupons->create(self::VENDOR, self::VENDOR, 'BIG', Coupon::FIXED, 900000);
        $quote = $this->coupons->quote('BIG', self::VENDOR, 100000);
        self::assertTrue($quote['ok']);
        self::assertSame(100000, $quote['discount_minor'], 'never more than the subtotal');

        $tooMuch = $this->coupons->create(self::VENDOR, self::VENDOR, 'OVER', Coupon::PERCENT, 10001);
        self::assertFalse($tooMuch->ok);
        self::assertSame('coupon_value_invalid', $tooMuch->code);
    }

    // --- wholesale ----------------------------------------------------------

    public function testOnlyAnApprovedBuyerSeesTheLadder(): void
    {
        $productId = $this->publish('محلول ضدعفونی', 'W-1');
        $saved = $this->wholesale->setTiers(self::VENDOR, self::VENDOR, $productId, [5 => 180000, 20 => 150000]);
        self::assertTrue($saved->ok, $saved->code);
        self::assertSame(2, $saved->context['steps']);

        self::assertTrue($this->wholesale->apply(self::BUYER, 'شرکت آزمایشی', '1234')->ok);
        self::assertNull($this->wholesale->priceFor(self::BUYER, $productId, 20), 'asking is not approval');

        self::assertTrue($this->wholesale->decide(self::BUYER, WholesaleStatus::Approved)->ok);
        self::assertSame(180000, $this->wholesale->priceFor(self::BUYER, $productId, 5));
        self::assertSame(150000, $this->wholesale->priceFor(self::BUYER, $productId, 25), 'the highest step that fits');
        self::assertNull($this->wholesale->priceFor(self::BUYER, $productId, 4), 'below the first step');

        self::assertTrue($this->wholesale->decide(self::BUYER, WholesaleStatus::Suspended)->ok);
        self::assertNull($this->wholesale->priceFor(self::BUYER, $productId, 25), 'suspension takes it away at once');
    }

    public function testALadderMustGetCheaperAsItGoesUp(): void
    {
        $productId = $this->publish('دستکش', 'W-2');
        $wrongWay = $this->wholesale->setTiers(self::VENDOR, self::VENDOR, $productId, [5 => 150000, 20 => 180000]);
        self::assertFalse($wrongWay->ok);
        self::assertSame('tier_not_descending', $wrongWay->code);

        $stepOfOne = $this->wholesale->setTiers(self::VENDOR, self::VENDOR, $productId, [1 => 150000]);
        self::assertFalse($stepOfOne->ok);
        self::assertSame('tier_quantity_invalid', $stepOfOne->code);

        $aboveRetail = $this->wholesale->setTiers(self::VENDOR, self::VENDOR, $productId, [5 => 900000]);
        self::assertFalse($aboveRetail->ok);
        self::assertSame('tier_price_invalid', $aboveRetail->code);

        self::assertSame([], $this->repository->tiersFor($productId), 'and none of them wrote a row');
    }

    public function testAnotherShopsProductCannotBeGivenALadder(): void
    {
        $productId = $this->publish('ماسک', 'W-3');
        $result = $this->wholesale->setTiers(self::OTHER_VENDOR, self::OTHER_VENDOR, $productId, [5 => 150000]);
        self::assertFalse($result->ok);
        self::assertSame('not_found', $result->code);
    }

    // --- tickets ------------------------------------------------------------

    public function testAThreadIsAppendOnlyAndAHiddenMessageKeepsItsText(): void
    {
        $opened = $this->tickets->open(self::VENDOR, self::VENDOR, 'سؤال دربارهٔ تسویه', 'سلام، سؤالی داشتم.');
        self::assertTrue($opened->ok, $opened->code);
        $ticketId = (int) $opened->context['ticket_id'];

        self::assertTrue($this->tickets->reply(self::MANAGER, $ticketId, 'پاسخ بازارگاه', true)->ok);
        self::assertSame(Ticket::ANSWERED, $this->repository->findTicket($ticketId)?->status);

        $messages = $this->repository->ticketMessages($ticketId);
        self::assertCount(2, $messages);
        $hidden = $this->tickets->hideMessage(self::MANAGER, $messages[0]->id, 'شامل اطلاعات شخصی بود');
        self::assertTrue($hidden->ok, $hidden->code);

        $after = $this->repository->ticketMessages($ticketId);
        self::assertTrue($after[0]->hidden);
        self::assertSame('سلام، سؤالی داشتم.', $after[0]->body, 'the text is kept, only the visibility changed');
        self::assertSame('', $after[0]->visibleBody(), 'and a reader is shown nothing');
        self::assertSame('شامل اطلاعات شخصی بود', $after[0]->hiddenReason);
        self::assertSame(self::MANAGER, $after[0]->hiddenBy);
    }

    public function testHidingNeedsAReasonAndAVendorCannotHideAnything(): void
    {
        $ticketId = (int) $this->tickets->open(self::VENDOR, self::VENDOR, 'موضوع', 'متن')->context['ticket_id'];
        $messageId = $this->repository->ticketMessages($ticketId)[0]->id;

        $noReason = $this->tickets->hideMessage(self::MANAGER, $messageId, '  ');
        self::assertFalse($noReason->ok);
        self::assertSame('hide_reason_required', $noReason->code);

        $vendorSide = new ManageTickets(
            $this->repository,
            new StaffAccess(
                new DbStaffRepository(new WpDatabase($this->wpdb), new SystemClock()),
                new DbVendorRepository(new WpDatabase($this->wpdb), new SystemClock())
            ),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new SystemClock(),
            new FakeCapabilityChecker(self::VENDOR, [])
        );
        $refused = $vendorSide->hideMessage(self::VENDOR, $messageId, 'دلخواه');
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
        self::assertFalse($this->repository->ticketMessages($ticketId)[0]->hidden);
    }

    public function testALockedThreadTakesNoMessageFromEitherSide(): void
    {
        $ticketId = (int) $this->tickets->open(self::VENDOR, self::VENDOR, 'موضوع', 'متن')->context['ticket_id'];
        self::assertTrue($this->tickets->setState(self::MANAGER, $ticketId, Ticket::OPEN, true)->ok);

        $vendorReply = $this->tickets->reply(self::VENDOR, $ticketId, 'باز هم', false);
        self::assertFalse($vendorReply->ok);
        self::assertSame('ticket_locked', $vendorReply->code);

        $managerReply = $this->tickets->reply(self::MANAGER, $ticketId, 'از طرف بازارگاه', true);
        self::assertFalse($managerReply->ok, 'a lock that only silenced the vendor would not be a lock');
        self::assertSame('ticket_locked', $managerReply->code);
        self::assertCount(1, $this->repository->ticketMessages($ticketId));
    }

    public function testAnotherShopsThreadIsNotReadable(): void
    {
        $ticketId = (int) $this->tickets->open(self::VENDOR, self::VENDOR, 'موضوع', 'متن')->context['ticket_id'];
        $outsider = new ManageTickets(
            $this->repository,
            new StaffAccess(
                new DbStaffRepository(new WpDatabase($this->wpdb), new SystemClock()),
                new DbVendorRepository(new WpDatabase($this->wpdb), new SystemClock())
            ),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new SystemClock(),
            new FakeCapabilityChecker(self::OTHER_VENDOR, [])
        );
        self::assertSame([], $outsider->thread(self::OTHER_VENDOR, $ticketId));
        self::assertSame([], $outsider->forVendor(self::OTHER_VENDOR, self::VENDOR));
    }

    // --- helper -------------------------------------------------------------

    private function publish(string $title, string $sku): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 50
        ));
        self::assertTrue($created->ok, $created->code);
        return (int) $created->context['product_id'];
    }
}
