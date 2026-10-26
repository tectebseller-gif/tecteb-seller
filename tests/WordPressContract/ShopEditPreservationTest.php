<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ShopEditMerge;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedShopFields;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Tests\Support\NoSpecTemplates;

/**
 * WHAT THIS PROVES: a price, SKU or weight edited by hand in WooCommerce
 * survives every later projection — and a vendor's fresh change still reaches
 * the shop at once.
 *
 * ⛔ **Every test here starts from the state the owner named: a product that
 * has ALREADY BEEN TAKEN IN.** «آزمون هدفمند باید از محصول ازقبل‌واردشده شروع
 * شود.» That is not a detail of convenience: intake copies the shop's own
 * values into the marketplace row, so the first projection after it writes
 * nothing new and records what it found — and only from there can «somebody
 * edited the shop» be told from «we have no idea who wrote this».
 *
 * The product is the STUB and is never reported as WooCommerce; real
 * WooCommerce stays `Not Run`. What makes the stub enough is `wrote`: it
 * records which setters were CALLED, so «this field was not written» is
 * measurable — stronger than comparing values, because a write of the same
 * string passes a value comparison and is still a write.
 */
final class ShopEditPreservationTest extends ContractTestCase
{
    private const VENDOR = 77;
    private const PRICE = 200000;
    private const SKU = 'SKU-INTAKE';

