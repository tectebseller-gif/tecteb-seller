<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Core\Support\SystemClock;

/**
 * WHAT THIS PROVES: a product whose stored name spells ی and ک the Persian way
 * is found by its full phrase, however the person searching spelled it — and
 * the SQL fold and the PHP fold are ONE rule.
 *
 * ⛔ WHY IT NEEDS REAL MARIADB. The thing under test is a collation boundary,
 * and `alpha.60` paid for emulating one in PHP. Asked of this database, with
 * the column's own `utf8mb4_unicode_520_ci`:
 *
 *     'سینی' = 'سيني'            → 0     Arabic yeh is NOT Persian yeh
 *     'کاف'  = 'كاف'             → 0     Arabic kaf is NOT Persian kaf
 *     'دو  فاصله' = 'دو فاصله'    → 0     a second space is a difference
 *     ZWNJ vs a space            → 0
 *     ZWNJ vs nothing            → 1     the joiner alone is ignorable
 *     'دستهٔ' = 'دسته'            → 1     the combining hamza is ignorable
 *     'BPI'  = 'bpi'             → 1
 *
 * The first three are the whole report: the owner searched for a product by
 * its full name and found nothing, then found it at once by «پنبه» — a word
 * that contains none of the characters the collation is strict about.
 *
 * The second half of this class is the drift guard. `sqlFold()` is GENERATED
 * from the same constants `foldForSearch()` uses, and this test asks the
 * database to fold a table of pinned values and compares its answer with
 * PHP's, value by value. Two folds that are written twice are two folds.
 */
final class PersianSearchFoldTest extends DatabaseTestCase
{
    private const VENDOR = 102;

    /**
     * Pairs the collation does NOT equate, which is why the fold exists.
     * Each row: what the database holds, and what somebody might type.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const SPELLINGS = [
        ['چسب تزریق پزشکی', 'چسب تزريق پزشكي', 'Arabic yeh and kaf for Persian ones'],
        ['چسب تزریق پزشکی', 'چسب  تزریق  پزشکی', 'two spaces where the title has one'],
        ['چسب تزریق پزشکی', 'چسب‌تزریق پزشکی', 'a half-space where the title has a space'],
        ['پنبه ۱۰۰ عددی', 'پنبه 100 عددی', 'ASCII digits for Persian ones'],
        ['پنبه ۱۰۰ عددی', 'پنبه ١٠٠ عددی', 'Arabic-Indic digits'],
        ['دستهٔ ابزار', 'دسته ابزار', 'the combining hamza — this one the collation already forgave'],
    ];

    /** Values the fold is pinned on, in both engines. @var list<string> */
    private const PINNED = [
        'چسب  تزريق پزشكي',
        "نیم\u{200C}فاصله ۱۲۳",
        'دستهٔ ابزار',
        'كاف و يای عربی',
        '  حاشیه  ',
        '۱۲۳٤٥',
        "آمبو\u{200C}بگ",
        'BPI Cotton',
        "ماسک\u{200D}سه\u{200E}لایه",
        "تست\u{0640}کشیده",
        'ؤ و ة و إ',
    ];

