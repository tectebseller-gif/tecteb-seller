<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\SpecTemplatesPage;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductBulkPreviewView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductConflictView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductCsvView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductFormView;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductListView;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
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

    /**
     * WHAT THIS PROVES: CSV is a disclosure beside «افزودن محصول», it opens with
     * no script, and the guidance beside it is read off the importer.
     *
     * Until `alpha.33` the block was a full card BELOW the list and below the
     * pager — on a shop with eighty products, three screens past the thing it
     * operates on, and the last thing on every page whether anybody wanted it
     * or not.
     */
    /**
     * WHAT THIS PROVES: the page is in the order the owner asked for, measured as
     * DOCUMENT ORDER rather than described.
     *
     * «بالای صفحه: نوار جست‌وجو و دکمهٔ افزودن محصول. سپس فیلتر وضعیت‌ها و اقدامات
     * گروهی. سپس محصولات و صفحه‌بندی. پایین همهٔ این‌ها: بخش جمع‌شوندهٔ ورود و
     * خروج گروهی، پیش‌فرض بسته.»
     *
     * `alpha.33` put the CSV disclosure at the top beside «افزودن محصول» and the
     * search below it, so opening a tool used twice a year pushed the field used
     * every day — and every product — down the page. Asserted as five positions
     * in one string, because «is it above the filters» is a claim about order and
     * only order can answer it.
     */
    public function testThePageRunsSearchThenFiltersThenProductsThenTheCsvFold(): void
    {
        $this->bootPlugin(false);
        $html = $this->list();

        $search = strpos($html, 'id="f-product-search"');
        $add = strpos($html, 'افزودن محصول');
        $tabs = strpos($html, 'class="tv-tabs"');
        $bulk = strpos($html, 'id="tmc-bulk"');
        $products = strpos($html, 'class="tv-products"');
        $csv = strpos($html, 'tv-bulk-csv__summary');
        foreach (['search' => $search, 'add' => $add, 'tabs' => $tabs, 'bulk' => $bulk,
                  'products' => $products, 'csv' => $csv] as $name => $at) {
            self::assertIsInt($at, $name . ' is not on the page at all');
        }

        self::assertLessThan($tabs, $search, 'the search belongs above the filters');
        self::assertLessThan($tabs, $add, 'and so does the add button');
        self::assertLessThan($bulk, $tabs, 'filters, then bulk actions');
        self::assertLessThan($products, $bulk, 'bulk actions, then the products');
        self::assertGreaterThan($products, $csv, 'and the CSV fold is below all of it');

        // Both halves of the CSV tool are at the bottom, not just its button:
        // «دکمه و محتوای CSV هر دو پایین باشند». The body is inside the same
        // `<details>`, so its position is the summary's — asserted anyway,
        // because that is the property the owner named.
        self::assertGreaterThan($products, strpos($html, 'tv-bulk-csv__body'));

        // Closed by default, and closed by ABSENCE of `open` rather than by a
        // script: a `<details>` with no `open` attribute is shut in every browser
        // and shut with JavaScript off.
        self::assertStringContainsString('<details class="tv-bulk-csv tv-bulk-csv--foot"><summary', $html);
        self::assertStringNotContainsString('<details class="tv-bulk-csv tv-bulk-csv--foot" open', $html);
        self::assertStringContainsString('ورود و خروج گروهی محصولات', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    /**
     * WHAT THIS PROVES: an empty list still offers the CSV tools — «در حالت فهرست
     * خالی نیز ابزار CSV برای کاربران مجاز در دسترس بماند».
     *
     * A shop with no products is the shop most likely to want to bring a file in,
     * and `alpha.33` showed the tools there only because they happened to sit
     * above the empty state. Now they are below it, by decision.
     */
    public function testTheCsvToolsAreStillThereWhenThereAreNoProductsYet(): void
    {
        $this->bootPlugin(false);
        $html = $this->list(products: []);

        self::assertStringContainsString('هنوز محصولی ثبت نکرده‌اید', $html, 'the empty state itself');
        self::assertStringContainsString('ورود و خروج گروهی محصولات', $html);
        self::assertStringContainsString('name="products_csv"', $html, 'and the file chooser with it');
        self::assertGreaterThan(
            strpos($html, 'هنوز محصولی ثبت نکرده‌اید'),
            strpos($html, 'tv-bulk-csv__summary'),
            'below the empty state, in the same place it is below a list'
        );
    }

    /**
     * WHAT THIS PROVES: the columns named beside the file chooser are the
     * columns the importer actually reads.
     *
     * Built from `ProductCsv::COLUMNS`, so the guidance cannot drift from the
     * file the exporter writes — and `id`/`status` are named as read-only
     * rather than left out, because a vendor who edits `status` expecting to
     * publish has been misled by silence.
     */
    public function testTheGuidanceNamesEveryEditableColumnAndBothReadOnlyOnes(): void
    {
        $this->bootPlugin(false);
        $html = $this->list();

        foreach (ProductCsv::COLUMNS as $column) {
            self::assertStringContainsString(
                ProductMessages::csvColumn($column),
                $html,
                'the editable column ' . $column . ' is not named'
            );
        }
        self::assertStringContainsString('فقط خواندنی:', $html);
        self::assertStringContainsString('ورود فایل هیچ محصولی را منتشر نمی‌کند.', $html);
        self::assertStringContainsString('۵۰۰', $html, 'the real row limit, in Persian digits');
        self::assertStringContainsString(ProductCsv::SPEC_PREFIX, $html);
    }

    /**
     * WHAT THIS PROVES: moving the forms did not change what they do.
     *
     * Same two actions, same field name, same nonce, and still two steps — the
     * first button previews and the word «اعمال» is nowhere on this page,
     * because applying is a separate, explicit action on the preview screen.
     */
    public function testTheMovedFormsStillPostTheSameThingsAndStillPreviewFirst(): void
    {
        $this->bootPlugin(false);
        $html = $this->list('<input type="hidden" name="tmc_vendor_nonce" value="n">');

        self::assertStringContainsString('value="export_products"', $html);
        self::assertStringContainsString('value="import_products"', $html);
        self::assertStringContainsString('name="products_csv"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        // Sliced from the fold's own opening tag to its own closing one. The
        // first version searched for `</details>` from position zero, and when
        // the class name changed `strpos` answered `false`, cast to 0, and the
        // «slice» became the whole page — so it counted the bulk bar's nonce too
        // and reported four. A slice whose start is not asserted is not a slice.
        $open = strpos($html, '<details class="tv-bulk-csv tv-bulk-csv--foot">');
        self::assertIsInt($open, 'the CSV fold is not on the page under that class');
        $close = strpos($html, '</details>', $open);
        self::assertIsInt($close);
        $fold = substr($html, $open, $close - $open);
        self::assertSame(2, substr_count($fold, 'tmc_vendor_nonce'), 'both CSV forms carry the nonce');
        self::assertStringContainsString('پیش‌نمایش ورود', $html);
        self::assertStringNotContainsString('value="apply_products_csv"', $html);
    }

    public function testAReadOnlyViewerIsToldWhyTheyCannotBringAFileBack(): void
    {
        $this->bootPlugin(false);
        $html = $this->list(mayEdit: false);

        self::assertStringContainsString('ورود و خروج گروهی محصولات', $html, 'they may still take the file');
        self::assertStringContainsString('export_products', $html);
        self::assertStringNotContainsString('import_products', $html);
        self::assertStringContainsString('ورود فایل به دسترسی ویرایش محصول نیاز دارد.', $html);
    }

    /** @param ?list<\Tecteb\Marketplace\Modules\Product\Domain\Product> $products */
    private function list(string $nonce = '', bool $mayEdit = true, ?array $products = null): string
    {
        return ProductListView::render(
            $products ?? [$this->product()],
            ['draft' => 1],
            '',
            1,
            1,
            $this->urls(),
            $nonce,
            null,
            $mayEdit,
            false
        );
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

    /**
     * The long description has to make the same round trip the other fields
     * make — and it is the one field where the carry cannot be a plain string,
     * because `null` («never written») and `''` («cleared») are two different
     * instructions and a hidden input can only send one of them.
     *
     * So the test is in two halves, and the second is what makes the first
     * mean anything: a value travels WITH the flag that says the control was
     * shown, and `null` travels as nothing at all. A form that always sent an
     * empty box would pass the first half and silently clear every legacy
     * product the moment its owner saved the price step.
     */
    public function testTheLongDescriptionTravelsWithEveryStepAndNullTravelsAsNothing(): void
    {
        $this->bootPlugin(false);
        $written = $this->product()->details->with(['description' => '<p>متن کامل</p>']);
        $legacy = $this->product()->details;
        self::assertNull($legacy->description, 'the fixture is a product from before migration 23');

        foreach (['2', '3', '4'] as $step) {
            $html = $this->renderStep($step, $written);
            self::assertStringContainsString(
                'name="description" value="&lt;p&gt;متن کامل&lt;/p&gt;"',
                $html,
                "step {$step} carries the long description"
            );
            self::assertStringContainsString(
                'name="description_base" value="value"',
                $html,
                "step {$step} says the value it carries was on screen"
            );

            $blank = $this->renderStep($step, $legacy);
            self::assertStringNotContainsString(
                'name="description"',
                $blank,
                "step {$step} sends nothing for a product that never had one"
            );
            self::assertStringNotContainsString('name="description_base"', $blank);
        }
    }

    private function renderStep(string $step, ProductDetails $details): string
    {
        return ProductFormView::render(
            5,
            $details,
            [],
            [],
            0,
            null,
            $step,
            ProductStatus::Draft,
            ['gloves' => 'دستکش'],
            $this->urls(),
            ''
        );
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

    /**
     * The limit appears on the control, in the hint and in the refusal — and
     * it is the same number in all three, because it comes from one place.
     */
    public function testTheFileChooserCarriesTheLimitItWillBeJudgedBy(): void
    {
        $html = ProductFormView::render(
            5,
            new ProductDetails(title: 'دستکش'),
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
            false,
            [],
            '',
            '',
            '',
            '',
            '',
            ProductImagePolicy::MAX_BYTES
        );

        self::assertStringContainsString('data-max-bytes="3145728"', $html);
        self::assertStringContainsString('data-allowed-mime="image/jpeg,image/png,image/webp"', $html);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
        // Said in words too: a limit only a script can read is not a limit the
        // vendor was told about.
        self::assertStringContainsString('تا ۳ مگابایت', $html);
        // The pre-flight has somewhere to speak, and it is a live region so a
        // screen reader hears it without the focus moving.
        self::assertStringContainsString('id="f-product-image-problem"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('aria-describedby="f-product-image-hint"', $html);
    }

    public function testAHostThatAllowsLessThanWeDoIsTheNumberTheVendorSees(): void
    {
        $html = ProductFormView::render(
            5,
            new ProductDetails(title: 'دستکش'),
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
            false,
            [],
            '',
            '',
            '',
            '',
            '',
            2097152                                   // the host stops at 2 MB
        );

        self::assertStringContainsString('data-max-bytes="2097152"', $html);
        self::assertStringContainsString('تا ۲ مگابایت', $html);
        self::assertStringNotContainsString('تا ۳ مگابایت', $html, 'promising 3 on a 2 MB host is an unwinnable retry');
    }

    /**
     * Every refusal the policy can produce has a Persian sentence — and the
     * list is read from the policy's own source, so a code added without a
     * sentence fails here rather than reaching a vendor as a bare key.
     */
    public function testEveryUploadRefusalTheServerCanProduceIsSaidInPersian(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Product/Domain/ProductImagePolicy.php'
        );
        preg_match_all("/return '([a-z_]+)';/", $source, $matches);
        $codes = array_values(array_filter(array_unique($matches[1])));

        self::assertNotEmpty($codes);
        self::assertContains('image_too_large', $codes, 'the extraction still finds the codes');
        foreach ($codes as $code) {
            $text = ProductMessages::notice($code, ['saved' => 1, 'max_mb' => 3]);
            self::assertNotNull($text, "«{$code}» would reach the vendor as a bare key");
            self::assertStringNotContainsString($code, $text, 'the code itself is not the message');
            // Not merely named: every one of them says what to do next, which
            // is the whole difference between a message and a dead end.
            // A Persian imperative addressed to the vendor ends «…ید», so this
            // asks «is the last thing said something to DO», not «does it use
            // one particular verb».
            self::assertMatchesRegularExpression('/ید\.$/u', $text, "«{$code}» does not end with an instruction");
        }
    }

    /**
     * The picture failed and the text did not. Both facts are in the sentence,
     * in that order — the save is what the vendor is worried about.
     */
    public function testAFailedUploadSaysTheRestWasSavedBeforeItSaysWhatWentWrong(): void
    {
        $text = (string) ProductMessages::notice('image_too_large', ['saved' => 1, 'max_mb' => 2]);

        self::assertStringContainsString('بقیهٔ فرم ذخیره شد', $text);
        self::assertStringContainsString('۲ مگابایت', $text, 'the number quoted is the one this installation enforces');
        self::assertLessThan(
            mb_strpos($text, '۲ مگابایت'),
            mb_strpos($text, 'بقیهٔ فرم ذخیره شد'),
            'the reassurance comes first'
        );

        // Without a save behind it — an upload on its own — the sentence does
        // not claim one happened.
        $alone = (string) ProductMessages::notice('image_too_large');
        self::assertStringNotContainsString('بقیهٔ فرم ذخیره شد', $alone);
        self::assertStringContainsString('۳ مگابایت', $alone, 'and falls back to our own ceiling');
    }

    public function testAnUploadRefusalIsPaintedAsAProblemNotAsASuccess(): void
    {
        foreach (['image_too_large', 'image_mime_not_allowed', 'transfer_failed', 'empty_file', 'no_file'] as $code) {
            self::assertTrue(ProductMessages::isErrorNotice($code), "«{$code}» must not render as a success");
        }
        self::assertFalse(ProductMessages::isErrorNotice('image_uploaded'));
    }

    // --------------------------------------------------- bulk action preview

    public function testTheBulkBarOffersBothPreviewAndRunWithoutJavaScript(): void
    {
        $html = ProductListView::render(
            [$this->product()],
            ['draft' => 1],
            '',
            1,
            1,
            $this->urls(),
            '<input type="hidden" name="n" value="x">'
        );

        // Two submits in one form, told apart by `name`/`value` — the plain
        // HTML mechanism, so the page needs no script to offer both.
        self::assertStringContainsString('name="tmc_vendor_action" value="preview_bulk_products"', $html);
        self::assertStringContainsString('name="tmc_vendor_action" value="bulk_products"', $html);
        self::assertStringNotContainsString(
            '<input type="hidden" name="tmc_vendor_action" value="bulk_products">',
            $html,
            'a hidden action would override whichever button was pressed'
        );
        self::assertStringContainsString('پیش‌نمایش نتیجه', $html);
    }

    public function testThePreviewNamesEveryRowAndWhatWouldHappenToIt(): void
    {
        $html = ProductBulkPreviewView::render(
            [
                'action' => 'submit',
                'rows' => [
                    ['product_id' => 5, 'ok' => true, 'code' => 'product_submitted', 'title' => 'دستکش لاتکس', 'from' => 'draft', 'to' => 'submitted'],
                    ['product_id' => 6, 'ok' => false, 'code' => 'missing_image', 'title' => 'ماسک سه‌لایه', 'from' => 'draft', 'to' => ''],
                ],
                'ok' => 1,
                'failed' => 1,
            ],
            $this->urls(),
            '<input type="hidden" name="n" value="x">'
        );

        self::assertStringContainsString('دستکش لاتکس', $html);
        self::assertStringContainsString('ماسک سه‌لایه', $html);
        self::assertStringContainsString('انجام می‌شود', $html);
        self::assertStringContainsString('انجام نمی‌شود', $html);
        // The refused row carries its own reason, not a count.
        self::assertStringContainsString('دست‌کم یک تصویر لازم است', $html);
        self::assertStringContainsString('هنوز چیزی انجام نشده', $html);
        // And it says out loud that this is a forecast.
        self::assertStringContainsString('پیش‌بینی است، نه تضمین', $html);
    }

    public function testConfirmingThePreviewPostsTheOrdinaryBulkActionWithTheSameRows(): void
    {
        $html = ProductBulkPreviewView::render(
            [
                'action' => 'archive',
                'rows' => [
                    ['product_id' => 5, 'ok' => true, 'code' => 'product_archived_ok', 'title' => 'الف', 'from' => 'draft', 'to' => 'archived'],
                    ['product_id' => 6, 'ok' => false, 'code' => 'invalid_transition', 'title' => 'ب', 'from' => 'archived', 'to' => 'archived'],
                ],
                'ok' => 1,
                'failed' => 1,
            ],
            $this->urls(),
            '<input type="hidden" name="n" value="x">'
        );

        // Not an «apply the preview» path of its own: the same action the list
        // posts, so there is one write path and it cannot drift.
        self::assertStringContainsString('name="tmc_vendor_action" value="bulk_products"', $html);
        self::assertStringContainsString('name="bulk_action" value="archive"', $html);
        // Every previewed row travels, including the one forecast to fail:
        // dropping it silently would make the after-report disagree with the
        // selection the vendor confirmed.
        self::assertStringContainsString('name="selected[]" value="5"', $html);
        self::assertStringContainsString('name="selected[]" value="6"', $html);
        // A preview whose only control commits it is not a preview.
        self::assertStringContainsString('بازگشت بدون اجرا', $html);
    }

    public function testThePreviewOfAnEmptySelectionOffersNothingToConfirm(): void
    {
        $html = ProductBulkPreviewView::render(
            ['action' => 'submit', 'rows' => [], 'ok' => 0, 'failed' => 0],
            $this->urls(),
            '<input type="hidden" name="n" value="x">'
        );

        self::assertStringNotContainsString('name="selected[]"', $html);
        self::assertStringNotContainsString('value="bulk_products"', $html, 'nothing to confirm, so no confirm form');
        self::assertStringContainsString('هیچ موردی انتخاب نشده بود', $html);
    }

    public function testAPreviewIsNotPaintedAsAFailure(): void
    {
        // «هنوز چیزی انجام نشده» in a red box reads as «چیزی خراب شد».
        self::assertFalse(ProductMessages::isErrorNotice('bulk_previewed'));
    }

    // ------------------------------- a refused save, and the way out of it

    public function testAFormWithNoStampIsToldWhatHappenedAndWhatToDo(): void
    {
        $text = (string) ProductMessages::notice('revision_missing', ['current_revision' => '7']);

        // The three things the vendor needs, in the order they need them.
        self::assertStringContainsString('این ذخیره انجام نشد', $text, 'it did not happen');
        self::assertStringContainsString('مقدارهایی که نوشته‌اید همین‌جا مانده‌اند', $text, 'nothing was lost');
        self::assertStringContainsString('دوباره ذخیره کنید', $text, 'and this is the way out');
        self::assertTrue(ProductMessages::isErrorNotice('revision_missing'));
    }

    /**
     * «تفاوت را ببینید» has to have something to look at.
     *
     * The older wording told the vendor to open a second tab and compare by
     * eye. A refusal nobody can act on is a refusal that gets pressed through.
     */
    public function testTheRefusalNamesTheFieldsThatActuallyDiffer(): void
    {
        $typed = new ProductDetails(title: 'دستکش نیتریل', categoryKey: 'gloves', priceMinor: 300000, sku: 'SKU-1', stock: 4);
        $stored = new ProductDetails(title: 'دستکش لاتکس', categoryKey: 'gloves', priceMinor: 200000, sku: 'SKU-1', stock: 4);

        $html = ProductConflictView::render($typed, $stored);

        self::assertStringContainsString('دستکش لاتکس', $html, 'what is on the row now');
        self::assertStringContainsString('دستکش نیتریل', $html, 'and what they typed');
        // The thousands separator is the one the rest of the plugin already
        // uses — a plain comma after the digits are converted. Inventing
        // «٬» here would make this one table read differently from the product
        // list beside it.
        self::assertStringContainsString('۳۰۰,۰۰۰', $html, 'prices are compared, in Persian digits');
        self::assertStringContainsString('۲۰۰,۰۰۰', $html);
        // Only what differs. A table repeating the unchanged SKU and stock
        // would bury the two rows it was built to show.
        self::assertStringNotContainsString('SKU-1', $html, 'an identical field is not listed');
        self::assertStringContainsString('۲ فیلد', $html, 'and it says how many differ');
        // Scrollable regions are reachable by keyboard — the same rule the
        // finance history table was fixed for.
        self::assertStringContainsString('tabindex="0"', $html);
    }

    public function testWhenNothingDiffersItSaysSoRatherThanShowingAnEmptyTable(): void
    {
        $same = new ProductDetails(title: 'دستکش لاتکس', categoryKey: 'gloves', priceMinor: 200000, sku: 'SKU-1', stock: 4);

        $html = ProductConflictView::render($same, $same);

        self::assertStringNotContainsString('<table', $html);
        self::assertStringContainsString('فرقی ندارند', $html);
    }

    public function testTheComparisonIsRenderedOnlyForTheRefusalsThatAskForIt(): void
    {
        $typed = new ProductDetails(title: 'تایپ‌شده', categoryKey: 'gloves', priceMinor: 300000);
        $stored = new ProductDetails(title: 'ثبت‌شده', categoryKey: 'gloves', priceMinor: 200000);

        $withComparison = $this->renderFormWithNotice('revision_missing', $typed, $stored);
        self::assertStringContainsString('ثبت‌شده', $withComparison, 'the stored value is shown');

        // An ordinary refusal — a missing price, say — has nothing to compare,
        // and rendering a diff table under it would be noise.
        $without = $this->renderFormWithNotice('bad_price', $typed, null);
        self::assertStringNotContainsString('tv-conflict', $without);
    }

    /**
     * The acceptance test for §4: two independent boxes, both usable with no
     * JavaScript at all.
     *
     * «بدون جاوااسکریپت هم هر دو قابل استفاده باشند» is not answered by «the
     * markup is there»: a tab strip built from a script would render both
     * boxes too and then hide one with `display:none`, and a hidden textarea
     * still posts. What makes this work without a script is that the panel on
     * top is chosen by a RADIO the browser itself toggles — so the assertions
     * are that both controls exist, that they are in the same form, that the
     * switch is markup and not a handler, and that this fieldset contains no
     * script at all.
     */
    public function testBothDescriptionBoxesAreInTheFormAndTheTabSwitchNeedsNoScript(): void
    {
        $this->bootPlugin(false);
        $html = $this->renderFormWithNotice(
            '',
            new ProductDetails(shortDescription: 'یک جمله', description: '<p>متن کامل</p>'),
            null
        );

        self::assertSame(
            1,
            substr_count($html, 'id="f-short_description" name="short_description"'),
            'one short box'
        );
        self::assertSame(
            1,
            substr_count($html, 'id="f-description" name="description"'),
            'one long box, and not a second short one'
        );
        self::assertStringContainsString(
            'name="description_base" value="value"',
            $html,
            'the form states that it was SHOWING a value, so emptying the box is an instruction'
        );
        self::assertStringNotContainsString(
            'name="description_clear"',
            $html,
            'and offers no second way to say it: the empty box IS the way'
        );
        self::assertStringContainsString('یک جمله', $html);
        self::assertStringContainsString('&lt;p&gt;متن کامل&lt;/p&gt;', $html, 'the stored markup is shown as text to edit, not run');

        // The switch: two radios of one name and two labels pointing at them.
        self::assertSame(2, substr_count($html, 'name="tmc_desc_tab"'));
        self::assertStringContainsString('for="tmc-desc-short"', $html);
        self::assertStringContainsString('for="tmc-desc-long"', $html);
        self::assertStringNotContainsString('<script', $html, 'nothing on this page is a script');
        self::assertStringNotContainsString('onclick', $html, 'and the tabs are not handlers');

        // Both boxes are inside the one form that «ذخیره و ادامه» posts, so a
        // browser with scripting off still sends both values in one request.
        $form = substr($html, (int) strpos($html, '<form'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));
        self::assertStringContainsString('name="short_description"', $form);
        self::assertStringContainsString('name="description"', $form);
    }

    /**
     * A product from before migration 23 has `null`, and the box has to be
     * empty rather than carrying the word «null» or the projector's generated
     * text — which would make the vendor's first save overwrite WooCommerce's
     * own description with something nobody typed.
     */
    public function testALegacyProductGetsAnEmptyLongBoxAndNotAGeneratedOne(): void
    {
        $this->bootPlugin(false);
        $html = $this->renderFormWithNotice('', new ProductDetails(shortDescription: 'یک جمله'), null);

        $box = substr($html, (int) strpos($html, 'name="description"'));
        $box = substr($box, 0, (int) strpos($box, '</textarea>'));
        self::assertStringNotContainsString('یک جمله', $box, 'the short text is not copied into the long box');
        self::assertStringNotContainsString('null', $box);
    }

    /**
     * §1 of `alpha.42`, on the form side: a legacy product's empty box is not
     * an instruction, and the only way to make it one is in plain sight.
     *
     * `alpha.41` sent `description_given=1` on every render, so this form —
     * which has nothing of the vendor's to show, because the row is `null` —
     * posted an empty string. `''` is «delete it». Opening the form and
     * saving the title asked for the shop's description to be deleted.
     */
    public function testALegacyProductSaysNothingWasOnScreenAndOffersTheClearInPlainSight(): void
    {
        $this->bootPlugin(false);
        $html = $this->renderFormWithNotice('', new ProductDetails(shortDescription: 'یک جمله'), null);

        self::assertStringContainsString(
            'name="description_base" value="none"',
            $html,
            'the form states that it was showing NOTHING, so an empty box is not an instruction'
        );
        self::assertStringNotContainsString('name="description_given"', $html, 'the flag that could not tell them apart is gone');

        // The one way to reach the shop's own text, and it is a plain
        // checkbox: no JavaScript, and unticked, because the default has to
        // be the answer that changes nothing.
        self::assertStringContainsString('name="description_clear"', $html);
        self::assertStringNotContainsString('name="description_clear" value="1" checked', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('خالی گذاشتن جعبهٔ بالا آن متن را پاک نمی‌کند', $html);
    }

    private function renderFormWithNotice(string $code, ProductDetails $typed, ?ProductDetails $stored): string
    {
        return ProductFormView::render(
            5,
            $typed,
            [],
            [],
            0,
            null,
            '1',
            ProductStatus::Draft,
            ['gloves' => 'دستکش'],
            $this->urls(),
            '',
            VendorNotice::of($code),
            null,
            null,
            false,
            false,
            [],
            '9',
            '',
            '',
            '',
            '',
            ProductImagePolicy::MAX_BYTES,
            $stored
        );
    }
}
