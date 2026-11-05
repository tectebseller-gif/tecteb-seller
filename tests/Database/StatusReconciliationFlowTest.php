<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationPlanChecklist;
use Tecteb\Marketplace\Modules\Migration\Application\OwnershipTransferPlan;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileStorefrontStatus;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\HandoverPage;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Migration\Presentation\MigrationWizardText;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontStatusReconciliation as Rule;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0032ProductMirroredStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogueReader;
use TmcWpStubs\State;

/**
 * `draft: 184` — the state shop 102 is in, and the repair that ends it.
 *
 * ⛔ **Every test here starts from the state the owner measured**, built the
 * way the site arrived at it rather than by writing the end result:
 *
 *  1. a proper intake, which records the shop's status in BOTH places;
 *  2. the status column forced back to `draft` while the STAMP IS LEFT
 *     ALONE — which is precisely what a pre-`alpha.92` intake plus
 *     `M0038ObservedMirroredStatusBackfill` produces. The old intake wrote
 *     `ProductStatus::Draft` unconditionally and dropped the stamp; the
 *     migration then filled the stamp from `wp_posts.post_status` and
 *     deliberately left the status column alone.
 *
 * So the fixture is not «a draft row»: it is a row that says `draft` beside a
 * stamp that says `publish` beside a product that IS published, and no path in
 * the plugin brought the three together.
 */
