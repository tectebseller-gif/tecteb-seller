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
use Tecteb\Marketplace\Modules\Migration\Domain\PreservationRisk;
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

    /** Published, and `hidden` in the catalogue — the §5 subject. */
    private const WC_HIDDEN = 8303;

    /**
     * `draft` in the shop AND hidden, so an unstamped row AGREES about the
     * publish state and the risk question is the only one left.
     */
    private const WC_DRAFT_HIDDEN = 8304;

    /** Published, with stock management off — the risk no stamp releases. */
    private const WC_UNMANAGED = 8305;

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

        // §5's three, each carrying the finding the real reader would derive
        // from the same product. The fake answers from `$risks`, keyed off
        // the detail's own id, so it cannot tell the planner one thing and
        // the guard another (`alpha.59`).
        $this->reader->add(
            self::WC_HIDDEN,
            [
                'title' => 'کالای پنهان',
                'regular_price' => '450000',
                'sku' => 'WC-' . self::WC_HIDDEN,
                'status' => 'publish',
                'catalog_visibility' => 'hidden',
            ],
            ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
        );
        $this->reader->risks[self::WC_HIDDEN] = [
            'catalog_visibility' => 'نمایش در فهرست «hidden» است و این بازارگاه فیلدی برایش ندارد.',
        ];
        $this->reader->add(
            self::WC_DRAFT_HIDDEN,
            [
                'title' => 'کالای پیش‌نویسِ پنهان',
                'regular_price' => '450000',
                'sku' => 'WC-' . self::WC_DRAFT_HIDDEN,
                'status' => 'draft',
                'catalog_visibility' => 'hidden',
            ],
            ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
        );
        $this->reader->risks[self::WC_DRAFT_HIDDEN] = [
            'catalog_visibility' => 'نمایش در فهرست «hidden» است و این بازارگاه فیلدی برایش ندارد.',
        ];
        $this->reader->add(
            self::WC_UNMANAGED,
            [
                'title' => 'کالای بی‌مدیریت موجودی',
                'regular_price' => '450000',
                'sku' => 'WC-' . self::WC_UNMANAGED,
                'status' => 'publish',
                'manage_stock' => false,
            ],
            ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
        );
        $this->reader->risks[self::WC_UNMANAGED] = [
            'manage_stock' => 'مدیریت موجودی خاموش است و همگام‌سازی بازارگاه آن را روشن می‌کند.',
        ];

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

        // ⛔ EVERY REAL RISK KEY, AND EVERY NAME A KEY WOULD BE SPELLED WITH.
        //
        // The first version of this test handed over `array_keys(
        // $state['risks'])` alone, and a mutation that added
        // `&& !in_array('status', $acknowledged, true)` to the guard SURVIVED
        // it: this fixture has no risk called `status`, so the array never
        // carried the one word that would have waived the check. The claim in
        // this method's name is about the ABSENCE of an acceptance key, so the
        // list has to contain the names such a key would plausibly use — not
        // just the keys today's product happens to carry (`alpha.88`).
        $everyName = array_merge(array_keys($state['risks']), [
            'status',
            'storefront_status',
            'mirrored_status',
            'publish',
            'visibility',
            ProjectedStatus::WOULD_CHANGE,
            ProjectedStatus::UNREADABLE,
        ]);

        $result = $transfer->take($id, $everyName, $seen);

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

    /**
     * The offer is capped at twenty five, and the page still says how many
     * there are.
     *
     * ⛔ **The first version of this test asserted `BATCH_LIMIT === 25` and
     * nothing else**, so deleting the `array_slice` that APPLIES the cap left
     * it green — the owner's «سقف ۲۵ محصول» was enforced by no test at all.
     * A constant is a number; the cap is what the service will accept, and
     * the only way to ask about it is to offer it more than it may take
     * (`alpha.80`: count the consumer, not the value).
     *
     * Twenty seven rows are refreshable, twenty five are offered, and the
     * digest is over exactly the twenty five offered — so the form cannot
     * carry a list the service computes differently. The count stays 27,
     * because a cap that states only one half hides the other (`alpha.85`).
     */
    public function testTheOfferIsCappedAtTwentyFiveWhileTheCountStaysWhole(): void
    {
        $over = OwnershipTransferPlan::BATCH_LIMIT + 2;
        $wcIds = [];
        for ($i = 0; $i < $over; $i++) {
            $wcId = 8600 + $i;
            $this->reader->add(
                $wcId,
                ['title' => 'کالای ' . $wcId, 'regular_price' => '450000', 'sku' => 'C-' . $wcId, 'status' => 'publish'],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
            $wcIds[] = $wcId;
        }
        foreach (array_chunk($wcIds, ImportExistingProducts::BATCH) as $i => $chunk) {
            $batch = $this->intake()->importBatch('cap-' . $i, $chunk);
            self::assertTrue($batch['ok'], (string) $batch['code']);
        }
        $ids = [];
        foreach ($wcIds as $wcId) {
            $product = $this->products->findObservedByWcProduct($wcId);
            self::assertNotNull($product);
            $id = (int) $product->id;
            $this->clearStamp($id);
            $ids[] = $id;
        }
        sort($ids);

        $plan = $this->plan()->plan(self::VENDOR);

        self::assertSame(
            $over,
            $plan['counts'][OwnershipTransferPlan::STATUS_BLOCKED],
            'the page knows about all of them'
        );
        self::assertCount(
            OwnershipTransferPlan::BATCH_LIMIT,
            $plan['refreshable'],
            'and offers exactly the cap'
        );
        $offered = array_slice($ids, 0, OwnershipTransferPlan::BATCH_LIMIT);
        self::assertSame($offered, $plan['refreshable']);
        self::assertSame(
            OwnershipTransferPlan::digestOf($offered),
            $plan['refresh_digest'],
            'the fingerprint is over what was offered, not over all of them'
        );
        // And the service accepts that list — the cap is not a display trick.
        $result = $this->refreshBatch()->apply(
            self::VENDOR,
            0,
            array_map('intval', (array) $plan['refreshable']),
            (string) $plan['refresh_digest']
        );
        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertCount(OwnershipTransferPlan::BATCH_LIMIT, $result['refreshed']);
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

    /**
     * One bucket, two reasons, two lines — because the grouping key is the
     * PAIR, not the bucket.
     *
     * ⛔ **The test above cannot ask this question**: its three blocked rows
     * all sit beside a `publish` product, so they share one sentence, and
     * grouping on the bucket alone gives the same single line. The mutation
     * that drops the reason from the key SURVIVED it.
     *
     * Here a fourth row sits beside a `pending` product, so the two
     * sentences name different statuses — «publish» vs «pending». On the
     * pair they are two lines of 3 and 1; on the bucket alone they collapse
     * into one line of 4, and the one sentence printed would be wrong for
     * whichever row lost. The owner's requirement is that each product's stop
     * reason is clear, so the reason has to be part of what is grouped.
     */
    public function testTwoReasonsInOneBucketAreTwoLines(): void
    {
        $publishBlocked = [];
        for ($i = 0; $i < 3; $i++) {
            $wcId = 8700 + $i;
            $this->reader->add(
                $wcId,
                ['title' => 'کالای ' . $wcId, 'regular_price' => '450000', 'sku' => 'D-' . $wcId, 'status' => 'publish'],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
            $id = $this->importOne($wcId);
            $this->clearStamp($id);
            $publishBlocked[] = $id;
        }
        $this->reader->add(
            8799,
            ['title' => 'کالای در انتظار', 'regular_price' => '450000', 'sku' => 'D-8799', 'status' => 'pending'],
            ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
        );
        $pendingBlocked = $this->importOne(8799);
        $this->clearStamp($pendingBlocked);

        $tally = $this->tally()->of(self::VENDOR);

        self::assertSame(4, $tally['counts'][OwnershipTransferPlan::STATUS_BLOCKED]);
        $groups = array_values(array_filter(
            (array) $tally['groups'],
            static fn (array $g): bool => $g['bucket'] === OwnershipTransferPlan::STATUS_BLOCKED
        ));
        self::assertCount(2, $groups, 'one bucket, two reasons, two lines');
        $byCount = [];
        foreach ($groups as $group) {
            $byCount[(int) $group['count']] = $group;
        }
        self::assertArrayHasKey(3, $byCount, 'the three publish rows are one line');
        self::assertArrayHasKey(1, $byCount, 'and the pending row is its own');
        self::assertSame($publishBlocked, $byCount[3]['ids']);
        self::assertSame([$pendingBlocked], $byCount[1]['ids']);
        // Each sentence names the status that row actually holds, in Persian.
        self::assertStringContainsString('publish', (string) $byCount[3]['why']);
        self::assertStringNotContainsString('pending', (string) $byCount[3]['why']);
        self::assertStringContainsString('pending', (string) $byCount[1]['why']);
        self::assertNotSame((string) $byCount[3]['why'], (string) $byCount[1]['why']);
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
        // ⛔ `price_scale` rather than `manage_stock` from `alpha.94`: stock
        // management is released once the product can be read, so a fixture
        // that kept the old key would call this test green while measuring a
        // row that no longer blocks anything.
        $this->reader->risks[self::WC_PUBLISHED] = [
            'price_scale' => 'قیمت حراج این محصول عدد صحیح نیست',
            'tags' => 'برچسب‌ها فیلدی ندارند',
        ];

        $plan = $this->plan()->plan(self::VENDOR);
        $result = $this->transfer()->take($id, [], []);

        self::assertSame([$id], $plan['buckets'][OwnershipTransferPlan::RISK_REAL]);
        self::assertSame(['price_scale'], $plan['rows'][0]['risks']);
        self::assertSame(['tags'], $plan['rows'][0]['disclosed']);
        self::assertFalse($result->ok);
        self::assertSame('ownership_risks_unacknowledged', $result->code);
        self::assertSame(['price_scale'], $result->context['risks']);
        // Not offered to the refresh batch either: a refresh cannot clear it.
        self::assertSame([], array_map('intval', (array) $plan['refreshable']));
    }

    /** With the one key acknowledged it goes through. The control. */
    public function testAnAcknowledgedBlockingRiskGoesThrough(): void
    {
        $id = $this->importOne(self::WC_PUBLISHED);
        $this->reader->risks[self::WC_PUBLISHED] = ['price_scale' => 'قیمت حراج عدد صحیح نیست'];

        $result = $this->transfer()->take($id, ['price_scale'], []);

        self::assertTrue($result->ok, $result->code);
    }

    // ============================================================== fixtures

    // ===== §5 a risk the stamp makes impossible, and the three controls

    /**
     * ⛔ THE NUMBER THIS SECTION IS FOR: shop 102, 184 rows, 0 ready.
     *
     * After migration 38 stamped every one of those rows, `project()` writes
     * NEITHER the status nor the catalogue visibility — both are inside
     * `if (!$statusHeld)` (`WooCommerceProjector.php:218-222`). So a stamped
     * row cannot lose a `hidden` or `search` visibility, and until
     * `alpha.93` the gate demanded a tick for it anyway: a blocker on a
     * write that cannot happen, which is the `alpha.91` §1-الف shape one key
     * later.
     *
     * The key is NOT moved to `DISCLOSURE` — the owner's §3 keeps it
     * protected and `PreservationRiskClassificationTest` holds it in
     * `BLOCKING`. What changed is that the question is asked WITH the row's
     * stamp.
     */
    public function testAStampedRowDoesNotBlockOnAVisibilityItCannotLose(): void
    {
        $id = $this->importOne(self::WC_HIDDEN);
        self::assertSame('publish', $this->stampOf($id), 'the intake stamped it');

        $rows = $this->plan()->plan(self::VENDOR)['rows'];
        $row = $this->rowFor($rows, $id);

        self::assertSame(OwnershipTransferPlan::READY, $row['bucket'], (string) $row['why']);
        self::assertSame([], $row['risks'], 'nothing left to acknowledge');
        self::assertContains(
            'catalog_visibility',
            (array) $row['disclosed'],
            'released from blocking, NOT removed from the report'
        );
        self::assertSame(ProjectedStatus::HELD, $row['status']);
    }

    /**
     * THE CONTROL: with no stamp, the same finding still blocks.
     *
     * The shop sits at `draft` here on purpose, so the publish state AGREES
     * and the row reaches the risk question at all — an unstamped row beside
     * a `publish` product is `STATUS_BLOCKED` first, and would have proved
     * nothing about the visibility (precedence is a decision, not an order
     * of reading).
     */
    public function testAnUnstampedRowStillBlocksOnTheSameVisibility(): void
    {
        $id = $this->importOne(self::WC_DRAFT_HIDDEN);
        $this->clearStamp($id);

        $rows = $this->plan()->plan(self::VENDOR)['rows'];
        $row = $this->rowFor($rows, $id);

        self::assertSame(ProjectedStatus::AGREES, $row['status'], 'draft beside draft');
        self::assertSame(OwnershipTransferPlan::RISK_REAL, $row['bucket'], (string) $row['why']);
        self::assertContains('catalog_visibility', (array) $row['risks']);
        self::assertNotContains('catalog_visibility', (array) $row['disclosed']);
    }

    /**
     * THE SECOND CONTROL: the release is one key wide, so a key the stamp
     * does not cover still blocks a stamped row.
     *
     * Without it, a release that leaked to every key would pass the test
     * above (`alpha.46`). ⛔ The key is `price_scale` from `alpha.94`:
     * `manage_stock` used to serve here, and it is now released by its OWN
     * fact — the product being readable — so the control had to move to a
     * key no context releases. The sale price's missing scale is that key,
     * and the reason is written where the release lists are.
     */
    public function testAStampedRowStillBlocksOnAKeyTheStampDoesNotCover(): void
    {
        $id = $this->importOne(self::WC_UNMANAGED);
        self::assertSame('publish', $this->stampOf($id));
        $this->reader->risks[self::WC_UNMANAGED]['price_scale'] = 'قیمت حراج عدد صحیح نیست';

        $rows = $this->plan()->plan(self::VENDOR)['rows'];
        $row = $this->rowFor($rows, $id);

        self::assertSame(OwnershipTransferPlan::RISK_REAL, $row['bucket'], (string) $row['why']);
        self::assertSame(['price_scale'], (array) $row['risks'], 'and only that one');
        self::assertContains('manage_stock', (array) $row['disclosed'], 'the released key is still reported');
    }

    // ===== §۳/۴ a risk the write going away makes impossible (`alpha.94`)

    /**
     * ⛔ A READABLE product does not block on stock management.
     *
     * `set_manage_stock(true)` was the last unconditional write in
     * `project()` and `$isNew` guarded only the quantity beside it, so the
     * first projection after a transfer switched management ON and wrote no
     * number — a managed product with no `_stock` row, which WooCommerce can
     * report as out of stock. `alpha.93` measured that and could only
     * disclose it; the write now lives in the `$isNew` arm, so a product this
     * marketplace did not create keeps its own setting.
     *
     * The key STAYS in `BLOCKING` — the owner's §3 keeps it protected and
     * `PreservationRiskClassificationTest` holds it there. What changed is
     * that the question is asked with the row's context, and the fact that
     * releases it is the one that makes its write impossible.
     */
    public function testAReadableProductDoesNotBlockOnStockManagement(): void
    {
        $id = $this->importOne(self::WC_UNMANAGED);
        self::assertSame('publish', $this->stampOf($id));

        $rows = $this->plan()->plan(self::VENDOR)['rows'];
        $row = $this->rowFor($rows, $id);

        self::assertSame(OwnershipTransferPlan::READY, $row['bucket'], (string) $row['why']);
        self::assertSame([], $row['risks'], 'nothing left to acknowledge');
        self::assertContains(
            'manage_stock',
            (array) $row['disclosed'],
            'released from blocking, NOT removed from the report'
        );
    }

    /**
     * And the guard reaches the same answer, with the batch's own call.
     *
     * `take($id, [], [])` — empty acknowledgement, empty seen-shop — is what
     * `ApplyOwnershipTransfer` passes, so a planner that said `ready` while
     * the guard refused would hand the manager a button whose refusal they
     * could not predict (`alpha.78`).
     */
    public function testTheGuardTakesTheRowTheReleaseMadeReady(): void
    {
        $id = $this->importOne(self::WC_UNMANAGED);
        $this->linkProjector($id, self::WC_UNMANAGED);

        $result = $this->transfer()->take($id, [], []);

        self::assertTrue($result->ok, $result->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership($id));
    }

    /**
     * ⛔ THE CONTROL THAT KEEPS THE RELEASE HONEST: an answer that could not
     * read the product releases NOTHING.
     *
     * `storefrontReadable()` derives the fact from the answer itself — the
     * absence of the `product` key — so a read that failed cannot become a
     * licence (`alpha.49`). At plan level such a row is bucketed
     * `unreadable` before the split is even reached, which is the second line
     * of the same defence.
     */
    public function testAnAnswerThatCouldNotReadTheProductReleasesNothing(): void
    {
        self::assertSame(
            ['product', 'manage_stock'],
            PreservationRisk::blockingGiven(
                ['product' => 'محصول خوانده نشد', 'manage_stock' => 'خاموش است'],
                true
            )
        );
    }

    /**
     * And the guard reaches the SAME answer the planner offered.
     *
     * `take($id, [], [])` is the batch's call — empty acknowledgement, empty
     * seen-shop — so this is what `ApplyOwnershipTransfer` would do. A
     * planner that bucketed `ready` while the guard still refused would hand
     * the manager a button whose refusal they could not predict
     * (`alpha.78`), and that is exactly what a stamp-blind guard would do.
     */
    public function testTheGuardAcceptsWhatThePlannerCalledReady(): void
    {
        $stamped = $this->importOne(self::WC_HIDDEN);
        $this->linkProjector($stamped, self::WC_HIDDEN);

        $result = $this->transfer()->take($stamped, [], []);
        self::assertTrue($result->ok, (string) $result->code);
        self::assertSame(
            LinkOwnership::Marketplace,
            $this->products->linkOwnership($stamped),
            'the row moved'
        );

        // The other half: unstamped, and refused BY NAME with no tick.
        $unstamped = $this->importOne(self::WC_DRAFT_HIDDEN);
        $this->clearStamp($unstamped);
        $this->linkProjector($unstamped, self::WC_DRAFT_HIDDEN);

        $refused = $this->transfer()->take($unstamped, [], []);
        self::assertFalse($refused->ok);
        self::assertSame('ownership_risks_unacknowledged', $refused->code);
        self::assertContains('catalog_visibility', (array) ($refused->context['risks'] ?? []));
        self::assertSame(
            LinkOwnership::Observed,
            $this->products->linkOwnership($unstamped),
            'and did not move'
        );
    }

    /**
     * THE OWNER'S CONSTRAINT, MEASURED: the transfer changes nothing.
     *
     * «قیمت، موجودی، SKU، دسته‌ها، وضعیت انتشار و اطلاعات محصولات ووکامرس
     * باید حفظ شوند» — asserted on BOTH sides of the one write this round
     * makes reachable, because a release that let a product through is only
     * safe if going through costs the product nothing.
     */
    public function testTakingAReleasedRowChangesNeitherTheShopNorTheFiveFields(): void
    {
        $id = $this->importOne(self::WC_HIDDEN);
        $this->linkProjector($id, self::WC_HIDDEN);

        $shopBefore = $this->shopProductDigest(self::WC_HIDDEN);
        $rowBefore = $this->rowFields($id);

        $result = $this->transfer()->take($id, [], []);
        self::assertTrue($result->ok, (string) $result->code);

        // ⛔ The PRODUCT's own fields, not `shopDigest()`: that one also
        // carries the double's bookkeeping, and `claimStorefrontPost()`
        // legitimately records the status it found (`_tmc_claimed_from_status`
        // — one of the three keys the claim writes). Asserting on it would
        // report the claim as a change to the product, which it is not.
        self::assertSame(
            $shopBefore,
            $this->shopProductDigest(self::WC_HIDDEN),
            'status, price, stock, sku, categories and visibility of the SHOP product'
        );
        self::assertSame($rowBefore, $this->rowFields($id), 'status, price, stock, sku, category');
        // And no visibility was written at all — the fake records one only
        // when `set_catalog_visibility()` is actually reached.
        self::assertSame('', $this->projector->visibilityOf(self::WC_HIDDEN), 'no visibility write');
        self::assertSame('publish', $this->stampOf($id), 'the stamp that holds it survives');
    }

    /** A released key is recorded, so the trail can answer «did anybody know?». */
    public function testTheReleasedKeyTravelsIntoTheAuditRow(): void
    {
        $id = $this->importOne(self::WC_HIDDEN);
        $this->linkProjector($id, self::WC_HIDDEN);

        $result = $this->transfer()->take($id, [], []);
        self::assertTrue($result->ok, (string) $result->code);

        $disclosed = '';
        foreach ($this->auditPayloads(AuditEventCatalog::DOKAN_OWNERSHIP_CHANGED) as $payload) {
            $disclosed .= (string) ($payload['disclosed'] ?? '');
        }
        self::assertStringContainsString('catalog_visibility', $disclosed);
    }

    /** The SHOP product's own fields — nothing the double keeps about itself. */
    private function shopProductDigest(int $wcId): string
    {
        $detail = $this->reader->detail($wcId);
        return (string) json_encode([
            'status' => (string) ($detail['status'] ?? ''),
            'catalog_visibility' => (string) ($detail['catalog_visibility'] ?? ''),
            'regular_price' => (string) ($detail['regular_price'] ?? ''),
            'sale_price' => (string) ($detail['sale_price'] ?? ''),
            'stock_quantity' => (string) ($detail['stock_quantity'] ?? ''),
            'sku' => (string) ($detail['sku'] ?? ''),
            'category_ids' => (array) ($detail['category_ids'] ?? []),
            'manage_stock' => (bool) ($detail['manage_stock'] ?? false),
        ]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function rowFor(array $rows, int $productId): array
    {
        foreach ($rows as $row) {
            if ((int) ($row['product_id'] ?? 0) === $productId) {
                return $row;
            }
        }
        self::fail('the plan did not list product ' . $productId);
    }

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
