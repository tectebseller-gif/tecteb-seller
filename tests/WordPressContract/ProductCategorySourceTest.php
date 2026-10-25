<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WpProductCategoryDirectory;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCategoryPickerView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use TmcWpStubs\State;

/**
 * The defect the owner found on staging, held open by a test.
 *
 * A shop with 1,070 `product_cat` terms and NO spec template offered the
 * vendor an empty «دسته» dropdown, so step 1 could not be completed and no
 * product ever reached review. Nothing caught it, because the only thing that
 * rendered the field was a test that passed it a hand-written map.
 *
 * So these tests start from the taxonomy, not from a fixture of our own.
 */
final class ProductCategorySourceTest extends TestCase
{
    protected function setUp(): void
    {
        State::$terms = ['product_cat' => []];
    }

    protected function tearDown(): void
    {
        State::$terms = [];
        State::$firedActions = [];
    }

    /** @param array<int,array{name:string,slug:string,parent:int,count:int}> $rows */
    private function seed(array $rows): void
    {
        State::$terms['product_cat'] = $rows;
    }

    /** A three-level tree with an empty leaf, none of them templated. */
    private function seedTree(): void
    {
        $this->seed([
            10 => ['name' => 'تجهیزات پزشکی', 'slug' => 'medical', 'parent' => 0, 'count' => 4],
            20 => ['name' => 'بیهوشی و تنفسی', 'slug' => 'anesthesia', 'parent' => 10, 'count' => 0],
            30 => ['name' => 'آمبوبگ', 'slug' => 'ambobag', 'parent' => 20, 'count' => 0],
            40 => ['name' => 'لوازم جانبی', 'slug' => 'accessories-a', 'parent' => 20, 'count' => 2],
            50 => ['name' => 'اورولوژی', 'slug' => 'urology', 'parent' => 10, 'count' => 0],
            60 => ['name' => 'لوازم جانبی', 'slug' => 'accessories-b', 'parent' => 50, 'count' => 0],
        ]);
    }

    public function testCategoriesComeFromWooCommerceEvenWithNoTemplateAtAll(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();       // no template repository at all

        self::assertSame(6, $directory->total(), 'every product_cat term is offered');
        $results = $directory->search('لوازم', 50);
        self::assertCount(2, $results, 'both «لوازم جانبی» branches are found');
    }

    public function testEmptyCategoriesAreOfferedBecauseTheProductsDoNotExistYet(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();

        $ambobag = $directory->find('30');
        self::assertInstanceOf(ProductCategory::class, $ambobag);
        self::assertSame(0, $ambobag->productCount, 'sanity: this leaf really has no products');
        self::assertContains('30', array_map(
            static fn (ProductCategory $c): string => $c->value(),
            $directory->search('آمبوبگ', 50)
        ));
    }

    public function testTheParentPathIsWhatTellsTwoIdenticalLeavesApart(): void
    {
        $this->seedTree();
        $paths = array_map(
            static fn (ProductCategory $c): string => $c->path,
            (new WpProductCategoryDirectory())->search('لوازم جانبی', 50)
        );
        sort($paths);
        self::assertSame([
            'تجهیزات پزشکی › اورولوژی › لوازم جانبی',
            'تجهیزات پزشکی › بیهوشی و تنفسی › لوازم جانبی',
        ], $paths);
    }

    public function testSearchFoldsArabicLetterShapes(): void
    {
        $this->seed([7 => ['name' => 'کیف کمک‌های اولیه', 'slug' => 'first-aid', 'parent' => 0, 'count' => 1]]);
        $directory = new WpProductCategoryDirectory();
        // ك (U+0643, Arabic kaf) typed where the site has ک (U+06A9).
        self::assertCount(1, $directory->search('كيف', 50), 'an Arabic keyboard still finds the Persian term');
    }

    public function testAThousandCategoriesAreCappedAndTheOverflowIsReported(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1070; $i++) {
            $rows[$i] = ['name' => 'دستهٔ شمارهٔ ' . $i, 'slug' => 'cat-' . $i, 'parent' => 0, 'count' => 0];
        }
        $this->seed($rows);
        $directory = new WpProductCategoryDirectory();

