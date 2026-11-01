<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Migration\Application\ApplyCategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbOrderHistoryRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Product\Application\AcceptDefaultCategory;
use Tecteb\Marketplace\Modules\Product\Application\CategoryLinkPlan;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbDefaultCategoryAcceptanceRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0036DefaultCategoryAcceptance;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WpProductCategoryLinkReader;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;
use Tecteb\Marketplace\Tests\Support\FakeProductCategoryDirectory;

/**
 * WHAT THIS PROVES: a manager can settle a product that carries only
 * WooCommerce's default term — explicitly, auditably and revocably — and NOT
 * ONE `product_cat` relationship, `category_key` or link row moves because of
 * it. And the acceptance stops counting by itself the moment the term or the
 * shop's setting changes.
 *
 * **Every test starts from the post-import state**, never from hand-written
 * rows: `alpha.79` shipped a defect because a test measured the state BEFORE
 * the operation that writes one side of the comparison.
 */
final class DefaultCategoryAcceptanceTest extends DatabaseTestCase
{
    private const SELLER = 4501;

    private const MANAGER = 4502;

    private const SOURCE = '%d8%aa%d8%ac%d9%87%db%8c%d8%b2%d8%a7%d8%aa';

    /** The shop's `default_product_cat` — named like the owner's site, not «Uncategorized». */
    private const GENERAL = 915;

    private const INJECTION = 911;

    /** Only carries the default term: the product this whole round is about. */
    private const WC_DEFAULT_ONLY = 8501;

    /** Carries one real category: the control, and it must never be acceptable. */
    private const WC_DEFINITE = 8502;

    private WpDatabase $database;

    private DbProductRepository $products;

    private DbDefaultCategoryAcceptanceRepository $acceptances;

    private FakeDokanReader $dokan;

    private FakeProductCategoryDirectory $directory;

    private CategoryLinkPlan $plan;

    private AcceptDefaultCategory $service;

