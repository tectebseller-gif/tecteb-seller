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
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
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

        $imported = $this->products->allForVendor(self::SELLER);
        self::assertCount(1, $imported);
        self::assertSame(ProductStatus::Draft, $imported[0]->status, 'nothing appears on the storefront');
        self::assertSame(991, $imported[0]->wcProductId, 'the id and the URL a customer bookmarked still work');
        self::assertSame('DK-1', $imported[0]->details->sku);
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
        self::assertCount(1, $this->products->allForVendor(self::SELLER));
        self::assertSame([], $this->migration->runs(), 'and the run is gone from the manifest');
    }

    /** A published product is a record of something; a rollback does not erase it. */
    public function testARollbackWillNotRemoveAProductSomebodyPublished(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $imported = $this->products->allForVendor(self::SELLER)[0];
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
        self::assertSame('already_linked_to_a_marketplace_row', $second->products[0]['reason']);
        self::assertSame(DokanMigrationPlan::SKIP, $second->vendors[0]['verdict']);
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
