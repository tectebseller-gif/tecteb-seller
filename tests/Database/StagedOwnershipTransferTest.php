<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Migration\Application\ApplyOwnershipTransfer;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipTransferPlan;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeCatalogueReader;

/**
 * Moving ownership of a shop's imported products a BATCH at a time — and
 * stopping at the first row that will not move.
 *
 * Every test here starts from a REAL intake, not from hand-written rows, and
 * that is load-bearing twice over. An intake writes the row FROM the
 * WooCommerce product, so the two agree by construction and the `ready`
 * bucket is reachable at all; and it is the state the owner's 184 products
 * are actually in, so a fixture that wrote its own row would be answering a
 * different question.
 *
 * ⛔ **It is also the correction `alpha.90` needs.** That round reported
 * `risks=manage_stock drift=price,sku,stock` «for a healthy fixture» and
 * concluded that «۱۸۴ کلیک» was an underestimate. It was measuring
 * `tools/upgrade-alpha89-state.php arrange`, which inserts a row with raw SQL
 * beside a bare `wp_insert_post()` product — so the row says 450000/3/SKU and
 * the product says nothing, and every comparison differs. A product that was
 * imported has no drift, and `testEveryIntakenRowIsReadyAndNothingDrifted`
 * is that measurement. How many of the real 184 carry a risk or a drift is
 * still `Not Run`: it needs the site.
 */
