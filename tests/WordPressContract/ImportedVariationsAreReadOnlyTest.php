<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcStorefrontVariationReader;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Presentation\VariationsView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use Tecteb\Marketplace\Tests\Support\NoSpecTemplates;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: reading an imported variable product shows its REAL
 * combinations and changes nothing about them.
 *
 * ⛔ WHY NO TEST EXISTED. Until `alpha.105` the shared stub had no
 * `WC_Product_Variable` and no `WC_Product_Variation`, and
 * `WooCommerceProjector::existingVariationIds()` opens with
 * `if (!$parent instanceof \WC_Product_Variable) return [];` — so on every
 * build in this repository that guard was true and the whole variable path
 * was outside every suite. `alpha.43` recorded the missing class; what it did
 * not record is that the missing class made a panel which cannot read a
 * variable product look correct (`alpha.59`, one layer up).
 *
 * THE FIXTURE IS SYNTHETIC AND SAYS SO. Parent 7700 with children 7701/7702/
 * 7703 — deliberately NOT the owner's #11955/#11965, which are rows on a live
 * site this repository has no business reaching. What is copied from the real
 * product is its SHAPE: three bundle sizes, three different prices, one child
 * that WooCommerce does not count stock for, and no `_tmc_variation_id` stamp
 * on any of them, which is exactly what «imported» means here.
 */
final class ImportedVariationsAreReadOnlyTest extends ContractTestCase
{
    private const VENDOR = 102;
    private const PARENT = 7700;
    private const PRODUCT_ROW = 189;

    /** @var array<int,array{sku:string,price:string,sale:string,qty:?int,manage:bool,status:string,image:int,attrs:array<string,string>}> */
    private const CHILDREN = [
        7701 => ['sku' => 'BPI-50', 'price' => '2600000', 'sale' => '', 'qty' => 8, 'manage' => true, 'status' => 'publish', 'image' => 501, 'attrs' => ['بسته' => '۵۰ عددی']],
        7702 => ['sku' => 'BPI-100', 'price' => '5187400', 'sale' => '', 'qty' => 3, 'manage' => true, 'status' => 'publish', 'image' => 502, 'attrs' => ['بسته' => '۱۰۰ عددی']],
        7703 => ['sku' => 'BPI-200', 'price' => '9900000', 'sale' => '8900000', 'qty' => null, 'manage' => false, 'status' => 'publish', 'image' => 0, 'attrs' => ['بسته' => '۲۰۰ عددی']],
    ];

