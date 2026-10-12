<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0025BrandRegistry;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use Tecteb\Marketplace\Tests\Support\FakeBrandDirectory;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: what the REAL FORM does, from `$_POST` to the redirect.
 *
 * Both defects this file is about were invisible to a service-level test,
 * because neither is in the service:
 *
 *  - §1 lived in the FORM. `ProductFormView` sent `description_given=1` on
 *    every render and `ProductArea` turned that into an empty string, so
 *    opening a legacy product and saving its title asked for the shop's
 *    description to be deleted. `ManageProducts::save()` was given `''` and
 *    did exactly as it was told — correctly, which is why no test of it
 *    could see anything wrong.
 *  - §2 lived in the REDIRECT. `$productId === 0 => '1'` sent the first save
 *    of a new product back to the step it came from, so «ذخیره و ادامه» did
 *    not continue. A test of the service sees a created product and a success
 *    code and has no idea.
 *
 * So the request is a real one: `$_POST` as the rendered form would send it,
 * through `ProductArea::handle()`, and the assertions are on the stored row
 * and on the `Location` the vendor's browser would follow.
 */
final class ProductFormPostTest extends DatabaseTestCase
{
    private const VENDOR = 8401;

    private ProductRepositoryInterface $products;
    private FakeBrandDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        // A clean set of tables, not a shared one: `DatabaseTestCase` truncates
        // the options table and nothing else, so products left by another test
        // in this process answer `sku_taken` to every save here — a true
        // sentence about the previous test, reported as a failure of this one.
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            // AND THE BRAND REQUESTS. Left out, a request this file's own
            // tests record survives into the next one — and because the drop
            // restarts product ids at 1, it comes back attached to a DIFFERENT
            // product. Measured: «no request beside a refused save» failed
            // about a refusal that recorded nothing, holding up a request
            // another test had made for the product that used to be id 1.
            ...M0025BrandRegistry::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $this->bootPlugin(false);
        Bootstrap::container()->get(MigrationRunner::class)->run();

        $clock = new SystemClock();
        (new DbVendorRepository($db, $clock))->upsertProfile(self::VENDOR, 'داروخانهٔ فرم', true, false);
        $this->products = Bootstrap::container()->get(ProductRepositoryInterface::class);

        // A brand list with two approved names, so «typed a name that is in
        // the list» and «typed one that is not» are both reachable. Without
        // it `NullBrandDirectory` answers «there is no list», which is a third
        // case and not the one these tests are about.
        $this->directory = new FakeBrandDirectory('آریا طب', 'مدلاین');
        Bootstrap::container()->bind(BrandDirectoryInterface::class, fn () => $this->directory);