final class StagedOwnershipTransferTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const VENDOR = 910;
    private const OTHER_VENDOR = 911;

    /** Five products whose row and shop agree: the batch's whole subject. */
    private const WC_READY = [8101, 8102, 8103, 8104, 8105];

    /** Imported, then given a preservation risk a human has to read. */
    private const WC_RISKY = 8106;

    /** The first of thirty, so one page holds more than one batch. */
    private const WC_BULK_FIRST = 8200;
    private const WC_BULK_COUNT = 30;

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private WpOptionStore $options;
    private FakeCatalogueReader $reader;
    private FakeCatalogProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->options = new WpOptionStore();
        $this->options->delete(ImportExistingProducts::RUNS_OPTION);
        $this->products = new DbProductRepository($this->db, $clock);
        $this->vendors = new DbVendorRepository($this->db, $clock);
        // Through the repository, not by hand: a profile written straight into
        // the table is «approved and unable to sell» (`alpha.17`).
        $this->vendors->upsertProfile(self::VENDOR, 'فروشگاه انتقال دسته‌ای', true, false);
        $this->vendors->upsertProfile(self::OTHER_VENDOR, 'فروشگاه دیگر', true, false);

        $this->reader = new FakeCatalogueReader();
        $bulk = range(self::WC_BULK_FIRST, self::WC_BULK_FIRST + self::WC_BULK_COUNT - 1);
        foreach ([...self::WC_READY, self::WC_RISKY, ...$bulk] as $wcId) {
            $this->reader->add(
                $wcId,
                ['title' => 'کالای ' . $wcId, 'regular_price' => '450000', 'sku' => 'WC-' . $wcId],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
        }
        $this->projector = new FakeCatalogProjector();
    }

    protected function tearDown(): void
    {
        $this->options->delete(ImportExistingProducts::RUNS_OPTION);
        parent::tearDown();
    }

    // ================================================== the preview, §4 «پیش‌نمایش»

    /**
     * An intaken row agrees with its product, so it is `ready` — and this is
     * the measurement that corrects `alpha.90`'s fixture artifact.
     */
    public function testEveryIntakenRowIsReadyAndNothingDrifted(): void
    {
        $this->importReady();

        $plan = $this->plan()->plan(self::VENDOR);

        self::assertTrue($plan['checked'], 'both questions could be asked');
        self::assertCount(5, $plan['rows']);
        self::assertSame(5, $plan['counts'][OwnershipTransferPlan::READY]);
        self::assertSame(0, $plan['counts'][OwnershipTransferPlan::DRIFT_FIXABLE]);
        self::assertSame(0, $plan['counts'][OwnershipTransferPlan::RISK_REAL]);
        self::assertSame(0, $plan['counts'][OwnershipTransferPlan::STATUS_BLOCKED]);
        foreach ($plan['rows'] as $row) {
            self::assertSame(OwnershipTransferPlan::READY, $row['bucket']);
            self::assertSame([], $row['risks'], 'an imported row carries no risk by itself');
            self::assertSame([], $row['drift'], 'the row was written FROM the product');
        }
        self::assertSame(5, $plan['observed_total']);
    }

    /** Each bucket, reached by the thing that defines it. */
    public function testEachBucketIsReachedByItsOwnCause(): void
    {
        $ready = $this->importReady();
        $risky = $this->importRisky();
        // A row pointing at a product the shop cannot answer about: «نمی‌دانم»
        // is not «they agree» (`alpha.49`), so it is not ready.
        $unreadable = $this->rowWithoutDetail(8999);
        // An imported row with no WooCommerce product behind it at all.
        $orphan = $this->rowWithoutStorefront();

        $plan = $this->plan()->plan(self::VENDOR);

        self::assertSame($ready, $plan['buckets'][OwnershipTransferPlan::READY]);
        self::assertSame([$risky], $plan['buckets'][OwnershipTransferPlan::RISK_REAL]);
        self::assertSame([$unreadable], $plan['buckets'][OwnershipTransferPlan::UNREADABLE]);
        self::assertSame([$orphan], $plan['buckets'][OwnershipTransferPlan::NO_STOREFRONT_PRODUCT]);
        // And the excluded one is named, with its reason, rather than silently
        // dropped — the half a batch cannot do honestly.
        $named = array_column($plan['rows'], 'risks', 'product_id');
        self::assertSame(['manage_stock'], $named[$risky]);
    }

    /** A drift puts a row in `drift_fixable` and names the field. */
    public function testAShopValueThatMovedIsNamedAndExcluded(): void
    {
        $ready = $this->importReady();
        $moved = $ready[1];
        $this->reader->details[self::WC_READY[1]]['regular_price'] = '470000';

        $plan = $this->plan()->plan(self::VENDOR);

        $buckets = $plan['buckets'];
        self::assertSame([$moved], $buckets[OwnershipTransferPlan::DRIFT_FIXABLE]);
        self::assertNotContains($moved, $plan['ready']);
        $drift = array_column($plan['rows'], 'drift', 'product_id');
        self::assertSame(['قیمت'], $drift[$moved]);
    }

    /** A build that cannot ask either question offers nothing. */
    public function testWithoutTheReaderNothingIsReady(): void
    {
        $this->importReady();

        $plan = (new OwnershipTransferPlan($this->products, $this->transfer()))->plan(self::VENDOR);

        self::assertFalse($plan['checked'], 'the screen must say the check did not run');
        self::assertSame([], $plan['ready']);
        self::assertSame(5, $plan['counts'][OwnershipTransferPlan::UNREADABLE]);
    }

    /** Drawing the preview writes nothing — rows, ownership and audit alike. */
    public function testThePreviewWritesNothing(): void
    {
        $this->importReady();
        $this->importRisky();
        $before = $this->catalogueDigest();
        $auditBefore = $this->auditRows();

        $this->plan()->plan(self::VENDOR);
        $this->plan()->plan(self::VENDOR, 0, 2);

        self::assertSame($before, $this->catalogueDigest());
        self::assertSame($auditBefore, $this->auditRows());
    }

    /** Another shop's rows are not in this shop's plan. */
    public function testThePlanIsScopedToOneShop(): void
    {
        $this->importReady();
        $other = $this->products->create(
            self::OTHER_VENDOR,
            new ProductDetails(title: 'کالای فروشگاه دیگر', priceMinor: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            9500
        );

        $plan = $this->plan()->plan(self::VENDOR);

        self::assertNotContains($other, array_column($plan['rows'], 'product_id'));
        self::assertSame(5, $plan['observed_total'], 'the tally is this shop\'s too');
    }

    // ===================================================== the cursor, past page one

    /**
     * Walking the cursor reaches every observed row exactly once.
     *
     * Asserted as a SET of ids rather than a count: a page that repeated one
     * row and dropped another would have the right total (`alpha.88`).
     */
    public function testTheCursorCoversEveryObservedRowExactlyOnce(): void
    {
        $expected = array_merge($this->importReady(), [$this->importRisky()]);
        sort($expected);

        $seen = [];
        $after = 0;
        $pages = 0;
        $service = $this->plan();
        do {
            $plan = $service->plan(self::VENDOR, $after, 2);
            foreach ($plan['rows'] as $row) {
                $seen[] = (int) $row['product_id'];
            }
            // A page that RETURNED rows must move the cursor, or the walk
            // would read the same rows for ever. The last request returns
            // nothing — six rows at two a page means page four is empty — and
            // that page is the one that says `finished`, so no «continue»
            // link is drawn from a cursor that did not move (`alpha.85`).
            if ($plan['rows'] !== []) {
                self::assertGreaterThan($after, $plan['cursor']);
            } else {
                self::assertTrue($plan['finished'], 'an empty page is the end of the walk');
                self::assertSame($after, (int) $plan['cursor'], 'and it moves nothing');
            }
            $after = (int) $plan['cursor'];
            $pages++;
            self::assertLessThan(10, $pages, 'the walk must terminate');
        } while (!$plan['finished']);

        sort($seen);
        self::assertSame($expected, $seen);
        self::assertSame(count($expected), count(array_unique($seen)));
        self::assertSame(4, $pages, 'six rows at two a page, the last one short');
    }

    /** The offered list is capped at the batch limit, and so is its digest. */
    public function testTheOfferedListIsCappedAtTheBatchLimit(): void
    {
        $ready = $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR, 0, 3);

        self::assertLessThanOrEqual(OwnershipTransferPlan::BATCH_LIMIT, count($plan['ready']));
        self::assertSame(
            OwnershipTransferPlan::digestOf($plan['ready']),
            $plan['digest'],
            'the form carries the digest of exactly what it offered'
        );
        self::assertSame(array_slice($ready, 0, 3), $plan['ready']);
    }

    /**
     * A page holding more than one batch offers exactly one batch — and the
     * rest need a SECOND click, which is «تأیید صریح مدیر برای هر دسته
     * عملیات» rather than a number this class chose.
     */
    public function testAPageWithMoreThanOneBatchNeedsASecondConfirmation(): void
    {
        $all = $this->importBulk();
        self::assertSame(self::WC_BULK_COUNT, count($all));

        $first = $this->plan()->plan(self::VENDOR, 0, 50);
        self::assertSame(self::WC_BULK_COUNT, $first['counts'][OwnershipTransferPlan::READY]);
        self::assertCount(OwnershipTransferPlan::BATCH_LIMIT, $first['ready'], 'one batch, not the page');
        self::assertSame(array_slice($all, 0, OwnershipTransferPlan::BATCH_LIMIT), $first['ready']);
        self::assertTrue($first['finished'], 'thirty rows read with a limit of fifty is the whole page');

        $one = $this->apply()->apply(self::VENDOR, 0, $first['ready'], $first['digest']);
        self::assertTrue($one['ok'], (string) $one['code']);
        self::assertSame(
            ['marketplace' => OwnershipTransferPlan::BATCH_LIMIT, 'observed' => self::WC_BULK_COUNT - OwnershipTransferPlan::BATCH_LIMIT],
            $this->products->ownershipTallyForVendor(self::VENDOR)
        );

        // The second click sees only what is left: the plan reads `observed`
        // rows, so a moved product cannot be offered twice.
        $second = $this->plan()->plan(self::VENDOR, 0, 50);
        self::assertSame(array_slice($all, OwnershipTransferPlan::BATCH_LIMIT), $second['ready']);
        $two = $this->apply()->apply(self::VENDOR, 0, $second['ready'], $second['digest']);
        self::assertTrue($two['ok'], (string) $two['code']);
        self::assertSame(
            ['marketplace' => self::WC_BULK_COUNT, 'observed' => 0],
            $this->products->ownershipTallyForVendor(self::VENDOR)
        );
        self::assertSame([], $this->projector->writes, 'and still nothing was ever projected');
    }

    // ========================================= applying one explicit batch, §4

    /**
     * The batch moves the offered rows, and creates NO second WooCommerce
     * product — measured three independent ways.
     */
    public function testAnAppliedBatchMovesTheOfferedRowsAndCreatesNoSecondProduct(): void
    {
        $ready = $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);
        $rowsBefore = $this->rowCount();

        $result = $this->apply()->apply(self::VENDOR, 0, $plan['ready'], $plan['digest']);

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame('ownership_batch_taken', $result['code']);
        self::assertSame($ready, $result['moved']);
        self::assertSame(0, $result['stopped_at']);
        foreach ($ready as $productId) {
            self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($productId));
        }
        // (1) no new marketplace row;
        self::assertSame($rowsBefore, $this->rowCount());
        // (2) nothing was ever asked to PROJECT, which is the only call that
        //     can make a product;
        self::assertSame([], $this->projector->writes);
        // (3) and every stamp points at a product the fixture made, so no id
        //     the double would have minted (`$nextId` starts at 1000).
        self::assertSame(
            self::WC_READY,
            array_values($this->projector->links),
            'the stamps are on the existing products, in order'
        );
        self::assertSame(['marketplace' => 5, 'observed' => 0], $this->products->ownershipTallyForVendor(self::VENDOR));
    }

    /**
     * A batch that fails part way stops THERE, names the product and the
     * reason, and reports what did move.
     */
    public function testTheBatchStopsAtTheFirstFailureAndNamesIt(): void
    {
        $ready = $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);

        $failing = new FailingDatabase($this->db);
        // The third ownership write, by table and column: nothing else in this
        // plugin writes that column, and `nth` makes it the third PRODUCT
        // rather than the first.
        $failing->failWhen(['UPDATE', 'tmc_products', M0010LinkOwnership::COLUMN], 3);
        $result = $this->apply($failing)->apply(self::VENDOR, 0, $plan['ready'], $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('stopped', $result['code']);
        self::assertSame([$ready[0], $ready[1]], $result['moved']);
        self::assertSame($ready[2], $result['stopped_at']);
        self::assertSame('storage_failed', $result['reason']);
        self::assertSame(5, $result['requested']);
        // Nothing past the failure was touched, and the failed one did not
        // half-move: its stamp went back with it (`alpha.90`).
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($ready[1]));
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($ready[2]));
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($ready[3]));
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($ready[4]));
        self::assertFalse($this->projector->owns(self::WC_READY[2], $ready[2]));
    }

    /** A batch is audited whether it finished or stopped. */
    public function testBothOutcomesAreAudited(): void
    {
        $ready = $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_products', M0010LinkOwnership::COLUMN], 2);
        self::assertFalse($this->apply($failing)->apply(self::VENDOR, 0, $plan['ready'], $plan['digest'])['ok']);

        $stopped = $this->auditPayloads(AuditEventCatalog::DOKAN_OWNERSHIP_BATCH);
        self::assertCount(1, $stopped, 'a batch that changed the catalogue and stopped says so');
        self::assertSame(1, (int) $stopped[0]['moved']);
        self::assertSame($ready[1], (int) $stopped[0]['stopped_at']);
        self::assertSame('storage_failed', (string) $stopped[0]['reason']);
        // And one `migration.ownership_changed` row per product that moved —
        // the trail an auditor follows, written by the transfer itself.
        self::assertSame(1, $this->auditRows(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED));

        $next = $this->plan()->plan(self::VENDOR);
        self::assertTrue($this->apply()->apply(self::VENDOR, 0, $next['ready'], $next['digest'])['ok']);

        $all = $this->auditPayloads(AuditEventCatalog::DOKAN_OWNERSHIP_BATCH);
        self::assertCount(2, $all);
        self::assertSame(4, (int) $all[1]['moved']);
        self::assertSame(0, (int) $all[1]['stopped_at']);
        self::assertSame(5, $this->auditRows(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED));
    }

    // ============================================ what the batch refuses, whole

    /**
     * The plan is recomputed, and a list that no longer matches is refused
     * ENTIRELY — consent is about what was printed (`alpha.49`).
     */
    public function testAPlanThatMovedSinceTheRenderIsRefusedWhole(): void
    {
        $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);
        // A risk appears between the render and the click.
        $this->reader->risks[self::WC_READY[2]] = ['manage_stock' => 'مدیریت موجودی خاموش است'];

        $result = $this->apply()->apply(self::VENDOR, 0, $plan['ready'], $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('plan_moved', $result['code']);
        self::assertSame([], $result['moved'], 'not even the rows that are still ready');
        self::assertSame(['marketplace' => 0, 'observed' => 5], $this->products->ownershipTallyForVendor(self::VENDOR));
    }

    /**
     * A submission that is a SUBSET of what was offered is refused rather
     * than filtered: carrying out part of something nobody asked for is not a
     * smaller version of the request.
     */
    public function testASubsetOfTheOfferedListIsRefusedRatherThanFiltered(): void
    {
        $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);
        $subset = array_slice($plan['ready'], 0, 3);

        $result = $this->apply()->apply(self::VENDOR, 0, $subset, $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('selection_not_offered', $result['code']);
        self::assertSame(['marketplace' => 0, 'observed' => 5], $this->products->ownershipTallyForVendor(self::VENDOR));
    }

    /** An id the page never offered is not a stale form, it is another request. */
    public function testAnIdThatWasNeverOfferedIsRefused(): void
    {
        $this->importReady();
        $risky = $this->importRisky();
        $plan = $this->plan()->plan(self::VENDOR);

        $result = $this->apply()->apply(self::VENDOR, 0, [...$plan['ready'], $risky], $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('selection_not_offered', $result['code']);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($risky));
    }

    /** No capability, no batch — and nothing moved. */
    public function testWithoutTheCapabilityNothingMovesAndNothingIsAudited(): void
    {
        $this->importReady();
        $plan = $this->plan()->plan(self::VENDOR);

        $result = $this->apply(null, new FakeCapabilityChecker(self::MANAGER, []))
            ->apply(self::VENDOR, 0, $plan['ready'], $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('forbidden', $result['code']);
        self::assertSame(['marketplace' => 0, 'observed' => 5], $this->products->ownershipTallyForVendor(self::VENDOR));
        self::assertSame(0, $this->auditRows(AuditEventCatalog::DOKAN_OWNERSHIP_BATCH));
    }

    /** An empty submission is named rather than reported as a done batch. */
    public function testAnEmptySubmissionIsNamed(): void
    {
        $this->importReady();

        $result = $this->apply()->apply(self::VENDOR, 0, [], 'anything');

        self::assertFalse($result['ok']);
        self::assertSame('nothing_selected', $result['code']);
        self::assertSame(0, $this->auditRows(AuditEventCatalog::DOKAN_OWNERSHIP_BATCH));
    }

    /**
     * The batch waives nothing: a risky row submitted with the right digest
     * is still refused, by the guard that already existed.
     *
     * Reached by offering the risky row through a plan built when the risk was
     * not yet there — the one way a batch can carry a row `take()` refuses,
     * and the thing the empty acknowledgement arrays are for.
     */
    public function testTheBatchCannotWaiveARiskTheSingleButtonWouldRefuse(): void
    {
        $risky = $this->importRisky(false);
        $plan = $this->plan()->plan(self::VENDOR);
        self::assertSame([$risky], $plan['ready'], 'ready while no risk is recorded');
        // Now the risk exists. The plan inside `apply()` will see it, so this
        // comes back as `plan_moved` — but the point is the row did NOT move,
        // and the direct call shows which guard refuses it.
        $this->reader->risks[self::WC_RISKY] = ['manage_stock' => 'مدیریت موجودی خاموش است'];

        $batch = $this->apply()->apply(self::VENDOR, 0, [$risky], $plan['digest']);
        $single = $this->transfer()->take($risky, [], []);

        self::assertFalse($batch['ok']);
        self::assertSame('plan_moved', $batch['code']);
        self::assertFalse($single->ok);
        self::assertSame('ownership_risks_unacknowledged', $single->code);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($risky));
    }

    // ===================================================================== wiring

    /** @return list<int> the five ready product ids, in id order */
    private function importReady(): array
    {
        $batch = $this->intake()->importBatch('staged-ready', self::WC_READY);
        self::assertTrue($batch['ok'], (string) $batch['code']);
        self::assertSame(count(self::WC_READY), (int) $batch['counts']['imported']);
        $ids = [];
        foreach (self::WC_READY as $wcId) {
            $product = $this->products->findObservedByWcProduct($wcId);
            self::assertNotNull($product, 'an observed row for ' . $wcId);
            $ids[] = (int) $product->id;
        }
        sort($ids);
        return $ids;
    }

    /** @return list<int> thirty agreeing rows, in id order */
    private function importBulk(): array
    {
        $wcIds = range(self::WC_BULK_FIRST, self::WC_BULK_FIRST + self::WC_BULK_COUNT - 1);
        // ⛔ In chunks, because `ImportExistingProducts::importBatch()` is
        // hard-capped at `self::BATCH` (25) whatever limit it is handed, and a
        // repeated call with the SAME list would re-judge the ids it already
        // imported and never reach the tail. Two intake batches, so the plan
        // has more rows than one ownership batch may move.
        $imported = 0;
        foreach (array_chunk($wcIds, ImportExistingProducts::BATCH) as $i => $chunk) {
            $batch = $this->intake()->importBatch('staged-bulk-' . $i, $chunk);
            self::assertTrue($batch['ok'], (string) $batch['code']);
            $imported += (int) $batch['counts']['imported'];
        }
        self::assertSame(self::WC_BULK_COUNT, $imported);
        $ids = [];
        foreach ($wcIds as $wcId) {
            $ids[] = (int) $this->products->findObservedByWcProduct($wcId)->id;
        }
        sort($ids);
        return $ids;
    }

    /** One imported row, then given a preservation risk a human must read. */
    private function importRisky(bool $withRisk = true): int
    {
        $batch = $this->intake()->importBatch('staged-risky', [self::WC_RISKY]);
        self::assertTrue($batch['ok'], (string) $batch['code']);
        if ($withRisk) {
            $this->reader->risks[self::WC_RISKY] = ['manage_stock' => 'مدیریت موجودی خاموش است'];
        }
        return (int) $this->products->findObservedByWcProduct(self::WC_RISKY)->id;
    }

    /** An observed row pointing at a product the shop cannot answer about. */
    private function rowWithoutDetail(int $wcProductId): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'کالای ناخوانا', priceMinor: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            $wcProductId
        );
    }

    /** An observed row with no WooCommerce product at all. */
    private function rowWithoutStorefront(): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(title: 'کالای بی‌فروشگاه', priceMinor: 1),
            ProductStatus::Draft,
            LinkOwnership::Observed,
            null
        );
    }

    private function intake(?FailingDatabase $gateway = null): ImportExistingProducts
    {
        return new ImportExistingProducts(
            $this->reader,
            new CatalogueInventory($this->reader),
            $gateway === null ? $this->products : new DbProductRepository($gateway, new SystemClock()),
            $this->vendors,
            $this->options,
            $this->audit(),
            new SystemClock(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            // One connection, two objects: the transaction the intake opens is
            // the gateway its statements go through, so a refused part really
            // does undo its siblings.
            $gateway ?? $this->db
        );
    }

    private function transfer(?FailingDatabase $gateway = null, ?FakeCapabilityChecker $capabilities = null): TransferOwnership
    {
        return new TransferOwnership(
            $gateway === null ? $this->products : new DbProductRepository($gateway, new SystemClock()),
            $this->projector,
            $this->audit(),
            $capabilities ?? new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            $this->reader,
            $this->intake($gateway)
        );
    }

    private function plan(?FailingDatabase $gateway = null): OwnershipTransferPlan
    {
        return new OwnershipTransferPlan(
            $gateway === null ? $this->products : new DbProductRepository($gateway, new SystemClock()),
            $this->transfer($gateway),
            $this->reader,
            $this->intake($gateway)
        );
    }

    private function apply(?FailingDatabase $gateway = null, ?FakeCapabilityChecker $capabilities = null): ApplyOwnershipTransfer
    {
        $checker = $capabilities ?? new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]);
        return new ApplyOwnershipTransfer(
            $this->plan($gateway),
            $this->transfer($gateway, $checker),
            $checker,
            $this->audit()
        );
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock());
    }

    /** Every row of this shop's catalogue, so «nothing was written» is a value. */
    private function catalogueDigest(): string
    {
        $rows = $this->wpdb->get_results(
            'SELECT id, wc_product_id, ' . M0010LinkOwnership::COLUMN . ' AS ownership, title, price_minor, stock, sku, updated_at
               FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC',
            ARRAY_A
        );
        return hash('sha256', (string) json_encode($rows));
    }

    private function rowCount(): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_products`');
    }

    private function auditRows(string $eventType = ''): int
    {
        $table = $this->wpdb->prefix . 'tmc_audit_events';
        if ($eventType === '') {
            return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM `' . $table . '`');
        }
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE event_type = %s',
            $eventType
        ));
    }

    /** @return list<array<string,mixed>> each row's payload, oldest first */
    private function auditPayloads(string $eventType): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT payload FROM `' . $this->wpdb->prefix . 'tmc_audit_events`
              WHERE event_type = %s ORDER BY id ASC',
            $eventType
        ), ARRAY_A);
        return array_map(
            static fn (array $row): array => (array) json_decode((string) $row['payload'], true),
            $rows
        );
    }
}
