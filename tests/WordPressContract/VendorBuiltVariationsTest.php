<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedAttributes;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Tests\Support\NoSpecTemplates;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: a variable product the VENDOR built in this panel — no
 * WooCommerce admin anywhere — reaches the shop as standard WooCommerce
 * structures, and the three ways it could be damaged do not happen.
 *
 * ⛔ WHY THESE FOUR TESTS AND NOT A REWRITE. §4 of the owner's instruction
 * asks for a path that already exists: `ManageVariations` has had all five
 * verbs since `alpha.6`, `VariationsView` renders the create, edit and delete
 * forms for both the axes and the combinations, and the five actions are
 * registered in `ProductArea::ACTIONS`. Rebuilding any of that would be the
 * «بازنویسی سلیقه‌ای» the first rule forbids. What §4 really asks is whether
 * the four named hazards are closed, so each one is measured here against the
 * real `WooCommerceProjector`:
 *
 *   1. standard structures — `WC_Product_Attribute` with `variation = true`
 *      on the parent, real `WC_Product_Variation` children, `sync()`;
 *   2. no duplicate combination;
 *   3. order-history safety when the vendor removes a combination;
 *   4. no unintended deletion.
 *
 * ⛔ AND THE DOUBLE HAD TO BE CORRECTED BEFORE ANY OF IT COULD BE MEASURED.
 * `WC_Product_Variable::get_children()` in this repository's stub answered
 * only an explicitly declared map, while WooCommerce derives children from
 * `post_parent`. `existingVariationIds()` reads `get_children()`, so a child
 * the projector had just created was a child of nothing — and every test in
 * this repository that projects the same product twice was measuring a shop
 * with no children at all. The stub now derives them, the whole contract
 * suite stayed green, and these four tests are what that correction bought.
 */
final class VendorBuiltVariationsTest extends ContractTestCase
{
    private const VENDOR = 77;
    private const ROW = 412;