    private AuditLogger $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);
        $this->database = $db;
        $this->resetTermTables();
        update_option('default_product_cat', self::GENERAL);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $options->delete(CategoryMap::SETTING);

        $this->products = new DbProductRepository($db, $clock);
        $this->acceptances = new DbDefaultCategoryAcceptanceRepository($db, $clock);

        $dokan = $this->dokan = new FakeDokanReader();
        $dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'تجهیزات تک‌طب', 'email' => 's@example.test', 'enabled' => true],
        ];
        $dokan->productRows = [
            $this->dokanProduct(self::WC_DEFAULT_ONLY, 'دستگاه پزشکی جنرال'),
            $this->dokanProduct(self::WC_DEFINITE, 'سرنگ'),
        ];

        $this->directory = new FakeProductCategoryDirectory();
        $this->directory->terms = [
            self::GENERAL => ['name' => 'پزشکی جنرال', 'path' => 'پزشکی جنرال'],
            self::INJECTION => ['name' => 'تزریق', 'path' => 'تجهیزات پزشکی › تزریق'],
        ];

        $this->auditor = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $migration = new ImportFromDokan(
            $dokan,
            new DbVendorRepository($db, $clock),
            $this->products,
            $options,
            $this->auditor,
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            new DbOrderHistoryRepository($db, $clock),
            new DbShopRecordRepository($db, $clock),
            new DbLedgerRepository($db, $clock),
            new CategoryMap($options)
        );

        // ⛔ THE RELATIONSHIPS ARE ATTACHED BEFORE THE IMPORT, because that is
        // the order on the real site: WooCommerce already holds them and the
        // intake reads them. Attaching afterwards would test a state the
        // intake never saw.
        $this->attach(self::WC_DEFAULT_ONLY, [self::GENERAL]);
        $this->attach(self::WC_DEFINITE, [self::INJECTION]);

        $imported = $migration->import($migration->plan());
        self::assertTrue($imported->ok, 'the fixture needs a real import to start from');

        $reader = new WpProductCategoryLinkReader($db, $this->directory);
        $this->plan = new CategoryLinkPlan($this->products, $reader, $this->acceptances);
        $this->service = new AcceptDefaultCategory($this->products, $reader, $this->acceptances, $this->auditor);
    }

    public function testMigrationThirtySixCreatesAVerifiableTableAndSeedsNothing(): void
    {
        $step = new M0036DefaultCategoryAcceptance();
        self::assertSame(36, $step->version());
        self::assertTrue($step->verify($this->database), 'migration 36 must verify its own shape');
        self::assertTrue(M0036DefaultCategoryAcceptance::carriedBy($this->database));
        // ⛔ NO BACKFILL. A seeding statement here would be the automatic
        // acceptance the owner's instruction forbids.
        self::assertSame(
            0,
            (int) $this->database->getVar(
                'SELECT COUNT(*) FROM `' . M0036DefaultCategoryAcceptance::table($this->database) . '`'
            ),
            'migration 36 must create no acceptance of its own'
        );
        // And running it twice is a no-op rather than an error.
        $step->up($this->database);
        self::assertTrue($step->verify($this->database));
    }

    /**
     * A table of that NAME and the wrong SHAPE answers «no», not «yes».
     *
     * ⛔ THE MIGRATION CHECK FOUND THIS, not a reading of the code. The first
     * `carriedBy()` asked only «is there a table of this name», so a
     * mis-shaped one of the same name answered «yes» — and the gate predicate
     * then wrote a clause naming `live_product_id` and `term_id`, a statement
     * that fails, so the count came back `-1` and the screen said «could not
     * count» about a question it could have answered.
     *
     * Asking for the two columns the clause names gives `false`, the clause
     * is omitted, and every imported row keeps counting. That direction is
     * conservative because omitting the clause can only make MORE rows wait,
     * never fewer; and the mis-shaped table is not swallowed, because
     * `verify()` refuses it and the structure does not move.
     */
    public function testAMisShapedTableOfThatNameDoesNotBreakTheCount(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        $accepted = $this->products->countImportedWithoutCategory();
        $table = M0036DefaultCategoryAcceptance::table($this->database);

        $this->database->execute('DROP TABLE IF EXISTS `' . $table . '`');
        $this->database->execute('CREATE TABLE `' . $table . '` (product_id BIGINT UNSIGNED NOT NULL)');

        // ⛔ THE BEHAVIOUR FIRST, because that is the claim: a structural
        // assertion placed above it aborts the test under the mutation and
        // the number nobody could read goes unmeasured (`alpha.82`).
        //
        // A FRESH repository, because the shape answer is memoised per
        // instance and the shape changed between requests, not inside one
        // (`alpha.67`).
        $fresh = $this->freshProducts();
        $count = $fresh->countImportedWithoutCategory();
        self::assertNotSame(-1, $count, 'the count is still answerable');
        self::assertSame(
            $accepted + 1,
            $count,
            'the clause is omitted, so the accepted row counts again — more waiting, never fewer'
        );
        self::assertContains($productId, $this->waitingIds($fresh));

        self::assertFalse(
            M0036DefaultCategoryAcceptance::carriedBy($this->database),
            'a table without the two columns the clause names does not carry the decision'
        );
        $step = new M0036DefaultCategoryAcceptance();
        self::assertFalse($step->verify($this->database), 'and verify() refuses it, which is where it is reported');
    }

    public function testADefaultOnlyProductIsWaitingUntilItIsAccepted(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertContains($productId, $this->waitingIds(), 'the default-only row starts out waiting');
        self::assertSame(
            CategoryLinkPlan::DEFAULT_TERM,
            $this->reasonFor($productId),
            'and the preview says which kind of undecided it is'
        );

        $result = $this->service->accept($productId, self::GENERAL, self::MANAGER);
        self::assertTrue($result['ok'], 'accept must succeed: ' . $result['code']);
        self::assertSame('default_category_accepted', $result['code']);
        self::assertSame(self::GENERAL, $result['term_id']);

        self::assertNotContains($productId, $this->waitingIds(), 'an accepted row is no longer waiting');
        self::assertSame(
            CategoryLinkPlan::DEFAULT_TERM_ACCEPTED,
            $this->reasonFor($productId),
            'and the preview says a manager decided'
        );
    }

    public function testTheCounterTheListAndTheGateMoveTogether(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        // ⛔ The INVARIANT, not a fixed number: whatever today's data is, the
        // list is never longer than the figure beside it (`alpha.87`).
        self::assertCount($this->products->countImportedWithoutCategory(), $this->waitingIds());
        $before = $this->products->countImportedWithoutCategory();

        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        self::assertSame($before - 1, $this->products->countImportedWithoutCategory());
        self::assertCount($this->products->countImportedWithoutCategory(), $this->waitingIds());
        // The per-shop count is the same question narrowed, so it moves too.
        self::assertSame(
            $this->products->countImportedWithoutCategory(),
            $this->products->countImportedWithoutCategory(self::SELLER),
            'this fixture has one shop, so the two counts are the same number'
        );
    }

    public function testAcceptingWritesNoCategoryKeyNoLinkRowAndNoRelationship(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        $relationshipsBefore = $this->relationshipSnapshot();
        $keysBefore = $this->categoryKeySnapshot();
        $linksBefore = $this->linkRowSnapshot();

        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        // ⛔ THE WHOLE POINT OF OPTION ج, measured on three tables rather than
        // promised in a docblock: «`category_key` و مجموعه پیوندهای دسته صرفاً
        // برای بستن دروازه دست‌کاری نشوند».
        self::assertSame($relationshipsBefore, $this->relationshipSnapshot(), 'no product_cat relationship moved');
        self::assertSame($keysBefore, $this->categoryKeySnapshot(), 'no category_key moved');
        self::assertSame($linksBefore, $this->linkRowSnapshot(), 'no link row was written');
        $row = $this->products->find($productId);
        self::assertNotNull($row);
        self::assertSame('', $row->details->categoryKey);
        self::assertSame([], $row->details->categoryIds);
    }

    public function testChangingTheShopsDefaultTermStopsTheAcceptanceCounting(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        self::assertNotContains($productId, $this->waitingIds());

        // The manager sets a different default in WooCommerce. The accepted
        // row is untouched — nothing swept it — and it stops counting anyway.
        update_option('default_product_cat', 777);
        // ⛔ THE NEXT REQUEST, which is what a settings change actually is.
        // `WpProductCategoryLinkReader::defaultTermId()` memoises per INSTANCE
        // and the gate's predicate reads the option statically, so inside ONE
        // request the two can only differ if something wrote the option
        // mid-flight — and nothing in `src/` writes it
        // (`OneWithoutCategoryPredicateTest::testNothingWritesTheOption`).
        // Rebuilding both here is the honest simulation; leaving the old
        // reader in place would have the preview answering about a value
        // production would never still be holding.
        $this->products = new DbProductRepository($this->database, new SystemClock());
        $this->acceptances = new DbDefaultCategoryAcceptanceRepository($this->database, new SystemClock());
        $reader = new WpProductCategoryLinkReader($this->database, $this->directory);
        $this->plan = new CategoryLinkPlan($this->products, $reader, $this->acceptances);

        self::assertContains(
            $productId,
            $this->waitingIds(),
            'an acceptance about a term that is no longer the default must not hold'
        );
        // ⛔ AND THE SCREEN AGREES WITH THE COUNTER. The preview decides the
        // same two halves in PHP that the predicate decides in SQL, and a
        // basket that still said «accepted» while the counter said «waiting»
        // would be `alpha.87` again one object over.
        self::assertNotSame(
            CategoryLinkPlan::DEFAULT_TERM_ACCEPTED,
            $this->reasonFor($productId),
            'the preview must not keep calling a stale decision accepted'
        );
        self::assertSame(
            1,
            (int) $this->database->getVar(
                'SELECT COUNT(*) FROM `' . M0036DefaultCategoryAcceptance::table($this->database)
                . '` WHERE live_product_id = %d',
                [$productId]
            ),
            'and the row itself is kept, so the trail still says who decided what'
        );
    }

    /**
     * ⛔ THE ONE STATE WHERE THE PREVIEW'S OWN RULE IS THE ONLY THING ASKED,
     * and the mutation loop is what found it missing.
     *
     * «Falsify by reverting» reported mutation 14 — `$holds = $acceptedTerm >
     * 0`, i.e. a stale decision still called accepted — as SURVIVED. The guard
     * was right; the test was absent (`alpha.82`: a surviving mutation is one
     * of four things and only one of them is a defect). The scenario above
     * does not reach it: once the shop's default moves away from the accepted
     * term, that term becomes a REAL category and the link classifies as
     * `DEFINITE`, so `classify()` never asks about the acceptance at all.
     *
     * The state that does reach it is the default moving AND the product
     * following it: attached = {new default}, so the link is `DEFAULT_ONLY`
     * again, while the stored decision names the OLD term. The gate's SQL
     * already answers «waiting» there; without this test nothing checked that
     * the screen agrees, and a screen saying «accepted» beside a counter
     * saying «waiting» is `alpha.87` one object over.
     */
    public function testTheDefaultMovedAndTheProductFollowedItIsNotStillAccepted(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        // The shop gets a new default, and this product ends up carrying only
        // that one — so it is default-only again, about a different term.
        $this->detachAll(self::WC_DEFAULT_ONLY);
        $this->attach(self::WC_DEFAULT_ONLY, [777]);
        update_option('default_product_cat', 777);
        $this->products = new DbProductRepository($this->database, new SystemClock());
        $this->acceptances = new DbDefaultCategoryAcceptanceRepository($this->database, new SystemClock());
        $reader = new WpProductCategoryLinkReader($this->database, $this->directory);
        $this->plan = new CategoryLinkPlan($this->products, $reader, $this->acceptances);

        self::assertContains(
            $productId,
            $this->waitingIds(),
            'the stored decision names a term this product no longer carries'
        );
        self::assertSame(
            CategoryLinkPlan::DEFAULT_TERM,
            $this->reasonFor($productId),
            'and the preview must ask again rather than honour the old decision'
        );
    }

    public function testAttachingARealCategoryStopsTheAcceptanceCounting(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        self::assertNotContains($productId, $this->waitingIds());

        $this->attach(self::WC_DEFAULT_ONLY, [self::INJECTION]);

        self::assertContains(
            $productId,
            $this->waitingIds(),
            'the product now has a real category, so the old acceptance answers nothing'
        );
    }

    public function testDetachingTheAcceptedTermStopsTheAcceptanceCounting(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        self::assertNotContains($productId, $this->waitingIds());

        $this->detachAll(self::WC_DEFAULT_ONLY);

        self::assertContains(
            $productId,
            $this->waitingIds(),
            'there is no term left to have accepted'
        );
    }

    public function testRevokingPutsTheProductBackAndKeepsTheHistory(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        $revoked = $this->service->revoke($productId, self::MANAGER);
        self::assertTrue($revoked['ok'], $revoked['code']);
        self::assertSame('default_category_revoked', $revoked['code']);
        self::assertSame(self::GENERAL, $revoked['term_id'], 'the revocation names the term it withdrew');

        self::assertContains($productId, $this->waitingIds());
        self::assertSame(CategoryLinkPlan::DEFAULT_TERM, $this->reasonFor($productId));
        // The row stays, with its live key cleared: «قابل لغو» and
        // «ممیزی‌پذیر» out of one table.
        $rows = $this->database->getResults(
            'SELECT live_product_id, term_id, decided_by, revoked_by FROM `'
            . M0036DefaultCategoryAcceptance::table($this->database) . '` WHERE product_id = %d',
            [$productId]
        );
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['live_product_id']);
        self::assertSame(self::GENERAL, (int) $rows[0]['term_id']);
        self::assertSame(self::MANAGER, (int) $rows[0]['decided_by']);
        self::assertSame(self::MANAGER, (int) $rows[0]['revoked_by']);
    }

    public function testAcceptingAgainAfterARevocationWorksAndKeepsBothRows(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        self::assertTrue($this->service->revoke($productId, self::MANAGER)['ok']);
        self::assertTrue(
            $this->service->accept($productId, self::GENERAL, self::MANAGER)['ok'],
            'the live key is free again, so a second decision is possible'
        );
        self::assertSame(
            2,
            (int) $this->database->getVar(
                'SELECT COUNT(*) FROM `' . M0036DefaultCategoryAcceptance::table($this->database)
                . '` WHERE product_id = %d',
                [$productId]
            ),
            'history grows; it is never overwritten'
        );
        self::assertNotContains($productId, $this->waitingIds());
    }

    public function testASecondAcceptanceLosesAtTheIndexAndWritesNothing(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        $again = $this->service->accept($productId, self::GENERAL, self::MANAGER);
        self::assertFalse($again['ok']);
        self::assertSame('already_accepted', $again['code']);
        self::assertSame(
            1,
            (int) $this->database->getVar(
                'SELECT COUNT(*) FROM `' . M0036DefaultCategoryAcceptance::table($this->database)
                . '` WHERE product_id = %d',
                [$productId]
            )
        );
    }

    public function testEveryRefusalHasItsOwnName(): void
    {
        $definite = $this->productIdOf(self::WC_DEFINITE);
        $defaultOnly = $this->productIdOf(self::WC_DEFAULT_ONLY);

        self::assertSame('nothing_selected', $this->service->accept(0, self::GENERAL, self::MANAGER)['code']);
        self::assertSame('not_found', $this->service->accept(999999, self::GENERAL, self::MANAGER)['code']);
        // A product with a real category is not acceptable at all: its own
        // relationship is recordable, which is a different door.
        self::assertSame(
            'not_default_only',
            $this->service->accept($definite, self::GENERAL, self::MANAGER)['code']
        );
        // Consent is about the term that was PRINTED (`alpha.49`).
        self::assertSame(
            'default_term_moved',
            $this->service->accept($defaultOnly, self::INJECTION, self::MANAGER)['code']
        );
        self::assertSame('not_accepted', $this->service->revoke($defaultOnly, self::MANAGER)['code']);
    }

    public function testAProductWhoseCategoryIsAlreadyRecordedIsNotAcceptable(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        $row = $this->products->find($productId);
        self::assertNotNull($row);
        // Somebody mapped a Dokan category onto it, the one existing door
        // that writes `category_key` without touching WooCommerce.
        self::assertSame('', $this->products->setCategoryWhenEmpty($productId, (string) self::INJECTION));

        self::assertSame(
            'category_already_recorded',
            $this->service->accept($productId, self::GENERAL, self::MANAGER)['code'],
            'two answers on one product would leave no screen able to say which the gate read'
        );
    }

    public function testAMarketplaceOwnRowIsOutOfScope(): void
    {
        $made = $this->products->create(
            self::SELLER,
            new ProductDetails(title: 'محصول خودمان'),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $made);
        self::assertSame(
            'not_imported',
            $this->service->accept($made, self::GENERAL, self::MANAGER)['code'],
            'the gate this closes counts imported rows only'
        );
    }

    public function testAFailedWriteIsReportedAsAFailureAndNotAsAnExistingDecision(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        $failing = new FailingDatabase($this->database);
        // ⛔ Named by its needles rather than by a constructor argument — and
        // the first version of this test passed one, which `FailingDatabase`
        // ignores, so NOTHING failed and the acceptance succeeded. A fixture
        // that cannot produce the state under test reports the healthy code
        // as broken, or worse, as fine (`alpha.78`).
        $failing->failWhen(['INSERT INTO', M0036DefaultCategoryAcceptance::TABLE]);
        $repo = new DbDefaultCategoryAcceptanceRepository($failing, new SystemClock());
        $reader = new WpProductCategoryLinkReader($this->database, $this->directory);
        $service = new AcceptDefaultCategory($this->products, $reader, $repo, $this->auditor);

        $result = $service->accept($productId, self::GENERAL, self::MANAGER);
        self::assertFalse($result['ok']);
        // ⛔ `storage_failed`, NOT `already_accepted`: the row is read back
        // rather than inferred from the failed write (`alpha.28`), because
        // telling a manager a decision exists that does not is worse than
        // telling them the write failed.
        self::assertSame('storage_failed', $result['code']);
        self::assertContains($productId, $this->waitingIds(), 'and nothing closed the gate');
    }

    public function testTheMappingScreenNamesTheAcceptanceInsteadOfBlamingThePageCap(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);

        $options = new WpOptionStore();
        // ⛔ WITH the Dokan reader. Without one `plan()` reports
        // `source_readable = false` and `apply()` refuses the whole batch
        // before any product is looked at — a true answer about Dokan being
        // gone, and nothing at all about the refusal under test.
        $mapping = new CategoryMapping(
            new CategoryMap($options),
            $this->products,
            $this->dokan,
            $this->directory
        );
        $applier = new ApplyCategoryMapping(
            $mapping,
            $this->products,
            $this->auditor,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            $this->acceptances
        );
        $result = $applier->apply([$productId => (string) self::INJECTION]);
        self::assertSame(
            'default_category_accepted',
            (string) ($result['outcomes'][$productId] ?? ''),
            'the refusal names the decision, not the page cap'
        );
        self::assertSame(0, (int) $result['written'], 'and nothing was written');
    }

    public function testAnAuditRowNamesTheProductAndTheTerm(): void
    {
        $productId = $this->productIdOf(self::WC_DEFAULT_ONLY);
        self::assertTrue($this->service->accept($productId, self::GENERAL, self::MANAGER)['ok']);
        $rows = $this->database->getResults(
            'SELECT event_type, actor_id, object_id, payload FROM `' . $this->wpdb->prefix
            . 'tmc_audit_events` WHERE event_type = %s ORDER BY id DESC',
            ['product.default_category_accepted']
        );
        self::assertCount(1, $rows);
        self::assertSame(self::MANAGER, (int) $rows[0]['actor_id']);
        self::assertSame((string) $productId, (string) $rows[0]['object_id']);
        self::assertStringContainsString((string) self::GENERAL, (string) $rows[0]['payload']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function dokanProduct(int $wcId, string $title): array
    {
        return [
            'wc_product_id' => $wcId,
            'vendor_user_id' => self::SELLER,
            'title' => $title,
            'sku' => 'DK-' . $wcId,
            'post_status' => 'publish',
            'price_minor' => 450000,
            'price_recorded' => true,
            'price_not_exact' => false,
            'stock' => 3,
            'source_category_key' => self::SOURCE,
            'source_category_label' => 'تجهیزات',
        ];
    }

    private function productIdOf(int $wcProductId): int
    {
        $value = $this->database->getVar(
            'SELECT id FROM `' . $this->wpdb->prefix . 'tmc_products` WHERE wc_product_id = %d',
            [$wcProductId]
        );
        self::assertNotNull($value, 'the import must have made a row for ' . $wcProductId);
        return (int) $value;
    }

    /** @return list<int> */
    private function waitingIds(?ProductRepositoryInterface $repo = null): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            ($repo ?? $this->products)->importedWithoutCategory(500)
        );
    }

    /**
     * A repository built fresh, for a question asked in the NEXT request.
     *
     * The table-shape answer is memoised per instance on purpose (`alpha.67`:
     * a `static` memo covers the whole process and makes a test that changes
     * the shape read a stale answer), so a test about a shape that changed
     * between requests has to ask a new instance.
     */
    private function freshProducts(): ProductRepositoryInterface
    {
        return new DbProductRepository($this->database, new SystemClock());
    }

    private function reasonFor(int $productId): string
    {
        $plan = $this->plan->plan();
        foreach ($plan['apply'] as $row) {
            if ((int) $row['product_id'] === $productId) {
                return CategoryLinkPlan::APPLY;
            }
        }
        foreach ($plan['held'] as $reason => $rows) {
            foreach ($rows as $row) {
                if ((int) $row['product_id'] === $productId) {
                    return (string) $reason;
                }
            }
        }
        return CategoryLinkPlan::SETTLED;
    }

    /** @return list<array<string,mixed>> */
    private function relationshipSnapshot(): array
    {
        return $this->database->getResults(
            'SELECT tr.object_id, tt.term_id FROM `' . $this->wpdb->prefix . 'term_relationships` tr
               INNER JOIN `' . $this->wpdb->prefix . 'term_taxonomy` tt
                       ON tt.term_taxonomy_id = tr.term_taxonomy_id
              ORDER BY tr.object_id ASC, tt.term_id ASC'
        );
    }

    /** @return list<array<string,mixed>> */
    private function categoryKeySnapshot(): array
    {
        return $this->database->getResults(
            'SELECT id, category_key FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        );
    }

    /** @return list<array<string,mixed>> */
    private function linkRowSnapshot(): array
    {
        return $this->database->getResults(
            'SELECT product_id, term_id FROM `' . $this->wpdb->prefix
            . 'tmc_product_categories` ORDER BY product_id ASC, term_id ASC'
        );
    }

    private function resetTermTables(): void
    {
        $pdo = $this->wpdb->pdo();
        foreach (['term_relationships', 'term_taxonomy'] as $suffix) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $this->wpdb->prefix . $suffix . '`');
        }
        $pdo->exec(
            'CREATE TABLE `' . $this->wpdb->prefix . 'term_taxonomy` (
                term_taxonomy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                taxonomy VARCHAR(32) NOT NULL DEFAULT \'\',
                PRIMARY KEY (term_taxonomy_id),
                UNIQUE KEY term_id_taxonomy (term_id, taxonomy)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
        );
        $pdo->exec(
            'CREATE TABLE `' . $this->wpdb->prefix . 'term_relationships` (
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_taxonomy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                term_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (object_id, term_taxonomy_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
        );
    }

    /** @param list<int> $termIds */
    private function attach(int $wcProductId, array $termIds, string $taxonomy = 'product_cat'): void
    {
        $pdo = $this->wpdb->pdo();
        foreach ($termIds as $termId) {
            $find = $pdo->prepare(
                'SELECT term_taxonomy_id FROM `' . $this->wpdb->prefix
                . 'term_taxonomy` WHERE term_id = ? AND taxonomy = ?'
            );
            $find->execute([$termId, $taxonomy]);
            $ttid = $find->fetchColumn();
            if ($ttid === false) {
                $pdo->prepare('INSERT INTO `' . $this->wpdb->prefix
                    . 'term_taxonomy` (term_id, taxonomy) VALUES (?, ?)')->execute([$termId, $taxonomy]);
                $ttid = $pdo->lastInsertId();
            }
            $pdo->prepare('INSERT IGNORE INTO `' . $this->wpdb->prefix
                . 'term_relationships` (object_id, term_taxonomy_id, term_order) VALUES (?, ?, 0)')
                ->execute([$wcProductId, $ttid]);
        }
    }

    /**
     * Every `product_cat` row for one product, removed directly.
     *
     * Directly, because `wp_set_object_terms($id, [], 'product_cat')` does NOT
     * leave a product with no category — WooCommerce substitutes the default
     * term, which is the very thing under test (`alpha.81`).
     */
    private function detachAll(int $wcProductId): void
    {
        $this->wpdb->pdo()->prepare(
            'DELETE FROM `' . $this->wpdb->prefix . 'term_relationships` WHERE object_id = ?'
        )->execute([$wcProductId]);
    }
}
