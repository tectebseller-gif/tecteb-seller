<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryLink;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewCardView;

/**
 * The review card must not tell a manager a product has no category when it
 * has two.
 *
 * The owner's measurement: WooCommerce product 10225 carries «ترالی شستشو»
 * and «ترالی نظافتی بیمارستانی», and the card printed «دسته‌ای انتخاب نشده یا
 * ترمش پیدا نشد». The cause was one argument — the card's category row read
 * `ProductDetails::categoryKey`, the single `varchar(64)`, resolved through
 * the directory. `CategorySet::soleKey()` returns `''` for every
 * multi-category product ON PURPOSE («اولین دسته را خودسرانه انتخاب نکن»),
 * and `ImportFromDokan` writes `''` for every row whose source category was
 * never mapped. Both arrive at the same sentence, and neither of them means
 * what that sentence said.
 *
 * So the card now carries THREE statements, and this test holds each of them
 * open separately — because the whole fault was two different facts sharing
 * one line.
 */
final class ProductCategoryDisplayTest extends TestCase
{
    /** The sentence that was wrong. It must not be reachable at all any more. */
    private const OLD = 'دسته‌ای انتخاب نشده یا ترمش پیدا نشد';

    /** @param list<int> $recordedIds */
    private function product(string $categoryKey = '', array $recordedIds = []): Product
    {
        return new Product(
            77,
            41,
            new ProductDetails(
                'ترالی نظافتی برند عیسی مدل ۲۲۰',
                'simple',
                $categoryKey,
                '',
                'ترالی نظافتی دو سطلی',
                4500000,
                null,
                null,
                null,
                'TRL-220',
                3,
                1,
                null,
                0,
                '',
                '',
                null,
                1,
                $recordedIds
            ),
            ProductStatus::Draft,
            [],
            [],
            0,
            '',
            1,
            null,
            '',
            10225
        );
    }

    /** @param list<array{id:int,path:string}> $recorded */
    private function card(Product $product, ?CategoryLink $shop, array $recorded): string
    {
        return ProductReviewCardView::render(
            $product,
            [],
            // The directory's answer for the SINGLE key — '' whenever the key
            // is empty, which is the input that used to decide everything.
            $product->details->categoryKey === '' ? '' : 'یک مسیر',
            null,
            [],
            [],
            null,
            '',
            '',
            '',
            true,
            ['url' => '', 'public' => false, 'reason' => 'missing'],
            false,
            'compared',
            [],
            $shop,
            $recorded
        );
    }

    /** @return array<int,string> */
    private function paths(): array
    {
        return [
            2301 => 'تجهیزات بیمارستانی › ترالی › ترالی شستشو',
            2302 => 'تجهیزات بیمارستانی › ترالی › ترالی نظافتی بیمارستانی',
            2226 => 'تجهیزات پزشکی › فشارسنج › فشارسنج عقربه‌ای',
        ];
    }