    private WpDatabase $db;
    private DbProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        // Through the repository, never straight into the table: a profile
        // written by hand is «approved and unable to sell» (`alpha.17`).
        (new DbVendorRepository($this->db, $clock))
            ->upsertProfile(self::VENDOR, 'فروشگاه واردشده', true, false);
    }

    /**
     * ⛔ THE COLLATION, MEASURED — so nothing below rests on a guess about it.
     *
     * If MariaDB ever starts equating Arabic and Persian yeh in this
     * collation, this test fails and the fold can be reconsidered. Until it
     * does, these three zeroes are the reason the fold is there at all.
     */
    public function testTheCollationDoesNotForgiveTheThreeDifferencesThatMatter(): void
    {
        self::assertSame(0, $this->collationEquates('سینی', 'سيني'), 'Arabic yeh must differ');
        self::assertSame(0, $this->collationEquates('کاف', 'كاف'), 'Arabic kaf must differ');
        self::assertSame(0, $this->collationEquates('دو  فاصله', 'دو فاصله'), 'a doubled space must differ');

        // And the two it DOES forgive, so the fold is not credited for them.
        self::assertSame(1, $this->collationEquates('دستهٔ', 'دسته'), 'the combining hamza is ignorable');
        self::assertSame(1, $this->collationEquates('BPI', 'bpi'), 'Latin case is already insensitive');
    }

    /** The database's fold and PHP's fold are the same answer, value by value. */
    public function testTheSqlFoldAndThePhpFoldAgreeOnEveryPinnedValue(): void
    {
        $table = $this->wpdb->prefix . 'tmc_fold_probe';
        $pdo = $this->wpdb->pdo();
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        $pdo->exec(
            "CREATE TABLE `{$table}` (
                id INT NOT NULL AUTO_INCREMENT,
                t VARCHAR(191) COLLATE utf8mb4_unicode_520_ci,
                PRIMARY KEY (id)
             ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci"
        );
        $insert = $pdo->prepare("INSERT INTO `{$table}` (t) VALUES (?)");
        foreach (self::PINNED as $value) {
            $insert->execute([$value]);
        }

        $rows = $pdo->query('SELECT t, ' . PersianCollation::sqlFold('t') . " AS folded FROM `{$table}` ORDER BY id")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");

        self::assertCount(count(self::PINNED), $rows, 'every pinned value came back');
        foreach ($rows as $row) {
            self::assertSame(
                PersianCollation::foldForSearch((string) $row['t']),
                (string) $row['folded'],
                'SQL and PHP must fold ' . bin2hex((string) $row['t']) . ' the same way'
            );
        }
    }

    /**
     * ⛔ THE REPORT ITSELF: the full phrase finds the product, in six
     * spellings, and a word that is not in the title still finds nothing.
     */
    public function testTheFullPhraseFindsTheProductHoweverItIsSpelled(): void
    {
        $glue = $this->save('چسب تزریق پزشکی', 'GLUE-1');
        $cotton = $this->save('پنبه ۱۰۰ عددی', 'COT-100');
        $handle = $this->save('دستهٔ ابزار', 'HAN-1');
        $expected = ['چسب تزریق پزشکی' => $glue, 'پنبه ۱۰۰ عددی' => $cotton, 'دستهٔ ابزار' => $handle];

        foreach (self::SPELLINGS as [$stored, $typed, $why]) {
            $found = $this->search($typed);
            self::assertSame(
                [$expected[$stored]],
                $found,
                "«{$typed}» must find «{$stored}» — {$why}"
            );
        }
    }

    /** The word that DID work before keeps working: a fix that narrows is a regression. */
    public function testTheSingleWordThatAlreadyWorkedStillWorks(): void
    {
        $cotton = $this->save('پنبه ۱۰۰ عددی', 'COT-100');
        $this->save('چسب تزریق پزشکی', 'GLUE-1');

        self::assertSame([$cotton], $this->search('پنبه'));
    }

    /** A CONTROL: the fold widens nothing. A word that is in no title finds nothing. */
    public function testAWordInNoTitleStillFindsNothing(): void
    {
        $this->save('چسب تزریق پزشکی', 'GLUE-1');

        self::assertSame([], $this->search('سرنگ'));
        self::assertSame([], $this->search('اکسیژن'));
    }

    /**
     * ⛔ THE SKU IS NOT FOLDED, and this is the control that says so.
     *
     * A stock code is an identifier: its spaces and its letters are part of
     * it, and folding one would make «AB 12» and «AB12» the same code. So the
     * needle reaches `sku` unfolded — which means a vendor searching for a
     * code types the code.
     */
    public function testTheStockCodeIsMatchedAsTypedAndNotFolded(): void
    {
        $spaced = $this->save('کالای الف', 'AB 12');
        $this->save('کالای ب', 'AB12');

        self::assertSame([$spaced], $this->search('AB 12'), 'the code with the space');
        // Latin case still matches, because the COLUMN's collation is
        // case-insensitive — measured above, not assumed.
        self::assertSame([$spaced], $this->search('ab 12'));
    }

    /** The brand is folded like the title: it is a name, not a code. */
    public function testTheBrandIsFoldedLikeTheTitle(): void
    {
        $id = $this->save('کالای برنددار', 'BR-1', 'سینا طب');

        self::assertSame([$id], $this->search('سينا طب'), 'Arabic yeh in the brand');
    }

    /** Both readers of the clause agree: the vendor list and the manager list. */
    public function testTheManagerListFoldsTheSameWay(): void
    {
        $glue = $this->save('چسب تزریق پزشکی', 'GLUE-1');

        $rows = $this->products->forManager(null, 'چسب تزريق پزشكي', 20, 0);
        self::assertSame([$glue], array_map(static fn ($p): int => $p->id, $rows));
        self::assertSame(1, $this->products->countForManager(null, 'چسب تزريق پزشكي'));
    }

    /** The count under the pager cannot disagree with the rows above it. */
    public function testTheCountAndTheRowsAnswerAboutOneSet(): void
    {
        $this->save('چسب تزریق پزشکی', 'GLUE-1');
        $this->save('چسب تزریق صنعتی', 'GLUE-2');

        self::assertCount(2, $this->search('چسب تزريق'));
        self::assertSame(2, $this->products->countForVendor(self::VENDOR, null, 'چسب تزريق'));
    }

    // ------------------------------------------------------------- helpers

    /** @return list<int> the ids the vendor's own list returns, in order */
    private function search(string $term): array
    {
        return array_map(
            static fn ($product): int => $product->id,
            $this->products->forVendor(self::VENDOR, null, 50, 0, $term)
        );
    }

    private function save(string $title, string $sku, string $brand = ''): int
    {
        return $this->products->create(
            self::VENDOR,
            new ProductDetails(
                title: $title,
                sku: $sku,
                brand: $brand,
                priceMinor: 100000,
                stock: 5
            ),
            ProductStatus::Draft
        );
    }

    private function collationEquates(string $left, string $right): int
    {
        $stmt = $this->wpdb->pdo()->prepare(
            'SELECT (CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_520_ci)
                  = (CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_520_ci)'
        );
        $stmt->execute([$left, $right]);
        return (int) $stmt->fetchColumn();
    }
}