    /**
     * §4, structures: the axis becomes a variation attribute and the
     * combinations become real children, with no WooCommerce admin involved.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheVendorsOwnCombinationsBecomeStandardWooCommerceStructures(): void
    {
        $this->arrange();

        $result = $this->projector()->project($this->row(), [$this->axis()], [
            $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8),
            $this->variation(2, '۱۰۰ عددی', 5_000_000, 'VB-100', 3),
        ]);
        self::assertTrue($result['ok'], (string) $result['code']);
        $parentId = (int) $result['wc_product_id'];

        // The axis, read back from storage rather than from what we passed in
        // (`alpha.43`): WooCommerce files an attribute under its own key.
        $parent = \wc_get_product($parentId);
        self::assertInstanceOf(\WC_Product_Variable::class, $parent);
        $attributes = $parent->get_attributes();
        $key = ProjectedAttributes::keyFor('بسته');
        self::assertArrayHasKey($key, $attributes);
        self::assertInstanceOf(\WC_Product_Attribute::class, $attributes[$key]);
        self::assertSame('بسته', $attributes[$key]->get_name());
        self::assertSame(['۵۰ عددی', '۱۰۰ عددی'], $attributes[$key]->get_options());
        self::assertTrue($attributes[$key]->get_variation(), 'an axis must generate variations');
        self::assertTrue($attributes[$key]->get_visible());

        // Two real children, each stamped with the row it came from.
        $children = $this->children($parentId);
        self::assertCount(2, $children);
        foreach ($children as $childId) {
            $child = \wc_get_product($childId);
            self::assertInstanceOf(\WC_Product_Variation::class, $child);
            self::assertSame($parentId, (int) $child->get_parent_id());
            self::assertGreaterThan(
                0,
                (int) \get_post_meta($childId, WooCommerceProjector::VARIATION_META, true),
                'a child this plugin created carries its stamp'
            );
            // ⛔ Plain strings on the child, objects on the parent — which is
            // how WooCommerce itself stores them.
            foreach ($child->get_attributes() as $value) {
                self::assertIsString($value);
            }
        }
        // And the parent's price range was recalculated by WooCommerce's own
        // routine rather than by arithmetic of ours.
        self::assertContains($parentId, State::$variableSyncs);
    }

    /**
     * §4, duplicates: projecting the same product again finds the children it
     * made and makes no more.
     *
     * ⛔ This is the test the broken double made impossible. With
     * `get_children()` answering an empty map, the second projection created
     * two MORE children and nothing could see it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecondProjectionFindsItsOwnChildrenAndMakesNoMore(): void
    {
        $this->arrange();
        $variations = [
            $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8),
            $this->variation(2, '۱۰۰ عددی', 5_000_000, 'VB-100', 3),
        ];

        $first = $this->projector()->project($this->row(), [$this->axis()], $variations);
        $parentId = (int) $first['wc_product_id'];
        $after = $this->children($parentId);
        self::assertCount(2, $after);

        $second = $this->projector()->project(
            $this->row($parentId),
            [$this->axis()],
            $variations
        );
        self::assertTrue($second['ok'], (string) $second['code']);
        self::assertSame($parentId, (int) $second['wc_product_id']);
        self::assertSame(
            $after,
            $this->children($parentId),
            'the same two children, by id — not two more beside them'
        );
    }

    /**
     * §4, order history: a combination the vendor removes is HIDDEN, never
     * deleted.
     *
     * An order line records the WooCommerce variation id it was sold as
     * (`tmc_order_items.variation_id`, written by `CaptureOrder` from the
     * shop's own line). Deleting the child would leave every past order
     * pointing at a post that no longer exists — so the projector sets it
     * `private` and `outofstock` instead, which takes it off sale and leaves
     * the record whole.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testACombinationTheVendorRemovedIsHiddenAndNotDeleted(): void
    {
        $this->arrange();
        $fifty = $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8);
        $hundred = $this->variation(2, '۱۰۰ عددی', 5_000_000, 'VB-100', 3);

        $first = $this->projector()->project($this->row(), [$this->axis()], [$fifty, $hundred]);
        $parentId = (int) $first['wc_product_id'];
        $sold = (int) ($first['variation_links'][2] ?? 0);
        self::assertGreaterThan(0, $sold);

        // The vendor removes the 100-pack. Our row is gone; the shop is told
        // by its absence from the list.
        $second = $this->projector()->project($this->row($parentId), [$this->axis()], [$fifty]);
        self::assertTrue($second['ok'], (string) $second['code']);

        $child = \wc_get_product($sold);
        self::assertInstanceOf(
            \WC_Product_Variation::class,
            $child,
            'the child an order points at still exists'
        );
        self::assertSame('private', $child->get_status(), 'hidden');
        self::assertSame('outofstock', $child->get_stock_status(), 'and not sellable');
        self::assertSame(
            '5000000',
            (string) $child->get_regular_price(),
            'and the price the order was taken at is untouched'
        );
    }

    /**
     * ⛔ §4, THE ONE THAT HAD TO BE MEASURED RATHER THAN REASONED ABOUT:
     * removing a combination and then adding the SAME one back.
     *
     * `deleteVariation()` really deletes our row, so the stamp on the shop's
     * child (`_tmc_variation_id`) and the row's own `wc_variation_id` pointer
     * both name a row that no longer exists. `matchVariations()` recognises a
     * child by exactly those two records — nothing matches the vendor's NEW
     * row, and a second child is created beside the hidden one.
     *
     * That is two WooCommerce children for one combination under one parent:
     * the shape `alpha.105` §3-۹ exists to prevent, the thing §4 asks for in
     * as many words («ترکیب تکراری ساخته نشود»), and the hidden one is the one
     * every past order points at.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRecreatingARemovedCombinationReusesItsChildRatherThanMakingASecond(): void
    {
        $this->arrange();
        $fifty = $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8);
        $hundred = $this->variation(2, '۱۰۰ عددی', 5_000_000, 'VB-100', 3);

        $first = $this->projector()->project($this->row(), [$this->axis()], [$fifty, $hundred]);
        $parentId = (int) $first['wc_product_id'];
        $wasSoldAs = (int) ($first['variation_links'][2] ?? 0);

        // Removed...
        $this->projector()->project($this->row($parentId), [$this->axis()], [$fifty]);
        // ...and added back, which is a NEW row with a new id and the SAME
        // combination, because the unique key is on (product_id, combination)
        // and the old row was deleted.
        $again = $this->variation(9, '۱۰۰ عددی', 5_400_000, 'VB-100', 4);
        $third = $this->projector()->project($this->row($parentId), [$this->axis()], [$fifty, $again]);
        self::assertTrue($third['ok'], (string) $third['code']);

        $hundredPacks = [];
        foreach ($this->children($parentId) as $childId) {
            $child = \wc_get_product($childId);
            if ($child instanceof \WC_Product_Variation && in_array('۱۰۰ عددی', $child->get_attributes(), true)) {
                $hundredPacks[] = $childId;
            }
        }
        self::assertCount(
            1,
            $hundredPacks,
            'one combination, one child — a deleted-and-recreated combination must not double'
        );
        self::assertSame(
            [$wasSoldAs],
            $hundredPacks,
            'and it is the SAME child, so every past order still points at the row that holds it'
        );
        $revived = \wc_get_product($wasSoldAs);
        self::assertInstanceOf(\WC_Product_Variation::class, $revived);
        self::assertSame('publish', $revived->get_status(), 'back on sale');
        self::assertSame('5400000', (string) $revived->get_regular_price(), 'with the new price');
    }

    /**
     * ⛔ CONTROL ONE, AND THE MOST IMPORTANT ONE IN THIS FILE: an IMPORTED
     * child is never adopted.
     *
     * The adoption above recognises a child by our own two stamps. An
     * imported child carries neither — `ImportFromDokan` writes no media, no
     * attribute rows and no stamps — and `alpha.105` §3-۹ and `alpha.106`
     * exist to keep this plugin's hands off it. If the adoption were widened
     * to «same combination, same parent», a vendor who defined an axis on an
     * imported product would have this projection take over the shop's own
     * child: its price, its stock and its SKU rewritten from our row.
     *
     * A guard wider than the defect is the bigger defect (`alpha.60`), so the
     * widening is pinned shut from the outside: the imported child must come
     * out of this byte-identical.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnImportedChildIsNeverAdoptedByAMatchingCombination(): void
    {
        $this->arrange();

        // A parent this plugin links to, holding a child it did NOT create.
        $parent = new \WC_Product_Variable(8800);
        $parent->set_name('چسب واردشده');
        $parent->set_status('publish');
        $parent->save();
        \update_post_meta(8800, WooCommerceProjector::PRODUCT_META, self::ROW);

        $imported = new \WC_Product_Variation(8801);
        $imported->set_parent_id(8800);
        $imported->set_status('publish');
        $imported->set_sku('SHOP-100');
        $imported->set_regular_price('7777777');
        $imported->set_manage_stock(true);
        $imported->set_stock_quantity(42);
        $imported->set_attributes([\sanitize_title('بسته') => '۱۰۰ عددی']);
        $imported->save();
        $before = $this->childState(8801);

        // Our row names the very same combination.
        $result = $this->projector()->project($this->row(8800), [$this->axis()], [
            $this->variation(5, '۱۰۰ عددی', 5_000_000, 'OURS-100', 3),
        ]);
        self::assertTrue($result['ok'], (string) $result['code']);

        self::assertSame($before, $this->childState(8801), 'the imported child is untouched');
        self::assertNotSame(
            8801,
            (int) ($result['variation_links'][5] ?? 0),
            'and our row got its own child, not the shop\'s'
        );
    }

    /**
     * Control two: an orphan belonging to a DIFFERENT product is not adopted,
     * even with the same combination and the same axis label.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnotherProductsOrphanIsNotAdopted(): void
    {
        $this->arrange();

        $parent = new \WC_Product_Variable(8900);
        $parent->set_name('محصول ما');
        $parent->set_status('publish');
        $parent->save();
        \update_post_meta(8900, WooCommerceProjector::PRODUCT_META, self::ROW);

        // Stamped by this plugin, but for product row 999 — whose variation
        // 3 is long gone.
        $foreign = new \WC_Product_Variation(8901);
        $foreign->set_parent_id(8900);
        $foreign->set_status('private');
        $foreign->set_regular_price('1111111');
        $foreign->set_attributes([\sanitize_title('بسته') => '۱۰۰ عددی']);
        $foreign->save();
        \update_post_meta(8901, WooCommerceProjector::PRODUCT_META, 999);
        \update_post_meta(8901, WooCommerceProjector::VARIATION_META, 3);
        $before = $this->childState(8901);

        $result = $this->projector()->project($this->row(8900), [$this->axis()], [
            $this->variation(5, '۱۰۰ عددی', 5_000_000, 'OURS-100', 3),
        ]);
        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertNotSame(8901, (int) ($result['variation_links'][5] ?? 0), 'not adopted');

        // ⛔ NOT BYTE-IDENTITY, AND THE REASON IS A PRE-EXISTING RULE THIS
        // TEST MUST NOT CONTRADICT. `existingVariationIds()` reads the stamp
        // off every child of this parent without asking WHICH product stamped
        // it, so a stamped child that no live row claims lands in the
        // «combinations the vendor removed» loop and is set `private` +
        // `outofstock`. That is correct for a child of our own parent, it has
        // been the behaviour since `alpha.6`, and asserting it away here would
        // have pinned a different rule than the one §4 is about.
        //
        // What the control is actually about is ADOPTION, so it pins the
        // facts adoption would have changed: the figures, the SKU and the
        // stamp.
        $after = $this->childState(8901);
        foreach (['regular', 'sale', 'sku', 'manage', 'qty', 'attributes', 'stamp', 'product'] as $field) {
            self::assertSame($before[$field], $after[$field], $field . ' is untouched');
        }
    }

    /**
     * Control three: TWO orphans for one combination are not resolved by
     * guesswork.
     *
     * Reviving either one is a claim about which past order matters, and this
     * method has refused to guess about an ambiguous child since `alpha.105`.
     * Behaviour is left exactly as it was — a new child — and the two orphans
     * are untouched, which is what makes the state readable afterwards.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTwoOrphansForOneCombinationAreLeftForAPersonToResolve(): void
    {
        $this->arrange();

        $parent = new \WC_Product_Variable(9000);
        $parent->set_name('محصول ما');
        $parent->set_status('publish');
        $parent->save();
        \update_post_meta(9000, WooCommerceProjector::PRODUCT_META, self::ROW);

        foreach ([9001 => 11, 9002 => 12] as $childId => $deadRow) {
            $orphan = new \WC_Product_Variation($childId);
            $orphan->set_parent_id(9000);
            $orphan->set_status('private');
            $orphan->set_regular_price((string) (1_000_000 + $childId));
            $orphan->set_attributes([\sanitize_title('بسته') => '۱۰۰ عددی']);
            $orphan->save();
            \update_post_meta($childId, WooCommerceProjector::PRODUCT_META, self::ROW);
            \update_post_meta($childId, WooCommerceProjector::VARIATION_META, $deadRow);
        }
        $before = [9001 => $this->childState(9001), 9002 => $this->childState(9002)];

        $result = $this->projector()->project($this->row(9000), [$this->axis()], [
            $this->variation(5, '۱۰۰ عددی', 5_000_000, 'OURS-100', 3),
        ]);
        self::assertTrue($result['ok'], (string) $result['code']);
        $got = (int) ($result['variation_links'][5] ?? 0);
        self::assertNotSame(9001, $got, 'neither orphan is picked');
        self::assertNotSame(9002, $got);
        // The same narrowing as the control above: both orphans keep every
        // figure that adoption would have rewritten, and the stale-child rule
        // goes on hiding them as it always did.
        foreach ([9001, 9002] as $childId) {
            $after = $this->childState($childId);
            foreach (['regular', 'sku', 'attributes', 'stamp', 'product'] as $field) {
                self::assertSame($before[$childId][$field], $after[$field], $childId . '.' . $field);
            }
        }
    }

    // ------------------------------------------------------------- internals

    private function arrange(): void
    {
        $this->bootPlugin(true);
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
        \WC_Product_Variable::resetChildren();
        State::$variableSyncs = [];
    }

    /** @return list<int> */
    private function children(int $parentId): array
    {
        $parent = \wc_get_product($parentId);
        if (!$parent instanceof \WC_Product_Variable) {
            return [];
        }
        $ids = $parent->get_children();
        sort($ids);
        return $ids;
    }