final class StatusReconciliationFlowTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const VENDOR = 102;

    /** Published in the shop — shop 102's own shape. */
    private const WC_PUBLISHED = 9319;

    /** A second published one, so a batch has more than a single row. */
    private const WC_SECOND = 9320;

    /** `draft` in the shop, so the repair has a row to move the OTHER way. */
    private const WC_DRAFT = 9321;

    /** `private` in the shop — a status this marketplace cannot express. */
    private const WC_PRIVATE = 9322;

    private WpDatabase $db;
    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private WpOptionStore $options;
    private FakeCatalogueReader $reader;

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
        $this->vendors->upsertProfile(self::VENDOR, 'tecteb', true, false);

        $this->reader = new FakeCatalogueReader();
        foreach ([
            self::WC_PUBLISHED => 'publish',
            self::WC_SECOND => 'publish',
            self::WC_DRAFT => 'draft',
            self::WC_PRIVATE => 'private',
        ] as $wcId => $status) {
            $this->reader->add(
                $wcId,
                [
                    'title' => 'کالای ' . $wcId,
                    'regular_price' => '450000',
                    'sku' => 'WC-' . $wcId,
                    'stock_quantity' => 7,
                    'status' => $status,
                ],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
        }
    }

    protected function tearDown(): void
    {
        $this->options->delete(ImportExistingProducts::RUNS_OPTION);
        parent::tearDown();
    }

    // ================================================ the defect, reproduced

    /**
     * The number on the owner's screen, from the query that prints it.
     *
     * `countsByStatus()` reads OUR column, so a shop whose products are all
     * live reports every one of them as a draft.
     */
    public function testTheShopReportsDraftsForProductsThatArePublished(): void
    {
        $this->asShop102();

        $counts = $this->products->countsByStatus(self::VENDOR);

        self::assertSame(3, $counts[ProductStatus::Draft->value] ?? 0, 'three rows say draft');
        self::assertArrayNotHasKey(ProductStatus::Published->value, $counts, 'and none says published');
        // And the shop disagrees about two of them.
        self::assertSame('publish', (string) $this->reader->detail(self::WC_PUBLISHED)['status']);
        self::assertSame('publish', (string) $this->reader->detail(self::WC_SECOND)['status']);
    }

    /**
     * ⛔ The half that is not cosmetic: `Draft` is vendor-editable, so a
     * sensitive change to a LIVE product takes `saveInPlace()` and never
     * reaches the review queue.
     */
    public function testWhileTheRowSaysDraftALiveProductIsEditableWithoutReview(): void
    {
        $ids = $this->asShop102();
        $product = $this->products->find($ids[self::WC_PUBLISHED]);

        self::assertNotNull($product);
        self::assertTrue(
            $product->status->isEditableByVendor(),
            'this is the bypass: the row claims to be a draft'
        );

        $this->repairAll();

        $after = $this->products->find($ids[self::WC_PUBLISHED]);
        self::assertNotNull($after);
        self::assertFalse(
            $after->status->isEditableByVendor(),
            'and after the repair a sensitive change must go through review'
        );
    }

    // ============================================================ the repair

    public function testThePreviewNamesEveryRowAndItsVerdict(): void
    {
        $ids = $this->asShop102();

        $plan = $this->service()->plan(self::VENDOR);

        self::assertSame(3, $plan['scanned']);
        self::assertTrue($plan['finished']);
        self::assertSame(2, $plan['counts'][Rule::REPAIR] ?? 0, 'the two published ones');
        self::assertSame(1, $plan['counts'][Rule::AGREED] ?? 0, 'the draft one already agrees');
        self::assertSame(
            [$ids[self::WC_PUBLISHED], $ids[self::WC_SECOND]],
            $plan['repairable']
        );
        $byId = [];
        foreach ($plan['rows'] as $row) {
            $byId[(int) $row['product_id']] = $row;
        }
        self::assertSame('publish', $byId[$ids[self::WC_PUBLISHED]]['shop_status']);
        self::assertSame(ProductStatus::Draft->value, $byId[$ids[self::WC_PUBLISHED]]['row_status']);
        self::assertSame(ProductStatus::Published->value, $byId[$ids[self::WC_PUBLISHED]]['target']);
    }

    /** The preview writes nothing — measured, not asserted from the source. */
    public function testThePreviewChangesNoRow(): void
    {
        $this->asShop102();
        $before = $this->allRows();

        $this->service()->plan(self::VENDOR);

        self::assertSame($before, $this->allRows());
    }

    public function testTheRepairBringsTheColumnToWhatTheShopSays(): void
    {
        $ids = $this->asShop102();

        $result = $this->repairAll();

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame('status_reconciled', $result['code']);
        self::assertSame(2, count($result['repaired']));
        $counts = $this->products->countsByStatus(self::VENDOR);
        self::assertSame(2, $counts[ProductStatus::Published->value] ?? 0);
        self::assertSame(1, $counts[ProductStatus::Draft->value] ?? 0, 'the shop draft stays a draft');
        self::assertSame(
            ProductStatus::Published,
            $this->products->find($ids[self::WC_PUBLISHED])?->status
        );
    }

    /** Running it again is a no-op, because nothing is left to repair. */
    public function testItIsIdempotent(): void
    {
        $this->asShop102();
        $this->repairAll();
        $after = $this->allRows();

        $plan = $this->service()->plan(self::VENDOR);
        self::assertSame([], $plan['repairable']);
        self::assertSame(3, $plan['counts'][Rule::AGREED] ?? 0);

        $second = $this->service()->apply(self::VENDOR, 0, [], $plan['digest']);
        self::assertFalse($second['ok']);
        self::assertSame('nothing_selected', $second['code']);
        self::assertSame($after, $this->allRows(), 'and not one column moved');
    }

    /**
     * ⛔ The rows the staged transfer already moved are the ones that need
     * this, so ownership must NOT be the scope.
     */
    public function testItReachesRowsTheTransferAlreadyMovedToTheMarketplace(): void
    {
        $ids = $this->asShop102();
        foreach ($ids as $productId) {
            self::assertTrue($this->products->setLinkOwnership($productId, LinkOwnership::Marketplace));
        }

        $result = $this->repairAll();

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame(
            ProductStatus::Published,
            $this->products->find($ids[self::WC_PUBLISHED])?->status
        );
        // And the ownership is exactly where the transfer left it.
        self::assertSame(
            LinkOwnership::Marketplace,
            $this->products->linkOwnership($ids[self::WC_PUBLISHED])
        );
    }

    // ===================================================== what it leaves be

    /**
     * The owner's §6, measured: thirteen things that must survive. The digest
     * covers the whole row bar the status, the counter and the clock, so a
     * broadened `SET` fails here whatever it reached for.
     */
    public function testNothingButTheStatusChanges(): void
    {
        $ids = $this->asShop102();
        $before = $this->rowDigests();

        $this->repairAll();

        $after = $this->rowDigests();
        foreach ($before as $productId => $digest) {
            self::assertSame($digest, $after[$productId], 'row ' . $productId . ' kept every other column');
        }
        // Named, too, because a digest that silently stopped covering a
        // column would pass: the five the owner lists first.
        $product = $this->products->find($ids[self::WC_PUBLISHED]);
        self::assertNotNull($product);
        self::assertSame(450000, $product->details->priceMinor);
        self::assertSame(7, $product->details->stock);
        self::assertSame('WC-' . self::WC_PUBLISHED, $product->details->sku);
        self::assertSame(self::WC_PUBLISHED, $product->wcProductId);
        self::assertSame('publish', $product->mirroredStatus, 'the stamp is NOT cleared');
    }

    public function testTheStampSurvivesSoTheCatalogueVisibilityStaysHeld(): void
    {
        $ids = $this->asShop102();

        $this->repairAll();

        foreach ($ids as $wcId => $productId) {
            // Each stamp records ITS OWN product's status — `publish` for the
            // two live ones and `draft` for the third. The point is that the
            // repair did not clear or rewrite any of them.
            self::assertSame(
                (string) $this->reader->detail($wcId)['status'],
                $this->stampOf($productId),
                'row ' . $productId . ' kept its stamp'
            );
            self::assertTrue($this->products->find($productId)?->storefrontStatusIsHeld());
        }
    }

    public function testARowWithNoStampIsNotEvenOffered(): void
    {
        $ids = $this->asShop102();
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products`
                SET `' . M0032ProductMirroredStatus::MIRRORED . '` = NULL WHERE id = %d',
            $ids[self::WC_PUBLISHED]
        ));

        $plan = $this->service()->plan(self::VENDOR);

        self::assertSame([$ids[self::WC_SECOND]], $plan['repairable']);
        self::assertSame(2, $plan['scanned'], 'the unstamped row is not even read');
        self::assertSame(
            ProductStatus::Draft,
            $this->products->find($ids[self::WC_PUBLISHED])?->status
        );
    }

    public function testADecisionInProgressIsReportedAndLeftAlone(): void
    {
        $ids = $this->asShop102();
        $this->setStatus($ids[self::WC_PUBLISHED], ProductStatus::Submitted);

        $plan = $this->service()->plan(self::VENDOR);

        self::assertSame([$ids[self::WC_SECOND]], $plan['repairable']);
        self::assertSame(1, $plan['counts'][Rule::IN_FLIGHT] ?? 0);
        $this->repairAll();
        self::assertSame(
            ProductStatus::Submitted,
            $this->products->find($ids[self::WC_PUBLISHED])?->status,
            'the submission is not overwritten'
        );
    }

    public function testAStatusWeCannotExpressIsDeclinedByName(): void
    {
        $ids = $this->asShop102();
        // ⛔ The intake REFUSES a `private` product by name, so this state
        // cannot be built by importing one — which is itself the measurement
        // that the row can only arrive here by the SHOP moving afterwards.
        // That is exactly how a real site reaches it.
        $this->reader->details[self::WC_PUBLISHED]['status'] = 'private';

        $plan = $this->service()->plan(self::VENDOR);

        self::assertNotContains($ids[self::WC_PUBLISHED], $plan['repairable']);
        self::assertSame(1, $plan['counts'][Rule::UNREADABLE] ?? 0);
        self::assertSame([$ids[self::WC_SECOND]], $plan['repairable']);
    }

    public function testAProductThatCannotBeReadIsNotAgreement(): void
    {
        $ids = $this->asShop102();
        unset($this->reader->details[self::WC_PUBLISHED]);

        $plan = $this->service()->plan(self::VENDOR);

        self::assertSame([$ids[self::WC_SECOND]], $plan['repairable']);
        self::assertSame(1, $plan['counts'][Rule::UNREADABLE] ?? 0);
    }

    // ================================================== the guards on the press

    public function testAListThatMovedSinceTheRenderIsRefused(): void
    {
        $this->asShop102();
        $plan = $this->service()->plan(self::VENDOR);

        $result = $this->service()->apply(self::VENDOR, 0, $plan['repairable'], 'not-the-digest');

        self::assertFalse($result['ok']);
        self::assertSame('plan_moved', $result['code']);
        self::assertSame(3, $this->products->countsByStatus(self::VENDOR)[ProductStatus::Draft->value] ?? 0);
    }

    public function testASubsetIsRefusedRatherThanFiltered(): void
    {
        $ids = $this->asShop102();
        $plan = $this->service()->plan(self::VENDOR);

        $result = $this->service()->apply(
            self::VENDOR,
            0,
            [$ids[self::WC_PUBLISHED]],
            $plan['digest']
        );

        self::assertFalse($result['ok']);
        self::assertSame('selection_not_offered', $result['code']);
        self::assertSame(3, $this->products->countsByStatus(self::VENDOR)[ProductStatus::Draft->value] ?? 0);
    }

    /**
     * ⛔ The lost race that matters: a manager decides the status between the
     * preview and the press. `updateStatus()` clears the stamp, so the
     * statement's guard stops the repair writing over that decision.
     */
    public function testAManagersDecisionTakenInBetweenWins(): void
    {
        $ids = $this->asShop102();
        $plan = $this->service()->plan(self::VENDOR);
        // The decision: the manager suspends it through the panel.
        self::assertTrue($this->products->updateStatus($ids[self::WC_PUBLISHED], ProductStatus::Suspended));
        self::assertNull($this->stampOf($ids[self::WC_PUBLISHED]), 'the decision cleared the stamp');

        $result = $this->service()->apply(self::VENDOR, 0, $plan['repairable'], $plan['digest']);

        // ⛔ Measured: the RE-PLAN catches it before the statement does. The
        // cleared stamp drops the row out of the refreshable list, so the
        // digest no longer matches and the whole batch is refused rather than
        // part of it carried out (`alpha.91`). The statement's own guard is
        // the second line of defence and is measured directly in
        // `testTheStatementRefusesAStampThatMovedUnderIt`.
        self::assertFalse($result['ok']);
        self::assertSame('plan_moved', $result['code']);
        self::assertSame(
            ProductStatus::Suspended,
            $this->products->find($ids[self::WC_PUBLISHED])?->status,
            'the decision stands'
        );
        self::assertSame(
            ProductStatus::Draft,
            $this->products->find($ids[self::WC_SECOND])?->status,
            'and nothing else moved either'
        );
    }

    /**
     * The statement's guard, exercised directly — because the service's
     * re-plan refuses first and would otherwise leave this untested.
     *
     * Three calls, one for each half of the `WHERE`: a stamp that moved, a
     * status that moved, and the control that works.
     */
    public function testTheStatementRefusesAStampThatMovedUnderIt(): void
    {
        $ids = $this->asShop102();
        $id = $ids[self::WC_PUBLISHED];

        // The stamp the caller believes in is no longer the row's.
        self::assertFalse($this->products->reconcileStatusToStorefront(
            $id,
            ProductStatus::Published,
            ProductStatus::Draft,
            'draft'
        ), 'a stale stamp must not write');
        self::assertSame(ProductStatus::Draft, $this->products->find($id)?->status);

        // The status the caller believes in is no longer the row's.
        self::assertFalse($this->products->reconcileStatusToStorefront(
            $id,
            ProductStatus::Published,
            ProductStatus::Submitted,
            'publish'
        ), 'a stale status must not write');
        self::assertSame(ProductStatus::Draft, $this->products->find($id)?->status);

        // A blank expectation can never match, so it is refused rather than
        // sent — and it must never be read as «this row has no stamp».
        self::assertFalse($this->products->reconcileStatusToStorefront(
            $id,
            ProductStatus::Published,
            ProductStatus::Draft,
            ''
        ), 'a blank stamp expectation must not write');

        // The control: both guards satisfied.
        self::assertTrue($this->products->reconcileStatusToStorefront(
            $id,
            ProductStatus::Published,
            ProductStatus::Draft,
            'publish'
        ));
        self::assertSame(ProductStatus::Published, $this->products->find($id)?->status);
    }

    public function testAStorageFailureStopsTheBatchAndNamesTheRow(): void
    {
        $ids = $this->asShop102();
        $plan = $this->service()->plan(self::VENDOR);
        $gateway = new FailingDatabase($this->db);
        $gateway->failWhen(['UPDATE', 'tmc_products', 'status = %s']);

        $result = $this->service($gateway)->apply(self::VENDOR, 0, $plan['repairable'], $plan['digest']);

        self::assertFalse($result['ok']);
        self::assertSame('stopped', $result['code']);
        self::assertSame('status_not_stored', $result['reason']);
        self::assertSame($ids[self::WC_PUBLISHED], $result['stopped_at']);
        self::assertSame(3, $this->products->countsByStatus(self::VENDOR)[ProductStatus::Draft->value] ?? 0);
    }

    public function testACallerWithoutThePermissionReadsNothingAndIsNotToldTheWalkIsOver(): void
    {
        $this->asShop102();

        $plan = $this->service(null, [])->plan(self::VENDOR);

        self::assertSame([], $plan['rows']);
        self::assertSame([], $plan['repairable']);
        self::assertFalse($plan['finished'], 'a refusal is not a claim about the catalogue');
        $result = $this->service(null, [])->apply(self::VENDOR, 0, [1], $plan['digest']);
        self::assertFalse($result['ok']);
        self::assertSame('forbidden', $result['code']);
    }

    // =============================================================== the trail

    public function testTheTrailCarriesEveryRowsBeforeAndAfter(): void
    {
        $ids = $this->asShop102();

        $this->repairAll();

        $payloads = $this->auditPayloads(AuditEventCatalog::STATUS_RECONCILED);
        self::assertCount(1, $payloads);
        self::assertSame(self::MANAGER, $this->auditActor(AuditEventCatalog::STATUS_RECONCILED), 'the trail says who');
        self::assertSame(2, (int) $payloads[0]['repaired']);
        self::assertSame(2, (int) $payloads[0]['requested']);
        self::assertSame(0, (int) $payloads[0]['stopped_at']);
        self::assertStringContainsString(
            $ids[self::WC_PUBLISHED] . ':draft→published',
            (string) $payloads[0]['rows']
        );
    }

    public function testTheTrailIsWrittenOnTheStoppedPathToo(): void
    {
        $ids = $this->asShop102();
        $plan = $this->service()->plan(self::VENDOR);
        // The SECOND write is refused, so the first row really moved and the
        // run really stopped half way — the state a trail that recorded only
        // complete runs would have nothing to say about.
        $gateway = new FailingDatabase($this->db);
        $gateway->failWhen(['UPDATE', 'tmc_products', 'status = %s'], 2);

        $this->service($gateway)->apply(self::VENDOR, 0, $plan['repairable'], $plan['digest']);

        $payloads = $this->auditPayloads(AuditEventCatalog::STATUS_RECONCILED);
        self::assertCount(1, $payloads);
        self::assertSame($ids[self::WC_SECOND], (int) $payloads[0]['stopped_at']);
        self::assertSame(1, (int) $payloads[0]['repaired'], 'the first one did move');
        self::assertSame('status_not_stored', (string) $payloads[0]['reason']);
        self::assertSame(
            ProductStatus::Published,
            $this->products->find($ids[self::WC_PUBLISHED])?->status,
            'and it stayed moved'
        );
    }

    // ========================================================== the admin surface

    /**
     * ⛔ The card RENDERED, from the real page and the real container.
     *
     * «تابعی که فراخوان ندارد قابلیت نیست» (`alpha.80`), and a page rendered
     * against an empty table says nothing about rendering a row
     * (`alpha.21`) — the two together are why this test seeds first and
     * renders afterwards rather than asserting the method exists.
     */
    public function testTheMigrationPageDrawsTheRepairCardWithItsRows(): void
    {
        $ids = $this->asShop102();

        $html = $this->renderMigrationPage();

        self::assertStringContainsString('اصلاح وضعیت انتشار در بازارگاه', $html);
        self::assertStringContainsString('value="apply_status_repair"', $html);
        self::assertStringContainsString('name="status_fix[]"', $html);
        self::assertStringContainsString('name="status_digest"', $html);
        // The row the manager has to be able to read: our id, and both statuses.
        self::assertStringContainsString(
            'value="' . $ids[self::WC_PUBLISHED] . '"',
            $html,
            'the offered id travels in the form'
        );
        self::assertStringContainsString('پیش‌نویس', $html);
        self::assertStringContainsString('منتشرشده', $html);
    }

    /**
     * And it is not drawn when there is nothing to repair, so a healthy site
     * never sees a migration-era control it has no use for.
     */
    public function testTheCardIsAbsentOnceEverythingAgrees(): void
    {
        $this->asShop102();
        $this->repairAll();

        $html = $this->renderMigrationPage();

        self::assertStringNotContainsString('اصلاح وضعیت انتشار در بازارگاه', $html);
        self::assertStringNotContainsString('value="apply_status_repair"', $html);
        // And no pager either: a finished scan has no page after it
        // (`alpha.85` — a link that goes nowhere is not progress).
        self::assertStringNotContainsString('ادامهٔ اصلاح وضعیت از اینجا', $html);
    }

    /**
     * WHAT THIS PROVES: the card can reach its own next page on the shape
     * staging reports — a shop whose transfer is ALREADY finished.
     *
     * ⛔ This is the case that makes the card's own cursor necessary. The
     * pager beside it is built from the OWNERSHIP plan, which walks
     * `observedForVendorAfter()`; once every row is `marketplace` that plan
     * returns nothing, so its `finished` is true, its `cursor` is nought and
     * it prints NO link. The stamp, meanwhile, survives the transfer — so
     * this card still has rows after this page, and without a cursor of its
     * own they are unreachable. Measured before the fix: the card drew
     * twenty-five rows and not one way forward.
     */
    public function testTheCardPagesItselfWhenTheTransferIsAlreadyFinished(): void
    {
        $ids = $this->asShop102();
        // Fifty-one stamped rows, so one page's scan cannot see them all…
        $ids += $this->seedStampedRows(48);
        // …and every row already transferred, which is what shop 102 is.
        foreach ($ids as $productId) {
            self::assertTrue($this->products->setLinkOwnership($productId, LinkOwnership::Marketplace));
        }

        $html = $this->renderMigrationPage();

        // The ownership pager is silent — this is the premise, asserted and
        // not assumed, because the whole point is that it cannot help here.
        self::assertStringNotContainsString('ادامه از اینجا', $html);
        // And the card offers its own way forward, carrying its own cursor.
        self::assertStringContainsString('ادامهٔ اصلاح وضعیت از اینجا', $html);
        self::assertStringContainsString('tv_after=', $html);
    }

    /**
     * WHAT THIS PROVES: «nothing to repair on this page» is not «nothing to
     * repair», and the page says which of the two it is.
     *
     * The card used to return an empty string the moment a page had no
     * repairable row — so a catalogue whose first page happened to agree hid
     * every later page behind no control at all.
     */
    public function testAPageWithNothingToRepairStillOffersTheNextOne(): void
    {
        // Fifty-one stamped rows that all AGREE with the shop: nothing to
        // repair here, and a scan that is not finished.
        $ids = $this->seedStampedRows(51, false);
        foreach ($ids as $productId) {
            self::assertTrue($this->products->setLinkOwnership($productId, LinkOwnership::Marketplace));
        }

        $html = $this->renderMigrationPage();

        self::assertStringNotContainsString('value="apply_status_repair"', $html, 'no button, because no row');
        self::assertStringContainsString('در این صفحه ردیفی برای اصلاح نیست', $html);
        self::assertStringContainsString('ادامهٔ اصلاح وضعیت از اینجا', $html);
    }

    /**
     * The wizard card, RENDERED from the real handover page — because a
     * checklist nothing calls is not a capability (`alpha.80`), and a page
     * rendered against an empty fixture says nothing about rendering a row
     * (`alpha.21`).
     */
    public function testTheHandoverPageDrawsTheTwelveStepsWithTheirStatuses(): void
    {
        $this->asShop102();

        $html = $this->renderHandoverPage();

        self::assertStringContainsString('مسیر مهاجرت سایت', $html);
        // Every step's name, from the text class rather than typed here.
        foreach (MigrationPlanChecklist::ORDER as $key) {
            self::assertStringContainsString(
                MigrationWizardText::step($key),
                $html,
                'step ' . $key . ' is missing from the table'
            );
        }
        // And the sync step reports the repair this round added: 184 rows of
        // drift do not hide the status rows, they are added to them.
        self::assertStringContainsString(
            MigrationWizardText::status(MigrationPlanChecklist::TODO),
            $html
        );
        self::assertStringContainsString('گام از', $html, 'the tally sentence');
    }

    /**
     * ⛔ And it says «آماده» for nothing it did not measure. The stub has no
     * rollback gate verdict and no Dokan, so at least one step must be
     * «اندازه‌گیری‌نشده» and the readiness notice must be the withholding one.
     */
    public function testTheWizardWithholdsReadinessWhenAStepCouldNotBeMeasured(): void
    {
        $this->asShop102();

        $html = $this->renderHandoverPage();

        self::assertStringContainsString(
            MigrationWizardText::status(MigrationPlanChecklist::UNKNOWN),
            $html,
            'an unmeasured step must say so'
        );
        self::assertStringContainsString('خوانده نمی‌شود', $html, 'readiness is withheld');
        self::assertStringNotContainsString('هر دوازده گام انجام شده است', $html);
    }

    // ================================================================ fixtures

    /**
     * Shop 102's measured state: three imported rows whose status column says
     * `draft` while the stamp records what the shop really holds.
     *
     * @return array<int,int> WooCommerce id => our row id
     */
    private function asShop102(): array
    {
        $ids = [];
        foreach ([self::WC_PUBLISHED, self::WC_SECOND, self::WC_DRAFT] as $wcId) {
            $ids[$wcId] = $this->importAndDraft($wcId);
        }
        return $ids;
    }

    /**
     * One row, imported properly and then forced back to the pre-`alpha.92`
     * status — the STAMP LEFT ALONE, which is what migration 38 filled.
     */
    /**
     * More stamped rows than one scan can see, through the real intake.
     *
     * @param bool $toDraft force each row to `Draft` so it disagrees with the
     *                      shop; `false` leaves it agreeing, which is the
     *                      «nothing on this page» case.
     * @return array<int,int> wcId => product row id
     */
    private function seedStampedRows(int $count, bool $toDraft = true): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $wcId = 9400 + $i;
            $this->reader->add(
                $wcId,
                [
                    'title' => 'کالای ' . $wcId,
                    'regular_price' => '450000',
                    'sku' => 'WC-' . $wcId,
                    'stock_quantity' => 7,
                    'status' => 'publish',
                ],
                ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']
            );
            $batch = $this->intake()->importBatch('status-bulk-' . $wcId, [$wcId]);
            self::assertTrue($batch['ok'], (string) $batch['code']);
            $product = $this->products->findObservedByWcProduct($wcId);
            self::assertNotNull($product, 'an observed row for ' . $wcId);
            $id = (int) $product->id;
            if ($toDraft) {
                $this->setStatus($id, ProductStatus::Draft);
            }
            self::assertNotNull($this->stampOf($id), 'the stamp must survive the fixture');
            $ids[$wcId] = $id;
        }
        return $ids;
    }

    private function importAndDraft(int $wcId): int
    {
        $batch = $this->intake()->importBatch('status-' . $wcId, [$wcId]);
        self::assertTrue($batch['ok'], (string) $batch['code']);
        $product = $this->products->findObservedByWcProduct($wcId);
        self::assertNotNull($product, 'an observed row for ' . $wcId);
        $id = (int) $product->id;
        $this->setStatus($id, ProductStatus::Draft);
        self::assertNotNull($this->stampOf($id), 'the stamp must survive the fixture');
        return $id;
    }

    private function setStatus(int $productId, ProductStatus $status): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            'UPDATE `' . $this->wpdb->prefix . 'tmc_products` SET status = %s WHERE id = %d',
            $status->value,
            $productId
        ));
        self::assertSame($status, $this->products->find($productId)?->status);
    }

    /** @return array{ok:bool, code:string, repaired:list<int>, stopped_at:int, reason:string, requested:int, changes:list<mixed>} */
    private function repairAll(): array
    {
        $plan = $this->service()->plan(self::VENDOR);
        return $this->service()->apply(self::VENDOR, 0, $plan['repairable'], $plan['digest']);
    }

    /** @param list<string> $capabilities */
    private function service(?FailingDatabase $gateway = null, ?array $capabilities = null): ReconcileStorefrontStatus
    {
        return new ReconcileStorefrontStatus(
            $gateway === null ? $this->products : new DbProductRepository($gateway, new SystemClock()),
            $this->reader,
            new FakeCapabilityChecker(
                self::MANAGER,
                $capabilities ?? [Capabilities::REVIEW_VENDOR]
            ),
            $this->audit()
        );
    }

    private function intake(): ImportExistingProducts
    {
        return new ImportExistingProducts(
            $this->reader,
            new CatalogueInventory($this->reader),
            $this->products,
            $this->vendors,
            $this->options,
            $this->audit(),
            new SystemClock(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            $this->db
        );
    }

    /**
     * The migration page, rendered through the real container with the fake
     * catalogue bound FIRST.
     *
     * The stub environment has no WooCommerce, so `CatalogueReaderInterface`
     * is not bound at all there and every consumer answers «I read nothing».
     * Binding it before the first `get()` is what makes the card reachable —
     * `Container::get()` memoises, so a reader bound afterwards would leave
     * the already-built services holding the absent one (`alpha.79`).
     */
    private function renderMigrationPage(): string
    {
        // WooCommerce declared, because the migration module binds the Dokan
        // and catalogue services only when there is a shop to read — and the
        // page asks the container for them unconditionally.
        $this->bootPlugin(true);
        $this->loginAdmin();
        $container = Bootstrap::container();
        $reader = $this->reader;
        $container->bind(CatalogueReaderInterface::class, static fn (): FakeCatalogueReader => $reader);
        $_GET['page'] = MigrationPage::SLUG;
        $_GET['tv_shop'] = (string) self::VENDOR;
        $caps = State::$currentUserCaps;
        $user = State::$currentUserId;
        try {
            return $this->capture(static function () use ($container): void {
                (new MigrationPage($container))->render();
            });
        } finally {
            // A fixture that spends a guarantee gives it back (`alpha.76`).
            State::$currentUserCaps = $caps;
            State::$currentUserId = $user;
            unset($_GET['page'], $_GET['tv_shop']);
        }
    }

    /** The handover page, through the real container, with the fake catalogue. */
    private function renderHandoverPage(): string
    {
        $this->bootPlugin(true);
        $this->loginAdmin();
        $container = Bootstrap::container();
        $reader = $this->reader;
        $container->bind(CatalogueReaderInterface::class, static fn (): FakeCatalogueReader => $reader);
        $_GET['page'] = HandoverPage::SLUG;
        $_GET['wz_shop'] = (string) self::VENDOR;
        $caps = State::$currentUserCaps;
        $user = State::$currentUserId;
        try {
            return $this->capture(static function () use ($container): void {
                (new HandoverPage($container))->render();
            });
        } finally {
            State::$currentUserCaps = $caps;
            State::$currentUserId = $user;
            unset($_GET['page'], $_GET['wz_shop']);
        }
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

    /**
     * Every column of every row of this shop, so «nothing else moved» is one
     * comparison rather than a list somebody has to maintain.
     *
     * @return array<int,string>
     */
    private function rowDigests(): array
    {
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT * FROM `' . $this->wpdb->prefix . 'tmc_products`
              WHERE vendor_user_id = %d ORDER BY id ASC',
            self::VENDOR
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            // The three this repair is ALLOWED to move.
            unset($row['status'], $row['row_version'], $row['updated_at']);
            $out[(int) $row['id']] = (string) json_encode($row);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function allRows(): array
    {
        $rows = (array) $this->wpdb->get_results(
            'SELECT * FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC',
            ARRAY_A
        );
        return array_map(static fn ($row): array => (array) $row, $rows);
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock());
    }

    /** The actor on the most recent row of this event type. */
    private function auditActor(string $eventType): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            'SELECT actor_id FROM `' . $this->wpdb->prefix . 'tmc_audit_events`
              WHERE event_type = %s ORDER BY id DESC LIMIT 1',
            $eventType
        ));
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
            $decoded = json_decode((string) ((array) $row)['payload'], true);
            $out[] = is_array($decoded) ? $decoded : [];
        }
        return $out;
    }
}
