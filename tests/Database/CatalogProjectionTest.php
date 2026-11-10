<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Events\EventBus;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeUnpaidOrderGuard;
use Tecteb\Marketplace\Tests\Support\TruncatingDatabase;

/**
 * ADR-008 as assertions: one storefront product per marketplace row, a sale
 * that a later sync cannot undo, and a withdrawal that deletes nothing.
 *
 * The storefront here is a double, because these are rules about the
 * marketplace's own behaviour — what it writes, when, and in which direction.
 * That the real WooCommerce writer obeys the same rules, and that a product
 * the marketplace did not create is never touched, is measured on a real
 * WooCommerce site in `docs/evidence/catalog/`.
 */
final class CatalogProjectionTest extends DatabaseTestCase
{
    private const VENDOR = 51;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private ReviewApplication $applications;
    private SyncCatalog $catalog;
    private FakeCatalogProjector $storefront;
    private FakeProductImages $images;
    private EventBus $events;
    private StorefrontStop $stop;
    private AuditLogger $auditLog;
    private StorefrontSwitch $switch;
    private FakeUnpaidOrderGuard $unpaidOrders;
    private DbProductRevisionRepository $revisions;

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
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->auditLog = $audit;
        $this->products = new DbProductRepository($db, $clock);
        $variations = new DbVariationRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $revisions = new DbProductRevisionRepository($db, $clock);
        $this->revisions = $revisions;
        $this->vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->images = new FakeProductImages();
        $this->storefront = new FakeCatalogProjector();
        $this->events = new EventBus();