        State::loginAs(self::VENDOR, []);
    }

    // --------------------------------------- §1 the legacy long description

    /**
     * THE DEFECT: open a legacy product, change only the title, save. The long
     * description must still be `null` — «nobody has asked for anything» —
     * because `null` is what keeps WooCommerce's own text, the manager's edits
     * to it and their SEO where they are.
     *
     * The POST is the one the rendered form sends for such a product: an empty
     * `description` box, and `description_base=none` saying that nothing of
     * the vendor's was on screen.
     */
    public function testSavingTheTitleOfALegacyProductDoesNotAskForItsDescriptionToBeDeleted(): void
    {
        $productId = $this->legacyProduct();
        self::assertNull($this->storedLong($productId), 'the fixture must start as a row from before the field');

        $outcome = $this->post($this->formFields($productId, [
            'title' => 'عنوان تازه',
            'description' => '',
            'description_base' => 'none',
        ]));

        self::assertSame('product_saved', $outcome->code);
        self::assertSame('عنوان تازه', $this->products->find($productId)?->details->title);
        self::assertNull(
            $this->storedLong($productId),
            'an empty box over nothing is not a request to delete the shop description'
        );
    }

    /** The same for the short description, which is the other field on that step. */
    public function testSavingTheShortDescriptionOfALegacyProductLeavesTheLongOneAlone(): void
    {
        $productId = $this->legacyProduct();

        $outcome = $this->post($this->formFields($productId, [
            'short_description' => 'یک جملهٔ تازه',
            'description' => '',
            'description_base' => 'none',
        ]));

        self::assertSame('product_saved', $outcome->code);
        self::assertSame('یک جملهٔ تازه', $this->products->find($productId)?->details->shortDescription);
        self::assertNull($this->storedLong($productId));
    }

    /**
     * And the explicit clear really clears — the half that makes the half
     * above mean something.
     *
     * A form that simply ignored an empty box would pass every assertion in
     * the two tests above and would leave the vendor no way at all to ask for
     * the shop's description to go.
     */
    public function testTheExplicitClearOnALegacyProductIsObeyed(): void
    {
        $productId = $this->legacyProduct();

        $outcome = $this->post($this->formFields($productId, [
            'description' => '',
            'description_base' => 'none',
            'description_clear' => '1',
        ]));

        self::assertSame('product_saved', $outcome->code);
        self::assertSame(
            '',
            $this->storedLong($productId),
            'ticking the box IS the instruction, and it has to land'
        );
    }

    /** A value on screen, emptied: that needs no checkbox, and must clear. */
    public function testEmptyingABoxThatHadAValueClearsItWithoutAnyCheckbox(): void
    {
        $productId = $this->legacyProduct();
        $this->post($this->formFields($productId, [
            'description' => '<p>متن فروشنده</p>',
            'description_base' => 'none',
        ]));
        self::assertSame('<p>متن فروشنده</p>', $this->storedLong($productId));

        $this->post($this->formFields($productId, [
            'description' => '',
            'description_base' => 'value',
        ]));
        self::assertSame('', $this->storedLong($productId));
    }

    /**
     * A form from an older build — no base field at all — changes nothing.
     *
     * This is the mid-upgrade case: a page rendered by `alpha.41` posted into
     * `alpha.42`. «Not in play» has to be distinguishable from «empty», or an
     * upgrade would delete descriptions on its own.
     */
    public function testAFormThatDoesNotMentionTheFieldLeavesItExactlyAsItIs(): void
    {
        $productId = $this->legacyProduct();
        $this->post($this->formFields($productId, [
            'description' => '<p>متن فروشنده</p>',
            'description_base' => 'value',
        ]));

        $fields = $this->formFields($productId, ['title' => 'عنوان دیگر']);
        unset($fields['description'], $fields['description_base']);
        $this->post($fields);

        self::assertSame('<p>متن فروشنده</p>', $this->storedLong($productId));
        self::assertSame('عنوان دیگر', $this->products->find($productId)?->details->title);
    }

    // ------------------------------------------------- §2 the create redirect

    /**
     * «ذخیره و ادامه» on a new product has to continue.
     *
     * Measured on the redirect, because that is where the defect was: the
     * service created the product and reported success either way.
     */
    public function testCreatingAProductFromStepOneLandsOnStepTwo(): void
    {
        $outcome = $this->post($this->formFields(0, [
            'title' => 'محصول تازه',
            'step' => '1',
            'create_token' => 'tok-step',
        ]));

        self::assertSame('product_created', $outcome->code, 'the create itself must succeed');
        $madeId = $this->onlyProductId();
        self::assertStringContainsString('product=' . $madeId, $outcome->target);
        self::assertStringContainsString('step=2', $outcome->target, 'a successful create continues');
        self::assertStringNotContainsString('step=1', $outcome->target);
    }

    /** With a picture, the same — and the picture is on the draft. */
    public function testCreatingAProductWithAPictureAlsoLandsOnStepTwo(): void
    {
        $outcome = $this->post($this->formFields(0, [
            'title' => 'محصول با تصویر',
            'step' => '1',
            'create_token' => 'tok-image',
            'image_ids' => ['4601'],
            'main_image_id' => '4601',
        ]));

        self::assertSame('product_created', $outcome->code);
        self::assertStringContainsString('step=2', $outcome->target);
    }

    /**
     * The three things that must NOT advance, in one test, because each is a
     * reason the old unconditional «back to step 1» existed.
     */
    public function testASearchAReorderAndARefusalAllStayWhereTheyWere(): void
    {
        $productId = $this->legacyProduct();

        $searched = $this->post($this->formFields($productId, [
            'search_category' => '1',
            'category_q' => 'دستکش',
            'step' => '2',
        ]));
        self::assertStringContainsString('step=1', $searched->target, 'a category search goes to the picker');
        self::assertStringContainsString('cat_q=', $searched->target);

        $moved = $this->post($this->formFields($productId, [
            'move_image' => 'up:1',
            'step' => '3',
        ]));
        self::assertStringContainsString('step=3', $moved->target, 'a reorder stays on the step it was asked from');

        // A refusal: a create whose title is empty never becomes a product,
        // and the vendor must come back to the step that has the title on it.
        $refused = $this->post($this->formFields(0, [
            'title' => '',
            'step' => '1',
            'create_token' => 'tok-refused',
        ]));
        self::assertNotSame('product_created', $refused->code);
        self::assertStringContainsString('step=1', $refused->target);
    }

    // ----------------------------------------------- §2 the one brand field

    /**
     * THE FIELD IS ABSENT, SO NOTHING IS ASKED — and the brand stays.
     *
     * This is the upgrade guard, and it is the `alpha.42` defect in a new
     * place. An `alpha.50` form posts `brand_term` and NO `brand`: its picker
     * replaced the text box. If a page rendered before the swap is submitted
     * after it, reading the absent box as an empty one would read it as
     * «delete this product's brand» — a save of the title quietly clearing a
     * field nobody touched.
     */
    public function testAFormWithNoBrandFieldAtAllLeavesTheBrandAlone(): void
    {
        $productId = $this->brandedProduct('آریا طب');

        $fields = $this->formFields($productId, ['title' => 'عنوان تازه']);
        unset($fields['brand']);
        // The dialect the picker spoke, which this version no longer reads.
        $fields['brand_term'] = 'keep';
        $outcome = $this->post($fields);

        self::assertSame('product_saved', $outcome->code);
        self::assertSame('عنوان تازه', $this->products->find($productId)?->details->title);
        self::assertSame('آریا طب', $this->products->find($productId)?->details->brand);
    }

    /** And an empty box really clears it: the half that makes the half above mean something. */
    public function testAnEmptyBrandBoxIsObeyed(): void
    {
        $productId = $this->brandedProduct('آریا طب');

        $outcome = $this->post($this->formFields($productId, ['brand' => '']));

        self::assertSame('product_saved', $outcome->code);
        self::assertSame('', $this->products->find($productId)?->details->brand);
    }

    /**
     * TYPING AN APPROVED NAME STORES THE LIST'S SPELLING, not the typing.
     *
     * «آريا طب» with an Arabic yeh is the same brand as «آریا طب», and the
     * catalogue must not end up holding both — which is the whole reason the
     * list is the only source of an approved name.
     */
    public function testTypingAnApprovedBrandStoresTheApprovedSpelling(): void
    {
        $productId = $this->brandedProduct('');

        $outcome = $this->post($this->formFields($productId, ['brand' => 'آريا طب']));

        self::assertSame('product_saved', $outcome->code);
        self::assertSame('آریا طب', $this->products->find($productId)?->details->brand);
        self::assertSame(
            // Asked of `BrandName`, not written out: the key of «آریا طب» is
            // «اریاطب», because the alef-with-hamza folds.
            $this->directory->findByKey(BrandName::key('آریا طب'))?->termId,
            $this->products->brandTermIdOf($productId)
        );
    }

    /**
     * TYPING A NAME NOBODY HAS APPROVED SAVES THE PRODUCT, RECORDS A REQUEST,
     * LEAVES THE BRAND ALONE — AND GOES FORWARD.
     *
     * All four in one assertion block because all four are the change the
     * owner asked for: one field, the ordinary «ذخیره و ادامه», no second
     * button, and «تا تأیید، برند فعلی محصول حفظ شود». The redirect matters as
     * much as the rest: `alpha.50` sent a brand request back to the step it
     * came from, so the save did the saving and not the continuing.
     */
    public function testTypingAnUnapprovedBrandRecordsARequestAndContinues(): void
    {
        $productId = $this->brandedProduct('آریا طب');

        $outcome = $this->post($this->formFields($productId, ['brand' => 'سینا طب']));

        self::assertSame('brand_requested', $outcome->code);
        self::assertSame('آریا طب', $this->products->find($productId)?->details->brand, 'the brand did not move');
        $open = $this->proposals()->openFor($productId);
        self::assertNotNull($open, 'and the name was recorded');
        self::assertSame('سینا طب', $open->name);
        self::assertStringContainsString('step=2', $outcome->target, '«ذخیره و ادامه» continues');
    }

    /** The same POST twice — a double click — asks once. */
    public function testPostingTheSameUnapprovedBrandTwiceAsksOnce(): void
    {
        $productId = $this->brandedProduct('آریا طب');
        $this->post($this->formFields($productId, ['brand' => 'سینا طب']));
        $first = $this->proposals()->openFor($productId)?->id ?? 0;

        $again = $this->post($this->formFields($productId, ['brand' => 'سینا طب']));

        self::assertSame('brand_request_unchanged', $again->code);
        self::assertSame($first, $this->proposals()->openFor($productId)?->id);
        self::assertSame(1, (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_brand_proposals`'
        ));
    }

    /**
     * A NEW PRODUCT: the typed name is attached to the product that save made.
     *
     * «اگر محصول هنوز ساخته نشده، نام پیشنهادی در جریان عادی ایجاد محصول حفظ و
     * پس از ایجاد به همان محصول متصل شود». The request is recorded after the
     * create, so there is an id to attach it to — and only after the create
     * SUCCEEDED, because a request against a product that did not save is a
     * request for nothing.
     */
    public function testANewProductCarriesItsTypedBrandIntoARequest(): void
    {
        $outcome = $this->post($this->formFields(0, [
            'brand' => 'سینا طب',
            'sku' => 'SKU-NEW-BRAND',
            'create_token' => 'tok-brand',
        ]));

        self::assertSame('brand_requested', $outcome->code);
        $madeId = (int) ($outcome->context['product_id'] ?? 0);
        self::assertGreaterThan(0, $madeId);
        $open = $this->proposals()->openFor($madeId);
        self::assertNotNull($open, 'the request is attached to the product that was just created');
        self::assertSame('سینا طب', $open->name);
        self::assertSame('', $this->products->find($madeId)?->details->brand, 'and the product has no brand yet');
    }

    /**
     * A REFUSED SAVE HANDS THE TYPED NAME BACK.
     *
     * «ورودی پس از خطای اعتبارسنجی حفظ شود». The carried `details.brand` holds
     * the value the FIELD would take, which for an unapproved name is the
     * brand the product already has — so carrying only that would hand the
     * vendor a box with somebody else's name in it and their own typing gone.
     */
    public function testARefusedSaveCarriesTheTypedBrandBack(): void
    {
        $productId = $this->brandedProduct('آریا طب');

        // A REAL validation error, named. The first version of this test used
        // an empty title and «not product_saved» as the oracle — and an empty
        // title is NOT a refusal on an existing draft (`alpha.36` gave it a
        // display name), so the save succeeded, answered `brand_requested`,
        // and «not product_saved» accepted it. An unreachable purchase window
        // is refused by `PurchaseQuantity::validate()` for certain
        // (`alpha.46`), and the code is asserted rather than negated.
        $refused = $this->post($this->formFields($productId, [
            'min_purchase' => '10',
            'max_purchase' => '11',
            'purchase_step' => '4',
            'brand' => 'سینا طب',
        ]));

        self::assertSame('bad_quantity_unreachable', $refused->code);
        self::assertNull($this->proposals()->openFor($productId), 'no request beside a refused save');
        $carried = Bootstrap::container()->get(FlashStoreInterface::class)
            ->take('product_form_' . self::VENDOR);
        self::assertIsArray($carried, 'the refused save carried the form back');
        self::assertSame(
            'سینا طب',
            $carried['brand_typed'] ?? null,
            'what the vendor typed, not the brand the product still holds'
        );
        self::assertSame(
            'آریا طب',
            $carried['details']['brand'] ?? null,
            'and the field value is still the unchanged brand — the two are different facts'
        );
    }

    // ------------------------------------------------------------- the request

    /**
     * One real POST through the area router.
     *
     * `Request::capture()` reads the superglobals, which is exactly how a
     * WordPress request reaches this code, so the form's own field names are
     * what is under test rather than a hand-built argument list.
     */
    private function post(array $fields): VendorAreaOutcome
    {
        $_POST = $fields;
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $outcome = (new ProductArea(Bootstrap::container()))->handle(
            'save_product',
            Request::capture(),
            self::VENDOR,
            $this->urls()
        );
        self::assertNotNull($outcome, 'the router did not handle save_product at all');
        return $outcome;
    }

    /**
     * Every field the rendered form sends, with the given ones replaced.
     *
     * Written out rather than scraped from the view so that a change to the
     * view cannot silently stop this test from posting a field — but kept in
     * step with `ProductFormView` deliberately: `description_base` is here
     * because the form sends it.
     *
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function formFields(int $productId, array $override = []): array
    {
        $stored = $productId > 0 ? $this->products->find($productId) : null;
        $base = [
            'tmc_vendor_action' => 'save_product',
            'product_id' => (string) $productId,
            'step' => '1',
            'revision' => $stored?->rowVersion ?? '',
            'title' => $stored?->details->title ?? 'دستکش لاتکس',
            'type' => 'simple',
            'category' => $stored?->details->categoryKey ?? 'gloves',
            'brand' => $stored?->details->brand ?? '',
            'short_description' => $stored?->details->shortDescription ?? 'یک جمله',
            'price' => (string) ($stored?->details->priceMinor ?? 200000),
            'sale_price' => '',
            'sale_from' => '',
            'sale_to' => '',
            'sku' => $stored?->details->sku ?? 'SKU-FORM',
            'stock' => (string) ($stored?->details->stock ?? 5),
            'min_purchase' => '1',
            'max_purchase' => '',
            'weight_grams' => '0',
            'dimensions' => '',
            'tax_class' => '',
            'main_image_id' => '0',
        ];
        return array_merge($base, $override);
    }

    /**
     * A draft with a brand on it, and the brand list bound beside it.
     *
     * The list is a fake because the real one is WooCommerce's `product_brand`
     * taxonomy and this suite has no WooCommerce — and it is the fake that
     * keeps the ONE rule the tests above lean on honest: `findByKey()` treats
     * «آريا طب» and «آریا طب» as the same brand.
     */
    private function brandedProduct(string $brand): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                brand: $brand,
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-FORM',
                stock: 5
            ),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function proposals(): BrandProposalRepositoryInterface
    {
        return Bootstrap::container()->get(BrandProposalRepositoryInterface::class);
    }

    /** A row from before migration 23: a long description of `null`. */
    private function legacyProduct(): int
    {
        $id = $this->products->create(
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-FORM',
                stock: 5
            ),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $id);
        return $id;
    }

    private function storedLong(int $productId): ?string
    {
        return $this->products->find($productId)?->details->description;
    }

    private function onlyProductId(): int
    {
        $rows = $this->products->forVendor(self::VENDOR);
        self::assertCount(1, $rows, 'exactly one product should exist at this point');
        return $rows[0]->id;
    }

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
}
