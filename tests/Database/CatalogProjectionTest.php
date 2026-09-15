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
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

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
    private StorefrontSwitch $switch;

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
        (new M0001CreateAuditTable())->up($db);
        (new M0002CreateVendorTables())->up($db);
        (new M0003CreateStoreAndStaffTables())->up($db);
        (new M0004CreateFinanceTables())->up($db);
        (new M0005CreateProductTables())->up($db);
        (new M0006CatalogAndOrders())->up($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->products = new DbProductRepository($db, $clock);
        $variations = new DbVariationRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $revisions = new DbProductRevisionRepository($db, $clock);
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
        $this->stop = new StorefrontStop($this->products, $this->catalog, $readiness, $access, $this->switch, $audit);
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

    public function testTheVendorsOwnInventoryEditIsTheOneThingThatWritesOutward(): void
    {
        $productId = $this->publish();
        $wcId = (int) $this->products->find($productId)?->wcProductId;
        $this->storefront->sell($productId, 3);
        self::assertSame(2, $this->storefront->stock[$wcId]);

        $result = $this->manage->updateInventory(self::VENDOR, self::VENDOR, $productId, 30, 'SKU-1', 1, null);
        self::assertTrue($result->ok, $result->code);
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
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $details, [], [$mediaId], $mediaId);
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
}
