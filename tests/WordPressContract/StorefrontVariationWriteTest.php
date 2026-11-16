<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullStorefrontVariationWriter;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcStorefrontVariationWriter;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the vendor's figures land on the REAL child and on
 * nothing else — §3 of the owner's November instruction.
 *
 * ⛔ **THE FIXTURE IS SYNTHETIC AND THE INSTRUCTION SAYS IT MUST BE.** «نمونه
 * فقط‌خواندنی: والد #11955 … هیچ عملیات نوشتنی روی این شناسه‌های واقعی انجام
 * نده.» Parent 7700 with children 7701/7702/7703 is this repository's own
 * shape — three bundle sizes, three prices, one child WooCommerce keeps no
 * number for — and nothing here can reach a live site.
 *
 * ⛔ **AND THE STUB HAD TO LEARN THE STATEMENT FIRST.** Until this round
 * `wc_update_product_stock()` in the shared double returned the quantity and
 * wrote nothing, so a guard riding on
 * `woocommerce_update_product_stock_query` was unreachable here and a test
 * about it would have passed against a build with no guard at all. The stub
 * now ensures the `_stock` row, applies that filter, and honours a trailing
 * `AND meta_value + 0 = N` numerically — which is what MySQL does with it.
 * Its own docblock lists what it still does not model.
 */
final class StorefrontVariationWriteTest extends ContractTestCase
{
    private const PARENT = 7700;
    private const OTHER_PARENT = 7800;
    private const OTHER_CHILD = 7801;

    /** @var array<int,array{sku:string,price:string,sale:string,qty:?int,manage:bool}> */
    private const CHILDREN = [
        7701 => ['sku' => 'BPI-50', 'price' => '2600000', 'sale' => '', 'qty' => 8, 'manage' => true],
        7702 => ['sku' => 'BPI-100', 'price' => '5187400', 'sale' => '', 'qty' => 3, 'manage' => true],
        7703 => ['sku' => 'BPI-200', 'price' => '9900000', 'sale' => '8900000', 'qty' => null, 'manage' => false],
    ];

    // ---------------------------------------------------------------- prices

