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
use Tecteb\Marketplace\Modules\Migration\Application\DokanMigrationPlan;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;

/**
 * The migration's three promises, on real MariaDB: the dry run writes nothing,
 * the import creates only our rows, and the rollback leaves none of them.
 *
 * That Dokan's own data is untouched is proved where it matters — against a
 * really installed Dokan, by fingerprint, in docs/evidence/dokan-migration/.
 * What is asserted HERE is the part that is ours: what we write, when, and
 * what a rollback removes.
 */
final class DokanMigrationTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const SELLER = 401;

    private ImportFromDokan $migration;
    private TransferOwnership $transfer;
    private FakeCatalogProjector $storefront;
    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private FakeDokanReader $dokan;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        (new M0001CreateAuditTable())->up($db);
        (new M0002CreateVendorTables())->up($db);
        (new M0003CreateStoreAndStaffTables())->up($db);
        (new M0005CreateProductTables())->up($db);
        (new M0006CatalogAndOrders())->up($db);
        (new M0010LinkOwnership())->up($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $this->products = new DbProductRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'داروخانه دکان', 'email' => 's@example.test', 'enabled' => true],
        ];
        $this->dokan->productRows = [
            ['wc_product_id' => 991, 'vendor_user_id' => self::SELLER, 'title' => 'باند کشی', 'sku' => 'DK-1', 'price_minor' => 120000, 'stock' => 7],
        ];
        $this->dokan->orderRows = [
            ['wc_order_id' => 5501, 'vendor_user_id' => self::SELLER, 'status' => 'completed', 'total_minor' => 120000],
        ];

        $this->storefront = new FakeCatalogProjector();
        $this->transfer = new TransferOwnership(
            $this->products,
            $this->storefront,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );
        $this->migration = new ImportFromDokan(
            $this->dokan,
            $this->vendors,
            $this->products,
            $options,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );
    }

    public function testTheDryRunMapsEveryRowAndWritesNothing(): void
    {
        $plan = $this->migration->plan();

        self::assertTrue($plan->isClean());
        self::assertSame(
            ['vendors' => 1, 'products' => 1, 'orders' => 0, 'conflicts' => 0, 'skipped' => 1],
            $plan->summary(),
            'the order is counted and skipped: its commission was Dokan\'s to compute'
        );
        self::assertSame('historic_commission_not_recomputed', $plan->orders[0]['reason']);
        self::assertStringContainsString('wc:991', $plan->products[0]['target'], 'and it points at the EXISTING product');

        self::assertNull($this->vendors->findProfileByUser(self::SELLER), 'nothing was written');
        self::assertSame([], $this->products->allForVendor(self::SELLER));
        self::assertSame([], $this->migration->runs());
    }

    public function testTheImportCreatesDraftsLinkedToTheProductsThatAlreadyExist(): void
    {
        $result = $this->migration->import($this->migration->plan());

        self::assertTrue($result->ok, $result->code);
        self::assertSame(1, $result->context['vendors']);
        self::assertSame(1, $result->context['products']);

        $profile = $this->vendors->findProfileByUser(self::SELLER);
        self::assertNotNull($profile);
        self::assertSame('داروخانه دکان', $profile->storeName);
        self::assertFalse($profile->canSell, 'imported, not yet trading');

        // NOT in allForVendor(): an imported row is `observed`, and every
        // "is this ours?" query — the vendor's catalogue, the purchase guard,
        // the storefront stop — must skip it. That scope is the fix for a real
        // measured bug: a dry-run import made Dokan's product unpurchasable.
        self::assertSame([], $this->products->allForVendor(self::SELLER), 'not the shop\'s catalogue yet');
        self::assertNull($this->products->findByWcProduct(991), 'and not ours to decide about');

        $imported = $this->products->observed();
        self::assertCount(1, $imported);
        self::assertSame(ProductStatus::Draft, $imported[0]->status, 'nothing appears on the storefront');
        self::assertSame(991, $imported[0]->wcProductId, 'the id and the URL a customer bookmarked still work');
        self::assertSame('DK-1', $imported[0]->details->sku);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($imported[0]->id));
    }

    public function testARollbackRemovesExactlyWhatThatRunCreated(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        // Something that was NOT part of the run, and must survive it.
        $ours = $this->products->create(self::SELLER, new ProductDetails(
            title: 'محصول خودمان',
            categoryKey: 'gloves',
            priceMinor: 50000,
            sku: 'OURS-1',
            stock: 3
        ), ProductStatus::Draft);

        $rolled = $this->migration->rollback($runId);

        self::assertTrue($rolled->ok, $rolled->code);
        self::assertSame(1, $rolled->context['products']);
        self::assertNotNull($this->products->find($ours), 'a row this run did not make is untouched');
        self::assertCount(1, $this->products->allForVendor(self::SELLER), 'only our own row is left');
        self::assertSame([], $this->products->observed(), 'and nothing mapped remains');
        self::assertSame([], $this->migration->runs(), 'and the run is gone from the manifest');
    }

    /** A published product is a record of something; a rollback does not erase it. */
    public function testARollbackWillNotRemoveAProductSomebodyPublished(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $imported = $this->products->observed()[0];
        $this->products->updateStatus($imported->id, ProductStatus::Published);

        $rolled = $this->migration->rollback($runId);

        self::assertTrue($rolled->ok);
        self::assertSame(0, $rolled->context['products'], 'it reports what it could not undo');
        self::assertNotNull($this->products->find($imported->id), 'and leaves it alone');
    }

    public function testAPlanWithAConflictIsRefusedRatherThanPartlyImported(): void
    {
        $this->vendors->upsertProfile(self::SELLER, 'داروخانه ما', true, false);
        $this->products->create(self::SELLER, new ProductDetails(
            title: 'باند کشی',
            categoryKey: 'gloves',
            priceMinor: 120000,
            sku: 'DK-1',
            stock: 7
        ), ProductStatus::Draft);

        $plan = $this->migration->plan();
        self::assertFalse($plan->isClean());
        self::assertSame(1, $plan->summary()['conflicts']);
        self::assertSame('sku_already_used_in_this_shop', $plan->products[0]['reason']);

        $result = $this->migration->import($plan);
        self::assertFalse($result->ok);
        self::assertSame('migration_has_conflicts', $result->code);
        self::assertCount(1, $this->products->allForVendor(self::SELLER), 'and nothing was imported around it');
    }

    public function testASecondRunSkipsWhatTheFirstOneAlreadyLinked(): void
    {
        $this->migration->import($this->migration->plan());
        $second = $this->migration->plan();

        self::assertSame(0, $second->summary()['products'], 'nothing left to import');
        self::assertSame(DokanMigrationPlan::SKIP, $second->products[0]['verdict']);
        self::assertSame('already_mapped_by_an_earlier_run', $second->products[0]['reason']);
        self::assertSame(DokanMigrationPlan::SKIP, $second->vendors[0]['verdict']);
    }

    /**
     * The bug this whole column exists for, in one test.
     *
     * A row that merely maps a product must be invisible to the two questions
     * that decide whether somebody else's shop keeps working: "whose product
     * is this?" and "what is on our shelf?". Measured on a real site before
     * the fix, the answers were "ours" and "this one" — so Dokan's vendor
     * product went unpurchasable and a stop would have drafted it.
     */
    public function testAMappedProductIsInvisibleToEverythingThatDecidesAboutSelling(): void
    {
        $this->migration->import($this->migration->plan());

        self::assertNull($this->products->findByWcProduct(991), 'not ours to guard');
        self::assertSame([], $this->products->projected(), 'not on our shelf to withdraw');
        self::assertSame([], $this->products->allForVendor(self::SELLER), 'not in a vendor catalogue');
        self::assertCount(1, $this->products->observed(), 'but the mapping is there to be read');
    }

    /** …and an explicit transfer is the one thing that changes all three. */
    public function testAnExplicitTransferIsWhatMakesItOurs(): void
    {
        $this->migration->import($this->migration->plan());
        $productId = $this->products->observed()[0]->id;

        $taken = $this->transfer->take($productId);
        self::assertTrue($taken->ok, $taken->code);
        self::assertSame(991, $taken->context['wc_product_id']);

        self::assertNotNull($this->products->findByWcProduct(991), 'now ours to guard');
        self::assertCount(1, $this->products->projected(), 'now on our shelf');
        self::assertSame([], $this->products->observed(), 'and no longer merely mapped');

        // …and reversible, in the same explicit way.
        $given = $this->transfer->giveBack($productId);
        self::assertTrue($given->ok, $given->code);
        self::assertNull($this->products->findByWcProduct(991));
        self::assertCount(1, $this->products->observed());
    }

    public function testTransferringTwiceSaysSoRatherThanPretending(): void
    {
        $this->migration->import($this->migration->plan());
        $productId = $this->products->observed()[0]->id;
        self::assertTrue($this->transfer->take($productId)->ok);

        $again = $this->transfer->take($productId);
        self::assertFalse($again->ok);
        self::assertSame('ownership_already', $again->code);
        self::assertSame('marketplace', $again->context['ownership']);
    }

    /** A product somebody took over is no longer a trial copy to delete. */
    public function testARollbackKeepsAProductWhoseOwnershipWasTransferred(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $productId = $this->products->observed()[0]->id;
        self::assertTrue($this->transfer->take($productId)->ok);

        $rolled = $this->migration->rollback($runId);

        self::assertFalse($rolled->ok);
        self::assertSame('rollback_kept_transferred', $rolled->code);
        self::assertSame((string) $productId, $rolled->context['kept']);
        self::assertNotNull($this->products->find($productId), 'it is still there');
    }

    public function testOnlyAManagerMayTransferOwnership(): void
    {
        $this->migration->import($this->migration->plan());
        $productId = $this->products->observed()[0]->id;

        $vendorSide = new TransferOwnership(
            $this->products,
            $this->storefront,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new FakeCapabilityChecker(self::SELLER, [])
        );
        $refused = $vendorSide->take($productId);
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($productId));
    }

    public function testWithoutTheManagersCapabilityNothingRunsAtAll(): void
    {
        $unprivileged = new ImportFromDokan(
            $this->dokan,
            $this->vendors,
            $this->products,
            new WpOptionStore(),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock()),
            new SystemClock(),
            new FakeCapabilityChecker(self::SELLER, [])
        );
        $result = $unprivileged->import($unprivileged->plan());
        self::assertFalse($result->ok);
        self::assertSame('forbidden', $result->code);
        self::assertSame([], $this->products->allForVendor(self::SELLER));
    }
}
