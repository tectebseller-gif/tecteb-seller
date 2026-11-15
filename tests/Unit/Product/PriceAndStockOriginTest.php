<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;

/**
 * WHAT THIS PROVES: one product gets ONE answer about where its price and
 * stock live, and «نمی‌دانم» never collapses into «ours».
 *
 * Eight readers consult this — the form, readiness, two save paths, the
 * projector, the store page, the REST route and the wholesale tiers — and in
 * `alpha.105` each decided for itself. A precedence that was right in one of
 * them would be eight screens disagreeing about one product (`alpha.78`).
 */
final class PriceAndStockOriginTest extends TestCase
{
    private function child(?int $price = 5_187_400, bool $enabled = true): StorefrontVariation
    {
        return new StorefrontVariation(
            11965,
            ['pa_size' => '100'],
            'BPI-100',
            $price,
            null,
            $price,
            $enabled,
            false,
            null,
            'instock',
            'no',
            0
        );
    }

    public function testASimpleProductsOwnFiguresAreAlwaysTheRealOnes(): void
    {
        // ⛔ Every other input says «the shop holds combinations» and the
        // answer is still OURS. A simple product is the branch nearly the
        // whole catalogue takes and it must not change by one byte: a guard
        // wider than the defect is the bigger defect (`alpha.60`).
        $origin = PriceAndStockOrigin::decide(
            ProductType::SIMPLE,
            false,
            true,
            StorefrontVariationSet::read([$this->child()], 0)
        );

        self::assertSame(PriceAndStockOrigin::OURS, $origin->state);
        self::assertSame('simple_product', $origin->reason);
        self::assertTrue($origin->mayWriteParentFigures());
        self::assertTrue($origin->offersParentFigures());
    }

    public function testAVariableProductWeBuiltKeepsItsFiguresHere(): void
    {
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            true,
            true,
            StorefrontVariationSet::read([$this->child()], 0)
        );

        self::assertSame(PriceAndStockOrigin::OURS, $origin->state);
        self::assertSame('marketplace_combinations', $origin->reason);
        self::assertTrue($origin->mayWriteParentFigures());
    }

    public function testAnImportedVariableParentsFiguresBelongToTheShop(): void
    {
        // The shape of TMC #189 / WC #11955: our tables empty, the shop's
        // parent carrying real children.
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            StorefrontVariationSet::read([$this->child()], 0)
        );

        self::assertSame(PriceAndStockOrigin::SHOP, $origin->state);
        self::assertSame('shop_combinations', $origin->reason);
        self::assertFalse($origin->mayWriteParentFigures());
        self::assertFalse($origin->offersParentFigures());
        self::assertFalse($origin->parentFiguresAreReal());
    }

    public function testNobodyAskedIsNotPermissionToWrite(): void
    {
        $origin = PriceAndStockOrigin::decide(ProductType::VARIABLE, false, true, null);

        self::assertSame(PriceAndStockOrigin::UNKNOWN, $origin->state);
        self::assertSame('not_read', $origin->reason);
        self::assertFalse($origin->mayWriteParentFigures());
    }

    public function testAFailedReadIsNeverPermissionToWrite(): void
    {
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE)
        );

        self::assertSame(PriceAndStockOrigin::UNKNOWN, $origin->state);
        self::assertSame('unreadable', $origin->reason);
        self::assertFalse($origin->mayWriteParentFigures());
    }

    /**
     * ⛔ A SUCCESSFUL READING THAT SAYS «NOTHING HERE» IS NOT A FAILED ONE,
     * and this test exists because the first version of `decide()` confused
     * them.
     *
     * None of these three can have children — WooCommerce is not installed,
     * the storefront product is gone, the storefront product is not variable
     * — so none of them can be holding this product's figures. Reading them as
     * «unknown» refused writes that have always been allowed: it broke
     * `VariationFlowTest::testAProductWhoseCombinationsAreOursStaysEditable`,
     * where a marketplace variable product could no longer have its FIRST
     * attribute defined. A guard wider than the defect is the bigger defect
     * (`alpha.60`), and the control test is what caught it.
     *
     * @dataProvider confirmedEmptyStates
     */
    public function testAConfirmedEmptyShopLeavesTheFiguresOurs(string $state, string $expected): void
    {
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            StorefrontVariationSet::unavailable($state)
        );

        self::assertSame(PriceAndStockOrigin::OURS, $origin->state, $state);
        self::assertSame($expected, $origin->reason);
        self::assertTrue($origin->mayWriteParentFigures());
    }

    /** @return array<string,array{string,string}> */
    public static function confirmedEmptyStates(): array
    {
        return [
            'woocommerce absent' => [StorefrontVariationSet::NO_WOOCOMMERCE, 'no_woocommerce'],
            'storefront product gone' => [
                StorefrontVariationSet::NO_STOREFRONT_PRODUCT,
                'no_storefront_product',
            ],
            'shop product is not variable' => [
                StorefrontVariationSet::NOT_VARIABLE,
                'shop_product_not_variable',
            ],
        ];
    }

    public function testAPartialReadIsUnknownRatherThanTheWholeTruth(): void
    {
        // Two children listed by the shop, one of them unreadable. Writing the
        // parent on the strength of children we could not see is worse than
        // the `alpha.100` lie of printing a partial count as the total.
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            StorefrontVariationSet::read([$this->child()], 0, 1)
        );

        self::assertSame(PriceAndStockOrigin::UNKNOWN, $origin->state);
        self::assertSame('children_unreadable', $origin->reason);
        self::assertFalse($origin->mayWriteParentFigures());
    }

    public function testAConfirmedEmptyShopIsNotTheSameAsAFailedRead(): void
    {
        // ⛔ The distinction §P0-پ asks for by name. A variable parent the shop
        // read successfully and which really has no children is a real, empty
        // answer: there are no combinations anywhere, so nothing is foreign
        // and readiness refuses it by name instead.
        $origin = PriceAndStockOrigin::decide(
            ProductType::VARIABLE,
            false,
            true,
            StorefrontVariationSet::read([], 0)
        );

        self::assertSame(PriceAndStockOrigin::OURS, $origin->state);
        self::assertSame('shop_has_no_children', $origin->reason);
    }

    public function testAVariableProductWithNoStorefrontProductIsStillOurs(): void
    {
        // A product being created: type chosen, nothing anywhere yet. The
        // vendor must still be able to type a price, and readiness refuses it
        // for having no attributes — which is the honest refusal.
        $origin = PriceAndStockOrigin::decide(ProductType::VARIABLE, false, false, null);

        self::assertSame(PriceAndStockOrigin::OURS, $origin->state);
        self::assertSame('no_storefront_product', $origin->reason);
        self::assertTrue($origin->offersParentFigures());
    }
}
