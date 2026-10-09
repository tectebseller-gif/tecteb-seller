<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
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
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $this->bootPlugin(false);
        Bootstrap::container()->get(MigrationRunner::class)->run();

        $clock = new SystemClock();
        (new DbVendorRepository($db, $clock))->upsertProfile(self::VENDOR, 'داروخانهٔ فرم', true, false);
        $this->products = Bootstrap::container()->get(ProductRepositoryInterface::class);

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
