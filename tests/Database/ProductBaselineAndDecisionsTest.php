<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0019BaselineAndDecisions;

/**
 * WHAT THIS PROVES: migration 19 is additive, idempotent, and guesses nothing.
 *
 * Two things arrive together because they answer the same complaint from two
 * directions. The decision table is the memory the single `review_note`
 * column never had — «رد، نیازمند اصلاح و ارسال مجدد نباید سابقه را حذف
 * کنند». The baseline column is the third value the merge rule needs, and it
 * arrives EMPTY on purpose: a guessed base would license writing over text
 * nobody has read.
 */
final class ProductBaselineAndDecisionsTest extends DatabaseTestCase
{
    private DbProductRepository $products;
    private DbProductDecisionRepository $decisions;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        foreach ([...M0005CreateProductTables::TABLES, M0019BaselineAndDecisions::DECISIONS] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . 'tmc_' . ltrim($suffix, 'tmc_'));
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->decisions = new DbProductDecisionRepository($this->db, $clock);
    }

    private function table(string $suffix): string
    {
        return M0019BaselineAndDecisions::table($this->db, $suffix);
    }

    public function testTheColumnAndTheTableExistAfterTheUpgrade(): void
    {
        $products = M0005CreateProductTables::table($this->db, M0005CreateProductTables::PRODUCTS);
        self::assertSame(1, (int) $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$products, M0019BaselineAndDecisions::BASELINE_COLUMN]
        ));
        self::assertSame(1, (int) $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$this->table(M0019BaselineAndDecisions::DECISIONS)]
        ));
    }

    public function testRunningTheMigrationTwiceDoesNotDuplicateHistory(): void
    {
        // A resumed migration is the normal case on a big site, and a history
        // that doubles every time it is resumed is not a history.
        $this->seedLegacyProductWithNote(41, 7, 'قیمت با بازار نمی‌خواند');
        (new M0019BaselineAndDecisions())->up($this->db);
        (new M0019BaselineAndDecisions())->up($this->db);

        $rows = $this->decisions->forProduct(41);
        self::assertCount(1, $rows);
        self::assertSame(ProductDecision::IMPORTED, $rows[0]->decision);
        self::assertSame('قیمت با بازار نمی‌خواند', $rows[0]->note);
    }

    public function testTheOldColumnIsCopiedAndNotCleared(): void
    {
        // Copied, because the text and its product are both in the row
        // already — this is not a guess. Not cleared, because `alpha.28`
        // reads that column and a rollback must find what it expects.
        $this->seedLegacyProductWithNote(42, 7, 'عکس دوم مال محصول دیگری است');
        (new M0019BaselineAndDecisions())->up($this->db);

        $products = M0005CreateProductTables::table($this->db, M0005CreateProductTables::PRODUCTS);
        self::assertSame(
            'عکس دوم مال محصول دیگری است',
            (string) $this->db->getVar('SELECT review_note FROM `' . $products . '` WHERE id = %d', [42])
        );
        self::assertCount(1, $this->decisions->forProduct(42));
    }

    public function testAProductWithNoNoteGetsNoInventedRow(): void
    {
        $this->seedLegacyProductWithNote(43, 7, '');
        (new M0019BaselineAndDecisions())->up($this->db);
        self::assertSame([], $this->decisions->forProduct(43));
    }

    public function testTheBaselineArrivesAbsentAndNothingBackfillsIt(): void
    {
        $this->seedLegacyProductWithNote(44, 7, '');
        (new M0019BaselineAndDecisions())->up($this->db);
        $product = $this->products->find(44);
        self::assertNotNull($product);
        self::assertNull(
            $product->baseline,
            'a guessed baseline would authorise writing over text nobody has read'
        );
    }

    public function testABaselineSurvivesTheRoundTripThroughTheColumn(): void
    {
        $this->seedLegacyProductWithNote(45, 7, '');
        (new M0019BaselineAndDecisions())->up($this->db);
        self::assertTrue($this->products->saveBaseline(45, ApprovedBaseline::of([
            'title' => 'آمبوبگ سیلیکونی',
            'short_description' => '',
            'images' => 'main:0|gallery:17,18',
        ])));

        $product = $this->products->find(45);
        self::assertNotNull($product?->baseline);
        self::assertSame('آمبوبگ سیلیکونی', $product->baseline->get('title'));
        // The distinction `alpha.28` was about, one layer up.
        self::assertTrue($product->baseline->has('short_description'));
        self::assertSame('', $product->baseline->get('short_description'));
        self::assertSame('main:0|gallery:17,18', $product->baseline->get('images'));
    }

    public function testClearingTheBaselineMeansAbsentAgainAndNotEmpty(): void
    {
        $this->seedLegacyProductWithNote(46, 7, '');
        (new M0019BaselineAndDecisions())->up($this->db);
        $this->products->saveBaseline(46, ApprovedBaseline::of(['title' => 'x']));
        $this->products->saveBaseline(46, null);
        self::assertNull($this->products->find(46)?->baseline);
    }

    public function testOneShopCannotReadAnothersCorrespondence(): void
    {
        // The scope is in the WHERE clause, not in the caller's memory.
        $this->seedLegacyProductWithNote(47, 7, 'یادداشت فروشگاه هفت');
        (new M0019BaselineAndDecisions())->up($this->db);

        self::assertCount(1, $this->decisions->forProduct(47, 7));
        self::assertSame([], $this->decisions->forProduct(47, 8));
        self::assertNull($this->decisions->latestForVendor(47, 8));
        self::assertNotNull($this->decisions->latestForVendor(47, 7));
    }

    public function testTheTrailIsNewestFirstAndAdditionsNeverRemove(): void
    {
        $this->seedLegacyProductWithNote(48, 7, '');
        (new M0019BaselineAndDecisions())->up($this->db);
        $this->decisions->record(48, 7, 1, ProductDecision::CHANGES_REQUESTED, 'قیمت را اصلاح کنید');
        $this->decisions->record(48, 7, 0, ProductDecision::SUBMITTED, '');
        $this->decisions->record(48, 7, 1, ProductDecision::REJECTED, 'همچنان اشکال دارد');

        $rows = $this->decisions->forProduct(48, 7);
        self::assertCount(3, $rows);
        self::assertSame(ProductDecision::REJECTED, $rows[0]->decision);
        self::assertSame(ProductDecision::SUBMITTED, $rows[1]->decision);
        self::assertSame(ProductDecision::CHANGES_REQUESTED, $rows[2]->decision);

        // The vendor is shown the newest row that actually explains
        // something: their own resubmission is not an explanation.
        $latest = $this->decisions->latestForVendor(48, 7);
        self::assertSame('همچنان اشکال دارد', $latest?->note);
    }

    private function seedLegacyProductWithNote(int $id, int $vendorUserId, string $note): void
    {
        $products = M0005CreateProductTables::table($this->db, M0005CreateProductTables::PRODUCTS);
        $this->db->execute(
            'INSERT INTO `' . $products . '`
                (id, vendor_user_id, title, type, category_key, brand, short_description,
                 price_minor, sku, stock, min_purchase, weight_grams, dimensions, tax_class,
                 status, review_note, spec_schema_version, main_image_id, created_at, updated_at)
             VALUES (%d, %d, %s, %s, %s, %s, %s, %d, %s, %d, %d, %d, %s, %s, %s, %s, %d, %d, %s, %s)',
            [
                $id, $vendorUserId, 'محصول قدیمی', 'simple', '30', '', '', 1000, 'SKU-' . $id,
                1, 1, 0, '', '', 'changes_requested', $note, 0, 0,
                '2026-09-01 10:00:00', '2026-09-02 11:30:00',
            ]
        );
    }
}
