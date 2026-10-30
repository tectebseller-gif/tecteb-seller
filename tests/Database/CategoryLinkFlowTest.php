<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords;
use Tecteb\Marketplace\Core\Migration\Migrations\M0018HandoverRowsAndRecordVersion;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbOrderHistoryRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Product\Application\ApplyCategoryLinks;
use Tecteb\Marketplace\Modules\Product\Application\CategoryLinkPlan;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0035ProductCategorySet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WpProductCategoryLinkReader;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;
use Tecteb\Marketplace\Tests\Support\FakeProductCategoryDirectory;

/**
 * WHAT THIS PROVES: the category each imported product's own WooCommerce
 * relationship names can be previewed and applied, and nothing else moves.
 *
 * **It starts from the post-import state, not from hand-written rows.** The
 * owner asked for exactly that — «آزمون‌ها از محصولات ازقبل‌واردشده شروع
 * شوند» — and `alpha.79` is the round where a test that measured the state
 * BEFORE the operation shipped a defect: the operation itself was writing one
 * side of the comparison. So every test below runs the real import first.
 *
 * The seven cases the owner listed are each one test: one category, several,
 * the same leaf name under two parents, a Persian category, a deleted term,
 * no category, and applying twice.
 */
final class CategoryLinkFlowTest extends DatabaseTestCase
{
    private const SELLER = 4101;

    private const MANAGER = 4102;

    /** Percent-encoded, because that is what `sanitize_title()` makes of a Persian name. */
    private const SOURCE = '%d8%aa%d8%ac%d9%87%db%8c%d8%b2%d8%a7%d8%aa';

    private const INJECTION = 711;

    private const GLUE_MEDICAL = 712;

    /** Same leaf name as `GLUE_MEDICAL`, different parent — and a different id. */
    private const GLUE_CONSUMABLE = 713;

    private const PERSIAN = 714;

    private const DEFAULT_TERM = 715;

    /** Attached to a product and absent from the directory: the deleted term. */
    private const DELETED = 799;

    private WpDatabase $database;

    private DbProductRepository $products;

    private FakeDokanReader $dokan;

    private FakeProductCategoryDirectory $directory;

    private ImportFromDokan $migration;

    private CategoryLinkPlan $plan;