    /**
     * ⛔ THE SCREEN THE OWNER PHOTOGRAPHED, and the three facts it got wrong.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheShopsCombinationsAreReadWithTheirRealIdsPricesAndStock(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();

        $set = $this->reader()->setFor(self::PARENT);

        self::assertTrue($set->isRead(), 'state: ' . $set->state);
        self::assertTrue($set->isComplete(), 'every child the shop listed was read');
        self::assertSame(3, $set->count());
        self::assertSame([7701, 7702, 7703], $set->wcVariationIds());
        // The cheapest a buyer could pay, and the dearest — the sale price on
        // the third child counts, because that is what a buyer pays.
        self::assertSame(2600000, $set->minPriceMinor());
        self::assertSame(8900000, $set->maxPriceMinor());
        self::assertSame(3, $set->buyableCount());
        // ⛔ NOT A ZERO. One child leaves stock to WooCommerce, so there is no
        // total — and inventing one by reading «not managed» as nought is the
        // defect this round closed.
        self::assertNull($set->totalStock());
        self::assertSame(['بسته' => ['۵۰ عددی', '۱۰۰ عددی', '۲۰۰ عددی']], $set->axes());
    }

    /** ⛔ AND IT WROTE NOTHING: not one setter, not one meta row. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadingTheShopChangesNothingAboutIt(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        $before = $this->snapshot();

        $this->reader()->setFor(self::PARENT);
        $this->reader()->rangesFor([self::PARENT]);
        // Twice, because an idempotency claim that is only tested once is a
        // claim about the first call.
        $this->reader()->setFor(self::PARENT);

        self::assertSame($before, $this->snapshot(), 'nothing the shop holds may move');
        foreach ([self::PARENT, ...array_keys(self::CHILDREN)] as $id) {
            $product = \wc_get_product($id);
            self::assertInstanceOf(\WC_Product::class, $product);
            self::assertSame([], $product->wrote, "no setter may be called on {$id}");
        }
    }

    /**
     * The panel prints the real combinations, and the sentence that sent the
     * vendor looking for a way to rebuild them is gone.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePanelShowsTheCombinationsInsteadOfSayingThereAreNone(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();

        $html = VariationsView::render(
            self::PRODUCT_ROW,
            [],
            [],
            [],
            $this->urls(),
            '<input type="hidden" name="_wpnonce" value="x">',
            $this->reader()->setFor(self::PARENT)
        );

        self::assertStringNotContainsString('هنوز ویژگی‌ای تعریف نشده است', $html);
        foreach (self::CHILDREN as $id => $child) {
            self::assertStringContainsString((string) $id, $this->asciiDigits($html), "variation {$id} must be named");
            self::assertStringContainsString($child['sku'], $html);
        }
        self::assertStringContainsString('۵۰ عددی', $html);
        self::assertStringContainsString('۱۰۰ عددی', $html);
        self::assertStringContainsString('۲۰۰ عددی', $html);

        // ⛔ **THIS ASSERTION WAS REWRITTEN, NOT RELAXED, AND THE OWNER MOVED
        // IT.** Until §3 of the November instruction it read
        // `assertStringNotContainsString('<form', $html)` — «there is nothing
        // on this panel a press could write» — which was the right rule while
        // the only door into a variation was `ManageVariations`, whose writes
        // would have produced a second set of combinations beside
        // WooCommerce's own.
        //
        // §3 asks for the other half: «فروشنده بتواند قیمت اصلی، قیمت حراج و
        // موجودی تنوع‌های واقعی را ویرایش کند». So the panel now offers exactly
        // two forms per combination, and what this test pins is that the set
        // is EXACTLY those two — because the thing the old assertion was
        // protecting is still true and still needs a guard: a create, delete
        // or attribute form on this branch would be the duplicate
        // `alpha.105` closed.
        self::assertStringContainsString('save_shop_variation_prices', $html);
        self::assertStringContainsString('save_shop_variation_stock', $html);
        foreach (array_keys(self::CHILDREN) as $id) {
            self::assertStringContainsString(
                'name="variation_id" value="' . $id . '"',
                $html,
                "variation {$id} must be editable by its REAL id"
            );
        }
        // The five verbs that write OUR tables stay unreachable from here.
        foreach ([
            'value="save_variation"',
            'value="save_variation_stock"',
            'value="delete_variation"',
            'value="save_attribute"',
            'value="delete_attribute"',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $html,
                $forbidden . ' would make a SECOND set of combinations beside the shop\'s'
            );
        }
        // Two forms per combination and not one more.
        self::assertSame(
            2 * count(self::CHILDREN),
            substr_count($html, '<form'),
            'exactly the price form and the stock form, for each combination'
        );

        // ⛔ AND EVERY FORM CARRIES WHAT THIS RENDER SHOWED, which is what
        // lets the save refuse over a figure that moved since (`alpha.49`).
        // The 200-pack keeps no number of its own, so its `seen_stock` is
        // EMPTY — «به‌ازای والد» — and that is a different fact from nought.
        self::assertStringContainsString('name="seen_regular" value="2600000"', $html);
        self::assertStringContainsString('name="seen_sale" value="8900000"', $html);
        self::assertStringContainsString('name="seen_exponent" value="0"', $html);
        self::assertStringContainsString('name="seen_stock" value="8"', $html);
        self::assertStringContainsString('name="seen_stock" value=""', $html);
    }

    /**
     * ⛔ AND RENDERING THAT PANEL STILL WRITES NOTHING.
     *
     * The forms are new; the no-write property is not. This is the same
     * question `testTheReaderCallsNoSetterOnAnyChild()` asks of the reader,
     * asked of the RENDER — because a panel that printed an editable value by
     * loading a product and saving it would be a write nobody asked for, and
     * the two tests would otherwise both be about the reader.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRenderingTheEditablePanelCallsNoSetterOnAnyChild(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();

        VariationsView::render(
            self::PRODUCT_ROW,
            [],
            [],
            [],
            $this->urls(),
            '<input type="hidden" name="_wpnonce" value="x">',
            $this->reader()->setFor(self::PARENT)
        );

        foreach ([self::PARENT, ...array_keys(self::CHILDREN)] as $id) {
            $product = \wc_get_product($id);
            self::assertInstanceOf(\WC_Product::class, $product);
            self::assertSame([], $product->wrote, "rendering must not write to {$id}");
        }
    }

    /**
     * ⛔ AND PROJECTION DOES NOT DUPLICATE OR HIDE THEM.
     *
     * The marketplace row says `variable` and holds no variations — which is
     * every imported product. The loop over `$variations` therefore never
     * runs, nothing is created, and the «stale» loop only ever sees children
     * this plugin stamped, of which there are none. Measured rather than
     * reasoned, because this is the sentence the whole round rests on.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProjectingAnImportedVariableProductLeavesEveryChildAlone(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        $before = $this->snapshot();

        $result = (new WooCommerceProjector(new NoSpecTemplates()))->project($this->row());
        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame([], $result['variation_links']);

        $after = $this->snapshot();
        foreach (array_keys(self::CHILDREN) as $id) {
            self::assertSame($before[$id], $after[$id], "child {$id} must be untouched");
        }
        self::assertCount(count($before), $after, 'no fourth child may appear');
        // And the shop was asked to recompute its range, which is what keeps
        // the list's `wc_product_meta_lookup` read honest.
        self::assertContains(self::PARENT, State::$variableSyncs);
    }

    /**
     * ⛔ THE RISK `alpha.90` NAMED AND DID NOT CLOSE: a marketplace row that
     * points at an existing child by `wc_variation_id` is now MATCHED rather
     * than ignored, so projection updates that child instead of creating a
     * second one beside it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARowPointingAtAnExistingChildUpdatesItRatherThanDuplicating(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        $countBefore = count(self::CHILDREN);

        $adopted = new ProductVariation(
            id: 55,
            productId: self::PRODUCT_ROW,
            attributes: ['bundle' => '۱۰۰ عددی'],
            priceMinor: 5187400,
            sku: 'BPI-100',
            stock: 3,
            enabled: true,
            wcVariationId: 7702
        );

        $result = (new WooCommerceProjector(new NoSpecTemplates()))
            ->project($this->row(), [$this->axis()], [$adopted]);

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame([55 => 7702], $result['variation_links'], 'the existing child, not a new one');
        self::assertCount($countBefore + 1, \WC_Product::$saved, 'the parent and three children, and nothing new');
        self::assertSame(55, (int) \get_post_meta(7702, WooCommerceProjector::VARIATION_META, true));
    }

    /**
     * Two rows, one child: neither is written, and nothing is created.
     *
     * The machine's two obvious answers are both destructive — overwrite the
     * child with one of them, or make a second child beside it. A person has
     * to resolve this, so the shop is left exactly as it is.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTwoRowsClaimingOneChildAreBothSkipped(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        $before = $this->snapshot();

        $first = new ProductVariation(
            id: 55,
            productId: self::PRODUCT_ROW,
            attributes: ['bundle' => '۱۰۰ عددی'],
            priceMinor: 5187400,
            sku: 'BPI-100',
            stock: 3,
            wcVariationId: 7702
        );
        $second = new ProductVariation(
            id: 56,
            productId: self::PRODUCT_ROW,
            attributes: ['bundle' => '۱۰۰ عددی دوم'],
            priceMinor: 1,
            sku: 'BPI-100-DUP',
            stock: 0,
            wcVariationId: 7702
        );

        $result = (new WooCommerceProjector(new NoSpecTemplates()))
            ->project($this->row(), [$this->axis()], [$first, $second]);

        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertSame([], $result['variation_links'], 'neither row may claim the child');
        self::assertSame($before[7702], $this->snapshot()[7702], 'the child keeps its own price');
        self::assertCount(count($before), $this->snapshot(), 'and no second child is created');
    }

    /** A pointer at something that is not this parent's child is not believed. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPointerAtSomebodyElsesVariationIsIgnored(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        // A variation that exists but belongs to another parent.
        $stranger = new \WC_Product_Variation();
        $stranger->set_parent_id(8800);
        $stranger->set_regular_price('111');
        $strangerId = $stranger->save();
        $stranger->wrote = [];

        $row = new ProductVariation(
            id: 57,
            productId: self::PRODUCT_ROW,
            attributes: ['bundle' => '۵۰۰ عددی'],
            priceMinor: 500,
            sku: 'BPI-500',
            stock: 1,
            wcVariationId: $strangerId
        );

        (new WooCommerceProjector(new NoSpecTemplates()))->project($this->row(), [$this->axis()], [$row]);

        self::assertSame(
            '111',
            (\wc_get_product($strangerId))->get_regular_price(),
            "another parent's variation may not be written"
        );
    }

    /** Four situations, four sentences — «نمی‌دانم» is never «ترکیبی ندارد». */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachReasonForHavingNothingToShowHasItsOwnSentence(): void
    {
        $this->bootPlugin(true);
        \WC_Product::reset();

        // No storefront product at all.
        self::assertSame(
            StorefrontVariationSet::NO_STOREFRONT_PRODUCT,
            $this->reader()->setFor(0)->state
        );
        self::assertSame(
            StorefrontVariationSet::NO_STOREFRONT_PRODUCT,
            $this->reader()->setFor(999999)->state
        );

        // A simple product where a variable one was expected.
        $simple = new \WC_Product_Simple();
        $simple->set_regular_price('100');
        $simpleId = $simple->save();
        self::assertSame(StorefrontVariationSet::NOT_VARIABLE, $this->reader()->setFor($simpleId)->state);

        // A variable parent with no children: READ, and empty is a real answer.
        $empty = new \WC_Product_Variable();
        $emptyId = $empty->save();
        \WC_Product_Variable::setChildren($emptyId, []);
        $set = $this->reader()->setFor($emptyId);
        self::assertTrue($set->isRead());
        self::assertFalse($set->hasVariations());
        self::assertNull($set->minPriceMinor(), 'no priced child is not a price of nought');
    }

