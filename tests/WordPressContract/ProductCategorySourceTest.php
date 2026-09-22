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
            $directory->find('30'),
            $directory->search('لوازم', 40),
            'لوازم',
            $directory->countMatches('لوازم'),
            $directory->total()
        );

        self::assertStringContainsString('name="category" value="30"', $html, 'the term id is the value');
        self::assertStringContainsString('value="30" checked', $html, 'the current choice is posted even when it matches no query');
        self::assertStringContainsString('تجهیزات پزشکی › بیهوشی و تنفسی › آمبوبگ', $html, 'the whole ancestry is on screen');
        self::assertStringContainsString('name="category_q"', $html, 'there is a search box');
        self::assertStringContainsString('بدون الگوی مشخصات', $html, 'a category with no template says so instead of hiding');
    }

    public function testWithNoCategoriesAtAllThePickerSaysWhereToMakeThem(): void
    {
        $this->seed([]);
        $directory = new WpProductCategoryDirectory();
        $html = ProductCategoryPickerView::render(null, [], '', 0, $directory->total());
        self::assertStringContainsString('دسته‌بندی‌ها', $html);
        self::assertStringNotContainsString('name="category"', $html, 'no radio to pick when there is nothing to pick');
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
}
