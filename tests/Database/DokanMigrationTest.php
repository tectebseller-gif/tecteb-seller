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
use Tecteb\Marketplace\Modules\Migration\Application\OrderHistoryRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbOrderHistoryRepository;
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
use Tecteb\Marketplace\Tests\Support\KillsTheProcessOnWrite;

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

    /** Kept so a test can lose the manifest the way a dying process does. */
    private WpOptionStore $options;

    private DbOrderHistoryRepository $history;
    private DbShopRecordRepository $shopRecords;
    private DbLedgerRepository $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0017DokanShopRecords::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $this->options = $options;
        $this->products = new DbProductRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->history = new DbOrderHistoryRepository($db, $clock);
        $this->shopRecords = new DbShopRecordRepository($db, $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'داروخانه دکان', 'email' => 's@example.test', 'enabled' => true],
        ];
        $this->dokan->productRows = [
            ['wc_product_id' => 991, 'vendor_user_id' => self::SELLER, 'title' => 'باند کشی', 'sku' => 'DK-1', 'price_minor' => 120000, 'stock' => 7],
        ];
        // Total and net stated separately, because the point of the history is
        // that BOTH are Dokan's and neither is worked out here. The 200,000
        // difference is what Dokan itself kept; no rate of ours produced it.
        $this->dokan->orderRows = [
            [
                'wc_order_id' => 5501,
                'vendor_user_id' => self::SELLER,
                'status' => 'completed',
                'total_minor' => 900000,
                'net_minor' => 700000,
            ],
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
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            $this->history,
            $this->shopRecords,
            $this->ledger
        );
    }

    public function testTheDryRunMapsEveryRowAndWritesNothing(): void
    {
        $plan = $this->migration->plan();

        self::assertTrue($plan->isClean());
        self::assertSame(
            ['vendors' => 1, 'products' => 1, 'orders' => 1, 'conflicts' => 0, 'skipped' => 0],
            $plan->summary(),
            'the past order is now recorded rather than skipped'
        );
        // The reason names what «import» means here, and it is not «recompute».
        self::assertSame('history_recorded_without_recomputing', $plan->orders[0]['reason']);
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

    // ------------------------------------------- the manifest that cannot drift

    /**
     * The defect this closes: a process killed part-way through a page had
     * created real rows that no run claimed, so `rollback()` could not find
     * them and a re-run did not know they were there.
     *
     * `alpha.13` moved the manifest write from «after both loops» to «after
     * each page», which made the window smaller and did not close it. The row
     * stamp closes it, because the row that exists IS the record that it was
     * created.
     */
    public function testRowsCreatedBeforeAManifestWriteAreStillFoundByRollback(): void
    {
        $runId = 'dokan-interrupted';

        // Exactly what a batch does before the manifest write it never reached.
        $page = $this->migration->importProductPage($runId, 0, 50);
        self::assertNotSame([], $page['created']['products'], 'the batch created something');

        // Now lose the manifest, which is what dying between the two writes
        // looks like from the outside.
        $this->options->set(ImportFromDokan::RUNS_OPTION, []);
        self::assertArrayNotHasKey($runId, $this->options->get(ImportFromDokan::RUNS_OPTION, []));

        // The run is still known, and still undoable, because the ROWS say so.
        self::assertArrayHasKey($runId, $this->migration->runs());
        self::assertSame('rows_only', $this->migration->runs()[$runId]['source']);

        $rollback = $this->migration->rollback($runId);
        self::assertTrue($rollback->ok, $rollback->code);
        self::assertSame(count($page['created']['products']), $rollback->context['products']);
        self::assertSame([], $this->products->allForVendor(self::SELLER), 'the rows are gone');
    }

    /**
     * The half of the stamp that `0014` missed, and the evidence run found.
     *
     * With the manifest deleted, the rollback removed the products and left
     * the SHOP standing — reporting `vendors=0` about a profile that was
     * plainly there and that nothing could now name.
     */
    public function testAShopCreatedBeforeAManifestWriteIsStillFoundByRollback(): void
    {
        $runId = 'dokan-shop-interrupted';

        $page = $this->migration->importVendorPage($runId, 0, 50);
        self::assertNotSame([], $page['created']['vendors'], 'the batch created a shop');
        self::assertNotNull($this->vendors->findProfileByUser(self::SELLER));

        $this->options->set(ImportFromDokan::RUNS_OPTION, []);

        self::assertArrayHasKey($runId, $this->migration->runs(), 'a run that made only a shop is still a run');
        self::assertSame([self::SELLER], $this->migration->runs()[$runId]['vendors']);
        self::assertSame('rows_only', $this->migration->runs()[$runId]['source']);

        $rollback = $this->migration->rollback($runId);
        self::assertTrue($rollback->ok, $rollback->code);
        self::assertSame(1, $rollback->context['vendors']);
        self::assertNull($this->vendors->findProfileByUser(self::SELLER), 'the shop went with it');
    }

    /**
     * A rollback ends the run — on the rows too, not only in the manifest.
     *
     * A row a rollback deliberately KEEPS still carrying the run id made
     * `runs()` bring the run back from the dead and offer to undo it again.
     */
    public function testARowARollbackKeepsStopsCarryingTheRun(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $imported = $this->products->observed()[0];
        // Published: a record of something, so `deleteDraft()` refuses it.
        $this->products->updateStatus($imported->id, ProductStatus::Published);

        $rolled = $this->migration->rollback($runId);

        self::assertTrue($rolled->ok, $rolled->code);
        self::assertNotNull($this->products->find($imported->id), 'the row is kept');
        self::assertSame([], $this->products->idsFromImportRun($runId), 'but it is not this run\'s any more');
        self::assertArrayNotHasKey($runId, $this->migration->runs(), 'so the run stays undone');
    }

    /**
     * «Already there» and «did not work» are different answers.
     *
     * They were one boolean until an evidence run reported «already imported»
     * about six orders the table did not contain. A migration that cannot tell
     * them apart reports a shop as migrated on the strength of writes that
     * never landed.
     */
    public function testARecordThatFailsIsNotReportedAsAlreadyThere(): void
    {
        $order = [
            'wc_order_id' => 9001,
            'vendor_user_id' => self::SELLER,
            'status' => 'wc-completed',
            'total_minor' => 900000,
            'net_minor' => 700000,
            'commission_minor' => 200000,
            'refunded' => false,
        ];

        self::assertSame(
            OrderHistoryRepositoryInterface::RECORDED,
            $this->history->record('run-a', $order),
            'the first write lands'
        );
        self::assertSame(
            OrderHistoryRepositoryInterface::ALREADY,
            $this->history->record('run-a', $order),
            'the second is the unique index, not a fault'
        );

        // A write that cannot land at all: the table is gone.
        $this->wpdb->dropTable($this->wpdb->prefix . 'tmc_dokan_order_history');
        self::assertSame(
            OrderHistoryRepositoryInterface::FAILED,
            $this->history->record('run-a', $order),
            'and a broken write says so rather than passing for a duplicate'
        );
    }

    // ------------------------- the rest of a shop: staff, balance, withdrawals

    public function testAShopsStaffBalanceAndWithdrawalsArriveAsRecords(): void
    {
        $this->dokan->staffRows = [
            ['staff_user_id' => 7001, 'vendor_user_id' => self::SELLER, 'display_name' => 'مریم رضایی', 'user_email' => 'm@example.test', 'dokan_role' => 'vendor_staff'],
        ];
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SELLER, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000', 'status' => 'approved'],
            ['row_id' => 2, 'trn_id' => 9001, 'vendor_user_id' => self::SELLER, 'trn_type' => 'dokan_withdraw', 'debit' => '0.0000', 'credit' => '250000.0000', 'status' => 'approved'],
        ];
        $this->dokan->withdrawRows = [
            ['withdraw_id' => 9001, 'vendor_user_id' => self::SELLER, 'amount' => '250000.0000', 'status' => 'dokan:1', 'method' => 'bank'],
        ];

        $runId = 'dokan-records-1';
        $page = $this->migration->importShopRecordPage($runId, 0, 0, 50);

        self::assertSame(1, $page['staff']);
        self::assertSame(2, $page['balance']);
        self::assertSame(1, $page['withdrawals']);
        self::assertSame(0, $page['failed']);

        $summary = $this->migration->shopRecordsFor(self::SELLER);
        self::assertTrue($summary['available']);
        self::assertSame(1, $summary['staff']);
        self::assertSame(2, $summary['balance_rows']);
        // Dokan's own figures, to the last of four decimal places. Nothing
        // here was converted to minor units or through a float on the way.
        self::assertSame('700000.0000', $summary['debit']);
        self::assertSame('250000.0000', $summary['credit']);
        self::assertSame('250000.0000', $summary['withdrawn']);
    }

    /**
     * An old balance is never restated under a new rate.
     *
     * This is the one that would be invisible in production until somebody's
     * accountant noticed. The shop's marketplace commission is set to 8% here,
     * and the imported rows must still read exactly what Dokan recorded under
     * whatever rate applied at the time.
     */
    public function testAnImportedBalanceIsNotRecomputedWithTodaysRate(): void
    {
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SELLER, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000'],
        ];

        $this->migration->importShopRecordPage('dokan-rate-1', 0, 0, 50);

        // 900,000 at today's 8% would leave 828,000; at Dokan's own rate it
        // was 700,000. The number that comes back is Dokan's.
        self::assertSame('700000.0000', $this->migration->shopRecordsFor(self::SELLER)['debit']);
        self::assertNotSame('828000.0000', $this->migration->shopRecordsFor(self::SELLER)['debit']);
    }

    public function testRecordingTheSameShopRecordTwiceWritesOneRow(): void
    {
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SELLER, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000', 'credit' => '0.0000'],
        ];
        $this->dokan->withdrawRows = [
            ['withdraw_id' => 9001, 'vendor_user_id' => self::SELLER, 'amount' => '250000.0000'],
        ];

        $first = $this->migration->importShopRecordPage('dokan-twice', 0, 0, 50);
        $second = $this->migration->importShopRecordPage('dokan-twice', 0, 0, 50);

        self::assertSame(1, $first['balance']);
        self::assertSame(0, $second['balance'], 'the unique key answered, not a check in PHP');
        self::assertSame(0, $second['failed'], 'and «already there» is not a failure');
        self::assertSame(1, $this->migration->shopRecordsFor(self::SELLER)['balance_rows']);
    }

    /**
     * FIN-02, enforced: one financial engine per order.
     *
     * An order this marketplace's own ledger already carries must not also
     * receive Dokan's figures. Two answers to «what is this worth», with
     * nothing to say which settlement should believe, is worse than either.
     */
    public function testAnOrderOurLedgerAlreadyCarriesIsRefusedHistory(): void
    {
        // Our ledger takes the order first — the shape CaptureOrder writes.
        // Double entry, so it balances to zero — the ledger refuses anything
        // else. Money in from the shopper, split between the seller's earning
        // and this marketplace's commission.
        $this->ledger->record(
            (new LedgerTransaction(CaptureOrder::eventKey(5501, 77), self::SELLER, '5501', '77'))
                ->add(LedgerAccount::CentralPayment, Money::of(-900000), 'order_captured')
                ->add(LedgerAccount::VendorEarning, Money::of(700000), 'order_captured')
                ->add(LedgerAccount::Commission, Money::of(200000), 'order_captured')
        );
        self::assertTrue($this->ledger->coversOrder(5501));

        $result = $this->migration->import($this->migration->plan());

        self::assertTrue($result->ok, $result->code);
        self::assertSame(0, $result->context['history'], 'no history was written for it');
        self::assertSame(1, $result->context['history_already_ours'], 'and the reason is named, not silent');
        self::assertSame(0, $this->history->summaryForVendor(self::SELLER)['orders']);
    }

    public function testAnOrderNobodyElseCarriesIsRecordedNormally(): void
    {
        self::assertFalse($this->ledger->coversOrder(5501));

        $result = $this->migration->import($this->migration->plan());

        self::assertSame(1, $result->context['history']);
        self::assertSame(0, $result->context['history_already_ours']);
    }

    public function testRollingBackTakesTheShopRecordsWithIt(): void
    {
        $this->dokan->staffRows = [
            ['staff_user_id' => 7001, 'vendor_user_id' => self::SELLER, 'display_name' => 'مریم رضایی'],
        ];
        $this->dokan->balanceRows = [
            ['row_id' => 1, 'trn_id' => 5501, 'vendor_user_id' => self::SELLER, 'trn_type' => 'dokan_orders', 'debit' => '700000.0000'],
        ];
        $this->dokan->withdrawRows = [
            ['withdraw_id' => 9001, 'vendor_user_id' => self::SELLER, 'amount' => '250000.0000'],
        ];

        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $this->migration->importShopRecordPage($runId, 0, 0, 50);
        self::assertSame(1, $this->migration->shopRecordsFor(self::SELLER)['staff']);

        $rolled = $this->migration->rollback($runId);

        self::assertTrue($rolled->ok, $rolled->code);
        self::assertSame(1, $rolled->context['staff']);
        self::assertSame(1, $rolled->context['balance']);
        self::assertSame(1, $rolled->context['withdrawals']);
        $after = $this->migration->shopRecordsFor(self::SELLER);
        self::assertSame(0, $after['staff']);
        self::assertSame(0, $after['balance_rows']);
        self::assertSame(0, $after['withdrawals']);
        // «0.0000», not «0»: MySQL types `COALESCE(SUM(debit), 0)` by the
        // widest operand, which is the DECIMAL(19,4) column. Asserting the
        // literal the database actually returns beats asserting a tidier one.
        self::assertSame('0.0000', $after['debit'], 'and the balance history went with them');
    }

    /**
     * Dokan Lite has no staff feature at all, and that is a different fact
     * from «this shop has no staff». The reader answers empty and the report
     * has to be able to say which — guessing is how four people's accounts
     * get quietly dropped.
     */
    public function testAnInstallWithNoStaffFeatureImportsNoneWithoutFailing(): void
    {
        $this->dokan->staffRows = [];

        $page = $this->migration->importShopRecordPage('dokan-lite', 0, 0, 50);

        self::assertSame(0, $page['staff']);
        self::assertSame(0, $page['failed'], 'absence is not a failure');
        self::assertSame(0, $this->dokan->counts()['staff'], 'and the count says none were found');
    }

    // ------------------------------- the process actually dies, mid-import

    /**
     * Kill the importer between every pair of writes and check what it left.
     *
     * This replaces the alpha.14 test, which deleted the manifest AFTER the
     * run had finished. That proved something — the rollback can work from the
     * rows alone — but it never proved the thing it was named for: nothing was
     * interrupted, so no half-written state was ever created or examined.
     *
     * Here a forked child runs the import with a database that `SIGKILL`s the
     * process before write N, for N = 1, 2, 3, … through the whole run. SIGKILL
     * cannot be caught, so the child stops with no `finally`, no shutdown
     * function and no destructor. Whatever the parent then reads is what a
     * crashed importer really leaves behind.
     *
     * After each kill the parent asserts the three things that must hold no
     * matter where the process died:
     *
     *  1. every row that exists carries the run that made it — there is no
     *     orphan nothing can name;
     *  2. no row is half made — a product row either has its WooCommerce link
     *     or does not exist at all;
     *  3. resuming creates no duplicate, and finishes with exactly the rows
     *     one clean run would have made.
     */
    public function testKillingTheImporterBetweenAnyTwoWritesLeavesNothingHalfMade(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::fail('pcntl/posix are required to kill a real process; skipping would hide the gap.');
        }

        // What one clean run produces, to compare every crashed run against.
        $cleanRun = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $expectedProducts = count($this->products->idsFromImportRun($cleanRun));
        $expectedVendors = count($this->vendors->idsFromImportRun($cleanRun));
        self::assertGreaterThan(0, $expectedProducts + $expectedVendors, 'the fixture must actually import something');
        $this->migration->rollback($cleanRun);
        $this->options->delete(ImportFromDokan::RUNS_OPTION);

        $observed = [];
        for ($killBefore = 1; $killBefore <= 12; $killBefore++) {
            $runId = sprintf('dokan-crash-%02d', $killBefore);

            $this->wpdb->disconnect();
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                // Child: its own connection, its own importer, and a database
                // that will stop the process mid-sequence.
                $this->runImportInDoomedChild($runId, $killBefore);
                exit(0);                        // reached only when it survived
            }
            pcntl_waitpid($pid, $status);
            $this->wpdb->reconnect();

            $died = pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL;
            $observed[] = $died ? 'killed' : 'finished';

            // (1) and (2): whatever is there, is whole and named.
            $this->assertNothingHalfMade($runId);

            // (3) resume, then compare with a clean run.
            $this->migration->importVendorPage($runId, 0, 50);
            $this->migration->importProductPage($runId, 0, 50);
            $this->assertNothingHalfMade($runId);
            self::assertSame(
                $expectedProducts,
                count($this->products->idsFromImportRun($runId)),
                "resuming after a kill before write {$killBefore} produced the wrong number of products"
            );
            self::assertSame(
                $expectedVendors,
                count($this->vendors->idsFromImportRun($runId)),
                "resuming after a kill before write {$killBefore} produced the wrong number of shops"
            );

            $rolled = $this->migration->rollback($runId);
            self::assertTrue($rolled->ok, $rolled->code);
            $this->options->delete(ImportFromDokan::RUNS_OPTION);
        }

        // The test is worthless if nothing ever died. A run that always
        // «finished» would pass every assertion above and prove nothing.
        self::assertContains('killed', $observed, 'no child was actually killed — the harness is not testing a crash');
    }

    /** Runs one import inside the forked child, on a database that will kill it. */
    private function runImportInDoomedChild(string $runId, int $killBefore): void
    {
        $wpdb = new \wpdb();
        $clock = new SystemClock();
        $doomed = new KillsTheProcessOnWrite(new WpDatabase($wpdb), $killBefore);
        $products = new DbProductRepository($doomed, $clock);
        $vendors = new DbVendorRepository($doomed, $clock);
        $migration = new ImportFromDokan(
            $this->dokan,
            $vendors,
            $products,
            new WpOptionStore(),
            new AuditLogger(new WpAuditRepository($wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            new DbOrderHistoryRepository($doomed, $clock)
        );
        $migration->importVendorPage($runId, 0, 50);
        $migration->importProductPage($runId, 0, 50);
    }

    /**
     * The two invariants a crash must not be able to break.
     *
     * Read straight off the table rather than through the repository: the
     * question is what is ON DISK after the process stopped, and a method that
     * filters is a method that could hide the orphan.
     */
    private function assertNothingHalfMade(string $runId): void
    {
        $prefix = $this->wpdb->prefix;

        $unstamped = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$prefix}tmc_products` WHERE import_run_id = '' AND link_ownership = 'observed'"
        );
        self::assertSame(0, (int) $unstamped, 'an imported row exists that no run claims');

        $unlinked = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$prefix}tmc_products` WHERE import_run_id <> '' AND wc_product_id IS NULL"
        );
        self::assertSame(
            0,
            (int) $unlinked,
            'a row was created without its WooCommerce link — the resume cannot see it and will make a second one'
        );

        $duplicated = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT wc_product_id FROM `{$prefix}tmc_products`
                 WHERE wc_product_id IS NOT NULL
                 GROUP BY wc_product_id HAVING COUNT(*) > 1
             ) d"
        );
        self::assertSame(0, (int) $duplicated, 'two marketplace rows point at one WooCommerce product');

        $unstampedShops = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$prefix}tmc_vendor_profiles` p
              WHERE p.import_run_id = ''
                AND NOT EXISTS (SELECT 1 FROM `{$prefix}tmc_vendor_applications` a WHERE a.user_id = p.user_id)"
        );
        self::assertSame(0, (int) $unstampedShops, 'an imported shop exists that no run claims');
    }

    public function testEachCreatedRowCarriesTheRunThatMadeIt(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];

        $stamped = $this->products->idsFromImportRun($runId);
        self::assertNotSame([], $stamped);
        self::assertContains($runId, $this->products->importRunIds());

        // And a run that made nothing claims nothing — the stamp is a fact
        // about the row, not a label applied to the catalogue.
        self::assertSame([], $this->products->idsFromImportRun('dokan-never-happened'));
    }

    public function testRollingBackTakesTheUnionSoNeitherSourceIsLost(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        $fromRows = $this->products->idsFromImportRun($runId);
        $fromManifest = array_map('intval', $this->migration->runs()[$runId]['products'] ?? []);

        // In a healthy run the two agree; the union must therefore delete each
        // row ONCE rather than counting it twice.
        self::assertSame($fromRows, $fromManifest);
        $rollback = $this->migration->rollback($runId);
        self::assertTrue($rollback->ok, $rollback->code);
        self::assertSame(count($fromRows), $rollback->context['products']);
    }

    public function testAnUnknownRunIsStillRefused(): void
    {
        // The fallback must not turn «no such run» into «an empty run», or a
        // typo would report a successful rollback of nothing.
        $result = $this->migration->rollback('dokan-not-a-run');
        self::assertFalse($result->ok);
        self::assertSame('not_found', $result->code);
    }

    // ------------------------------------------ the history, and what it is not

    public function testPastOrdersArriveCarryingDokansOwnFigures(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];

        $rows = $this->history->forVendor(self::SELLER);
        self::assertCount(1, $rows);
        self::assertSame($runId, (string) $rows[0]['run_id']);
        self::assertSame('dokan', (string) $rows[0]['source']);
        // The fixture's order: total 900000, net 700000. Both are carried, and
        // the commission is the DIFFERENCE Dokan recorded — not a rate applied.
        self::assertSame(900000, (int) $rows[0]['total_minor']);
        self::assertSame(700000, (int) $rows[0]['net_minor']);
        self::assertSame(200000, (int) $rows[0]['commission_minor']);
    }

    /**
     * The rule the whole table exists to keep: one financial engine per order.
     *
     * Settlement, the balance and every report read the ledger. If importing
     * history wrote a single line there, a shop's payable balance would include
     * money Dokan already paid them.
     */
    public function testImportingHistoryWritesNoLedgerLine(): void
    {
        $before = (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_ledger`'
        );
        $this->migration->import($this->migration->plan());
        $after = (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_ledger`'
        );

        self::assertSame($before, $after, 'the ledger must not learn about Dokan\'s past orders');
        self::assertSame(1, $this->history->countForVendor(self::SELLER), 'but the history did');
    }

    public function testTheSameOrderIsRecordedOnceHoweverOftenTheJobResumes(): void
    {
        $runId = 'dokan-resumed';
        $first = $this->migration->importOrderPage($runId, 0, 50);
        // A resumed job re-runs its last page from the cursor it wrote before
        // the crash. That is normal, and must not duplicate a past sale.
        $second = $this->migration->importOrderPage($runId, 0, 50);

        self::assertSame(1, $first['done']);
        self::assertSame(0, $second['done']);
        self::assertSame(1, $second['skipped'], 'the unique index refused it, not a check in PHP');
        self::assertSame(1, $this->history->countForVendor(self::SELLER));
    }

    public function testRollingBackAnImportTakesItsHistoryWithIt(): void
    {
        $runId = (string) $this->migration->import($this->migration->plan())->context['run_id'];
        self::assertSame(1, $this->history->countForRun($runId));

        $result = $this->migration->rollback($runId);
        self::assertTrue($result->ok, $result->code);
        self::assertSame(1, $result->context['history']);
        self::assertSame(0, $this->history->countForVendor(self::SELLER), 'no half-migrated shop');
    }

    public function testDeletingARunNeverTakesRowsThatBelongToAnother(): void
    {
        $this->migration->importOrderPage('run-a', 0, 50);
        // An empty run id must delete NOTHING: `run_id` defaults to '', so a
        // careless DELETE would take every row a pre-run-id build wrote.
        self::assertSame(0, $this->history->deleteRun(''));
        self::assertSame(1, $this->history->countForVendor(self::SELLER));

        self::assertSame(0, $this->history->deleteRun('run-b'), 'a different run owns nothing here');
        self::assertSame(1, $this->history->countForVendor(self::SELLER));
    }

    public function testTheSummaryAddsDokansNumbersAndComputesNothing(): void
    {
        $this->migration->importOrderPage('run-sum', 0, 50);
        $summary = $this->migration->historyFor(self::SELLER);

        self::assertTrue($summary['available']);
        self::assertSame(1, $summary['orders']);
        self::assertSame(900000, $summary['total_minor']);
        self::assertSame(700000, $summary['net_minor']);
    }
}