    /** The ordinary case: a regular price, and a sale, on one child. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePricesOfOneChildAreWrittenAndReadBack(): void
    {
        $this->arrange();

        $out = $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 2_900_000, 2_500_000, 2_600_000, null);

        self::assertSame('written', $out['outcome']);
        self::assertSame(2_900_000, $out['found_regular']);
        self::assertSame(2_500_000, $out['found_sale']);
        self::assertSame('2900000', \wc_get_product(7701)->get_regular_price());
        self::assertSame('2500000', \wc_get_product(7701)->get_sale_price());
    }

    /**
     * ⛔ THE PARENT IS NOT WRITTEN. The whole of `alpha.106` was that a
     * variable parent carrying `manage_stock = true` and a nought takes every
     * child out of sale with it; this round opens a door at the children and
     * must leave that closed.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWritingAChildCallsNoSetterOnTheParent(): void
    {
        $this->arrange();

        $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 2_900_000, null, 2_600_000, null);
        $this->writer()->writeStockIfUnchanged(self::PARENT, 7701, 4, 8);

        $parent = \wc_get_product(self::PARENT);
        self::assertSame([], $parent->wrote, 'not one setter on the parent');
        self::assertFalse((bool) $parent->get_manage_stock(), 'the parent must not start managing stock');
        self::assertNull(State::$postMeta[self::PARENT]['_stock'] ?? null, 'the parent gets no stock row');
    }

    /** And the other children are byte-identical afterwards. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEditingOneChildLeavesTheOthersAlone(): void
    {
        $this->arrange();
        $before = $this->snapshot();

        $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 2_900_000, null, 2_600_000, null);
        $this->writer()->writeStockIfUnchanged(self::PARENT, 7701, 4, 8);
        $after = $this->snapshot();

        foreach ([7702, 7703, self::PARENT] as $id) {
            self::assertSame($before[$id], $after[$id], "id {$id} must be untouched");
        }
        self::assertNotSame($before[7701], $after[7701], 'and the one that was edited did change');
    }

    /**
     * A figure that moved since the render is REFUSED, not overwritten — and
     * the answer says what the shop holds now, so the vendor can decide.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAPriceThatMovedSinceTheRenderIsRefused(): void
    {
        $this->arrange();
        // Somebody else — a manager in wp-admin — raised it.
        $child = \wc_get_product(7701);
        $child->set_regular_price('3100000');
        $child->save();

        $out = $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 2_900_000, null, 2_600_000, null);

        self::assertSame('moved', $out['outcome']);
        self::assertSame(3_100_000, $out['found_regular']);
        self::assertSame('3100000', \wc_get_product(7701)->get_regular_price(), 'their figure stays');
    }

    /**
     * ⛔ A SALE THAT IS NOT BELOW THE PRICE IS REFUSED, because WooCommerce
     * would store it and then ignore it — `get_price()` stays at the regular
     * figure — and the vendor would be told «saved» about a sale no shopper
     * ever sees.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASaleThatIsNotBelowThePriceIsRefusedAndNothingIsWritten(): void
    {
        $this->arrange();
        $before = $this->snapshot();

        $out = $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 2_600_000, 2_600_000, 2_600_000, null);

        self::assertSame('bad_sale', $out['outcome']);
        self::assertSame($before, $this->snapshot(), 'a refusal writes nothing');
    }

    /** Clearing a sale is a real edit, and `null` is how it is said. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASaleCanBeCleared(): void
    {
        $this->arrange();

        $out = $this->writer()->writePricesIfUnchanged(self::PARENT, 7703, 9_900_000, null, 9_900_000, 8_900_000);

        self::assertSame('written', $out['outcome']);
        self::assertNull($out['found_sale']);
        self::assertSame('', \wc_get_product(7703)->get_sale_price());
    }

    // ----------------------------------------------------------------- stock

    /** The guarded set: the row still holds what the screen showed. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStockIsWrittenWhileTheShopStillHoldsWhatWasSeen(): void
    {
        $this->arrange();

        $out = $this->writer()->writeStockIfUnchanged(self::PARENT, 7701, 4, 8);

        self::assertSame('written', $out['outcome']);
        self::assertSame(4, $out['found']);
        self::assertSame(4, (int) round((float) State::$postMeta[7701]['_stock']));
    }

    /**
     * ⛔ AND THE GUARD IS THE STATEMENT'S CONDITION, not a read before it.
     *
     * A sale landing between the render and the save is the case `alpha.50`
     * measured: `wc_maybe_reduce_stock_levels()` writes the row in one
     * unconditional statement with no transaction. Here the row has moved to 6
     * and the vendor's form still says 8, so nothing is written and the
     * shopper's reduction survives.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStockThatMovedSinceTheRenderIsRefused(): void
    {
        $this->arrange();
        State::$postMeta[7701]['_stock'] = '6.000000';      // two were sold

        $out = $this->writer()->writeStockIfUnchanged(self::PARENT, 7701, 4, 8);

        self::assertSame('moved', $out['outcome']);
        self::assertSame(6, $out['found']);
        self::assertSame(6, (int) round((float) State::$postMeta[7701]['_stock']));
    }

    /**
     * The first number for a child that kept none: management goes on FOR THE
     * CHILD, and the parent's flag is not touched.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheFirstNumberForAnUnmanagedChildTurnsManagementOnForThatChildOnly(): void
    {
        $this->arrange();
        self::assertFalse((bool) \wc_get_product(7703)->get_manage_stock());

        $out = $this->writer()->writeStockIfUnchanged(self::PARENT, 7703, 5, null);

        self::assertSame('written', $out['outcome']);
        self::assertTrue((bool) \wc_get_product(7703)->get_manage_stock());
        self::assertSame(5, (int) \wc_get_product(7703)->get_stock_quantity());
        self::assertFalse((bool) \wc_get_product(self::PARENT)->get_manage_stock(), 'the parent stays as it was');
        self::assertSame([], \wc_get_product(self::PARENT)->wrote);
    }

    /**
     * And the opposite: the screen said «no number of its own» and one has
     * appeared since, so the vendor's «first number» is about a state that no
     * longer exists.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFirstNumberIsRefusedWhenTheChildHasAcquiredOneSince(): void
    {
        $this->arrange();
        $child = \wc_get_product(7703);
        $child->set_manage_stock(true);
        $child->set_stock_quantity(2);
        $child->save();

        $out = $this->writer()->writeStockIfUnchanged(self::PARENT, 7703, 5, null);

        self::assertSame('moved', $out['outcome']);
        self::assertSame(2, (int) \wc_get_product(7703)->get_stock_quantity());
    }

    /** Nought is a number, and it means «out of stock» rather than «no answer». */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStockOfNoughtIsWrittenAndSetsTheStatusOutOfStock(): void
    {
        $this->arrange();

        $out = $this->writer()->writeStockIfUnchanged(self::PARENT, 7701, 0, 8);

        self::assertSame('written', $out['outcome']);
        self::assertSame(0, $out['found']);
        self::assertSame('outofstock', \wc_get_product(7701)->get_stock_status());
    }

    // ------------------------------------------------------------ membership