    private ApplyCategoryLinks $applier;

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
            ...M0018HandoverRowsAndRecordVersion::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);
        $this->database = $db;
        $this->resetTermTables();
        update_option('default_product_cat', self::DEFAULT_TERM);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $options->delete(CategoryMap::SETTING);

        $this->products = new DbProductRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'داروخانه', 'email' => 's@example.test', 'enabled' => true],
        ];
        $this->dokan->productRows = [
            $this->dokanProduct(8001, 'سرنگ'),
            $this->dokanProduct(8002, 'چسب سه‌کاره'),
            $this->dokanProduct(8003, 'باند کشی'),
            $this->dokanProduct(8004, 'گاز استریل'),
            $this->dokanProduct(8005, 'ماسک'),
            $this->dokanProduct(8006, 'دستکش'),
        ];

        $this->directory = new FakeProductCategoryDirectory();
        $this->directory->terms = [
            self::INJECTION => ['name' => 'تزریق', 'path' => 'تجهیزات پزشکی › تزریق'],
            // ⛔ Two ids, one leaf name, two parents. The owner's «نام مشابه
            // با شناسهٔ متفاوت»: nothing in this path compares names, so they
            // must stay two categories.
            self::GLUE_MEDICAL => ['name' => 'چسب', 'path' => 'تجهیزات پزشکی › چسب'],
            self::GLUE_CONSUMABLE => ['name' => 'چسب', 'path' => 'مواد مصرفی › چسب'],
            self::PERSIAN => ['name' => 'پانسمان و زخم‌پوش', 'path' => 'مواد مصرفی › پانسمان و زخم‌پوش'],
            self::DEFAULT_TERM => ['name' => 'دسته‌بندی‌نشده', 'path' => 'دسته‌بندی‌نشده'],
        ];

        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->migration = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($db, $clock),
            $this->products,
            $options,
            $audit,
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            new DbOrderHistoryRepository($db, $clock),
            new DbShopRecordRepository($db, $clock),
            new DbLedgerRepository($db, $clock),
            new CategoryMap($options)
        );

        $reader = new WpProductCategoryLinkReader($db, $this->directory);
        $this->plan = new CategoryLinkPlan($this->products, $reader);
        $this->applier = new ApplyCategoryLinks($this->products, $reader, $audit);
    }

    /** @return array<string,mixed> */
    private function dokanProduct(int $wcId, string $title): array
    {
        return [
            'wc_product_id' => $wcId,
            'vendor_user_id' => self::SELLER,
            'title' => $title,
            'sku' => 'DK-' . $wcId,
            'post_status' => 'publish',
            'price_minor' => 120000,
            'price_recorded' => true,
            'price_not_exact' => false,
            'stock' => 7,
            'source_category_key' => self::SOURCE,
            'source_category_label' => 'تجهیزات',
        ];
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

    /** Every `product_cat` relationship in the database, as a comparable snapshot. */
    private function relationshipSnapshot(): array
    {
        $rows = $this->database->getResults(
            'SELECT tr.object_id, tt.term_id FROM `' . $this->wpdb->prefix . 'term_relationships` tr
               INNER JOIN `' . $this->wpdb->prefix . 'term_taxonomy` tt
                       ON tt.term_taxonomy_id = tr.term_taxonomy_id
              ORDER BY tr.object_id ASC, tt.term_id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row['object_id'] . ':' . (int) $row['term_id'];
        }
        return $out;
    }

    /** Every product column except the one this round may write. */
    private function productSnapshot(): array
    {
        $rows = $this->database->getResults(
            'SELECT * FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            unset($row['category_key'], $row['updated_at'], $row['row_version']);
            $out[] = $row;
        }
        return $out;
    }

    private function runImport(): void
    {
        $result = $this->migration->import($this->migration->plan());
        self::assertTrue($result->ok, 'the fixture needs a successful import to start from');
    }

    /**
     * The row behind a WooCommerce product, whatever its link ownership.
     *
     * ⛔ Not `findByWcProduct()`. That one carries `link_ownership =
     * 'marketplace'` in its `WHERE`, and MEASURED here, the Dokan import
     * lands every row as `observed` — which is exactly what the owner's 184
     * rows are. A helper built on it returned null for every product and the
     * first run reported eight failures about a path that works
     * (`alpha.33`'s shape: a fixture asking the wrong question).
     *
     * It matters beyond the fixture: `ApplyCategoryLinks` reaches these rows
     * through `findMany()`, which has no ownership filter, and
     * `setCategoryWhenEmpty()` asks for the import stamp rather than for
     * ownership. So an imported, un-owned row IS writable — and writing its
     * category does not make it owned, which `productSnapshot()` checks by
     * comparing `link_ownership` before and after.
     */
    private function rowId(int $wcProductId): int
    {
        $row = $this->database->getRow(
            'SELECT id FROM `' . $this->wpdb->prefix . 'tmc_products` WHERE wc_product_id = %d',
            [$wcProductId]
        );
        self::assertNotNull($row, 'the import should have made a row for ' . $wcProductId);
        return (int) $row['id'];
    }

    /** The recorded set for a product, read from the link table. */
    private function recordedSet(int $wcProductId): array
    {
        return $this->products->categoryIdsFor($this->rowId($wcProductId)) ?? [];
    }

    /** Which bucket the preview put one product in. */
    private function reasonFor(array $plan, int $wcProductId): string
    {
        foreach ($plan['apply'] as $row) {
            if ((int) $row['wc_product_id'] === $wcProductId) {
                return CategoryLinkPlan::APPLY;
            }
        }
        foreach ($plan['held'] as $reason => $rows) {
            foreach ($rows as $row) {
                if ((int) $row['wc_product_id'] === $wcProductId) {
                    return (string) $reason;
                }
            }
        }
        return CategoryLinkPlan::SETTLED;
    }

    private function storedKey(int $wcProductId): string
    {
        $row = $this->database->getRow(
            'SELECT category_key FROM `' . $this->wpdb->prefix . 'tmc_products` WHERE wc_product_id = %d',
            [$wcProductId]
        );
        return $row === null ? '__no_row__' : trim((string) $row['category_key']);
    }

    /** What the import left every row's ownership as — read, not assumed. */
    private function ownerships(): array
    {
        $out = [];
        foreach ($this->database->getResults(
            'SELECT wc_product_id, link_ownership FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        ) as $row) {
            $out[(int) $row['wc_product_id']] = (string) $row['link_ownership'];
        }
        return $out;
    }

    /**
     * THE STARTING STATE, asserted first and on its own.
     *
     * Every test below is about changing it; if this is wrong, nothing below
     * measures what it claims to.
     */
    public function testTheImportLandsEveryProductWithNoCategoryAtAll(): void
    {
        $this->runImport();

        foreach ([8001, 8002, 8003, 8004, 8005, 8006] as $wcId) {
            self::assertSame('', $this->storedKey($wcId), 'product ' . $wcId);
        }
        self::assertSame(6, $this->products->countImported());
        // The state this path has to work on: stamped as imported, and NOT
        // owned by the marketplace.
        self::assertSame(array_fill_keys([8001, 8002, 8003, 8004, 8005, 8006], 'observed'), $this->ownerships());
    }

    public function testOneCategoryIsProposedWithItsIdAndFullPath(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);

        $plan = $this->plan->plan();
        $proposed = [];
        foreach ($plan['apply'] as $row) {
            $proposed[$row['wc_product_id']] = $row;
        }

        self::assertArrayHasKey(8001, $proposed);
        self::assertSame('711', $proposed[8001]['proposed_key']);
        self::assertSame('تجهیزات پزشکی › تزریق', $proposed[8001]['proposed_path']);
        self::assertSame(1, $plan['counts'][CategoryLinkPlan::APPLY]);
    }

    /** The Persian category travels as itself — id and full ancestry. */
    public function testAPersianCategoryIsProposedUnchanged(): void
    {
        $this->runImport();
        $this->attach(8004, [self::PERSIAN]);

        $row = null;
        foreach ($this->plan->plan()['apply'] as $candidate) {
            if ($candidate['wc_product_id'] === 8004) {
                $row = $candidate;
            }
        }

        self::assertNotNull($row);
        self::assertSame('714', $row['proposed_key']);
        self::assertSame('مواد مصرفی › پانسمان و زخم‌پوش', $row['proposed_path']);
    }

    /**
     * Several categories: PROPOSED whole, every id shown, no single key.
     *
     * ⛔ **This test asserted the opposite until `alpha.82`,** and it was
     * right to: while the model held one key, «اولین دسته را خودسرانه انتخاب
     * نکن» could only be honoured by holding the row. The owner's decision
     * for this round is that the relationship itself is kept — «مجموعهٔ کامل
     * دسته‌ها باید حفظ شود… انتخاب یک دسته به جای دسته‌های فعلی تأیید
     * نمی‌شود» — so the row is a candidate now, and what must stay true is
     * that the SINGLE KEY is still nobody's guess.
     *
     * Rewritten rather than deleted: the half that mattered is still
     * asserted, on the same fixture.
     */
    public function testAMultiCategoryProductIsProposedWholeAndGetsNoSingleKey(): void
    {
        $this->runImport();
        $this->attach(8002, [self::INJECTION, self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);

        $plan = $this->plan->plan();
        $proposed = array_column($plan['apply'], null, 'wc_product_id');

        self::assertArrayHasKey(8002, $proposed, 'the whole relationship is recordable now');
        self::assertArrayNotHasKey(CategoryLinkPlan::MULTIPLE, $plan['held'], 'no longer held');
        self::assertSame([711, 712, 713], $proposed[8002]['proposed_ids']);
        self::assertSame('', $proposed[8002]['proposed_key'], 'no first-id guess');
        self::assertSame('', $proposed[8002]['stored_key']);
        // The short list a human reads: three ids, three full paths.
        self::assertSame(
            ['تجهیزات پزشکی › تزریق', 'تجهیزات پزشکی › چسب', 'مواد مصرفی › چسب'],
            array_column($proposed[8002]['proposed_paths'], 'path')
        );
    }

    /** Two categories whose leaf name is the same word stay two categories. */
    public function testTheSameLeafNameUnderTwoParentsIsNotCollapsed(): void
    {
        $this->runImport();
        $this->attach(8003, [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);

        $proposed = array_column($this->plan->plan()['apply'], null, 'wc_product_id');

        self::assertSame([712, 713], $proposed[8003]['proposed_ids'], 'two ids, not one');
        self::assertCount(2, array_unique(array_column($proposed[8003]['proposed_paths'], 'path')));
    }

    public function testADeletedTermIsHeldAsInvalidAndNeverWritten(): void
    {
        $this->runImport();
        $this->attach(8005, [self::DELETED]);

        $plan = $this->plan->plan();

        self::assertArrayHasKey(CategoryLinkPlan::INVALID, $plan['held']);
        self::assertSame(8005, $plan['held'][CategoryLinkPlan::INVALID][0]['wc_product_id']);
        $outcome = $this->applier->apply([$this->rowId(8005)], [], self::MANAGER);
        self::assertSame(1, $outcome['counts'][CategoryLinkPlan::INVALID]);
        self::assertSame('', $this->storedKey(8005));
    }

    public function testNoCategoryAtAllIsItsOwnHeldReason(): void
    {
        $this->runImport();

        $plan = $this->plan->plan();

        self::assertArrayHasKey(CategoryLinkPlan::NO_CATEGORY, $plan['held']);
        self::assertCount(6, $plan['held'][CategoryLinkPlan::NO_CATEGORY], 'nothing attached yet');
    }

    public function testTheShopsDefaultTermIsNotACategoryAndIsHeldApart(): void
    {
        $this->runImport();
        $this->attach(8006, [self::DEFAULT_TERM]);

        $plan = $this->plan->plan();

        self::assertArrayHasKey(CategoryLinkPlan::DEFAULT_TERM, $plan['held']);
        self::assertSame(8006, $plan['held'][CategoryLinkPlan::DEFAULT_TERM][0]['wc_product_id']);
        self::assertArrayNotHasKey(8006, array_column($plan['apply'], null, 'wc_product_id'));
    }

    /**
     * Applying writes the key — and moves nothing else at all.
     *
     * The WooCommerce relationship is snapshotted before and after, which is
     * «حفظ روابط ووکامرس» measured rather than asserted in prose; and every
     * other product column is compared too.
     */
    public function testApplyingRecordsTheSetAndTouchesNothingElse(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);
        $this->attach(8002, [self::INJECTION, self::GLUE_MEDICAL]);
        $relationshipsBefore = $this->relationshipSnapshot();
        $columnsBefore = $this->productSnapshot();

        $result = $this->applier->apply(
            [$this->rowId(8001), $this->rowId(8002)],
            // The SET the page printed, for each — `alpha.82` compares sets.
            [$this->rowId(8001) => '711', $this->rowId(8002) => '711,712'],
            self::MANAGER
        );

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['counts']['linked'], 'both are recorded now');
        self::assertSame('711', $this->storedKey(8001), 'one category, so one key');
        // ⛔ AND THE MULTI-CATEGORY ONE STILL GETS NO KEY. The relationship is
        // recorded; which of its two categories a spec template should come
        // from is the owner's open decision and not this writer's.
        self::assertSame('', $this->storedKey(8002));
        self::assertSame([711], $this->recordedSet(8001));
        self::assertSame([711, 712], $this->recordedSet(8002));
        self::assertSame($relationshipsBefore, $this->relationshipSnapshot(), 'no WooCommerce relationship moved');
        self::assertSame($columnsBefore, $this->productSnapshot(), 'and no other column moved');
    }

    /**
     * EQUALITY OF THE ID SET, before and after — the owner's acceptance rule.
     *
     * «برابری مجموعهٔ شناسه‌ها را قبل و بعد از اعمال، ویرایش و پذیرش اندازه
     * بگیر؛ برابری تعداد کافی نیست.» So this compares the recorded set with
     * the product's own relationship id by id, on the case that mattered: a
     * PARENT and its own CHILD, attached at once.
     */
    public function testAParentAndItsChildAreBothRecorded(): void
    {
        $this->runImport();
        // 712 is «تجهیزات پزشکی › چسب»; 713 is «مواد مصرفی › چسب». Two
        // branches is the owner's other named case; the parent/child pair is
        // the one `alpha.81` destroyed, and here both ids must survive.
        $this->attach(8004, [self::INJECTION, self::GLUE_MEDICAL, self::PERSIAN]);
        $id = $this->rowId(8004);

        $result = $this->applier->apply([$id], [], self::MANAGER);

        self::assertSame(1, $result['counts']['linked']);
        self::assertSame([711, 712, 714], $result['results'][$id]['ids']);
        self::assertSame([711, 712, 714], $this->recordedSet(8004), 'the set, id by id');
        self::assertSame('', $this->storedKey(8004));
    }

    /**
     * A set that moved between the page and the click is REFUSED, and the
     * comparison is by set rather than by count.
     */
    public function testASetThatMovedSinceThePageIsRefused(): void
    {
        $this->runImport();
        $this->attach(8005, [self::INJECTION, self::GLUE_MEDICAL]);
        $id = $this->rowId(8005);

        // Two ids then, two ids now — and NOT the same two.
        $result = $this->applier->apply([$id], [$id => '711,713'], self::MANAGER);

        self::assertSame(1, $result['counts']['link_moved']);
        self::assertSame([], $this->recordedSet(8005), 'nothing written');
    }

    /** Reordering is not a change: the same set in another order is applied. */
    public function testTheSameSetInAnotherOrderIsNotTreatedAsMoved(): void
    {
        $this->runImport();
        $this->attach(8005, [self::INJECTION, self::GLUE_MEDICAL]);
        $id = $this->rowId(8005);

        $result = $this->applier->apply([$id], [$id => '712,711'], self::MANAGER);

        self::assertSame(1, $result['counts']['linked']);
        self::assertSame([711, 712], $this->recordedSet(8005));
    }

    /**
     * A set somebody already recorded is never replaced, and the answer is
     * named rather than a silent success.
     */
    public function testARecordedSetIsNotOverwritten(): void
    {
        $this->runImport();
        $this->attach(8006, [self::INJECTION, self::GLUE_MEDICAL]);
        $id = $this->rowId(8006);
        // A decision somebody took: one of the two, recorded explicitly.
        self::assertSame('', $this->products->saveCategorySet($id, [self::PERSIAN]));

        $plan = $this->plan->plan();
        $result = $this->applier->apply([$id], [], self::MANAGER);

        self::assertSame(
            CategoryLinkPlan::DECIDED,
            $this->reasonFor($plan, 8006),
            'the preview says so too'
        );
        self::assertSame(
            1,
            $result['counts']['category_set_decided'],
            'refused by its own name — «already set» would hide which half it was'
        );
        self::assertSame([714], $this->recordedSet(8006), 'the human decision stands');
    }

    /** The gate counts the SET, not the key: a recorded product is answered. */
    public function testTheGateCountsARecordedSetAsAnswered(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION, self::GLUE_MEDICAL]);
        self::assertSame(6, $this->products->countImportedWithoutCategory());

        $this->applier->apply([$this->rowId(8001)], [], self::MANAGER);

        self::assertSame(
            5,
            $this->products->countImportedWithoutCategory(),
            'a product whose whole relationship is recorded is not «without a category»'
        );
    }

    /** Applying twice writes once. The condition is inside the statement. */
    public function testApplyingTwiceWritesNothingTheSecondTime(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);
        $id = $this->rowId(8001);

        $first = $this->applier->apply([$id], [], self::MANAGER);
        $relationshipsAfterFirst = $this->relationshipSnapshot();
        $second = $this->applier->apply([$id], [], self::MANAGER);

        self::assertSame(1, $first['counts']['linked']);
        self::assertSame(1, $second['counts']['already_set'], 'named, not a silent success');
        self::assertArrayNotHasKey('linked', $second['counts']);
        self::assertSame('711', $this->storedKey(8001));
        self::assertSame($relationshipsAfterFirst, $this->relationshipSnapshot());
    }

    /**
     * A legacy single key is not a decision, and it does not block recording.
     *
     * ⛔ THIS TEST ASSERTED THE OPPOSITE UNTIL `alpha.82`, and the old
     * assertion was the rule the owner rejected. `setCategoryWhenEmpty()` is
     * the Dokan MAPPING writer: it fills `category_key` and writes no
     * relationship, deliberately — a mapped destination is a marketplace
     * classification and attaching it in WooCommerce would be «اضافه کردن یک
     * رابطه», which is forbidden. So a row carrying such a key has NOTHING
     * recorded, and recording the product's own `product_cat` relationship is
     * not overwriting anybody: «مرجع باید شناسه‌های واقعی ترم‌های همان محصول
     * در ووکامرس باشد».
     *
     * And the key itself is left exactly as it is. What a single key means
     * for a product in several categories is the owner's open decision
     * (`docs/open-decisions-alpha82.md` §1) and this path does not take it.
     */
    public function testALegacySingleKeyDoesNotBlockRecordingTheRelationship(): void
    {
        $this->runImport();
        $id = $this->rowId(8001);
        self::assertSame('', $this->products->setCategoryWhenEmpty($id, (string) self::PERSIAN));
        $this->attach(8001, [self::INJECTION, self::GLUE_MEDICAL]);
        self::assertSame([], $this->recordedSet(8001), 'the mapping wrote no relationship');

        $plan = $this->plan->plan();
        $outcome = $this->applier->apply(
            [$id],
            [$id => CategorySet::toValue([self::INJECTION, self::GLUE_MEDICAL])],
            self::MANAGER
        );

        self::assertSame(CategoryLinkPlan::APPLY, $this->reasonFor($plan, 8001));
        self::assertSame(1, $outcome['counts']['linked']);
        self::assertSame(
            [self::INJECTION, self::GLUE_MEDICAL],
            $this->recordedSet(8001),
            'the product\'s own relationship, whole'
        );
        self::assertSame(
            (string) self::PERSIAN,
            $this->storedKey(8001),
            'and the mapped key is untouched — that decision is not this path\'s to take'
        );
    }

    /**
     * THE UPGRADE CASE: a recorded set that is a strict subset is COMPLETED.
     *
     * This is the shape migration 35 creates on staging and it is the reason
     * the round exists. The backfill writes ONE row per product from
     * `category_key`, and that key is what the pre-`alpha.82` intake stored —
     * `category_ids[0]`. So the owner's nine products arrive with one of their
     * ids recorded and the rest recorded nowhere.
     *
     * Refusing that as «somebody decided it» would make exactly those
     * products unrecordable: «مدیر نباید مجبور به انتخاب مجدد دسته‌های موجود
     * شود.» Completing it removes nothing — the assertion below is set
     * equality before and after, not a count, because «برابری تعداد کافی
     * نیست».
     */
    public function testARecordedSubsetIsCompletedAndNothingIsRemoved(): void
    {
        $this->runImport();
        $id = $this->rowId(8002);
        $this->attach(8002, [self::INJECTION, self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);
        // Exactly what the backfill leaves: the first of several, recorded,
        // with the matching legacy key beside it.
        self::assertSame('', $this->products->saveCategorySet($id, [self::INJECTION]));
        self::assertSame('', $this->products->setCategoryWhenEmpty($id, (string) self::INJECTION));

        $plan = $this->plan->plan();
        $row = null;
        foreach ($plan['apply'] as $candidate) {
            if ((int) $candidate['wc_product_id'] === 8002) {
                $row = $candidate;
            }
        }
        self::assertNotNull($row, 'it is offered, not held');
        self::assertTrue($row['completes'], 'and the page is told it is a completion');
        self::assertSame(
            [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE],
            $row['adds_ids'],
            'with the ids that would be ADDED, which is the only thing that changes'
        );

        $relationshipsBefore = $this->relationshipSnapshot();
        $outcome = $this->applier->apply(
            [$id],
            [$id => CategorySet::toValue([self::INJECTION, self::GLUE_MEDICAL, self::GLUE_CONSUMABLE])],
            self::MANAGER
        );

        self::assertSame(1, $outcome['counts']['linked']);
        self::assertSame(
            [self::INJECTION, self::GLUE_MEDICAL, self::GLUE_CONSUMABLE],
            $this->recordedSet(8002),
            'the whole set, and the one that was already there is still there'
        );
        self::assertSame(
            (string) self::INJECTION,
            $this->storedKey(8002),
            'the legacy key stays: its meaning is the owner\'s open decision'
        );
        self::assertSame(
            $relationshipsBefore,
            $this->relationshipSnapshot(),
            'and WooCommerce was not touched at all'
        );
    }

    /** The ambiguous rows are grouped by the SET, so one answer covers many. */
    public function testTheAmbiguousProductsAreGroupedByTheirSetOfCategories(): void
    {
        $this->runImport();
        $this->attach(8002, [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);
        $this->attach(8003, [self::GLUE_CONSUMABLE, self::GLUE_MEDICAL]);
        $this->attach(8004, [self::INJECTION, self::GLUE_MEDICAL]);

        $groups = $this->plan->plan()['groups'];

        self::assertCount(2, $groups, 'two distinct sets, not three products');
        self::assertSame(2, $groups[0]['products'], 'the shared set is counted once, for two products');
        self::assertSame([712, 713], $groups[0]['term_ids']);
    }

    /** A key is refused when the product's single term moved after the render. */
    public function testAProductWhoseCategoryMovedAfterTheRenderIsRefused(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);
        $id = $this->rowId(8001);

        // The page showed 712; the shop says 711.
        $outcome = $this->applier->apply([$id], [$id => '712'], self::MANAGER);

        self::assertSame(1, $outcome['counts']['link_moved']);
        self::assertSame('', $this->storedKey(8001), 'nothing written on a stale consent');
    }

    /** A tag is not a category, even on the same product. */
    public function testATagOnTheProductIsNotProposedAsItsCategory(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);
        $this->attach(8001, [self::GLUE_MEDICAL], 'product_tag');

        $row = null;
        foreach ($this->plan->plan()['apply'] as $candidate) {
            if ($candidate['wc_product_id'] === 8001) {
                $row = $candidate;
            }
        }

        self::assertNotNull($row);
        self::assertSame('711', $row['proposed_key']);
        self::assertSame([711], $row['term_ids']);
    }

    /** The write is audited, with the path rather than a bare id. */
    public function testTheWriteLeavesAnAuditTrailNamingTheCategory(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION]);

        $this->applier->apply([$this->rowId(8001)], [], self::MANAGER);

        $rows = $this->database->getResults(
            'SELECT event_type, payload FROM `' . $this->auditTable() . "` WHERE event_type = 'product.category_linked'"
        );
        self::assertCount(1, $rows);
        $payload = json_decode((string) $rows[0]['payload'], true);
        self::assertSame(8001, $payload['wc_product_id'] ?? 0);
        self::assertSame('711', (string) ($payload['category_key'] ?? ''));
        self::assertSame('تجهیزات پزشکی › تزریق', $payload['path'] ?? '');
        self::assertSame('woocommerce_relationship', $payload['source'] ?? '');
    }

    /**
     * AN EMPTY SET IS REFUSED BY NAME, AND NOTHING IS DELETED.
     *
     * ⛔ This is the test the round shipped without, and the gap had a shape
     * worth naming: `saveCategorySet()` refuses an empty set AND
     * `syncCategorySet()` returns early on one, so the mutation that removed
     * the refusal changed nothing anybody could observe and falsification
     * reported it as «survived». Two guards reading as one is how a guard
     * stops being measured — so this calls the method DIRECTLY, which is the
     * only place the refusal is the whole answer.
     *
     * «هیچ رابطه‌ای خودکار حذف نشود» is the rule, and the recorded rows after
     * the refusal are the measurement of it.
     */
    public function testAnEmptySetIsRefusedAndDeletesNothing(): void
    {
        $this->runImport();
        $this->attach(8001, [self::INJECTION, self::GLUE_MEDICAL]);
        $id = $this->rowId(8001);
        self::assertSame('', $this->products->saveCategorySet($id, [self::INJECTION, self::GLUE_MEDICAL]));
        self::assertSame([self::INJECTION, self::GLUE_MEDICAL], $this->recordedSet(8001));

        self::assertSame(
            'category_set_empty',
            $this->products->saveCategorySet($id, []),
            'refused by its own name, so a caller cannot read it as «done, nothing to do»'
        );
        self::assertSame(
            [self::INJECTION, self::GLUE_MEDICAL],
            $this->recordedSet(8001),
            'and both relationships are still recorded'
        );

        // A set of nothing but rubbish is the same answer: `CategorySet::of()`
        // drops anything that is not a run of digits, so this reaches the
        // refusal rather than writing `0`.
        self::assertSame('category_set_empty', $this->products->saveCategorySet($id, [0, -3]));
        self::assertSame([self::INJECTION, self::GLUE_MEDICAL], $this->recordedSet(8001));
    }

    /**
     * THE PAIR, AND WHY IT IS A PAIR.
     *
     * `recordCategorySet()` refuses a narrowing set twice over: once in PHP
     * (`CategorySet::lost(...) !== []`) and once INSIDE the claim statement
     * (`AND NOT EXISTS (… term_id NOT IN (…))`). The SQL half is the guarantee
     * — `alpha.74`'s rule, that a check which returns is a report and a
     * guarantee is one with the write inside it — and the PHP half is the
     * report that names WHICH refusal it was.
     *
     * ⛔ So reverting the PHP half alone changes no behaviour, and the round's
     * falsification said «survived» about a guard that is doing its job. The
     * honest measurement is this: remove BOTH halves and the recorded decision
     * is overwritten. This test fails if either half stops being reachable,
     * because it asserts the NAME of the refusal (which only PHP produces) and
     * the recorded SET (which only the statement protects).
     */
    public function testNarrowingARecordedSetIsRefusedByNameAndByTheStatement(): void
    {
        $this->runImport();
        $this->attach(8006, [self::INJECTION, self::GLUE_MEDICAL]);
        $id = $this->rowId(8006);
        // Three recorded, and the product carries two of them: applying would
        // LOSE one, which is what «somebody filed it there» protects.
        self::assertSame(
            '',
            $this->products->saveCategorySet($id, [self::INJECTION, self::GLUE_MEDICAL, self::PERSIAN])
        );

        $code = $this->products->recordCategorySet($id, [self::INJECTION, self::GLUE_MEDICAL]);

        self::assertSame(
            'category_set_decided',
            $code,
            'the NAME comes from the PHP half: «already_set» would hide which half it was'
        );
        self::assertSame(
            [self::INJECTION, self::GLUE_MEDICAL, self::PERSIAN],
            $this->recordedSet(8006),
            'and the SET is what the statement-level guard protects — nothing lost, nothing merged'
        );
    }

    /**
     * `carriedBy()` HAS THREE ANSWERS, AND A FAILED READ IS NOT «ABSENT».
     *
     * ⛔ `alpha.68`'s finding, one table along. Every caller of this treats
     * `false` as «running on schema 34»: the read falls back to the single key
     * and the write refuses by name. `null` has to mean «I could not ask»,
     * because reading a failed catalogue query as «the table is not there»
     * makes a multi-category product answer with ONE category and report
     * success — the exact narrowing this round exists to close.
     *
     * Both reads are refused by name, because the second one is the whole
     * point of the method: `SHOW COLUMNS` is a different path in the server,
     * and a table that exists always answers its own columns.
     */
    public function testAFailedTableReadIsNotTheSameAsAnAbsentTable(): void
    {
        self::assertTrue(
            M0035ProductCategorySet::carriedBy($this->database),
            'the control: the table is here on this schema'
        );

        $failing = new FailingDatabase($this->database);
        $failing->failReadWhen(['information_schema.TABLES']);
        $failing->failReadWhen(['SHOW COLUMNS FROM']);
        self::assertNull(
            M0035ProductCategorySet::carriedBy($failing),
            'neither read answered, so the honest answer is «I do not know»'
        );

        // And the catalogue read failing ALONE is not «unknown»: the second
        // read answers, and it answers truthfully.
        $onlyCatalogue = new FailingDatabase($this->database);
        $onlyCatalogue->failReadWhen(['information_schema.TABLES']);
        self::assertTrue(
            M0035ProductCategorySet::carriedBy($onlyCatalogue),
            'a failed catalogue read is a reason to ask differently, not an answer'
        );
    }

    /**
     * THE ROOT CAUSE OF THE REVIEW CARD'S «دسته‌ای انتخاب نشده», measured on
     * real MariaDB rather than inferred from a screenshot.
     *
     * The owner saw it on two Dokan-imported, observe-only products — one in
     * a single category, one in two — and asked for a definitive distinction
     * between «اشکال نمایش», «نبود رابطه در داده داخلی» and «رفتار عمدی برای
     * محصول هنوز پذیرفته‌نشده». This test establishes the second one as a
     * fact: after a real import, the key is empty and the link table holds
     * nothing, WHILE WooCommerce keeps every term.
     *
     * So it is not deletion and not corruption: the relationship was never
     * recorded. `ImportFromDokan` writes `categoryKey:
     * marketplaceCategoryFor(source) ?? ''` and passes no `categoryIds`, so
     * `syncCategorySet()` returns early on the empty set — and migration 35's
     * backfill writes one row per NON-EMPTY all-digit key, which an empty key
     * is not.
     *
     * And it is not about acceptance either: nothing here has been accepted,
     * and nothing here would change if it were — `acceptCorrection()` writes
     * `soleKey()` too.
     */
    public function testAfterTheDokanImportTheRelationshipIsUnrecordedWhileWooCommerceKeepsEveryTerm(): void
    {
        $this->runImport();
        // One category, the shape of WooCommerce product 10972.
        $this->attach(8001, [self::INJECTION]);
        // Two, the shape of 10225.
        $this->attach(8002, [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);

        $terms = $this->relationshipSnapshot();

        // WHAT THE MARKETPLACE HAS: nothing, for either of them.
        self::assertSame('', $this->storedKey(8001), 'the import stored no key — no mapping was saved');
        self::assertSame('', $this->storedKey(8002));
        self::assertSame([], $this->recordedSet(8001), 'and no link row: not recorded, not deleted');
        self::assertSame([], $this->recordedSet(8002));

        // WHAT `ProductDetails` therefore answers — and this is exactly what
        // the old card had to work with, which is why it said «none».
        $oneRow = $this->products->find($this->rowId(8001));
        self::assertNotNull($oneRow);
        self::assertSame([], $oneRow->details->categorySet(), 'the fallback narrows to nothing too');

        // WHAT WOOCOMMERCE HAS: every term, untouched by any of this.
        self::assertSame($terms, $this->relationshipSnapshot(), 'reading changed no relationship');
        self::assertContains('8001:' . self::INJECTION, $terms);
        self::assertContains('8002:' . self::GLUE_MEDICAL, $terms);
        self::assertContains('8002:' . self::GLUE_CONSUMABLE, $terms);

        // AND THE WAY OUT ALREADY EXISTS: the link plan sees both rows and
        // proposes the whole set for each, with no first-id guess.
        $plan = $this->plan->plan();
        $proposed = array_column($plan['apply'], null, 'wc_product_id');
        self::assertSame([self::INJECTION], $proposed[8001]['proposed_ids'] ?? []);
        self::assertSame([self::GLUE_MEDICAL, self::GLUE_CONSUMABLE], $proposed[8002]['proposed_ids'] ?? []);
        self::assertSame('', $proposed[8002]['proposed_key'] ?? 'x', 'still no arbitrary single key');
    }

    /**
     * And once the relationship IS recorded, the set survives whole — so the
     * screen has something true to show and the complete set is inspectable
     * by id, which is what §3 of the instruction requires.
     */
    public function testARecordedSetIsReadableAsTheWholeSetOfIds(): void
    {
        $this->runImport();
        $this->attach(8002, [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);
        $before = $this->relationshipSnapshot();

        $id = $this->rowId(8002);
        $result = $this->applier->apply([$id], [$id => '712,713'], self::MANAGER);
        self::assertTrue($result['ok']);
        self::assertSame(1, $result['counts']['linked']);

        self::assertSame([self::GLUE_MEDICAL, self::GLUE_CONSUMABLE], $this->recordedSet(8002));
        self::assertSame('', $this->storedKey(8002), 'and the single key is STILL empty, on purpose');
        self::assertSame($before, $this->relationshipSnapshot(), 'WooCommerce was not written to');
    }

    /**
     * EVERY IMPORTED ROW IS REACHABLE, not just the first page of them.
     *
     * `plan()` has taken a keyset position since `alpha.81` and the admin page
     * passed none, so it always read `id > 0 LIMIT 500`. This walks the whole
     * catalogue two rows at a time — the same thing the screen now does with
     * its «ادامه از اینجا» link — and asserts the union is every row and no
     * row twice. Asserted by ID rather than by count, because equal counts
     * were accepted for most of a release once (`alpha.78`).
     */
    public function testEveryImportedRowIsReachableByPagingPastTheCap(): void
    {
        $this->runImport();
        foreach ([8001, 8002, 8003, 8004, 8005, 8006] as $wcId) {
            $this->attach($wcId, [self::INJECTION]);
        }
        $expected = [];
        foreach ([8001, 8002, 8003, 8004, 8005, 8006] as $wcId) {
            $expected[] = $this->rowId($wcId);
        }
        sort($expected);

        $seen = [];
        $after = 0;
        $pages = 0;
        do {
            $page = $this->plan->plan(2, $after);
            $pages++;
            foreach ($page['apply'] as $row) {
                $seen[] = (int) $row['product_id'];
            }
            $after = (int) $page['cursor'];
            // The whole-catalogue total never follows the page's cap.
            self::assertSame(6, (int) $page['total']);
            self::assertLessThanOrEqual(2, (int) $page['scanned']);
        } while (!$page['finished'] && $pages < 10);

        sort($seen);
        self::assertSame($expected, $seen, 'every imported row, exactly once');
        self::assertSame(4, $pages, 'three full pages and the short one that ends the walk');
        self::assertTrue($page['finished']);
    }

    /**
     * ONE NUMBER ABOUT THE WHOLE CATALOGUE, and it does not follow the limit.
     *
     * The page's own counts are about the page — which is right, and is why
     * «تمام شد» could not be read off this screen before. This figure is a
     * `COUNT(*)` over our own two tables with no `LIMIT`.
     */
    public function testTheWholeCatalogueFigureIgnoresTheRowLimit(): void
    {
        $this->runImport();
        $page = $this->plan->plan(1, 0);

        self::assertSame(1, (int) $page['scanned'], 'one row on the page');
        self::assertSame(6, (int) $page['unrecorded'], 'and six in the catalogue');
    }

    /** It falls as relationships are recorded, and reaches nought. */
    public function testTheFigureFallsAsRelationshipsAreRecorded(): void
    {
        $this->runImport();
        foreach ([8001, 8002, 8003, 8004, 8005, 8006] as $wcId) {
            $this->attach($wcId, [self::INJECTION]);
        }
        self::assertSame(6, $this->products->countImportedWithoutCategorySet());

        $this->applier->apply([$this->rowId(8001)], [], self::MANAGER);
        self::assertSame(5, $this->products->countImportedWithoutCategorySet());

        $rest = [];
        foreach ([8002, 8003, 8004, 8005, 8006] as $wcId) {
            $rest[] = $this->rowId($wcId);
        }
        $this->applier->apply($rest, [], self::MANAGER);
        self::assertSame(
            0,
            $this->products->countImportedWithoutCategorySet(),
            'nought here means every imported row has a recorded relationship'
        );
    }

    /**
     * ⛔ THE TWO QUESTIONS, AND WHY THE SCREEN PRINTS BOTH.
     *
     * A row whose recorded set is a STRICT SUBSET of what the product carries
     * is RECORDED — this figure counts it as answered, and so does the
     * completeness gate. It is not COMPLETE, and only the per-page WooCommerce
     * read can say so: the plan puts it in `apply` with `completes` true and
     * names the ids it would add. Both statements are true, they are about
     * different things, and a screen that printed one of them as «کامل شد»
     * would be the `alpha.84` defect one object over.
     */
    public function testAPartiallyRecordedSetIsCountedAsRecordedAndStillOfferedForCompletion(): void
    {
        $this->runImport();
        $this->attach(8002, [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE]);
        $id = $this->rowId(8002);
        // Exactly what migration 35's backfill leaves: one id of two.
        self::assertSame('', $this->products->saveCategorySet($id, [self::GLUE_MEDICAL]));

        self::assertSame(
            5,
            $this->products->countImportedWithoutCategorySet(),
            'it has a recorded relationship, so it is not in the unrecorded count'
        );

        $plan = $this->plan->plan();
        self::assertSame(CategoryLinkPlan::APPLY, $this->reasonFor($plan, 8002));
        $entry = null;
        foreach ($plan['apply'] as $row) {
            if ((int) $row['product_id'] === $id) {
                $entry = $row;
            }
        }
        self::assertIsArray($entry);
        self::assertTrue($entry['completes'], 'said, not inferred');
        self::assertSame([self::GLUE_CONSUMABLE], $entry['adds_ids'], 'and only an addition');

        $before = $this->relationshipSnapshot();
        $this->applier->apply([$id], [], self::MANAGER);
        self::assertSame(
            [self::GLUE_MEDICAL, self::GLUE_CONSUMABLE],
            $this->recordedSet(8002),
            'completion only adds'
        );
        self::assertSame($before, $this->relationshipSnapshot(), 'WooCommerce was not written to');
    }

    /**
     * A FIGURE THAT COULD NOT BE ASKED IS `-1`, NEVER NOUGHT.
     *
     * Nought reads as «every imported row has a recorded relationship», which
     * is the one meaning that must not come out of a statement that never ran
     * (`alpha.67`). Both ways of not knowing are measured: the count itself
     * failing, and the table absent — which is what an install still on
     * schema 34 looks like.
     *
     * ⛔ Only the first of those two discriminates. With the table gone the
     * statement would fail and `getVar()` would answer null, so `-1` comes out
     * with or without the early return; what that early return buys is a
     * failing statement never being sent while a page renders, and that is
     * written in the method rather than claimed here.
     */
    public function testAFigureThatCouldNotBeAskedIsNotNought(): void
    {
        $this->runImport();
        self::assertSame(6, $this->products->countImportedWithoutCategorySet(), 'the control');

        $failing = new FailingDatabase($this->database);
        $failing->failReadWhen(['NOT EXISTS']);
        self::assertSame(
            -1,
            (new DbProductRepository($failing, new SystemClock()))->countImportedWithoutCategorySet(),
            'the count failed, and a failed count is not nought'
        );

        // Schema 34: the link table is simply not there, so the question
        // cannot be asked at all — and a fresh repository, because the answer
        // is memoised per instance (`alpha.67`).
        $this->wpdb->dropTable(M0035ProductCategorySet::table($this->database));
        self::assertSame(
            -1,
            (new DbProductRepository($this->database, new SystemClock()))->countImportedWithoutCategorySet(),
            'no table means no answer, not «nothing left to record»'
        );
    }
}