    /**
     * Every byte of a child a control cares about, for a before/after
     * comparison: the price, the stock, the SKU, the status and the options.
     *
     * @return array<string,mixed>
     */
    private function childState(int $childId): array
    {
        $child = \wc_get_product($childId);
        if (!$child instanceof \WC_Product_Variation) {
            return ['missing' => true];
        }
        return [
            'parent' => (int) $child->get_parent_id(),
            'status' => $child->get_status(),
            'sku' => $child->get_sku(),
            'regular' => (string) $child->get_regular_price(),
            'sale' => (string) $child->get_sale_price(),
            'manage' => $child->get_manage_stock(),
            'qty' => $child->get_stock_quantity(),
            'stock_status' => $child->get_stock_status(),
            'attributes' => $child->get_attributes(),
            'image' => (int) $child->get_image_id(),
            'stamp' => (int) \get_post_meta($childId, WooCommerceProjector::VARIATION_META, true),
            'product' => (int) \get_post_meta($childId, WooCommerceProjector::PRODUCT_META, true),
        ];
    }

    private function axis(): ProductAttribute
    {
        return new ProductAttribute(1, 'bundle', 'بسته', ['۵۰ عددی', '۱۰۰ عددی'], 0);
    }

    /**
     * §4/§7, the generated row with no price: it is NOT a storefront child
     * yet, so the parent's public price range does not start at nought and
     * the buyer's selector does not offer an option nobody priced.
     *
     * ⛔ MEASURED ON A REAL INSTALL FIRST. After pressing the generate button
     * on WooCommerce 11.0.1, `get_price_html()` read «۰ تومان – ۴۰۰٬۰۰۰
     * تومان» and `get_available_variations()` returned nine where three had
     * prices. The six new children were `is_in_stock() === false`, so nothing
     * was buyable for nought — the safe direction, and not the point: the
     * range was wrong on a page a buyer reads, and a vendor who writes the
     * stock before the price would have closed that gap themselves.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAGeneratedRowWithNoPriceIsNotYetAStorefrontChild(): void
    {
        $this->arrange();

        $result = $this->projector()->project($this->row(), [$this->axis()], [
            $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8),
            // What `buildVariations()` writes: the combination, and no price.
            new ProductVariation(2, self::ROW, ['bundle' => '۱۰۰ عددی'], 0, null, '', 0),
        ]);
        self::assertTrue($result['ok'], (string) $result['code']);
        $parentId = (int) $result['wc_product_id'];

        $children = $this->children($parentId);
        self::assertCount(1, $children, 'only the priced combination reached the shop');
        $child = \wc_get_product((int) $children[0]);
        self::assertInstanceOf(\WC_Product_Variation::class, $child);
        self::assertSame(
            1,
            (int) \get_post_meta((int) $children[0], WooCommerceProjector::VARIATION_META, true),
            'and it is the priced row, not whichever came first'
        );
        // The unpriced row holds no link, which is already how this projector
        // says «not mirrored» — no new vocabulary was invented for it.
        self::assertArrayNotHasKey(2, $result['variation_links']);
        self::assertArrayHasKey(1, $result['variation_links']);
    }

    /**
     * …and the moment the vendor writes the price, the child appears — in the
     * SAME projection path, with no second verb and no duplicate.
     *
     * ⛔ The control that makes the test above mean something: a guard that
     * simply never created the child would pass that one too.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWritingThePriceBringsTheChildIntoBeingWithoutDuplicating(): void
    {
        $this->arrange();

        $priced = $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8);
        $first = $this->projector()->project($this->row(), [$this->axis()], [
            $priced,
            new ProductVariation(2, self::ROW, ['bundle' => '۱۰۰ عددی'], 0, null, '', 0),
        ]);
        // ⛔ The parent id travels back, as it does from the real caller: the
        // row carries `wcProductId` once the catalogue has stored the link,
        // and a test that leaves it null measures a SECOND parent and reads
        // the first one's children — which is how the first draft of this
        // test passed for the wrong reason.
        $firstParent = (int) $first['wc_product_id'];
        self::assertCount(1, $this->children($firstParent), 'only the priced row so far');

        $second = $this->projector()->project($this->row($firstParent), [$this->axis()], [
            $priced,
            $this->variation(2, '۱۰۰ عددی', 5_000_000, 'VB-100', 3),
        ]);
        self::assertTrue($second['ok'], (string) $second['code']);
        self::assertSame($firstParent, (int) $second['wc_product_id']);

        $children = $this->children($firstParent);
        self::assertCount(2, $children, 'the priced row joined; the first was not duplicated');
        $stamps = [];
        foreach ($children as $childId) {
            $stamps[] = (int) \get_post_meta((int) $childId, WooCommerceProjector::VARIATION_META, true);
        }
        \sort($stamps);
        self::assertSame([1, 2], $stamps);
    }

    /**
     * ⛔ AND THE NARROWING IS ASSERTED, not just written in a comment: a price
     * CLEARED after the child exists still travels to the shop.
     *
     * Skipping that case would leave WooCommerce holding the old number with
     * no row willing to correct it — worse than the defect this closes. The
     * guard reads `$isNew`, and this is the half of it that is easy to lose
     * in a later edit.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPriceClearedAfterTheChildExistsStillReachesTheShop(): void
    {
        $this->arrange();

        $first = $this->projector()->project($this->row(), [$this->axis()], [
            $this->variation(1, '۵۰ عددی', 2_600_000, 'VB-50', 8),
        ]);
        $parentId = (int) $first['wc_product_id'];
        $childId = (int) $this->children($parentId)[0];
        self::assertSame('2600000', (string) \wc_get_product($childId)->get_regular_price());

        $again = $this->projector()->project($this->row($parentId), [$this->axis()], [
            new ProductVariation(1, self::ROW, ['bundle' => '۵۰ عددی'], 0, null, 'VB-50', 8),
        ]);
        self::assertSame($parentId, (int) $again['wc_product_id']);

        self::assertCount(1, $this->children($parentId), 'no second child was made');
        self::assertSame(
            '0',
            (string) \wc_get_product($childId)->get_regular_price(),
            'the shop must not keep a price the row no longer holds'
        );
    }

    private function variation(
        int $id,
        string $option,
        int $priceMinor,
        string $sku,
        int $stock
    ): ProductVariation {
        return new ProductVariation($id, self::ROW, ['bundle' => $option], $priceMinor, null, $sku, $stock);
    }

    private function row(?int $wcProductId = null): Product
    {
        return new Product(
            self::ROW,
            self::VENDOR,
            new ProductDetails(
                title: 'چسب پنبه‌ای',
                categoryKey: 'dressing',
                brand: 'آریا طب',
                shortDescription: 'یک جمله',
                priceMinor: 0,
                salePriceMinor: null,
                sku: 'VB-PARENT',
                stock: 0,
                type: ProductType::VARIABLE
            ),
            ProductStatus::Published,
            [],
            [],
            0,
            '',
            0,
            null,
            '',
            $wcProductId,
            new ProductSeo(),
            '',
            null,
            LinkOwnership::MARKETPLACE_VALUE,
            null,
            false
        );
    }

    private function projector(): WooCommerceProjector
    {
        return new WooCommerceProjector(new NoSpecTemplates());
    }
}