    public function testAProductInOneCategoryNamesItWithItsWholePath(): void
    {
        $html = $this->card(
            $this->product('2226', [2226]),
            CategoryLink::of(10225, [2226], $this->paths()),
            [['id' => 2226, 'path' => $this->paths()[2226]]]
        );

        self::assertStringContainsString('فشارسنج عقربه‌ای', $html);
        self::assertStringContainsString('#۲۲۲۶', $html, 'the id travels with the path');
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /**
     * Specimen 2, and the whole point: the data is intact and the screen was
     * wrong. `categoryKey` is `''` here because `soleKey()` refuses to pick a
     * winner — and the previous card read nothing else.
     */
    public function testAProductInTwoCategoriesNamesBothRatherThanClaimingItHasNone(): void
    {
        $link = CategoryLink::of(10225, [2301, 2302], $this->paths());
        self::assertSame(CategoryLink::MULTIPLE, $link->state, 'the fixture is the shape being tested');

        $html = $this->card(
            $this->product('', [2301, 2302]),
            $link,
            [
                ['id' => 2301, 'path' => $this->paths()[2301]],
                ['id' => 2302, 'path' => $this->paths()[2302]],
            ]
        );

        self::assertStringNotContainsString(self::OLD, $html, 'the sentence that was wrong is gone');
        self::assertStringContainsString('ترالی شستشو', $html);
        self::assertStringContainsString('ترالی نظافتی بیمارستانی', $html);
        self::assertStringContainsString('۲ دسته', $html, 'and how many, counted rather than inferred');
    }

    /** A parent and its child at once — the owner's own shape. */
    public function testAParentAndItsChildAreBothKept(): void
    {
        $paths = [
            244 => 'تجهیزات پزشکی',
            245 => 'تجهیزات پزشکی › آمبوبگ',
        ];
        $html = $this->card(
            $this->product('', [244, 245]),
            CategoryLink::of(10225, [244, 245], $paths),
            [['id' => 244, 'path' => $paths[244]], ['id' => 245, 'path' => $paths[245]]]
        );

        self::assertStringContainsString('#۲۴۴', $html);
        self::assertStringContainsString('#۲۴۵', $html);
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /**
     * Specimen 1's shape: WooCommerce holds the category, the marketplace has
     * recorded nothing, and the page has to say THAT rather than imply the
     * product is uncategorised in the shop.
     *
     * This is the case the owner's instruction is most explicit about: «اگر
     * رابطه داخلی ساخته نشده، همین را صریح بگوید؛ نه اینکه به‌اشتباه القا کند
     * محصول دسته‌بندی ندارد.»
     */
    public function testAnUnrecordedRelationshipIsNamedWhileTheShopsCategoryIsStillShown(): void
    {
        $html = $this->card(
            $this->product('', []),                       // nothing recorded, no key
            CategoryLink::of(10972, [2226], $this->paths()),
            []
        );

        self::assertStringContainsString('فشارسنج عقربه‌ای', $html, 'the shop still holds it, and the page says so');
        // The row says the state; the sentence that keeps it from being read
        // as «this product has no category» is in the fold below it, which is
        // still in the page's HTML (`alpha.85` §A).
        self::assertStringContainsString('هنوز ثبت نشده است', $html);
        self::assertStringContainsString('بازارگاه چیزی ثبت نکرده', $html);
        self::assertStringContainsString('پیوند دسته', $html, 'and where the decision is taken');
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /** A term somebody deleted is invalid, never absent. */
    public function testADeletedTermIsNamedAsOneRatherThanCountedAsNoCategory(): void
    {
        $link = CategoryLink::of(10225, [99999], $this->paths());
        self::assertSame(CategoryLink::INVALID, $link->state);

        $html = $this->card($this->product('', []), $link, []);

        self::assertStringContainsString('ترم حذف‌شده', $html);
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /** WooCommerce's own placeholder term is not a category. */
    public function testTheDefaultTermIsNotReportedAsACategory(): void
    {
        $link = CategoryLink::of(10225, [15], $this->paths(), 15);
        self::assertSame(CategoryLink::DEFAULT_ONLY, $link->state);

        $html = $this->card($this->product('', []), $link, []);

        self::assertStringContainsString('ترم پیش‌فرض', $html);
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /** «Could not read» and «has none» are opposite facts (`alpha.8`). */
    public function testAFailedReadIsSaidOutLoudAndNeverReadAsHavingNone(): void
    {
        $html = $this->card($this->product('', []), CategoryLink::unreadable(10225), []);

        self::assertStringContainsString('ناموفق', $html);
        self::assertStringNotContainsString(self::OLD, $html);
    }

    /** And a caller that did not ask gets the sentence that admits it. */
    public function testNotAskingIsItsOwnAnswer(): void
    {
        $html = $this->card($this->product('', []), null, []);

        self::assertStringContainsString('پرسیده نشد', $html);
        self::assertStringNotContainsString(self::OLD, $html);
        self::assertStringNotContainsString('هیچ دستهٔ معتبری ندارد', $html, 'never the stronger claim');
    }

    /**
     * Reading this page writes nothing — asserted from the view's own bytes,
     * not from a paragraph (`alpha.58`'s rule: a missing capability is a
     * property of OUR code, so the guard belongs where the code is).
     */
    public function testTheCardCannotChangeACategory(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Product/Presentation/Admin/ProductReviewCardView.php'
        );
        foreach (['set_category_ids', 'wp_set_object_terms', 'wp_insert_term', 'update_post_meta'] as $writer) {
            self::assertStringNotContainsString($writer, $source, $writer . ' has no business on a card that is read');
        }
    }

    /** The old sentence is not reachable from anywhere in the plugin. */
    public function testTheMisleadingSentenceIsGoneFromTheWholeSource(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), self::OLD)) {
                $found[] = $file->getPathname();
            }
        }
        self::assertSame([], $found, 'that sentence conflated two facts and must not come back');
    }
}