        self::assertSame(1070, $directory->total());
        self::assertSame(1070, $directory->countMatches('دسته'), 'the count is the whole match, not the page');
        self::assertCount(40, $directory->search('دسته', 40), 'the page is capped');
    }

    public function testTheFormShowsRealCategoriesAndAlwaysPostsTheChosenOne(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();

        $html = ProductCategoryPickerView::render(
            [30 => $directory->find('30')],
            $directory->search('لوازم', 40),
            'لوازم',
            $directory->countMatches('لوازم'),
            $directory->total()
        );

        self::assertStringContainsString('name="category[]" value="30"', $html, 'the term id is the value');
        self::assertStringContainsString('value="30" checked', $html, 'the current choice is posted even when it matches no query');
        self::assertStringContainsString('تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ', $html, 'the whole ancestry is on screen');
        self::assertStringContainsString('name="category_q"', $html, 'there is a search box');
        self::assertStringContainsString('بدون الگوی مشخصات', $html, 'a category with no template says so instead of hiding');
    }

    public function testWithNoCategoriesAtAllThePickerSaysWhereToMakeThem(): void
    {
        $this->seed([]);
        $directory = new WpProductCategoryDirectory();
        $html = ProductCategoryPickerView::render([], [], '', 0, $directory->total());
        self::assertStringContainsString('دسته‌بندی‌ها', $html);
        self::assertStringNotContainsString('name="category[]"', $html, 'no box to tick when there is nothing to pick');
    }

    public function testALegacyTemplateKeyStillResolvesToItsTerm(): void
    {
        $this->seed([
            99 => ['name' => 'تشخیصی', 'slug' => 'tmc-diagnostics', 'parent' => 0, 'count' => 3],
        ]);
        $directory = new WpProductCategoryDirectory();
        $found = $directory->find('diagnostics');
        self::assertInstanceOf(ProductCategory::class, $found, 'a product saved before alpha.24 still renders');
        self::assertSame(99, $found->id);
    }

    /**
     * The three spellings the owner named, against the same term.
     *
     * «آمبوبگ» as it is on the site, «آمبو بگ» with a space, and «امبوبک»
     * with two letters off. The first two must be exact — a space is not a
     * difference in Persian — and the third must arrive labelled as a guess.
     */
    public function testTheThreeSpellingsOfOneTermAllFindIt(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();

        foreach (['آمبوبگ', 'آمبو بگ'] as $typed) {
            $hits = $directory->search($typed, 40);
            self::assertNotSame([], $hits, $typed . ' finds something');
            self::assertSame(30, $hits[0]->id, $typed . ' finds the right term first');
            self::assertFalse($hits[0]->fuzzy, $typed . ' is an exact answer, not a guess');
        }

        $near = $directory->search('امبوبک', 40);
        self::assertNotSame([], $near, 'a one-letter slip still finds the term');
        self::assertSame(30, $near[0]->id);
        self::assertTrue($near[0]->fuzzy, 'and it is labelled a guess rather than passed off as exact');
    }

    public function testAnExactMatchIsNeverPushedBelowAGuess(): void
    {
        $this->seed([
            1 => ['name' => 'ماسک', 'slug' => 'mask', 'parent' => 0, 'count' => 0],
            2 => ['name' => 'ماسک اکسیژن', 'slug' => 'oxygen-mask', 'parent' => 0, 'count' => 0],
            3 => ['name' => 'مانک', 'slug' => 'manek', 'parent' => 0, 'count' => 0],
        ]);
        $hits = (new WpProductCategoryDirectory())->search('ماسک', 40);
        self::assertSame([1, 2, 3], array_map(static fn (ProductCategory $c): int => $c->id, $hits));
        self::assertSame([false, false, true], array_map(static fn (ProductCategory $c): bool => $c->fuzzy, $hits));
    }

    public function testTheChosenCategoryIsRenderedApartFromTheResults(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();

        $html = ProductCategoryPickerView::render(
            [30 => $directory->find('30')],
            $directory->search('لوازم', 40),
            'لوازم',
            $directory->countMatches('لوازم'),
            $directory->total(),
            false,
            'https://example.test/wp-admin/admin-ajax.php',
            'a-nonce',
            'tmc_category_suggest'
        );

        // The chosen block comes first and carries the checked radio; the
        // result list below is a separate element the script replaces whole.
        self::assertLessThan(
            strpos($html, 'id="tv-catpick-results"'),
            strpos($html, 'id="tv-catpick-chosen"'),
            'the choice is above the results, not buried in them'
        );
        self::assertSame(1, substr_count($html, 'value="30"'), 'the chosen category appears once, not twice');
        self::assertStringContainsString('value="30" checked', $html);
        self::assertStringContainsString('data-suggest-url=', $html, 'the live search is configured from the markup');
        self::assertStringContainsString('role="status"', $html, 'and it says out loud what changed');
    }

    public function testWithoutTheEndpointThePickerIsStillTheOldForm(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();
        // What a reader who may not edit gets: no url, no nonce.
        $html = ProductCategoryPickerView::render(
            [30 => $directory->find('30')],
            $directory->search('', 40),
            '',
            $directory->countMatches(''),
            $directory->total()
        );
        self::assertStringNotContainsString('data-suggest-url=', $html);
        self::assertStringContainsString('name="search_category"', $html, 'the submit button is the feature, not the fallback');
    }

    public function testANearMatchSaysSoOnScreen(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();
        $html = ProductCategoryPickerView::results($directory->search('امبوبک', 40), [], 'امبوبک', 1);
        self::assertStringContainsString('پیشنهاد نزدیک', $html);
        self::assertStringContainsString('is-fuzzy', $html);
    }

    public function testTheReviewStepShowsThePathNotTheBareTermId(): void
    {
        $this->seedTree();
        $directory = new WpProductCategoryDirectory();
        $details = new ProductDetails('دستگاه نمونه', 'simple', '30', '', '', 1000);

        $html = \Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView::render(
            5,
            $details,
            [],
            [],
            0,
            null,
            '4',
            ProductStatus::Draft,
            ['selected' => $directory->find('30'), 'results' => [], 'query' => '', 'matched' => 0, 'total' => 6],
            new VendorUrls('/vendor/', '/vendor/products/', '/vendor/orders/'),
            ''
        );
        self::assertStringContainsString('تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ', $html);
        self::assertStringNotContainsString('<dd>30</dd>', $html, 'a bare term id is not an answer the vendor can read');
    }

    /**
     * The reported search, end to end, against a real directory.
     *
     * «جست‌وجوی «چسب تزریق پزشکی» صفحه را برمی‌گرداند و نتیجهٔ قابل‌مشاهده
     * ندارد، ولی «چسب» نتیجه می‌دهد و همان دسته در نتایج هست.» The tree here
     * is the shape that produces it: the words are spread across the leaf and
     * its ancestry, so no contiguous run of the folded query exists anywhere.
     */
    private function seedGlueTree(): void
    {
        $this->seed([
            10 => ['name' => 'تجهیزات پزشکی', 'slug' => 'medical', 'parent' => 0, 'count' => 0],
            20 => ['name' => 'تزریق و سرم', 'slug' => 'injection', 'parent' => 10, 'count' => 0],
            30 => ['name' => 'چسب', 'slug' => 'glue', 'parent' => 20, 'count' => 3],
        ]);
    }

    public function testTheWholePhraseFindsTheCategoryItsFirstWordFinds(): void
    {
        $this->seedGlueTree();
        $directory = new WpProductCategoryDirectory();

        $one = $directory->search('چسب', 40);
        $three = $directory->search('چسب تزریق پزشکی', 40);

        self::assertCount(1, $one, 'the half that always worked');
        self::assertSame(30, $one[0]->id);
        self::assertNotSame([], $three, 'and the whole phrase finds it too');
        self::assertSame(30, $three[0]->id);
        // Not labelled a guess: every word really is on the row's ancestry.
        self::assertFalse($three[0]->fuzzy);
    }

    public function testASearchThatFindsNothingNamesTheWordNoCategoryHas(): void
    {
        $this->seedGlueTree();
        $directory = new WpProductCategoryDirectory();

        self::assertSame([], $directory->search('چسب دستکش', 40));
        $explain = $directory->explainNoMatch('چسب دستکش');

        self::assertSame(['چسب', 'دستکش'], $explain['words']);
        self::assertSame(['دستکش'], $explain['missing'], 'the word that is nowhere');
        self::assertSame(['چسب' => 1], $explain['found'], 'and the one that is');
    }

    /**
     * The empty result says which word to drop, not «write part of the name».
     *
     * The old sentence was advice the vendor had already followed, which is
     * what made the page read as «nothing happened».
     */
    public function testTheEmptyResultMessageNamesTheMissingWord(): void
    {
        $this->seedGlueTree();
        $directory = new WpProductCategoryDirectory();

        $html = ProductCategoryPickerView::results(
            [],
            [],
            'چسب دستکش',
            0,
            $directory->explainNoMatch('چسب دستکش')
        );

        self::assertStringContainsString('دستکش', $html);
        self::assertStringNotContainsString('بخشی از نام را بنویسید', $html, 'the advice that did not help');
    }

    /** Every word findable, never together: the advice is «fewer words». */
    public function testWordsThatExistButNeverTogetherGetTheirOwnMessage(): void
    {
        $this->seed([
            10 => ['name' => 'چسب', 'slug' => 'glue', 'parent' => 0, 'count' => 1],
            20 => ['name' => 'دستکش', 'slug' => 'gloves', 'parent' => 0, 'count' => 1],
        ]);
        $directory = new WpProductCategoryDirectory();

        $explain = $directory->explainNoMatch('چسب دستکش');
        self::assertSame([], $explain['missing'], 'both words are in the catalogue');
        self::assertSame(['چسب' => 1, 'دستکش' => 1], $explain['found']);

        $html = ProductCategoryPickerView::results([], [], 'چسب دستکش', 0, $explain);
        self::assertStringContainsString('کلمهٔ کمتر', $html);
    }

    /** An empty box still gets the browsing invitation, not a diagnosis. */
    public function testAnEmptyQueryIsNotDiagnosed(): void
    {
        $html = ProductCategoryPickerView::results([], [], '', 0, []);

        self::assertStringContainsString('برای دیدن دسته‌ها جست‌وجو کنید', $html);
    }

    /** The typed text survives the search, which is what makes it a submit. */
    public function testTheTypedQueryIsStillInTheBoxAfterSearching(): void
    {
        $this->seedGlueTree();
        $directory = new WpProductCategoryDirectory();

        $html = ProductCategoryPickerView::render(
            [],
            $directory->search('چسب تزریق پزشکی', 40),
            'چسب تزریق پزشکی',
            1,
            3
        );

        self::assertStringContainsString('name="category_q"', $html);
        self::assertStringContainsString('value="چسب تزریق پزشکی"', $html);
        // And the results block keeps the id the redirect lands on.
        self::assertStringContainsString('id="tv-catpick-results"', $html);
    }
}