        $access = new StaffAccess($staff, $this->vendors);
        $readiness = new ProductReadiness($templates, $variations);
        $this->catalog = new SyncCatalog($this->products, $variations, $this->storefront, $audit);
        $this->switch = new StorefrontSwitch($options, $clock);
        $this->switch->markResumed();
        $this->unpaidOrders = new FakeUnpaidOrderGuard();
        $this->stop = new StorefrontStop(
            $this->products,
            $this->catalog,
            $readiness,
            $access,
            $this->switch,
            $audit,
            $this->unpaidOrders
        );
        $publishing = new ProductPublishPolicy($options);
        $states = new ProductStateMachine();

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $revisions,
            $readiness,
            $this->catalog,
            $this->images,
            $access,
            $publishing,
            $states,
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $revisions,
            $templates,
            $readiness,
            $this->catalog,
            $publishing,
            $states,
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS])
        );
        $this->applications = new ReviewApplication(
            $this->vendors,
            new ApplicationStateMachine(),
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [VendorCapabilities::REVIEW]),
            $this->events
        );
        $catalog = $this->catalog;
        $this->events->on(EventBus::VENDOR_SUSPENDED, static function (array $p) use ($catalog): void {
            $catalog->withdrawVendor((int) $p['vendor_user_id'], (string) ($p['reason'] ?? ''));
        });
        $conditions = $this->stop;
        $this->events->on(EventBus::VENDOR_REINSTATED, static function (array $p) use ($catalog, $conditions): void {
            $catalog->republishVendor((int) $p['vendor_user_id'], $conditions);
        });

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', true, false);
    }

    public function testApprovingAProductPutsItInTheStorefrontExactlyOnce(): void
    {
        $productId = $this->publish();

        $product = $this->products->find($productId);
        self::assertTrue($product?->isProjected(), 'the link is stored on the marketplace row');
        $firstId = (int) $product->wcProductId;
        self::assertSame('publish', $this->storefront->statuses[$firstId]);

        // Every later decision projects again — and must reach the same post.
        $this->catalog->publish($productId);
        $this->catalog->publish($productId);
        self::assertSame($firstId, (int) $this->products->find($productId)?->wcProductId, 'no second product');
        self::assertCount(1, $this->storefront->links, 'one storefront product for one marketplace row');
    }

    public function testASaleIsNeverUndoneByAnotherProjection(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        self::assertSame(5, $this->storefront->stock[$wcId]);

        $this->storefront->sell($productId, 4);
        self::assertSame(1, $this->storefront->stock[$wcId], 'the storefront knows what it sold');

        // A projection for any other reason — an SEO edit, a re-approval —
        // must leave that number alone (ADR-008).
        $this->catalog->publish($productId);
        self::assertSame(1, $this->storefront->stock[$wcId], 'old stock did not come back');

        // And the marketplace's own column catches up rather than leading.
        self::assertSame(1, $this->catalog->pullStock($productId));
        self::assertSame(1, $this->products->find($productId)?->details->stock);
    }

    /**
     * On a LIVE product the vendor's restock is a request, not a write.
     *
     * This test asserted the opposite until `alpha.48`, and it was right to:
     * `SensitiveChange::IMMEDIATE` carried `stock` and an explicit restock
     * went straight out. The owner decided on 11 October 2026 that a live
     * product's stock needs the manager, so the assertion is rewritten rather
     * than softened — and what it now pins is the pair that matters: the shop
     * does NOT move, and the request exists.
     */
    public function testTheVendorsOwnRestockOfALiveProductIsAProposal(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        $this->storefront->sell($productId, 3);
        self::assertSame(2, $this->storefront->stock[$wcId]);

        $result = $this->manage->updateInventory(self::VENDOR, self::VENDOR, $productId, 30, 'SKU-1', 1, null);
        self::assertTrue($result->ok, $result->code);
        self::assertSame('stock_revision_requested', $result->code);
        self::assertSame(2, $this->storefront->stock[$wcId], 'the shop keeps the number a shopper meets');
        self::assertSame(2, $this->products->find($productId)?->details->stock, 'and so does our mirror');

        $pending = $this->revisions->pendingFor($productId);
        self::assertNotNull($pending);
        self::assertSame(30, (int) $pending->payload['stock_proposal']['value']);
        self::assertSame(2, (int) $pending->payload['stock_proposal']['baseline'], 'measured against the shop');
    }

    /** A draft is the vendor's own, so there is nobody to ask. */
    public function testTheVendorsOwnInventoryEditOnADraftWritesOutwardAtOnce(): void
    {
        // A DRAFT with a storefront row — the shape `SyncCatalog::prepare()`
        // makes so the manager can use WooCommerce's own editor before
        // approval. It is the one state where the vendor owns both sides.
        $productId = $this->readyProduct('SKU-DRAFT');
        self::assertTrue($this->catalog->prepare($productId)->ok);
        $wcId = (int) $this->products->find($productId)?->wcProductId;

        $result = $this->manage->updateInventory(self::VENDOR, self::VENDOR, $productId, 30, 'SKU-DRAFT', 1, null);
        self::assertTrue($result->ok, $result->code);
        self::assertSame('inventory_saved', $result->code);
        self::assertSame(30, $this->storefront->stock[$wcId], 'an explicit restock does reach the storefront');
    }

    public function testSuspendingAProductTakesItOutOfTheShopWithoutDeletingIt(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;

        self::assertTrue($this->review->suspend($productId, 'بررسی مدارک')->ok);
        self::assertSame('draft', $this->storefront->statuses[$wcId]);
        self::assertArrayHasKey($wcId, $this->storefront->links === [] ? [] : array_flip($this->storefront->links));
        self::assertSame($wcId, (int) $this->products->find($productId)?->wcProductId, 'the link survives');

        self::assertTrue($this->review->republish($productId)->ok);
        self::assertSame('publish', $this->storefront->statuses[$wcId]);
    }

    public function testSuspendingTheShopTakesEveryProductOutOfTheStorefront(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        $applicationId = $this->approvedApplication();

        self::assertTrue($this->applications->suspend($applicationId, 'تعلیق موقت')->ok);
        self::assertSame('draft', $this->storefront->statuses[$wcId], 'a suspended shop sells nothing');

        self::assertTrue($this->applications->reinstate($applicationId)->ok);
        self::assertSame('publish', $this->storefront->statuses[$wcId], 'reinstatement puts the live ones back');
    }

    public function testALinkPointingAtAPostThatIsGoneIsRemadeRatherThanFollowed(): void
    {
        $productId = $this->publish();
        $firstId = (int) $this->products->find($productId)?->wcProductId;

        // Somebody deleted the product in wp-admin: the link now points at
        // nothing, and `owns()` is what notices.
        unset($this->storefront->links[$productId], $this->storefront->statuses[$firstId]);

        $result = $this->catalog->publish($productId);
        self::assertTrue($result->ok, $result->code);
        $secondId = (int) $this->products->find($productId)?->wcProductId;
        self::assertNotSame($firstId, $secondId, 'a fresh post rather than a write into a hole');
        self::assertSame('publish', $this->storefront->statuses[$secondId]);
    }


    /**
     * Deactivating the plugin, or a manager preparing a rollback, must leave
     * nothing of the marketplace's on sale — and must delete nothing.
     */
    public function testStoppingTakesEveryMarketplaceProductOutOfTheShopWithoutDeletingAnything(): void
    {
        $a = $this->publish('SKU-A');
        $b = $this->publish('SKU-B');
        $wcA = (int) $this->products->find($a)?->wcProductId;
        $wcB = (int) $this->products->find($b)?->wcProductId;
        self::assertSame('publish', $this->storefront->statuses[$wcA]);

        $outcome = $this->stop->stop('plugin_deactivated', self::MANAGER);

        self::assertSame(2, $outcome['withdrawn']);
        self::assertSame(0, $outcome['failed']);
        self::assertSame('draft', $this->storefront->statuses[$wcA], 'out of the shop');
        self::assertSame('draft', $this->storefront->statuses[$wcB]);
        self::assertTrue($this->stop->isStopped(), 'and the marketplace remembers that it is stopped');

        // Nothing was deleted: both rows are still here, still linked, still
        // published in the marketplace's own workflow.
        self::assertNotNull($this->products->find($a));
        self::assertSame($wcA, (int) $this->products->find($a)?->wcProductId);
        self::assertTrue($this->products->find($a)?->status->isLive());
        self::assertCount(2, $this->storefront->links, 'no storefront product was removed');
    }

    /**
     * Coming back is not the mirror image of going away.
     *
     * A product that stopped qualifying while the shop was closed stays out,
     * and says which condition it failed. This is the "reactivation must not
     * put things back on sale unless the conditions hold" rule, measured.
     */
    public function testResumingBringsBackOnlyWhatStillQualifies(): void
    {
        $live = $this->publish('SKU-LIVE');
        $suspendedShop = $this->publish('SKU-SUSPENDED');
        $this->products->updateStatus($suspendedShop, ProductStatus::Suspended);

        $this->stop->stop('manager_stopped', self::MANAGER);
        $outcome = $this->stop->resume(self::MANAGER);

        self::assertSame(1, $outcome['published'], 'only the one that still qualifies');
        self::assertArrayHasKey($suspendedShop, $outcome['refused']);
        self::assertSame(StorefrontStop::REFUSED_NOT_PUBLISHED, $outcome['refused'][$suspendedShop]);
        self::assertFalse($this->stop->isStopped(), 'the stop itself is lifted');
        self::assertSame('publish', $this->storefront->statuses[(int) $this->products->find($live)?->wcProductId]);
    }

    /**
     * One product that would not leave the shop makes the whole stop a
     * failure — the owner's rule, and the reason it is a rule.
     *
     * The flag is read only by this plugin, while it runs. The manager presses
     * this button because they are about to take this plugin away, and from
     * that moment the only thing between a shopper and an unrecorded sale is
     * the product being a draft. So «۲ از ۳ محصول» is not a success with a
     * footnote; it is the failure, and it has to be reported by id.
     */
    public function testAStopThatLeavesOneProductOnSaleIsReportedAsAFailure(): void
    {
        $ok = $this->publish('SKU-OK');
        $stuck = $this->publish('SKU-STUCK');
        $this->storefront->refusesToWithdraw = [$stuck];

        $result = $this->stop->stopAsResult('manager_stopped', self::MANAGER);

        self::assertFalse($result->ok, 'a partial stop is a failure');
        self::assertSame('storefront_stop_incomplete', $result->code);
        self::assertSame((string) $stuck, $result->context['stuck'], 'named by id');
        self::assertSame(1, $result->context['withdrawn']);
        self::assertSame(
            'draft',
            $this->storefront->statuses[(int) $this->products->find($ok)?->wcProductId],
            'the ones that could leave still left'
        );
        self::assertSame(
            'publish',
            $this->storefront->statuses[(int) $this->products->find($stuck)?->wcProductId],
            'and the one that could not is still on sale, which is the point'
        );
        // The marketplace still refuses to sell — a half-emptied shelf is
        // precisely the state that must keep refusing.
        self::assertTrue($this->stop->isStopped());
    }

    /** …and the failure is written down where the plugin's absence cannot erase it. */
    public function testAPartialStopLeavesANoteForTheNextAdminPage(): void
    {
        $stuck = $this->publish('SKU-STUCK');
        $this->storefront->refusesToWithdraw = [$stuck];

        $this->stop->stop('plugin_deactivated', self::MANAGER);

        self::assertSame(['products' => [$stuck], 'orders' => []], $this->switch->stuck());

        // And a later stop that finishes clears it, without anyone asking.
        $this->storefront->refusesToWithdraw = [];
        $this->stop->stop('manager_stopped', self::MANAGER);
        self::assertSame(['products' => [], 'orders' => []], $this->switch->stuck());
    }

    /**
     * ⛔ A SCAN THAT HIT ITS CAP SAYS SO, AND USED TO SAY NOTHING.
     *
     * `stop()` reads `projected()`, which carries a `LIMIT`. On a shop with
     * more owned products than that limit the stop withdrew the first batch,
     * left the rest **ON SALE**, and named none of them — they are not in
     * `stuck`, because `stuck` holds the ones it TRIED and failed. After
     * deactivation nothing guards those products and nothing records the
     * money when they sell, so silence here is the worst outcome on the one
     * path that exists to prevent exactly that.
     *
     * The cap is reached with a double rather than with five hundred and one
     * real products: the thing under test is the comparison between «how many
     * did I get» and «how many are there».
     */
    /**
     * ⛔ §6: «هیچ سقف پنهان مانند ۵۰۰ نباید باعث باقی‌ماندن محصولات فروش‌پذیر
     * شود.»
     *
     * The number above was already persisted and already raised as an admin
     * notice. What it was NOT in was the answer handed back to whoever pressed
     * the button: `stopAsResult()` built `failed` from the stuck lists alone,
     * so a shop with more products than `SCAN_LIMIT` and no per-product
     * failure read «توقف فروش انجام شد» while the 501st product was still
     * `publish`.
     *
     * A cap is allowed — the class argues for keeping it rather than raising
     * it. A cap that reports success is not.
     */
    public function testAStopThatReachedItsCapIsNotReportedAsDone(): void
    {
        $this->publish('SKU-SEEN');
        $this->publish('SKU-UNSEEN');

        $short = new TruncatingDatabase(new WpDatabase($this->wpdb));
        $short->truncateWhen(['wc_product_id IS NOT NULL', 'ORDER BY id ASC LIMIT'], 1);
        $capped = new StorefrontStop(
            new DbProductRepository($short, new SystemClock()),
            $this->catalog,
            new ProductReadiness(
                new DbSpecTemplateRepository($short, new SystemClock()),
                new DbVariationRepository($short, new SystemClock())
            ),
            new StaffAccess(
                new DbStaffRepository($short, new SystemClock()),
                new DbVendorRepository($short, new SystemClock())
            ),
            $this->switch,
            $this->auditLog,
            $this->unpaidOrders
        );

        $result = $capped->stopAsResult('manager_stopped', self::MANAGER);

        self::assertFalse($result->ok, 'a cap that was reached is not a finished stop');
        self::assertSame('storefront_stop_incomplete', $result->code);
        self::assertSame(1, $result->context['unseen'] ?? null, 'and the count travels with the refusal');
        self::assertSame(0, $result->context['failed'] ?? null, 'nothing was stuck — they were never tried');
    }

    /**
     * The control, and it is the one that keeps the fix narrow: a stop that
     * really did see everything still reports plain success.
     */
    public function testAStopThatSawEverythingIsStillReportedAsDone(): void
    {
        $this->publish('SKU-ONE');
        $this->publish('SKU-TWO');

        $result = $this->stop->stopAsResult('manager_stopped', self::MANAGER);

        self::assertTrue($result->ok, $result->code);
        self::assertSame('storefront_stopped', $result->code);
        self::assertSame(2, $result->context['withdrawn'] ?? null);
    }

    public function testAStopThatReachedItsCapSaysHowManyItNeverLookedAt(): void
    {
        $this->publish('SKU-SEEN');
        $this->publish('SKU-UNSEEN');

        $short = new TruncatingDatabase(new WpDatabase($this->wpdb));
        // The list read, not the count: `countProjected()` must still answer
        // with the truth, which is the whole point of asking it separately.
        $short->truncateWhen(['wc_product_id IS NOT NULL', 'ORDER BY id ASC LIMIT'], 1);
        $capped = new StorefrontStop(
            new DbProductRepository($short, new SystemClock()),
            $this->catalog,
            new ProductReadiness(
                new DbSpecTemplateRepository($short, new SystemClock()),
                new DbVariationRepository($short, new SystemClock())
            ),
            new StaffAccess(
                new DbStaffRepository($short, new SystemClock()),
                new DbVendorRepository($short, new SystemClock())
            ),
            $this->switch,
            $this->auditLog,
            $this->unpaidOrders
        );

        $outcome = $capped->stop('plugin_deactivated', self::MANAGER);

        self::assertSame(1, $outcome['withdrawn'], 'it withdrew what it could see');
        self::assertSame(1, $outcome['unseen'], 'and said how many it could not');
        self::assertSame([], $outcome['stuck'], 'they are not «stuck» — they were never tried');
        self::assertSame(1, $this->switch->unseen(), 'the number outlives the run');
    }

    /**
     * And a count that could not be read is not read as nought.
     *
     * `alpha.8`, on the conservative side: a failed count on a full-looking
     * scan reports «there is something I did not see», because the direction
     * that is safe to be wrong in is the one that tells the manager to look.
     */
    public function testAnUnreadableCountIsNotReadAsNothingLeftOver(): void
    {
        $this->publish('SKU-COUNT');
        $failing = new FailingDatabase(new WpDatabase($this->wpdb));
        $failing->failReadWhen(['SELECT COUNT(*)', 'wc_product_id IS NOT NULL']);
        $clock = new SystemClock();
        $blind = new StorefrontStop(
            new DbProductRepository($failing, $clock),
            $this->catalog,
            new ProductReadiness(
                new DbSpecTemplateRepository($failing, $clock),
                new DbVariationRepository($failing, $clock)
            ),
            new StaffAccess(
                new DbStaffRepository($failing, $clock),
                new DbVendorRepository($failing, $clock)
            ),
            $this->switch,
            $this->auditLog,
            $this->unpaidOrders
        );

        // One product, far from the cap: an unreadable count here is «I know
        // how many I saw and the scan did not fill up», so nought is honest.
        self::assertSame(0, $blind->stop('manager_stopped', self::MANAGER)['unseen']);
    }

    /**
     * An unpaid order's pay link counts the same as a product on the shelf.
     *
     * WooCommerce never re-asks whether the goods may still be sold when it
     * takes money for an existing order, so a link left working is a sale left
     * open — and a stop that left one is not a success either.
     */
    public function testAPayLinkThatWouldNotRetireFailsTheStopToo(): void
    {
        $this->publish('SKU-A');
        $this->unpaidOrders->payable = [4001, 4002];
        $this->unpaidOrders->refuses = [4002];

        $result = $this->stop->stopAsResult('manager_stopped', self::MANAGER);

        self::assertFalse($result->ok);
        self::assertSame('', $result->context['stuck'], 'no product is stuck');
        self::assertSame('4002', $result->context['orders_stuck'], 'the order is');
        self::assertSame(['products' => [], 'orders' => [4002]], $this->switch->stuck());
    }

    /** Stopping retires every open pay link; resuming hands back the same ones. */
    public function testResumingGivesBackExactlyThePayLinksTheStopRetired(): void
    {
        $this->publish('SKU-A');
        $this->unpaidOrders->payable = [5001, 5002];

        $stopped = $this->stop->stop('manager_stopped', self::MANAGER);
        self::assertSame(2, $stopped['orders_held']);
        self::assertSame([], $this->stop->payableOrders(), 'none of them takes money now');

        $resumed = $this->stop->resume(self::MANAGER);
        self::assertSame(2, $resumed['orders_released']);
        self::assertSame([5001, 5002], $this->stop->payableOrders(), 'the same links, not new ones');
    }

    /** While selling is stopped, a reinstated vendor's products stay off sale. */
    public function testReinstatingAVendorWhileSellingIsStoppedPutsNothingBackOnSale(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;

        $this->stop->stop('manager_stopped', self::MANAGER);
        self::assertSame('draft', $this->storefront->statuses[$wcId]);

        // The vendor is suspended and reinstated while the marketplace is
        // stopped: permission to trade is not permission to be on sale.
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', false, false);
        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', true, false);
        $this->catalog->republishVendor(self::VENDOR, $this->stop);

        self::assertSame('draft', $this->storefront->statuses[$wcId], 'still off sale');
        self::assertFalse($this->stop->mayGoLive($productId));
    }

    public function testWithoutWooCommerceNothingIsProjectedAndTheProductSaysSo(): void
    {
        $this->storefront->available = false;
        $productId = $this->readyProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok, 'the marketplace decision still stands');

        $product = $this->products->find($productId);
        self::assertFalse($product?->isProjected(), 'and honestly reports that it has no public page');
        self::assertSame('woocommerce_missing', $this->catalog->publish($productId)->code);
    }

    // -------------------------------------------------------------- helpers

    /** The SKU is unique per shop, so a test that needs two products says so. */
    private function readyProduct(string $sku = 'SKU-1'): int
    {
        $details = new ProductDetails(
            title: 'دستکش لاتکس',
            categoryKey: 'gloves',
            priceMinor: 200000,
            sku: $sku,
            stock: 5
        );
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, $details);
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        $mediaId = 700 + $productId;
        $this->images->give($mediaId, self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $details, [], [$mediaId], $mediaId, $this->stampOf($productId));
        return $productId;
    }

    private function publish(string $sku = 'SKU-1'): int
    {
        $productId = $this->readyProduct($sku);
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);
        return $productId;
    }

    /** An approved application for the same user, so suspension has something to act on. */
    private function approvedApplication(): int
    {
        $applicationId = $this->vendors->saveDraft(self::VENDOR, new ApplicantDetails(
            'داروخانه نمونه',
            'شرکت نمونه',
            'vendor@example.test',
            '09120000000',
            'تهران',
            true
        ));
        self::assertGreaterThan(0, $applicationId);
        $this->vendors->updateStatus(
            $applicationId,
            \Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus::Approved,
            self::MANAGER,
            null
        );
        return $applicationId;
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

    /**
     * ⛔ A REAL STOP ANSWERS THE «left on sale» NOTE — and nothing measured it.
     *
     * The gated deactivation path counts what it left up and writes the
     * number where the next admin page reads it. A real stop takes the shelf
     * down, so that sentence stops being true whoever wrote it.
     *
     * Found by falsification rather than by reading: deleting
     * `clearLeftOnSale()` left every suite green, so the note could have
     * outlived the thing it described — a warning on every admin page about
     * products that are no longer on sale, with no control anywhere that
     * clears it.
     */
    public function testARealStopAnswersTheLeftOnSaleNote(): void
    {
        update_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, 7, false);
        self::assertSame(
            7,
            (int) get_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, 0),
            'the note is there to begin with, or this measures nothing'
        );

        $this->stop->stop('manager_stopped', self::MANAGER);

        self::assertFalse(
            get_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, false),
            'a stop removes the note rather than leaving a stale count on every admin page'
        );
    }

    /**
     * And the control: a stop is the only thing that clears it. Resuming puts
     * the shelf back up, and whatever a later deactivation leaves on sale is
     * for that deactivation to count — not for `resume()` to guess at.
     */
    public function testResumingDoesNotInventANote(): void
    {
        $this->stop->stop('manager_stopped', self::MANAGER);
        $this->stop->resume(self::MANAGER);

        self::assertFalse(
            get_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, false),
            'resuming writes no count of its own'
        );
    }

}
