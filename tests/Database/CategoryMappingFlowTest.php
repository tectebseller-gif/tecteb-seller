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
use Tecteb\Marketplace\Modules\Migration\Application\ApplyCategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMapping;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\MigrationCompleteness;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Application\StaffRoleMap;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbFinanceHandoverRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbOrderHistoryRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeDokanReader;
use Tecteb\Marketplace\Tests\Support\FakeProductCategoryDirectory;

/**
 * The category mapping, from the state the import leaves behind.
 *
 * ⛔ **Every test here starts AFTER a real import run**, not from rows written
 * by hand. That is the shape `alpha.79` was delivered without and the reason
 * it shipped a defect: a test that measures the state before an operation says
 * nothing about what the operation changes, and here the operation is what
 * creates the rows the whole round is about — products carrying
 * `category_key = ''` because nobody had mapped their source category.
 *
 * The path covered is the one the owner named: نمایش ← ذخیره ← اعمال صریح روی
 * محصولات موجود ← بررسی نتیجه. Plus the refusals, which are the half that
 * decides whether the path is safe: an invalid target, a product with no source
 * category, a product somebody has already categorised, and Dokan gone.
 */
final class CategoryMappingFlowTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const SELLER = 401;

    /** The two source categories the fixture's shop uses, as Dokan has them. */
    private const SOURCE_A = '%d8%aa%d8%ac%d9%87%db%8c%d8%b2%d8%a7%d8%aa';
    private const SOURCE_B = '%d9%85%d8%b5%d8%b1%d9%81%db%8c';

    private DbProductRepository $products;
    private FakeDokanReader $dokan;
    private FakeProductCategoryDirectory $directory;
    private CategoryMap $map;
    private CategoryMapping $mapping;
    private ApplyCategoryMapping $apply;
    private ImportFromDokan $migration;
    private WpDatabase $database;
    private SystemClock $clock;
    private WpOptionStore $options;

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

        $clock = new SystemClock();
        $this->clock = $clock;
        $options = new WpOptionStore();
        $options->delete(ImportFromDokan::RUNS_OPTION);
        $options->delete(CategoryMap::SETTING);
        $this->options = $options;

        $this->products = new DbProductRepository($db, $clock);
        $this->dokan = new FakeDokanReader();
        $this->dokan->vendorRows = [
            ['user_id' => self::SELLER, 'store_name' => 'داروخانه دکان', 'email' => 's@example.test', 'enabled' => true],
        ];
        // Three products, three situations — and the source keys are
        // PERCENT-ENCODED, because that is what `sanitize_title()` makes of a
        // Persian term name and it is the shape that broke every transport
        // this round had to avoid.
        $this->dokan->productRows = [
            $this->dokanProduct(991, 'باند کشی', 'DK-1', self::SOURCE_A),
            $this->dokanProduct(992, 'سرنگ', 'DK-2', self::SOURCE_A),
            // No source category at all: no mapping can ever fix it.
            $this->dokanProduct(993, 'قرص', 'DK-3', ''),
        ];

        $this->directory = new FakeProductCategoryDirectory();
        $this->directory->terms = [
            71 => ['name' => 'تجهیزات پزشکی', 'path' => 'پزشکی › تجهیزات پزشکی'],
            72 => ['name' => 'مصرفی', 'path' => 'پزشکی › مصرفی'],
        ];

        $this->map = new CategoryMap($options);
        $this->mapping = new CategoryMapping($this->map, $this->products, $this->dokan, $this->directory);
        $this->apply = new ApplyCategoryMapping(
            $this->mapping,
            $this->products,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );

        $this->migration = new ImportFromDokan(
            $this->dokan,
            new DbVendorRepository($db, $clock),
            $this->products,
            $options,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            new DbOrderHistoryRepository($db, $clock),
            new DbShopRecordRepository($db, $clock),
            new DbLedgerRepository($db, $clock),
            // The real map, so the import reads the decision exactly as it
            // does in production — which is how «unmapped means no category»
            // gets measured rather than assumed.
            $this->map
        );
    }

    /** @return array<string,mixed> */
    private function dokanProduct(int $wcId, string $title, string $sku, string $sourceKey): array
    {
        return [
            'wc_product_id' => $wcId,
            'vendor_user_id' => self::SELLER,
            'title' => $title,
            'sku' => $sku,
            'post_status' => 'publish',
            'price_minor' => 120000,
            'price_recorded' => true,
            'price_not_exact' => false,
            'stock' => 7,
            'source_category_key' => $sourceKey,
            'source_category_label' => $sourceKey === self::SOURCE_A
                ? 'تجهیزات'
                : ($sourceKey === self::SOURCE_B ? 'مصرفی' : ''),
        ];
    }

    /** The real import, run the way the migration page runs it. */
    private function runImport(): void
    {
        $result = $this->migration->import($this->migration->plan());
        self::assertTrue($result->ok, 'the fixture needs a successful import to start from');
    }

    /** @return array<int,string> product id → stored category key */
    private function storedCategories(): array
    {
        $rows = $this->database->getResults(
            'SELECT id, category_key FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['category_key'];
        }
        return $out;
    }

    /**
     * THE STARTING STATE: the import lands the products and maps none of them.
     *
     * Asserted first and separately, because every test below is about
     * changing it — and if this is wrong, nothing below is measuring what it
     * claims to.
     */
    public function testTheImportLeavesEveryProductWithoutACategory(): void
    {
        $this->runImport();

        $stored = $this->storedCategories();
        self::assertCount(3, $stored, 'three products arrived');
        self::assertSame(['', '', ''], array_values($stored), 'and not one of them carries a category');
        self::assertSame(3, $this->products->countImportedWithoutCategory());
        self::assertSame(3, $this->products->countImportedWithoutCategory(self::SELLER));
        self::assertCount(3, $this->products->importedWithoutCategory());
    }

    /**
     * نمایش: the source categories, with name, key and product count.
     *
     * And the product carrying no source category is NOT in the table: it is
     * counted apart, because offering it a target would be offering to decide
     * something that has no subject.
     */
    public function testTheSourceCategoriesAreListedWithTheirCounts(): void
    {
        $this->runImport();

        $rows = $this->mapping->sourceCategories();
        self::assertCount(1, $rows, 'one source category among three products');
        self::assertSame(self::SOURCE_A, $rows[0]['key']);
        self::assertSame('تجهیزات', $rows[0]['label']);
        self::assertSame(2, $rows[0]['products']);
        self::assertFalse($rows[0]['decided'], 'nobody has mapped it yet');
        self::assertSame('', $rows[0]['target_path']);

        self::assertSame(1, $this->mapping->productsWithoutSourceCategory());
    }

    /**
     * ذخیره: saving the decision, and the whole finding — IT MOVES NO PRODUCT.
     *
     * This is the assertion `alpha.79` would have needed and could not have
     * made, because nothing could save the mapping at all. The gate going
     * green on the option alone is what the owner reported, so both halves are
     * asserted here: the decision is stored, and the catalogue is untouched.
     */
    public function testSavingTheMappingChangesNoProduct(): void
    {
        $this->runImport();
        $before = $this->storedCategories();

        self::assertTrue($this->map->save([self::SOURCE_A => '71']));

        self::assertSame('71', $this->map->marketplaceCategoryFor(self::SOURCE_A), 'the decision is stored');
        self::assertSame($before, $this->storedCategories(), 'and not one product row moved');
        self::assertSame(3, $this->products->countImportedWithoutCategory(), 'still three waiting');

        $rows = $this->mapping->sourceCategories();
        self::assertTrue($rows[0]['decided']);
        self::assertTrue($rows[0]['target_valid']);
        self::assertSame('پزشکی › تجهیزات پزشکی', $rows[0]['target_path']);
    }

    /**
     * پیش از اعمال: the count and the proposed change, before anything is written.
     */
    public function testThePlanNamesWhatWouldChangeAndWhatWouldNot(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $before = $this->storedCategories();

        $plan = $this->mapping->plan();
        self::assertTrue($plan['source_readable']);
        self::assertSame(3, $plan['total']);
        self::assertSame(3, $plan['scanned']);
        self::assertTrue($plan['finished']);
        self::assertCount(2, $plan['apply'], 'the two mapped products');
        self::assertSame('71', $plan['apply'][0]['target']);
        self::assertSame('پزشکی › تجهیزات پزشکی', $plan['apply'][0]['target_path']);
        self::assertSame(self::SOURCE_A, $plan['apply'][0]['source_key']);
        // And the third is HELD, by name.
        self::assertSame([1], array_values(array_map('count', $plan['held'])));
        self::assertArrayHasKey('no_source_category', $plan['held']);

        self::assertSame($before, $this->storedCategories(), 'planning writes nothing');
    }

    /**
     * اعمال صریح ← بررسی نتیجه: the explicit application, and what it touched.
     *
     * The products' other columns are read before and after and compared, so
     * «فقط ستون دسته» is measured rather than described: status, ownership,
     * price, stock, SKU, the WooCommerce link and the import stamp all have to
     * come back identical.
     */
    public function testApplyingWritesTheCategoryAndNothingElse(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $plan = $this->mapping->plan();

        $others = fn (): array => $this->database->getResults(
            'SELECT id, vendor_user_id, status, link_ownership, price_minor, stock, sku,
                    wc_product_id, import_run_id, title
               FROM `' . $this->wpdb->prefix . 'tmc_products` ORDER BY id ASC'
        );
        $before = $others();

        $shown = [];
        foreach ($plan['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }
        $result = $this->apply->apply($shown);

        self::assertSame('', $result['refused']);
        self::assertSame(2, $result['written']);
        self::assertSame(['written', 'written'], array_values($result['outcomes']));

        $stored = $this->storedCategories();
        self::assertSame(['71', '71', ''], array_values($stored), 'two written, the third untouched');
        self::assertSame(1, $this->products->countImportedWithoutCategory(), 'and one still waiting');
        self::assertSame($before, $others(), 'every other column of every row is byte-identical');
    }

    /**
     * No re-import, and no duplicate product.
     *
     * The row count and the WooCommerce links are compared across the whole
     * application, because «بدون ورود مجدد یا ساخت محصول تکراری» is a claim
     * about the table and not about intent.
     */
    public function testApplyingCreatesNoProductAndNoSecondRun(): void
    {
        $this->runImport();
        $runsBefore = $this->options->get(ImportFromDokan::RUNS_OPTION, []);
        $this->map->save([self::SOURCE_A => '71']);

        $shown = [];
        foreach ($this->mapping->plan()['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }
        $this->apply->apply($shown);

        self::assertSame(
            3,
            (int) $this->database->getVar('SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_products`'),
            'no product was created'
        );
        self::assertSame(
            0,
            count($this->products->duplicateWcMappings()),
            'and no WooCommerce product has two rows'
        );
        self::assertSame($runsBefore, $this->options->get(ImportFromDokan::RUNS_OPTION, []), 'no new import run');
    }

    /**
     * A category somebody already chose is NOT overwritten.
     *
     * The guard is inside the `UPDATE` (`alpha.50`), so this holds even for a
     * value written between the page being drawn and the button being pressed
     * — which is the window a re-read before the write would leave open.
     */
    public function testACategoryAlreadyChosenIsLeftAlone(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $plan = $this->mapping->plan();
        $shown = [];
        foreach ($plan['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }
        // A manager picks a different category on the product page, after the
        // plan above was drawn.
        $first = (int) array_key_first($shown);
        self::assertSame('', $this->products->setCategoryWhenEmpty($first, '72'));

        $result = $this->apply->apply($shown);

        self::assertSame('already_set', $result['outcomes'][$first]);
        self::assertSame(1, $result['written'], 'only the other one');
        self::assertSame('72', $this->storedCategories()[$first], 'the human choice stands');
    }

    /**
     * A mapping that points at a category which no longer resolves is HELD.
     *
     * Not written and not silently dropped: the product stays waiting and the
     * reason is named, because a key that resolves to nothing is a worse
     * category than none at all.
     */
    public function testAnInvalidTargetIsHeldAndNamed(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '9999']);

        $rows = $this->mapping->sourceCategories();
        self::assertTrue($rows[0]['decided'], 'a decision was taken');
        self::assertFalse($rows[0]['target_valid'], 'and it does not resolve');

        $plan = $this->mapping->plan();
        self::assertSame([], $plan['apply']);
        self::assertArrayHasKey('invalid_target', $plan['held']);
        self::assertCount(2, $plan['held']['invalid_target']);

        // And the writer refuses it even when a stale form carries it.
        $result = $this->apply->apply([
            (int) array_key_first($this->storedCategories()) => '9999',
        ]);
        self::assertSame(0, $result['written']);
        self::assertSame(['', '', ''], array_values($this->storedCategories()));
    }

    /**
     * A target that moved between the page and the click is refused BY NAME.
     *
     * `alpha.49`'s rule: the manager approves a change that was shown. A click
     * on a page rendered before somebody re-mapped the source category is not
     * consent to the new answer.
     */
    public function testATargetThatMovedIsRefused(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $shown = [];
        foreach ($this->mapping->plan()['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }
        // Somebody re-maps it while that page is on screen.
        $this->map->save([self::SOURCE_A => '72']);

        $result = $this->apply->apply($shown);

        self::assertSame(0, $result['written']);
        self::assertSame(['target_moved', 'target_moved'], array_values($result['outcomes']));
        self::assertSame(['', '', ''], array_values($this->storedCategories()), 'nothing written');
    }

    /**
     * A product with no source category is never given one.
     *
     * Not by the plan, and not by the writer even when an id is posted for it.
     */
    public function testAProductWithNoSourceCategoryIsNeverAssignedOne(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71', '' => '72']);

        // The empty source key cannot even be stored as a decision.
        self::assertSame(['%d8%aa%d8%ac%d9%87%db%8c%d8%b2%d8%a7%d8%aa' => '71'], $this->map->all());

        $plan = $this->mapping->plan();
        $heldIds = array_column($plan['held']['no_source_category'], 'product_id');
        self::assertCount(1, $heldIds);

        $result = $this->apply->apply([(int) $heldIds[0] => '72']);
        self::assertSame(0, $result['written']);
        self::assertSame('no_source_category', $result['outcomes'][(int) $heldIds[0]]);
        self::assertSame('', $this->storedCategories()[(int) $heldIds[0]]);
    }

    /**
     * With Dokan gone, nothing is planned and nothing is written.
     *
     * ⛔ And the reason is named rather than read as «چیزی برای نگاشت نیست».
     * The import reads a product's source category and does not keep it, so
     * with Dokan absent there is nothing on our side to map from — which is a
     * different sentence from «everything is mapped».
     */
    public function testWithoutDokanThePlanRefusesRatherThanEmptying(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $shown = [];
        foreach ($this->mapping->plan()['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }

        $this->dokan->available = false;

        self::assertFalse($this->mapping->sourceAvailable());
        self::assertSame([], $this->mapping->sourceCategories());
        $plan = $this->mapping->plan();
        self::assertFalse($plan['source_readable']);
        self::assertSame([], $plan['apply']);
        self::assertArrayHasKey('source_unreadable', $plan['held']);
        self::assertCount(3, $plan['held']['source_unreadable']);

        $result = $this->apply->apply($shown);
        self::assertSame('source_unreadable', $result['refused']);
        self::assertSame(['', '', ''], array_values($this->storedCategories()));
    }

    /**
     * THE GATE: saving the mapping does not close it; applying does.
     *
     * Four measurements in order, and the middle one is the whole finding —
     * «صرف ذخیرهٔ option و سبز شدن وضعیت کافی نیست».
     */
    public function testTheGateClosesOnlyWhenTheProductsHaveTheirCategory(): void
    {
        $this->runImport();
        $completeness = $this->completeness();

        $gate = fn (): bool => (bool) $completeness->forVendor(self::SELLER)['gates']['categories_mapped'];
        $waiting = fn (): int => (int) $completeness->forVendor(self::SELLER)['detail']['products_without_category'];

        self::assertFalse($gate(), 'nothing mapped, nothing categorised');
        self::assertSame(3, $waiting());

        // Map BOTH source situations as far as they can be mapped, so Dokan's
        // own «unmapped» list is empty and only our rows are left open.
        $this->map->save([self::SOURCE_A => '71']);
        self::assertFalse($gate(), 'the option is saved and 184-style rows are still waiting');
        self::assertSame(3, $waiting());

        $shown = [];
        foreach ($this->mapping->plan()['apply'] as $row) {
            $shown[(int) $row['product_id']] = (string) $row['target'];
        }
        $this->apply->apply($shown);
        self::assertFalse($gate(), 'one product still has no category of any kind');
        self::assertSame(1, $waiting());

        // The last one needs a human on the product page — no mapping reaches
        // it. Done here through the same narrow write the apply path uses.
        $last = array_search('', $this->storedCategories(), true);
        self::assertIsInt($last);
        self::assertSame('', $this->products->setCategoryWhenEmpty((int) $last, '72'));
        self::assertSame(0, $waiting());
        self::assertTrue($gate(), 'and only now');
    }

    /**
     * A row no import made is not a migration decision's to touch.
     *
     * The vendor's own draft carries no import stamp, so it is neither counted
     * nor writable here: its empty category is theirs to fill on their form.
     */
    public function testARowNoImportMadeIsOutOfReach(): void
    {
        $this->runImport();
        $own = $this->products->create(
            self::SELLER,
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                title: 'پیش‌نویس خودِ فروشنده',
                categoryKey: '',
                priceMinor: 1000,
                sku: 'OWN-1',
                stock: 1
            ),
            ProductStatus::Draft,
            LinkOwnership::Marketplace
        );
        self::assertGreaterThan(0, $own);

        self::assertSame(3, $this->products->countImportedWithoutCategory(), 'the vendor draft is not counted');
        self::assertSame('not_imported', $this->products->setCategoryWhenEmpty($own, '71'));
        self::assertSame('', $this->storedCategories()[$own]);
    }

    /**
     * A product the plan does not list gets the reason READ off its row.
     *
     * ⛔ Three answers, and absence fuses them. «Somebody chose a category»
     * means a human decided and this refused to overwrite them; «no such row»
     * is a stale form; and «empty, yet not listed» is the plan's own page cap
     * — which must NOT be written, because its target was never derived.
     */
    public function testAProductMissingFromThePlanIsExplainedFromItsRow(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);
        $first = (int) array_key_first($this->storedCategories());

        // No such row at all.
        $gone = $this->apply->apply([999999 => '71']);
        self::assertSame('not_found', $gone['outcomes'][999999]);
        self::assertSame(0, $gone['written']);

        // A category a human chose, after the plan was drawn.
        self::assertSame('', $this->products->setCategoryWhenEmpty($first, '72'));
        $taken = $this->apply->apply([$first => '71']);
        self::assertSame('already_set', $taken['outcomes'][$first]);
        self::assertSame('72', $this->storedCategories()[$first], 'and it stands');
    }

    /**
     * A COUNT that could not be read is not nought.
     *
     * ⛔ This test exists because the mutation «report the failed count as
     * `(int) null`» SURVIVED the first falsification run — twelve of thirteen
     * were caught and this one was not, so the `-1` was a sentence in a
     * docblock and nothing else. Nought here means «nothing left to decide»
     * and would close the gate on a statement that never ran (`alpha.67`).
     *
     * The failure is injected into the real gateway BY NAME, so every other
     * read on the same connection keeps working — which is the only way to
     * tell a refused read from «no such row», the collision `alpha.37`'s
     * migration was broken by.
     */
    public function testACountThatCouldNotBeReadIsNotNought(): void
    {
        $this->runImport();
        $this->map->save([self::SOURCE_A => '71']);

        $blinded = new FailingDatabase($this->database);
        $blinded->failReadWhen(['COUNT(*)', 'category_key']);
        $products = new DbProductRepository($blinded, $this->clock);

        self::assertSame(-1, $products->countImportedWithoutCategory(), 'a failed read says so');
        self::assertSame(-1, $products->countImportedWithoutCategory(self::SELLER));

        // The plan still lists the page it could read, and says it did not
        // reach the end — rather than claiming three of three.
        $mapping = new CategoryMapping($this->map, $products, $this->dokan, $this->directory);
        $plan = $mapping->plan();
        self::assertSame(-1, $plan['total']);
        self::assertFalse($plan['finished'], 'an unreadable total is never a finished scan');

        // And the gate stays OPEN on it.
        $gates = $this->completenessWith($products)->forVendor(self::SELLER)['gates'];
        self::assertFalse($gates['categories_mapped']);
        self::assertSame(
            -1,
            $this->completenessWith($products)->forVendor(self::SELLER)['detail']['products_without_category'],
            'and the page is told it is a failed read, not a zero'
        );
    }

    /** An empty key is not a category, and writing it back would report success. */
    public function testAnEmptyCategoryIsRefused(): void
    {
        $this->runImport();
        $first = (int) array_key_first($this->storedCategories());
        self::assertSame('bad_category', $this->products->setCategoryWhenEmpty($first, '  '));
        self::assertSame('not_found', $this->products->setCategoryWhenEmpty(999999, '71'));
    }

    private function completeness(): MigrationCompleteness
    {
        return $this->completenessWith($this->products);
    }

    private function completenessWith(DbProductRepository $products): MigrationCompleteness
    {
        $clock = $this->clock;
        $db = $this->database;
        $records = new DbShopRecordRepository($db, $clock);
        return new MigrationCompleteness(
            $records,
            new StaffRoleMap($this->options),
            new ReconcileDokanFinance(
                $records,
                new DbFinanceHandoverRepository($db, $clock),
                $db,
                new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
                $clock,
                new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
            ),
            new DbVendorRepository($db, $clock),
            $products,
            $this->dokan,
            $this->map
        );
    }
}
