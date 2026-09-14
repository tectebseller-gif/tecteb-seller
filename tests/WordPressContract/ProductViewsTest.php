<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\SpecTemplatesPage;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCsvView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductListView;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use TmcWpStubs\State;
use TmcWpStubs\WpDieException;

/**
 * The product screens as HTML: escaping, Persian, the data-preservation
 * mechanism of the four-step form, and the capability gate on the manager's
 * two pages.
 */
final class ProductViewsTest extends ContractTestCase
{
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

    private function product(int $id = 5, string $title = 'دستکش لاتکس', ProductStatus $status = ProductStatus::Draft): Product
    {
        return new Product(
            $id,
            21,
            new ProductDetails(
                title: $title,
                categoryKey: 'gloves',
                priceMinor: 200000,
                salePriceMinor: 150000,
                saleFrom: '2026-01-01',
                saleTo: '2030-01-01',
                sku: 'SKU-1',
                stock: 4
            ),
            $status,
            ['material' => 'لاتکس'],
            [11],
            11,
            'تصویر واضح‌تری لازم است.'
        );
    }

    public function testTheListIsPersianShowsEveryStatusTabAndOffersCsvBothWays(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product(), $this->product(6, 'ماسک سه‌لایه', ProductStatus::ChangesRequested)],
            ['draft' => 1, 'changes_requested' => 1],
            '',
            1,
            2,
            $this->urls(),
            '<input type="hidden" name="tmc_vendor_nonce" value="n">',
            null,
            true,
            false
        );
        foreach (['پیش‌نویس', 'در انتظار بررسی', 'نیازمند اصلاح', 'منتشرشده', 'تعلیق‌شده', 'بایگانی', 'همه'] as $label) {
            self::assertStringContainsString($label, $html, "status tab «{$label}»");
        }
        self::assertStringContainsString('افزودن محصول', $html);
        self::assertStringContainsString('export_products', $html);
        self::assertStringContainsString('import_products', $html);
        self::assertStringContainsString('۲', $html, 'counts are rendered in Persian digits');
        self::assertStringContainsString('۱۵۰٬۰۰۰', str_replace(',', '٬', $html), 'the sale price is what a buyer would pay');
        self::assertStringContainsString('تصویر واضح‌تری لازم است.', $html, 'the reason to fix is on the row (UX §5.1)');
        self::assertStringContainsString('محصول تازه و تغییرهای حساس پس از تأیید مدیر', $html);
    }

    public function testTheListEscapesWhatAVendorTypedAndNeverRunsIt(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product(5, '<script>alert(1)</script>')],
            ['draft' => 1],
            '',
            1,
            1,
            $this->urls(),
            '',
            null,
            true,
            false
        );
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAViewerWithoutEditRightsGetsNoWriteControls(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product()],
            ['draft' => 1],
            '',
            1,
            1,
            $this->urls(),
            '',
            null,
            false,
            false
        );
        self::assertStringNotContainsString('افزودن محصول', $html);
        self::assertStringNotContainsString('import_products', $html);
        self::assertStringContainsString('export_products', $html, 'view rights still allow the read-only export');
    }

    public function testEveryStepCarriesTheOtherThreeStepsValuesSoNothingIsLost(): void
    {
        $this->bootPlugin(false);
        $template = new SpecTemplate(1, 'gloves', 'دستکش', 2, [
            new SpecField(1, 'material', 'جنس', SpecFieldType::Text, true),
        ]);
        $details = $this->product()->details;

        foreach (['1', '2', '3', '4'] as $step) {
            $html = ProductFormView::render(
                5,
                $details,
                ['material' => 'لاتکس'],
                [['id' => 11, 'url' => 'https://example.test/11.jpg']],
                11,
                $template,
                $step,
                ProductStatus::Draft,
                ['gloves' => 'دستکش'],
                $this->urls(),
                '',
                null,
                null,
                null,
                false,
                false
            );
            // AC-UI: the values of the steps not on screen must travel with
            // this submission, or saving step 2 would blank step 1.
            if ($step !== '1') {
                self::assertStringContainsString('name="title" value="دستکش لاتکس"', $html, "step {$step} carries the title");
                self::assertStringContainsString('name="image_ids[]" value="11"', $html, "step {$step} carries the gallery");
            }
            if ($step !== '2') {
                self::assertStringContainsString('name="price" value="200000"', $html, "step {$step} carries the price");
                self::assertStringContainsString('name="stock" value="4"', $html, "step {$step} carries the stock");
            }
            if ($step !== '3') {
                self::assertStringContainsString('name="spec[material]" value="لاتکس"', $html, "step {$step} carries the spec answer");
            }
            self::assertStringContainsString('value="save_product"', $html);
            self::assertStringContainsString('name="step" value="' . $step . '"', $html);
            // The step navigation must mark where the vendor is and link the
            // three places they can go. steps() has numeric-looking keys, which
            // PHP stores as integers — this is the assertion that catches a
            // comparison forgetting it.
            self::assertStringContainsString('aria-current="step"', $html, "step {$step} is marked current");
            self::assertSame(4, preg_match_all('/class="tv-tab[ "]/', $html), "step {$step} shows four steps");
            self::assertStringNotContainsString('is-disabled', $html, 'a saved product can reach every step');
        }
    }

    public function testANewProductCanReachStepOneAndOnlyStepOne(): void
    {
        $this->bootPlugin(false);
        $html = ProductFormView::render(
            0,
            new ProductDetails(),
            [],
            [],
            0,
            null,
            '1',
            ProductStatus::Draft,
            ['gloves' => 'دستکش'],
            $this->urls(),
            '',
            null,
            null,
            null,
            false,
            false
        );
        self::assertSame(3, substr_count($html, 'is-disabled'), 'steps 2–4 wait until there is a draft to come back to');
        self::assertStringContainsString('aria-current="step"', $html, 'step 1 is where the vendor is, and is a link');
        self::assertStringContainsString('۱. معرفی</a>', $html, 'step 1 is not rendered as an unreachable span');
    }

    public function testStepFourPrintsTheShareAsUnsetRatherThanZeroWhenNoRateExists(): void
    {
        $this->bootPlugin(false);
        $unset = (new CommissionCalculator())->calculate(Money::of(150000), CommissionRate::unset(), 'none', 21);
        $html = ProductFormView::render(
            5,
            $this->product()->details,
            [],
            [['id' => 11, 'url' => 'https://example.test/11.jpg']],
            11,
            null,
            '4',
            ProductStatus::Draft,
            ['gloves' => 'دستکش'],
            $this->urls(),
            '',
            null,
            OperationResult::success('ready'),
            $unset,
            false,
            false
        );
        self::assertStringContainsString('نرخ کمیسیون این محصول هنوز تعیین نشده است', $html);
        self::assertStringContainsString('این «صفر» نیست', $html, 'FIN-02 must be visible, not just enforced');
        self::assertStringNotContainsString('سهم شما</dt>', $html);
        self::assertStringContainsString('ارسال برای بررسی', $html);

        $calculated = (new CommissionCalculator())->calculate(Money::of(150000), CommissionRate::ofBasisPoints(1000), 'general', 21);
        $withRate = ProductFormView::render(
            5,
            $this->product()->details,
            [],
            [['id' => 11, 'url' => 'https://example.test/11.jpg']],
            11,
            null,
            '4',
            ProductStatus::Draft,
            ['gloves' => 'دستکش'],
            $this->urls(),
            '',
            null,
            OperationResult::success('ready'),
            $calculated,
            true,
            false
        );
        self::assertStringContainsString('سهم شما', $withRate);
        self::assertStringContainsString('انتشار محصول', $withRate, 'direct publishing renames the button honestly');
    }

    public function testAnIncompleteProductGetsNoSubmitButtonAndIsToldWhy(): void
    {
        $this->bootPlugin(false);
        $html = ProductFormView::render(
            5,
            new ProductDetails(),
            [],
            [],
            0,
            null,
            '4',
            ProductStatus::Draft,
            [],
            $this->urls(),
            '',
            null,
            OperationResult::failure('incomplete_product', ['fields' => 'title، category، price']),
            null,
            false,
            false
        );
        self::assertStringContainsString('عنوان، دسته، قیمت', $html, 'field keys are translated for the reader');
        self::assertStringNotContainsString('value="submit_product"', $html);
        self::assertStringContainsString('پس از رفع موارد بالا', $html);
    }

    public function testALiveProductSaysItsEditsBecomeAProposal(): void
    {
        $this->bootPlugin(false);
        $html = ProductFormView::render(
            5,
            $this->product()->details,
            [],
            [['id' => 11, 'url' => 'https://example.test/11.jpg']],
            11,
            null,
            '1',
            ProductStatus::Published,
            ['gloves' => 'دستکش'],
            $this->urls(),
            '',
            null,
            null,
            null,
            false,
            true
        );
        self::assertStringContainsString('نسخه فعلی روی سایت می‌ماند', $html);
        self::assertStringContainsString('موجودی فوری اعمال می‌شود', $html);
        self::assertStringContainsString('در انتظار بررسی مدیر است', $html, 'the pending proposal is announced');
    }

    public function testTheCsvPreviewSaysNothingIsWrittenYetAndOffersTheSecondStep(): void
    {
        $this->bootPlugin(false);
        $report = [
            'rows' => [
                ['line' => 2, 'sku' => 'SKU-A', 'title' => 'محصول یک', 'action' => 'create', 'code' => 'csv_row_ok', 'context' => []],
                ['line' => 3, 'sku' => 'SKU-B', 'title' => 'محصول دو', 'action' => 'skip', 'code' => 'bad_stock', 'context' => []],
            ],
            'created' => 1,
            'updated' => 0,
            'skipped' => 1,
        ];
        $html = ProductCsvView::render($report, false, $this->urls(), '');
        self::assertStringContainsString('هنوز چیزی ذخیره نشده', $html);
        self::assertStringContainsString('apply_products_csv', $html);
        self::assertStringContainsString('موجودی نمی‌تواند منفی باشد', $html, 'a refused row says why in the vendor\'s words');
        self::assertStringContainsString('۳', $html, 'line numbers are Persian');

        $applied = ProductCsvView::render($report, true, $this->urls(), '');
        self::assertStringNotContainsString('apply_products_csv', $applied, 'an applied report cannot be applied again');
        self::assertStringContainsString('ورود CSV انجام شد', $applied);
    }

    public function testTheManagerPagesRefuseEveryoneWithoutTheirOwnCapability(): void
    {
        $this->bootPlugin(false);
        $identities = [
            'guest' => static fn () => State::logout(),
            'subscriber' => static fn () => State::loginAs(5, ['read']),
            'seller-like' => static fn () => State::loginAs(6, ['read', 'edit_products', 'dokandar']),
            'vendor-reviewer' => static fn () => State::loginAs(8, ['read', 'tmc_review_vendor']),
        ];
        $pages = [
            'tmc_review_products' => ProductReviewPage::class,
            'tmc_manage_spec_templates' => SpecTemplatesPage::class,
        ];
        foreach ($identities as $name => $login) {
            foreach ($pages as $cap => $class) {
                $login();
                $page = new $class(Bootstrap::container());
                $output = '';
                try {
                    $output = $this->capture(static fn () => $page->render());
                    self::fail("{$class} rendered for {$name}");
                } catch (WpDieException $e) {
                    self::assertSame(403, $e->args['response']);
                    self::assertStringNotContainsString($cap, $e->getMessage(), 'the refusal does not name the capability');
                }
                self::assertSame('', $output);
            }
        }
    }

}
