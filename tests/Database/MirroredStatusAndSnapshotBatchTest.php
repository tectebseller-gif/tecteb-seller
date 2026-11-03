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
use Tecteb\Marketplace\Modules\Migration\Application\ApplyRefreshSnapshot;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipTransferPlan;
use Tecteb\Marketplace\Modules\Migration\Application\ShopTransferTally;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProjectedStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0019BaselineAndDecisions;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0032ProductMirroredStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeCatalogueReader;

/**
 * The publish state of an imported product, and the batch that makes a row
 * safe to transfer without writing one byte to the shop.
 *
 * ⛔ **The state every test here starts from is the state shop 102 is in.**
 * `ImportFromDokan` never passed `create()`'s seventh argument, so all 184
 * rows say «پیش‌نویس» with NO `mirrored_status` beside products their vendor
 * is selling. The fixture reproduces that by importing properly and then
 * CLEARING the stamp, which is exactly what the Dokan path produced — and it
 * separates the two questions: this file is about the missing stamp, and the
 * drift those rows also carry has its own tests below.
 *
 * The owner's §1 and §2, and the requirement that tests prevent a change to
 * «وضعیت انتشار، قیمت، موجودی، دسته‌بندی و SKU».
 */
final class MirroredStatusAndSnapshotBatchTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const VENDOR = 920;

    /** Published in the shop, imported, then stripped of its stamp. */
    private const WC_PUBLISHED = 8301;

    /** A second one, so a batch has more than a single row. */
    private const WC_SECOND = 8302;

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
        $this->vendors->upsertProfile(self::VENDOR, 'فروشگاه وضعیت', true, false);

        $this->reader = new FakeCatalogueReader();
        foreach ([self::WC_PUBLISHED, self::WC_SECOND] as $wcId) {
            $this->reader->add(
                $wcId,
                [
                    'title' => 'کالای ' . $wcId,
                    'regular_price' => '450000',
                    'sku' => 'WC-' . $wcId,
                    // Published, which is the whole point: a published product
                    // is what a projection would draft.
                    'status' => 'publish',
                ],
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

    // ============================================= §1 the stamp, and the stop

    /** The proper intake records it, which is why this path was never broken. */
    public function testACatalogueIntakeRecordsTheShopStatus(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);

        $product = $this->products->find($id);
        self::assertNotNull($product);
        self::assertSame('publish', $product->mirroredStatus);
        self::assertTrue($product->storefrontStatusIsHeld());
    }

    /**
     * ⛔ And a row without it is REFUSED, with the two statuses named.
     *
     * The measured hazard of `docs/dokan-exit-drift-analysis-alpha91-fa.md`
     * §2: on `alpha.91` this transfer succeeded, and the first projection
     * afterwards wrote `draft` + `hidden` over a selling product.
     */
    public function testARowWithNoStampBesideAPublishedProductCannotBeTransferred(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);

        $result = $this->transfer()->take($id, [], []);

        self::assertFalse($result->ok);
        self::assertSame('storefront_status_would_change', $result->code);
        self::assertSame('publish', $result->context['now']);
        self::assertSame('draft', $result->context['would_write']);
        self::assertSame(LinkOwnership::Observed, $this->products->linkOwnership($id));
    }

    /**
     * ⛔ And NO acknowledgement waives it.
     *
     * «متوقف شود» is not «قابل پذیرش با تیک». Every risk key and the whole
     * drift fingerprint are handed over, and the refusal is the same.
     */
    public function testNoAcknowledgementCanWaiveAStatusChange(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);
        $transfer = $this->transfer();
        $state = $transfer->acceptanceState($id);
        $seen = [];
        foreach ($state['drift'] as $field => $pair) {
            $seen[$field] = (string) $pair['fingerprint'];
        }

        $result = $transfer->take($id, array_keys($state['risks']), $seen);

        self::assertFalse($result->ok);
        self::assertSame('storefront_status_would_change', $result->code);
    }

    /** A product the shop cannot answer about is refused by its own name. */
    public function testAnUnreadableProductIsRefusedAsUnreadableNotAsAChange(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);
        unset($this->reader->details[self::WC_PUBLISHED]);

        $result = $this->transfer()->take($id, [], []);

        self::assertFalse($result->ok);
        self::assertSame('storefront_status_unreadable', $result->code);
    }

    /** The planner says so too, in Persian, naming both statuses. */
    public function testThePlanBucketsItAsStatusBlockedAndSaysWhy(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);

        $plan = $this->plan()->plan(self::VENDOR);

        self::assertSame([$id], $plan['buckets'][OwnershipTransferPlan::STATUS_BLOCKED]);
        self::assertSame([], $plan['ready']);
        $row = $plan['rows'][0];
        self::assertSame(ProjectedStatus::WOULD_CHANGE, $row['status']);
        self::assertStringContainsString('publish', (string) $row['why']);
        self::assertStringContainsString('draft', (string) $row['why']);
        self::assertStringContainsString('بازخوانی اطلاعات', (string) $row['why']);
        // And it is offered to the batch that can clear it.
        self::assertSame([$id], array_map('intval', (array) $plan['refreshable']));
    }

    /** A re-snapshot records the stamp, and then the row is ready. */
    public function testARefreshRecordsTheStampAndTheRowBecomesReady(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);

        $refreshed = $this->transfer()->refreshFromStorefront($id);

        self::assertTrue($refreshed->ok, $refreshed->code);
        self::assertSame('publish', $this->stampOf($id));
        $plan = $this->plan()->plan(self::VENDOR);
        self::assertSame([$id], $plan['buckets'][OwnershipTransferPlan::READY]);
        $taken = $this->transfer()->take($id, [], []);
        self::assertTrue($taken->ok, $taken->code);
    }

    /**
     * ⛔ And a stamp a human's decision released is NOT written again.
     *
     * `updateStatus()` clears it the moment somebody in this marketplace
     * decides, and a refresh that re-stamped it would hold open a status that
     * person deliberately released.
     */
    public function testARefreshNeverOverwritesAStampThatIsAlreadyThere(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        // A stamp that disagrees with the shop, so a rewrite would be visible.
        $this->setStamp($id, 'pending');

        self::assertTrue($this->transfer()->refreshFromStorefront($id)->ok);

        self::assertSame('pending', $this->stampOf($id));
    }

    /**
     * ⛔ And a held row's projection leaves the shop's publish state alone.
     *
     * The guard this whole section protects, measured end to end rather than
     * argued — and it is only expressible because `FakeCatalogProjector`
     * gained the real hold this round (`alpha.59`).
     */
    public function testAProjectionOfAHeldRowWritesNeitherStatusNorVisibility(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->linkProjector($id, self::WC_PUBLISHED);
        $product = $this->products->find($id);
        self::assertNotNull($product);
        self::assertTrue($product->storefrontStatusIsHeld());

        $this->projector->project($product);

        self::assertSame('', $this->projector->storefrontStatus(self::WC_PUBLISHED));
        self::assertSame('', $this->projector->visibilityOf(self::WC_PUBLISHED));
    }

    /**
     * The control: a row with no stamp DOES get both written.
     *
     * Without it, a projector that wrote nothing at all would satisfy the
     * assertion above (`alpha.46`).
     */
    public function testAProjectionOfAnUnheldRowWritesBoth(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);
        $this->linkProjector($id, self::WC_PUBLISHED);
        $product = $this->products->find($id);
        self::assertNotNull($product);
        self::assertFalse($product->storefrontStatusIsHeld());

        $this->projector->project($product);

        self::assertSame('draft', $this->projector->storefrontStatus(self::WC_PUBLISHED));
        self::assertSame('hidden', $this->projector->visibilityOf(self::WC_PUBLISHED));
    }

    // =================================================== §2 the batch, capped

    public function testTheBatchRefreshesWhatThePageOfferedAndAuditsIt(): void
    {
        $first = $this->importOne(self::WC_PUBLISHED);
        $second = $this->importOne(self::WC_SECOND);
        $this->clearStamp($first);
        $this->clearStamp($second);
        $plan = $this->plan()->plan(self::VENDOR);

        $result = $this->refreshBatch()->apply(
            self::VENDOR,
            0,
            array_map('intval', (array) $plan['refreshable']),
            (string) $plan['refresh_digest']
        );

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame('snapshot_batch_refreshed', $result['code']);
        self::assertSame([$first, $second], $result['refreshed']);
        self::assertSame('publish', $this->stampOf($first));
        self::assertSame('publish', $this->stampOf($second));
        $rows = $this->auditPayloads(AuditEventCatalog::CATALOGUE_SNAPSHOT_BATCH);
        self::assertCount(1, $rows);
        self::assertSame('2', (string) $rows[0]['refreshed']);
        self::assertSame('2', (string) $rows[0]['requested']);
    }

    /** A form whose list the page did not print is refused WHOLE. */
    public function testASelectionThePageNeverOfferedIsRefused(): void
    {
        $first = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($first);
        $plan = $this->plan()->plan(self::VENDOR);

        $result = $this->refreshBatch()->apply(
            self::VENDOR,
            0,
            [$first, 999999],
            (string) $plan['refresh_digest']
        );

        self::assertFalse($result['ok']);
        self::assertSame('selection_not_offered', $result['code']);
        self::assertNull($this->stampOf($first), 'nothing was refreshed');
    }

    /** A catalogue that moved between the render and the click is refused. */
    public function testAPlanThatMovedIsRefused(): void
    {
        $first = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($first);

        $result = $this->refreshBatch()->apply(self::VENDOR, 0, [$first], 'deadbeef');

        self::assertFalse($result['ok']);
        self::assertSame('plan_moved', $result['code']);
        self::assertNull($this->stampOf($first));
    }

    /** It stops at the first row that will not refresh, and names it. */
    public function testTheBatchStopsAtTheFirstFailureAndRecordsBothHalves(): void
    {
        $first = $this->importOne(self::WC_PUBLISHED);
        $second = $this->importOne(self::WC_SECOND);
        $this->clearStamp($first);
        $this->clearStamp($second);
        $plan = $this->plan()->plan(self::VENDOR);
        $offered = array_map('intval', (array) $plan['refreshable']);
        self::assertSame([$first, $second], $offered);

        // ⛔ A FAILURE THE PLANNER CANNOT SEE, and that is the whole care in
        // this fixture. The first version made the second product unreadable,
        // which is a change the re-plan DOES see — so the batch answered
        // `plan_moved` before the loop, which is correct behaviour and not
        // the behaviour under test. A refused baseline write is invisible to
        // a plan built from reads, so the loop really runs and really stops.
        //
        // The second occurrence, so the first row's refresh succeeds and the
        // second's does not.
        $gateway = new FailingDatabase($this->db);
        $gateway->failWhen(['UPDATE', M0019BaselineAndDecisions::BASELINE_COLUMN], 2);

        $result = $this->refreshBatch($gateway)->apply(
            self::VENDOR,
            0,
            $offered,
            (string) $plan['refresh_digest']
        );

        self::assertFalse($result['ok']);
        self::assertSame('stopped', $result['code']);
        self::assertSame($second, $result['stopped_at']);
        self::assertSame('intake_refresh_not_stored', $result['reason']);
        self::assertSame([$first], $result['refreshed'], 'the first one stays refreshed');
        self::assertSame('publish', $this->stampOf($first));
        self::assertNull($this->stampOf($second), 'the refused row is whole and untouched');
        $rows = $this->auditPayloads(AuditEventCatalog::CATALOGUE_SNAPSHOT_BATCH);
        self::assertCount(1, $rows, 'the stopped batch is audited too');
        self::assertSame((string) $second, (string) $rows[0]['stopped_at']);
    }

    /** The cap is the batch's, and it is the same number for both buttons. */
    public function testTheOfferIsCappedAtTwentyFive(): void
    {
        self::assertSame(25, OwnershipTransferPlan::BATCH_LIMIT);
    }

    // ================================= the owner's five fields, before/after

    /**
     * ⛔ A TRANSFER changes neither the publish status, the price, the stock,
     * the category nor the SKU — of the WooCommerce product OR of our row.
     *
     * The owner's named requirement, measured rather than argued: both sides
     * are digested before and after, and the only thing allowed to differ is
     * the ownership column the transfer exists to move.
     */
    public function testATransferChangesNoneOfTheFiveFields(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        // A product the shop already serves, so the digest is about a real
        // post rather than about the double learning a link mid-test:
        // `claimStorefrontPost()` seeds its status when it has none, and that
        // bookkeeping is not a change to a product.
        $this->linkProjector($id, self::WC_PUBLISHED);
        $this->projector->statuses[self::WC_PUBLISHED] = 'publish';
        $shopBefore = $this->shopDigest(self::WC_PUBLISHED);
        $rowBefore = $this->rowFields($id);

        $result = $this->transfer()->take($id, [], []);

        self::assertTrue($result->ok, $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($id));
        self::assertSame($shopBefore, $this->shopDigest(self::WC_PUBLISHED));
        self::assertSame($rowBefore, $this->rowFields($id));
    }

    /**
     * ⛔ And a RE-SNAPSHOT changes nothing on the WooCommerce product either.
     *
     * It is allowed to change our own row — that is its entire purpose — so
     * the shop side is digested and the row side is read for what it SHOULD
     * now hold: the shop's own values.
     */
    public function testARefreshChangesNothingOnTheWooCommerceProduct(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->clearStamp($id);
        $shopBefore = $this->shopDigest(self::WC_PUBLISHED);

        self::assertTrue($this->transfer()->refreshFromStorefront($id)->ok);

        self::assertSame($shopBefore, $this->shopDigest(self::WC_PUBLISHED));
        $after = $this->rowFields($id);
        self::assertSame('450000', (string) $after['price_minor']);
        self::assertSame('WC-' . self::WC_PUBLISHED, (string) $after['sku']);
    }

    // ============================================== §4 the whole-shop tally

    /** Every row of the shop, across pages, grouped by reason. */
    public function testTheTallyCoversTheWholeShopNotOnePage(): void
    {
        $blocked = [];
        for ($i = 0; $i < 3; $i++) {
            $wcId = 8400 + $i;
            $this->reader->add(
                $wcId,
                ['title' => 'کالای ' . $wcId, 'regular_price' => '450000', 'sku' => 'S-' . $wcId, 'status' => 'publish'],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
            $id = $this->importOne($wcId);
            $this->clearStamp($id);
            $blocked[] = $id;
        }
        $ready = $this->importOne(self::WC_PUBLISHED);

        $tally = $this->tally()->of(self::VENDOR);

        self::assertTrue($tally['finished'], 'it reached the end');
        self::assertSame(4, $tally['scanned']);
        self::assertSame(4, $tally['observed_total']);
        self::assertSame(1, $tally['counts'][OwnershipTransferPlan::READY]);
        self::assertSame(3, $tally['counts'][OwnershipTransferPlan::STATUS_BLOCKED]);
        // Grouped: three rows with one reason become one line with a count of
        // three, and every id is named inside it.
        $groups = array_values(array_filter(
            (array) $tally['groups'],
            static fn (array $g): bool => $g['bucket'] === OwnershipTransferPlan::STATUS_BLOCKED
        ));
        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]['count']);
        self::assertSame($blocked, $groups[0]['ids']);
        self::assertSame(0, $groups[0]['more']);
        self::assertNotSame('', (string) $groups[0]['why']);
        self::assertContains($ready, array_merge(...array_map(
            static fn (array $g): array => $g['ids'],
            (array) $tally['groups']
        )));
    }

    /** A shop with more rows than one page still adds up. */
    public function testTheTallyWalksPastTheScanLimit(): void
    {
        $wcIds = [];
        for ($i = 0; $i < OwnershipTransferPlan::SCAN_LIMIT + 4; $i++) {
            $wcId = 8500 + $i;
            $this->reader->add(
                $wcId,
                ['title' => 'کالای ' . $wcId, 'regular_price' => '450000', 'sku' => 'B-' . $wcId, 'status' => 'publish'],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
            $wcIds[] = $wcId;
        }
        foreach (array_chunk($wcIds, ImportExistingProducts::BATCH) as $i => $chunk) {
            $batch = $this->intake()->importBatch('tally-' . $i, $chunk);
            self::assertTrue($batch['ok'], (string) $batch['code']);
        }

        $tally = $this->tally()->of(self::VENDOR);

        self::assertTrue($tally['finished']);
        self::assertSame(count($wcIds), $tally['scanned']);
        self::assertSame(count($wcIds), $tally['counts'][OwnershipTransferPlan::READY]);
        // And the per-page plan still reports ONE page, which is the gap the
        // tally exists to close.
        $page = $this->plan()->plan(self::VENDOR);
        self::assertSame(
            OwnershipTransferPlan::SCAN_LIMIT,
            $page['counts'][OwnershipTransferPlan::READY]
        );
        self::assertFalse($page['finished']);
    }

    // ================================================= §3 risk classification

    /** A disclosure-only product is READY and transfers with no tick. */
    public function testADisclosureOnlyRiskDoesNotBlockTheTransfer(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->reader->risks[self::WC_PUBLISHED] = [
            'tags' => 'برچسب‌ها فیلدی ندارند',
            'shipping_class' => 'کلاس ارسال فیلدی ندارد',
        ];

        $plan = $this->plan()->plan(self::VENDOR);
        $result = $this->transfer()->take($id, [], []);

        self::assertSame([$id], $plan['buckets'][OwnershipTransferPlan::READY]);
        self::assertSame(['tags', 'shipping_class'], $plan['rows'][0]['disclosed']);
        self::assertSame([], $plan['rows'][0]['risks']);
        self::assertTrue($result->ok, $result->code);
        // ⛔ And the trail says what it was told about and left alone.
        $rows = $this->auditPayloads(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED);
        self::assertCount(1, $rows);
        self::assertSame('tags,shipping_class', (string) $rows[0]['disclosed']);
    }

    /** A blocking risk still stops it, and the batch still stops with it. */
    public function testABlockingRiskStillNeedsAHuman(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->reader->risks[self::WC_PUBLISHED] = [
            'manage_stock' => 'مدیریت موجودی خاموش است',
            'tags' => 'برچسب‌ها فیلدی ندارند',
        ];

        $plan = $this->plan()->plan(self::VENDOR);
        $result = $this->transfer()->take($id, [], []);

        self::assertSame([$id], $plan['buckets'][OwnershipTransferPlan::RISK_REAL]);
        self::assertSame(['manage_stock'], $plan['rows'][0]['risks']);
        self::assertSame(['tags'], $plan['rows'][0]['disclosed']);
        self::assertFalse($result->ok);
        self::assertSame('ownership_risks_unacknowledged', $result->code);
        self::assertSame(['manage_stock'], $result->context['risks']);
        // Not offered to the refresh batch either: a refresh cannot clear it.
        self::assertSame([], array_map('intval', (array) $plan['refreshable']));
    }

    /** With the one key acknowledged it goes through. The control. */
    public function testAnAcknowledgedBlockingRiskGoesThrough(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->reader->risks[self::WC_PUBLISHED] = ['manage_stock' => 'مدیریت موجودی خاموش است'];

        $result = $this->transfer()->take($id, ['manage_stock'], []);

        self::assertTrue($result->ok, $result->code);
    }

    // ============================================================== fixtures

    private function importOne(int $wcId): int
    {
        $batch = $this->intake()->importBatch('status-' . $wcId, [$wcId]);
        self::assertTrue($batch['ok'], (string) $batch['code']);
        $product = $this->products->findObservedByWcProduct($wcId);
        self::assertNotNull($product, 'an observed row for ' . $wcId);
        return (int) $product->id;
    }

    /**
     * The state the Dokan intake left behind, both halves of it.
     *
     * ⛔ **Clearing the stamp alone is not that state, and this test file's
     * first version got it wrong.** `ImportFromDokan` wrote `ProductStatus::
     * Draft` unconditionally AND dropped the stamp, so a row sits at
     * «پیش‌نویس» beside a `publish` product. A catalogue-imported row of the
     * same product carries `Published`, so clearing only its stamp leaves a
     * row that AGREES with the shop — the transfer is then correctly allowed,
     * and four assertions here failed about code that was working.
     *
     * Raw SQL on purpose, for both: there is no service that un-records a
     * stamp or walks a row's status backwards, and there must not be. This
     * reproduces a defect; it does not use a feature.
     */
    private function clearStamp(int $productId): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products`
                SET `' . M0032ProductMirroredStatus::MIRRORED . '` = NULL,
                    status = %s
              WHERE id = %d',
            ProductStatus::Draft->value,
            $productId
        ));
        self::assertNull($this->stampOf($productId), 'the fixture really cleared it');
        $product = $this->products->find($productId);
        self::assertNotNull($product);
        self::assertSame(ProductStatus::Draft, $product->status, 'and really drafted the row');
    }

    /**
     * Tell the storefront double which post this row is linked to.
     *
     * Needed because the double learns a link from a projection or a claim,
     * and a test that asserts «the shop's status did not change» must be
     * asking about the SAME id the intake recorded — otherwise `project()`
     * invents a fresh one and the assertion is about a product nobody has.
     */
    private function linkProjector(int $productId, int $wcId): void
    {
        $this->projector->links[$productId] = $wcId;
    }

    private function setStamp(int $productId, string $status): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products`
                SET `' . M0032ProductMirroredStatus::MIRRORED . '` = %s WHERE id = %d',
            $status,
            $productId
        ));
    }

    private function stampOf(int $productId): ?string
    {
        $value = $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT `' . M0032ProductMirroredStatus::MIRRORED . '` FROM `'
            . $this->wpdb->prefix . 'tmc_products` WHERE id = %d',
            $productId
        ));
        return $value === null ? null : (string) $value;
    }

    /** The five fields of the SHOP product, as one value. */
    private function shopDigest(int $wcId): string
    {
        $detail = $this->reader->detail($wcId);
        return (string) json_encode([
            'status' => (string) ($detail['status'] ?? ''),
            'regular_price' => (string) ($detail['regular_price'] ?? ''),
            'stock' => (string) ($detail['stock'] ?? ''),
            'sku' => (string) ($detail['sku'] ?? ''),
            'category_ids' => (array) ($detail['category_ids'] ?? []),
            // What a projection wrote, if it wrote anything at all.
            'projected_status' => $this->projector->storefrontStatus($wcId),
            'projected_visibility' => $this->projector->visibilityOf($wcId),
        ]);
    }

    /** @return array<string,mixed> the same five on OUR row */
    private function rowFields(int $productId): array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT status, price_minor, stock, sku, category_key FROM `'
            . $this->wpdb->prefix . 'tmc_products` WHERE id = %d',
            $productId
        ), ARRAY_A);
        self::assertIsArray($row);
        return $row;
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
            // One connection, two objects: the transaction the refresh opens
            // is the gateway its statements go through, so a refused part
            // really does undo its siblings.
            $gateway ?? $this->db
        );
    }

    private function transfer(?FailingDatabase $gateway = null): TransferOwnership
    {
        return new TransferOwnership(
            $gateway === null ? $this->products : new DbProductRepository($gateway, new SystemClock()),
            $this->projector,
            $this->audit(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
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

    private function refreshBatch(?FailingDatabase $gateway = null): ApplyRefreshSnapshot
    {
        $checker = new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]);
        return new ApplyRefreshSnapshot(
            $this->plan($gateway),
            $this->transfer($gateway),
            $checker,
            $this->audit()
        );
    }

    /** No budget: the tally must walk the whole fixture shop. */
    private function tally(): ShopTransferTally
    {
        return new ShopTransferTally($this->plan());
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock());
    }

    /** @return list<array<string,mixed>> */
    private function auditPayloads(string $eventType): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT payload FROM `' . $this->wpdb->prefix . 'tmc_audit_events`
              WHERE event_type = %s ORDER BY id ASC',
            $eventType
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $out[] = is_array($decoded) ? $decoded : [];
        }
        return $out;
    }
}