    /** Grams on our side; `'0.012'` is the kilogram string the shop holds. */
    private const GRAMS = 12;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEditInWooCommerceSurvivesTheNextProjection(): void
    {
        $wcId = $this->takenIn();

        // What the owner does: open the product in WooCommerce and change it.
        $this->shopEdit($wcId, static function (\WC_Product $p): void {
            $p->set_regular_price('480000');
            $p->set_sku('SKU-OWNER');
            $p->set_weight('0.014');
        });

        $result = $this->projector()->project($this->product($wcId));
        self::assertTrue($result['ok'], (string) $result['code']);

        $saved = \WC_Product::$saved[$wcId];
        // The three setters were NOT called, so nothing could have been
        // written — the measurement `alpha.82` would have failed.
        self::assertNotContains('regular_price', $saved->wrote);
        self::assertNotContains('sku', $saved->wrote);
        self::assertNotContains('weight', $saved->wrote);
        self::assertSame('480000', (string) $saved->get_regular_price());
        self::assertSame('SKU-OWNER', (string) $saved->get_sku());
        self::assertSame('0.014', (string) $saved->get_weight());

        // And each one is NAMED, with both numbers, for the manager's screen.
        $report = ProjectedShopFields::conflicts($wcId);
        self::assertSame(
            [ShopEditMerge::PRICE, ShopEditMerge::SKU, ShopEditMerge::WEIGHT],
            array_keys($report)
        );
        foreach ($report as $entry) {
            self::assertSame(ShopEditMerge::KEEP_EDITED, $entry['answer']);
        }
        self::assertSame('480000', $report[ShopEditMerge::PRICE]['shop']);
        self::assertSame((string) self::PRICE, $report[ShopEditMerge::PRICE]['ours']);
        // Grams on both sides of the report, which is the unit every
        // marketplace screen already shows.
        self::assertSame('14', $report[ShopEditMerge::WEIGHT]['shop']);
        self::assertSame((string) self::GRAMS, $report[ShopEditMerge::WEIGHT]['ours']);

        // `price_held` is NOT set: that flag means «the marketplace has no
        // price», the review list and the health page read it as exactly
        // that, and this product has one.
        self::assertFalse($result['price_held']);
        self::assertSame('', (string) get_post_meta($wcId, WooCommerceProjector::PRICE_HELD_META, true));

        // ⛔ AND AGAIN, because «survives the next projection» is not the
        // promise — «survives every projection» is.
        //
        // A projection that recorded the value it had just DECLINED to write
        // would claim the owner's edit as its own, and the run after that
        // would overwrite it with a clear conscience. One more projection is
        // the only thing that can tell the two apart.
        \WC_Product::$saved[$wcId]->wrote = [];
        self::assertTrue($this->projector()->project($this->product($wcId))['ok']);
        self::assertNotContains('regular_price', \WC_Product::$saved[$wcId]->wrote);
        self::assertSame('480000', (string) \WC_Product::$saved[$wcId]->get_regular_price());
        self::assertSame(
            (string) self::PRICE,
            ProjectedShopFields::record($wcId)[ShopEditMerge::PRICE]['sent'],
            'the record still says what WE last wrote, not what somebody else put there'
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFreshVendorChangeStillReachesAnUneditedShop(): void
    {
        // The control, and the half of this that is easy to break: a fix that
        // keeps everything is a restriction, and «این اختیار نباید محدود
        // شود».
        $wcId = $this->takenIn();

        $result = $this->projector()->project($this->product($wcId, price: 500000, sku: 'SKU-VENDOR', grams: 20));
        self::assertTrue($result['ok'], (string) $result['code']);

        $saved = \WC_Product::$saved[$wcId];
        self::assertSame('500000', (string) $saved->get_regular_price());
        self::assertSame('SKU-VENDOR', (string) $saved->get_sku());
        self::assertSame('0.02', (string) $saved->get_weight());
        self::assertSame([], ProjectedShopFields::conflicts($wcId), 'nothing to report when nobody else edited');
        self::assertSame(
            [ShopEditMerge::WRITE_FRESH, ShopEditMerge::WRITE_FRESH, ShopEditMerge::WRITE_FRESH],
            array_values($result['shop_fields'])
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWhenBothSidesMovedTheFreshValueIsAppliedAndTheConflictIsRecorded(): void
    {
        $wcId = $this->takenIn();
        $this->shopEdit($wcId, static function (\WC_Product $p): void {
            $p->set_regular_price('480000');
        });

        $result = $this->projector()->project($this->product($wcId, price: 500000));
        self::assertTrue($result['ok'], (string) $result['code']);

        // «تغییر جدید باید اعمال شود» …
        self::assertSame('500000', (string) \WC_Product::$saved[$wcId]->get_regular_price());
        // … «اما تعارض باید ثبت و گزارش شود».
        $report = ProjectedShopFields::conflicts($wcId);
        self::assertArrayHasKey(ShopEditMerge::PRICE, $report);
        self::assertSame(ShopEditMerge::WRITE_CONFLICT, $report[ShopEditMerge::PRICE]['answer']);
        self::assertSame('480000', $report[ShopEditMerge::PRICE]['shop'], 'the number that was overwritten is kept on the report');
        self::assertSame('500000', $report[ShopEditMerge::PRICE]['ours']);
        // The SKU and the weight moved on neither side, so they are not on it.
        self::assertArrayNotHasKey(ShopEditMerge::SKU, $report);
        self::assertArrayNotHasKey(ShopEditMerge::WEIGHT, $report);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAShopPriceThatCannotBeComparedExactlyIsNeitherWrittenNorRounded(): void
    {
        // `alpha.67`: exact or refused, never rounded into agreement.
        $wcId = $this->takenIn();
        $this->shopEdit($wcId, static function (\WC_Product $p): void {
            $p->set_regular_price('480000.50');
        });

        $result = $this->projector()->project($this->product($wcId, price: 500000));
        self::assertTrue($result['ok'], (string) $result['code']);
        self::assertNotContains('regular_price', \WC_Product::$saved[$wcId]->wrote);
        self::assertSame('480000.50', (string) \WC_Product::$saved[$wcId]->get_regular_price());
        $report = ProjectedShopFields::conflicts($wcId);
        self::assertSame(ShopEditMerge::UNREADABLE, $report[ShopEditMerge::PRICE]['answer']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCategorySetAndTheAttributesAreUntouchedByAllOfThis(): void
    {
        // The owner's standing requirement, asserted on the path this round
        // changed: «تمام دسته‌های موجود WooCommerce… باید بدون حذف یا کاهش
        // روابط حفظ شوند.»
        $wcId = $this->takenIn(static function (\WC_Product $p): void {
            $p->set_category_ids([260, 261]);
        });
        $this->shopEdit($wcId, static function (\WC_Product $p): void {
            $p->set_regular_price('480000');
        });

        $product = $this->product($wcId, categoryIds: [260, 261]);
        self::assertTrue($this->projector()->project($product)['ok']);

        $after = array_map('intval', (new \WC_Product_Simple($wcId))->get_category_ids());
        sort($after);
        self::assertSame([260, 261], $after, 'the whole set, parent and child, untouched');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testANewProductGetsItsPriceWrittenEvenThoughTheShopHoldsNothing(): void
    {
        // ⛔ The regression this rule could most easily cause, and the reason
        // `$isNew` travels into the merge: on a product this projection just
        // created the shop holds nothing, so a value comparison would differ,
        // read as «no record and not a no-op» and NEVER WRITE A PRICE.
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $result = $this->projector()->project($this->product(null));
        self::assertTrue($result['ok'], (string) $result['code']);
        $saved = \WC_Product::$saved[$result['wc_product_id']];
        self::assertSame((string) self::PRICE, (string) $saved->get_regular_price());
        self::assertSame(self::SKU, (string) $saved->get_sku());
        self::assertSame('0.012', (string) $saved->get_weight());
        self::assertSame([], ProjectedShopFields::conflicts((int) $result['wc_product_id']));
        // And the record is of what the SHOP HOLDS after the save, not of
        // what was sent (`alpha.26`).
        $record = ProjectedShopFields::record((int) $result['wc_product_id']);
        self::assertSame(
            ['sent' => (string) self::PRICE, 'shop' => (string) self::PRICE],
            $record[ShopEditMerge::PRICE]
        );
        self::assertSame(['sent' => '12', 'shop' => '12'], $record[ShopEditMerge::WEIGHT]);
    }

    /**
     * A product as it stands AFTER intake, and after one projection.
     *
     * The shop already holds the marketplace's own values — which is what
     * intake leaves behind, because it copies them from the shop in the first
     * place — so the first projection writes nothing new and records what it
     * found. Everything this class measures starts here.
     */
    private function takenIn(?callable $arrange = null): int
    {
        $this->bootPlugin(true);
        $this->declareWooCommerce();

        $existing = new \WC_Product_Simple();
        $existing->set_regular_price((string) self::PRICE);
        $existing->set_sku(self::SKU);
        $existing->set_weight('0.012');
        if ($arrange !== null) {
            $arrange($existing);
        }
        $wcId = (int) $existing->save();
        update_post_meta($wcId, WooCommerceProjector::PRODUCT_META, 41);
        update_post_meta($wcId, WooCommerceProjector::VENDOR_META, self::VENDOR);

        $first = $this->projector()->project($this->product($wcId));
        self::assertTrue($first['ok'], (string) $first['code']);
        // Nothing was in dispute, so nothing is reported — and the record now
        // exists, which is what makes the next projection able to tell an
        // edit from a value nobody has claimed.
        self::assertSame([], ProjectedShopFields::conflicts($wcId));
        self::assertArrayHasKey(ShopEditMerge::PRICE, ProjectedShopFields::record($wcId));
        \WC_Product::$saved[$wcId]->wrote = [];
        return $wcId;
    }

    /** What a person does in the WooCommerce editor: change a value, save. */
    private function shopEdit(int $wcId, callable $edit): void
    {
        $product = \wc_get_product($wcId);
        self::assertInstanceOf(\WC_Product::class, $product);
        $edit($product);
        $product->save();
        \WC_Product::$saved[$wcId]->wrote = [];
    }

    /**
     * `isAvailable()` asks for the class, so the test has to declare it — and
     * `WpDependencyProbeTest` is about that class being ABSENT, which is why
     * every test here runs in its own process.
     */
    private function declareWooCommerce(): void
    {
        if (!class_exists('WooCommerce')) {
            eval('class WooCommerce {}');
        }
        \WC_Product::reset();
    }

    private function projector(): WooCommerceProjector
    {
        return new WooCommerceProjector(new NoSpecTemplates(null));
    }

    /** @param list<int> $categoryIds */
    private function product(
        ?int $wcProductId,
        int $price = self::PRICE,
        string $sku = self::SKU,
        int $grams = self::GRAMS,
        array $categoryIds = []
    ): Product {
        return new Product(
            41,
            self::VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                shortDescription: 'یک جمله',
                priceMinor: $price,
                sku: $sku,
                stock: 5,
                weightGrams: $grams,
                categoryIds: $categoryIds
            ),
            ProductStatus::Published,
            [],
            [],
            0,
            '',
            0,
            null,
            '',
            $wcProductId
        );
    }
}
