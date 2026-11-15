<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * WHAT THIS PROVES: the vendor's form never offers an imported variable
 * parent's nought as a price or a stock they can edit — and never prints it
 * as a figure either.
 *
 * ⛔ §۴'s most important delivery condition, in as many words: «هیچ صفرِ
 * ساختگی به‌عنوان قیمت یا موجودی قابل ویرایش/ذخیره نمایش داده نشود». This is
 * the DISPLAY half; the save half is `ImportedParentFiguresTest`, which is
 * where the enforcement lives — a field hidden by a view is not a guard.
 *
 * The fixture is synthetic. No live id appears in it.
 */
final class ImportedParentFormTest extends TestCase
{
    private const WC_PARENT = 7700;

    private function urls(): VendorUrls
    {
        return new VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            'https://example.test/vendor/products/'
        );
    }

    /** The imported parent's own row: a type, a title, and nought for both figures. */
    private function parentRow(): ProductDetails
    {
        return new ProductDetails(
            title: 'ست کامل تجهیزات نمونه',
            type: ProductType::VARIABLE,
            categoryKey: '9559',
            priceMinor: 0,
            sku: 'BPI-PARENT',
            stock: 0
        );
    }

    private function child(int $id, string $size, int $price): StorefrontVariation
    {
        return new StorefrontVariation(
            $id,
            ['اندازه' => $size],
            'BPI-' . $size,
            $price,
            null,
            $price,
            true,
            false,
            null,
            'instock',
            'no',
            0
        );
    }

    private function shopSet(): StorefrontVariationSet
    {
        return StorefrontVariationSet::read([
            $this->child(11966, '۵۰', 2_593_700),
            $this->child(11965, '۱۰۰', 5_187_400),
            $this->child(11967, '۲۰۰', 10_374_800),
        ], 0);
    }

    /** @param array<string,mixed> $variable */
    private function render(string $step, ProductDetails $details, array $variable): string
    {
        return ProductFormView::render(
            189,
            $details,
            [],
            [['id' => 4200, 'url' => 'https://example.test/4200.jpg']],
            4200,
            null,
            $step,
            ProductStatus::Published,
            [],
            $this->urls(),
            '',
            null,
            null,
            null,
            false,
            false,
            $variable
        );
    }

    /** @param array<string,mixed> $extra */
    private function importedVariable(array $extra = []): array
    {
        $set = $extra['storefront'] ?? $this->shopSet();
        return [
            'attributes' => [],
            'variations' => [],
            'thumbnails' => [],
            'storefront' => $set,
            'origin' => PriceAndStockOrigin::decide(ProductType::VARIABLE, false, true, $set),
        ];
    }

    /**
     * ⛔ THE MEASURED DEFECT. On the `alpha.105` bytes step 2 rendered
     * `name="price"` and `name="stock"` as `class="tv-input"` boxes holding
     * «0», beside a product selling three sizes.
     */
    public function testStepTwoOffersNoEditablePriceOrStockForAnImportedParent(): void
    {
        $html = $this->render('2', $this->parentRow(), $this->importedVariable());

        self::assertStringNotContainsString(
            'id="f-price"',
            $html,
            'no editable price box — that was the defect'
        );
        self::assertStringNotContainsString('id="f-stock"', $html, 'and no editable stock box');
        self::assertStringNotContainsString('id="f-sale_price"', $html);
        // The three figures still travel, carrying EXACTLY what the row holds,
        // so the round trip is the identity and an ordinary save is not
        // refused for moving a price nobody touched.
        self::assertStringContainsString('<input type="hidden" name="price" value="0">', $html);
        self::assertStringContainsString('<input type="hidden" name="stock" value="0">', $html);
    }

    /**
     * ⛔ AND THE OTHER HALF OF §D: the SKU and the three purchase limits ARE
     * the parent's own, so they stay editable. A step 2 with nothing on it
     * would be a guard wider than the defect (`alpha.60`).
     */
    public function testTheSkuAndThePurchaseLimitsStayEditable(): void
    {
        $html = $this->render('2', $this->parentRow(), $this->importedVariable());

        self::assertStringContainsString('id="f-sku"', $html);
        self::assertStringContainsString('id="f-min_purchase"', $html);
        self::assertStringContainsString('id="f-max_purchase"', $html);
        self::assertStringContainsString('id="f-purchase_step"', $html);
    }

    /** What the vendor gets INSTEAD: the figures that are actually true. */
    public function testStepTwoShowsTheRealVariationPriceRange(): void
    {
        $html = $this->render('2', $this->parentRow(), $this->importedVariable());

        self::assertStringContainsString('۲٬۵۹۳٬۷۰۰', $html, 'the cheapest variation');
        self::assertStringContainsString('۱۰٬۳۷۴٬۸۰۰', $html, 'and the dearest');
        self::assertStringContainsString('۳ از ۳ تنوع', $html, 'how many can be bought');
        // The parent keeps no number of its own, and that is said rather than
        // shown as nought.
        self::assertStringContainsString(
            'والد عدد موجودی خودش را نگه نمی‌دارد',
            $html,
            'null parent stock is a meaning, not a zero'
        );
        self::assertStringNotContainsString('ناموجود', $html, 'and never «out of stock»');
    }

    /** Step 4 read the row too, and it is the screen just before submit. */
    public function testStepFourPrintsTheRangeRatherThanTheNought(): void
    {
        $html = $this->render('4', $this->parentRow(), $this->importedVariable());

        self::assertStringContainsString('۲٬۵۹۳٬۷۰۰', $html);
        self::assertStringNotContainsString('۰ تومان', $html, 'the nought is never printed as a price');
    }

    /**
     * «نامشخص», not nought and not «ناموجود», when the read failed — the
     * distinction §۱-A asks for by name.
     */
    public function testAFailedShopReadSaysUnknownRatherThanNought(): void
    {
        $variable = $this->importedVariable([
            'storefront' => StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE),
        ]);

        $step2 = $this->render('2', $this->parentRow(), $variable);
        self::assertStringNotContainsString('id="f-price"', $step2);
        self::assertStringContainsString('خوانده نشد', $step2);
        self::assertStringContainsString('«نامشخص» با «صفر» و «ناموجود» یکی نیست', $step2);

        $step4 = $this->render('4', $this->parentRow(), $variable);
        self::assertStringContainsString('نامشخص', $step4);
        self::assertStringNotContainsString('۰ تومان', $step4);
    }

    /**
     * ⛔ THE CONTROLS, and they carry as much weight as the refusals. A simple
     * product and a variable product whose combinations are OURS must render
     * exactly as they did in `alpha.105`.
     */
    public function testASimpleProductKeepsItsEditablePriceAndStock(): void
    {
        $simple = new ProductDetails(
            title: 'باند کشی ساده',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'SIMPLE-1',
            stock: 9
        );

        $html = $this->render('2', $simple, []);

        self::assertStringContainsString('id="f-price"', $html);
        self::assertStringContainsString('id="f-stock"', $html);
        self::assertStringContainsString('value="450000"', $html);
    }

    public function testANativeVariableProductKeepsItsEditableFigures(): void
    {
        // Our own tables hold an axis, so the shop is the mirror — even with
        // the same reader wired in and the same children present.
        $variable = [
            'attributes' => [new ProductAttribute(1, 'size', 'اندازه', ['۵۰', '۱۰۰'])],
            'variations' => [],
            'thumbnails' => [],
            'storefront' => null,
            'origin' => PriceAndStockOrigin::decide(ProductType::VARIABLE, true, true, $this->shopSet()),
        ];

        $html = $this->render('2', $this->parentRow(), $variable);

        self::assertStringContainsString('id="f-price"', $html);
        self::assertStringContainsString('id="f-stock"', $html);
    }

    /**
     * A form rendered by a caller that passes no verdict at all behaves as it
     * always did. Every other consumer of this view — and the tests of six
     * previous rounds — go through this path.
     */
    public function testAnAbsentVerdictChangesNothing(): void
    {
        $html = $this->render('2', $this->parentRow(), []);

        self::assertStringContainsString('id="f-price"', $html);
        self::assertStringContainsString('id="f-stock"', $html);
    }
    /**
     * ⛔ P0-ت, THE PRESENTATION HALF: step 1 does not offer a type the
     * service will refuse.
     *
     * The refusal itself lives in `ManageProducts::save()` and
     * `ReviewProducts::approveRevision()`, where §1-B says enforcement
     * belongs. This is the courtesy beside it: a chooser that always comes
     * back with «type_change_not_allowed» teaches a vendor the panel is
     * broken, so the type is stated, carried in a hidden input, and said to be
     * the manager's decision.
     */
    public function testStepOneStatesTheTypeRatherThanOfferingItForAnImportedParent(): void
    {
        $html = $this->render('1', $this->parentRow(), $this->importedVariable());

        self::assertStringNotContainsString('id="f-type"', $html, 'no chooser');
        self::assertStringContainsString(
            '<input type="hidden" name="type" value="variable">',
            $html,
            'the value travels unchanged, so a save of the title sends the type it found'
        );
        self::assertStringContainsString('تنوع‌های این محصول در ووکامرس هستند', $html);
        self::assertStringContainsString('تصمیم مدیر بازارگاه', $html);
    }

    /**
     * AND THE CONTROLS, because a lock on every product would be the wider
     * guard that is the bigger defect (`alpha.60`). A simple product, a native
     * variable one, and a caller that passes no verdict all keep the chooser.
     */
    public function testEveryOtherProductKeepsItsTypeChooser(): void
    {
        $simple = $this->render('1', $this->parentRow()->with(['type' => ProductType::SIMPLE]), [
            'attributes' => [],
            'variations' => [],
            'thumbnails' => [],
            'storefront' => null,
            'origin' => PriceAndStockOrigin::decide(ProductType::SIMPLE, false, true, null),
        ]);
        self::assertStringContainsString('id="f-type"', $simple, 'a simple product');

        $ours = $this->render('1', $this->parentRow(), [
            'attributes' => [],
            'variations' => [],
            'thumbnails' => [],
            'storefront' => null,
            'origin' => PriceAndStockOrigin::decide(ProductType::VARIABLE, true, true, null),
        ]);
        self::assertStringContainsString('id="f-type"', $ours, 'a variable product whose combinations are ours');

        $absent = $this->render('1', $this->parentRow(), []);
        self::assertStringContainsString('id="f-type"', $absent, 'and a caller that passes no verdict');
    }
}