    /** A child the shop lists and we could not load is counted, never dropped. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAChildThatCouldNotBeLoadedIsCountedRatherThanDropped(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        // The shop lists a fourth child that does not load.
        \WC_Product_Variable::setChildren(self::PARENT, [...array_keys(self::CHILDREN), 7799]);

        $set = $this->reader()->setFor(self::PARENT);

        self::assertTrue($set->isRead());
        self::assertFalse($set->isComplete(), 'the read was short and must say so');
        self::assertSame(3, $set->count());
        self::assertSame(1, $set->unreadableChildren);
    }

    /** A price the shop holds at a scale this shop cannot express is null, not nought. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPriceThatCannotBeExpressedExactlyIsUnknownRatherThanFree(): void
    {
        $this->bootPlugin(true);
        $this->arrangeImportedParent();
        $odd = \wc_get_product(7701);
        self::assertInstanceOf(\WC_Product::class, $odd);
        $odd->set_regular_price('2600000.50');     // half a toman, at exponent 0
        $odd->save();

        $set = $this->reader()->setFor(self::PARENT);
        $first = $set->variations[0];

        self::assertNull($first->currentPriceMinor, 'half a toman is not nought');
        self::assertFalse($first->isBuyable(), 'and a price we did not read is not a price');
        self::assertSame(1, $set->unreadablePriceCount());
        // The range still reports the two it could read, and the count beside
        // it still says three.
        self::assertSame(5187400, $set->minPriceMinor());
        self::assertSame(3, $set->count());
    }

    // ------------------------------------------------------------- fixture

    /**
     * An imported variable parent: real children, real ids, and NO
     * `_tmc_variation_id` on any of them. That last part is what makes it
     * imported rather than ours.
     */
    private function arrangeImportedParent(): void
    {
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
        State::$variableSyncs = [];

        $parent = new \WC_Product_Variable(self::PARENT);
        $parent->set_name('چسب پنبه‌ای BPI');
        $parent->set_status('publish');
        // The parent of a variable product manages no stock of its own, and
        // reading that as «۰ / ناموجود» is the defect.
        $parent->set_manage_stock(false);
        $parent->set_stock_status('instock');
        $parent->save();
        update_post_meta(self::PARENT, WooCommerceProjector::PRODUCT_META, self::PRODUCT_ROW);
        update_post_meta(self::PARENT, WooCommerceProjector::VENDOR_META, self::VENDOR);
        $parent->wrote = [];

        foreach (self::CHILDREN as $id => $child) {
            $variation = new \WC_Product_Variation($id);
            $variation->set_parent_id(self::PARENT);
            $variation->set_status($child['status']);
            $variation->set_sku($child['sku']);
            $variation->set_regular_price($child['price']);
            if ($child['sale'] !== '') {
                $variation->set_sale_price($child['sale']);
            }
            $variation->set_manage_stock($child['manage']);
            $variation->set_stock_quantity($child['qty']);
            $variation->set_stock_status($child['qty'] === null || $child['qty'] > 0 ? 'instock' : 'outofstock');
            if ($child['image'] > 0) {
                $variation->set_image_id($child['image']);
            }
            // ⛔ PLAIN STRINGS, because that is what WooCommerce stores on a
            // variation: the PARENT holds `WC_Product_Attribute` objects with
            // their option lists, and each child holds the ONE option it was
            // made for. The first version of this fixture wrapped them like a
            // parent and every read died on «could not be converted to
            // string» — a fixture that models a verb differently from the real
            // class measures the wrong contract (`alpha.43`, `alpha.59`).
            $variation->set_attributes($child['attrs']);
            $variation->save();
            $variation->wrote = [];
        }
        \WC_Product_Variable::setChildren(self::PARENT, array_keys(self::CHILDREN));
    }