    /**
     * ⛔ THE OWNERSHIP PROOF: a variation id that is not one of THIS parent's
     * children is refused, whatever the form said.
     *
     * The caller has already proved the vendor owns the marketplace row that
     * points at this parent. This is the other half, and without it a posted
     * `variation_id` would be a way to write to another shop's variation.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAVariationOfAnotherParentIsRefusedAndNothingIsWritten(): void
    {
        $this->arrange();
        $before = $this->snapshot();

        $prices = $this->writer()->writePricesIfUnchanged(self::PARENT, self::OTHER_CHILD, 1, null, 7_000_000, null);
        $stock = $this->writer()->writeStockIfUnchanged(self::PARENT, self::OTHER_CHILD, 1, 9);

        self::assertSame('not_a_child', $prices['outcome']);
        self::assertSame('not_a_child', $stock['outcome']);
        self::assertSame($before, $this->snapshot(), 'the other shop\'s variation is untouched');
    }

    /** A parent that is not variable has no combinations to edit. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASimpleParentIsRefusedByName(): void
    {
        $this->arrange();
        $simple = new \WC_Product(7900);
        $simple->set_status('publish');
        $simple->save();

        self::assertSame(
            'not_variable',
            $this->writer()->writePricesIfUnchanged(7900, 7701, 1, null, null, null)['outcome']
        );
    }

    /** No WooCommerce, said rather than guessed. */
    public function testTheNullWriterNamesTheReasonRatherThanFailing(): void
    {
        $writer = new NullStorefrontVariationWriter();
        self::assertNull($writer->priceExponent());
        self::assertSame('no_woocommerce', $writer->writePricesIfUnchanged(1, 2, 3, null, null, null)['outcome']);
        self::assertSame('no_woocommerce', $writer->writeStockIfUnchanged(1, 2, 3, null)['outcome']);
    }

    /**
     * The scale is the SHOP's, asked of the shop.
     *
     * A price read in one scale and written in another moves by a factor of a
     * hundred (`alpha.39`), so the writer reports the scale it will use rather
     * than letting a caller assume nought.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePriceScaleComesFromTheShop(): void
    {
        $this->arrange();
        self::assertSame(0, $this->writer()->priceExponent());

        State::$priceDecimals = 2;
        self::assertSame(2, $this->writer()->priceExponent());
        // And a figure is then read in THAT scale: 2,600,000 minor units at
        // two decimals is the «26000.00» the shop stores.
        $out = $this->writer()->writePricesIfUnchanged(self::PARENT, 7701, 260_000_000, null, 260_000_000, null);
        self::assertSame('written', $out['outcome']);
        self::assertSame('2600000.00', \wc_get_product(7701)->get_regular_price());
    }

    // ------------------------------------------------------------- internals

    private function writer(): WcStorefrontVariationWriter
    {
        return new WcStorefrontVariationWriter();
    }

    private function arrange(): void
    {
        $this->bootPlugin(true);
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
        State::$priceDecimals = 0;

        $parent = new \WC_Product_Variable(self::PARENT);
        $parent->set_name('چسب پنبه‌ای BPI');
        $parent->set_status('publish');
        // A variable parent keeps no number of its own, which is the state
        // `alpha.106` protects.
        $parent->set_manage_stock(false);
        $parent->set_stock_status('instock');
        $parent->save();
        $parent->wrote = [];

        foreach (self::CHILDREN as $id => $child) {
            $this->child(self::PARENT, $id, $child);
        }
        // ⛔ `get_children()` IS THE MEMBERSHIP WooCommerce RECOGNISES, and in
        // the double it is a declared list rather than something derived from
        // each child's `parent_id` — which is faithful: on a real site it comes
        // from a `post_parent` query, not from the child objects in memory. So
        // the fixture declares it, exactly as the reader's fixture does.
        \WC_Product_Variable::setChildren(self::PARENT, array_keys(self::CHILDREN));

        // A SECOND shop's variable product, so «is this child mine?» has a
        // real negative case rather than an invented id.
        $other = new \WC_Product_Variable(self::OTHER_PARENT);
        $other->set_status('publish');
        $other->save();
        $this->child(self::OTHER_PARENT, self::OTHER_CHILD, [
            'sku' => 'OTHER-1', 'price' => '7000000', 'sale' => '', 'qty' => 9, 'manage' => true,
        ]);
        \WC_Product_Variable::setChildren(self::OTHER_PARENT, [self::OTHER_CHILD]);
    }

    /** @param array{sku:string,price:string,sale:string,qty:?int,manage:bool} $child */
    private function child(int $parentId, int $id, array $child): void
    {
        $variation = new \WC_Product_Variation($id);
        $variation->set_parent_id($parentId);
        $variation->set_status('publish');
        $variation->set_sku($child['sku']);
        $variation->set_regular_price($child['price']);
        if ($child['sale'] !== '') {
            $variation->set_sale_price($child['sale']);
        }
        $variation->set_manage_stock($child['manage']);
        $variation->set_stock_quantity($child['qty']);
        $variation->set_stock_status($child['qty'] === null || $child['qty'] > 0 ? 'instock' : 'outofstock');
        $variation->save();
        // The `_stock` row the guarded statement is about. WooCommerce writes
        // it from `save()`; the stub's `save()` does not, so the fixture puts
        // the row where a real site would have it — and in the spelling
        // `save()` uses («8», not «8.000000»), which is half the reason the
        // comparison is numeric.
        if ($child['manage'] && $child['qty'] !== null) {
            State::$postMeta[$id]['_stock'] = (string) $child['qty'];
        }
        $variation->wrote = [];
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (array_keys(\WC_Product::$saved) as $id) {
            $product = \wc_get_product((int) $id);
            if (!$product instanceof \WC_Product) {
                continue;
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
                'meta' => $meta,
            ];
        }
        ksort($out);
        return $out;
    }
}