    /**
     * Every effective byte of the shop's own state, for a before/after
     * comparison: the id, the price, the stock, the status, the picture, the
     * attributes and the meta rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach (array_keys(\WC_Product::$saved) as $id) {
            $product = \wc_get_product((int) $id);
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $attributes = [];
            foreach ((array) $product->get_attributes() as $key => $attribute) {
                $attributes[(string) $key] = $attribute instanceof \WC_Product_Attribute
                    ? [$attribute->get_name(), $attribute->get_options()]
                    : null;
            }
            $meta = State::$postMeta[(int) $id] ?? [];
            ksort($meta);
            $out[(int) $id] = [
                'status' => $product->get_status(),
                'sku' => $product->get_sku(),
                'regular' => $product->get_regular_price(),
                'sale' => $product->get_sale_price(),
                'manage' => $product->get_manage_stock(),
                'qty' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'image' => $product->get_image_id(),
                'attributes' => $attributes,
                'meta' => $meta,
            ];
        }
        ksort($out);
        return $out;
    }

    private function row(): Product
    {
        return new Product(
            id: self::PRODUCT_ROW,
            vendorUserId: self::VENDOR,
            details: new ProductDetails(
                title: 'چسب پنبه‌ای BPI',
                type: ProductType::VARIABLE,
                sku: 'BPI',
                priceMinor: 0,
                stock: 0
            ),
            status: ProductStatus::Published,
            wcProductId: self::PARENT,
            linkOwnership: LinkOwnership::MARKETPLACE_VALUE
        );
    }

    /** The one axis the fixture's combinations are defined along. */
    private function axis(): ProductAttribute
    {
        return new ProductAttribute(1, 'bundle', 'بسته', ['۵۰ عددی', '۱۰۰ عددی', '۲۰۰ عددی'], 0);
    }

    private function reader(): WcStorefrontVariationReader
    {
        return new WcStorefrontVariationReader(
            new \Tecteb\Marketplace\Tests\Support\FakeDatabase()
        );
    }

    private function urls(): VendorUrls
    {
        return new VendorUrls('https://example.test/vendor/', 'https://example.test/vendor/application/', '', '', '', 'https://example.test/vendor/products/');
    }

    /** Persian digits back to ASCII, so an id can be looked for as a number. */
    private function asciiDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
