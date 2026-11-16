<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductTypeChange;

/**
 * When the kind of a product may change — and the four answers.
 *
 * The rule follows from one measurement rather than from a preference:
 * `WooCommerceProjector` picks the WooCommerce class in `blank()`, and
 * `blank()` is reached only when the product has no shop post yet. For a
 * product already in the shop the existing object is reused and its type is
 * never written. `ProjectorNeverChangesAnExistingTypeTest` pins that from the
 * projector's own bytes; this pins what follows from it.
 */
final class ProductTypeChangeTest extends TestCase
{
    public function testTheSameTypeIsNotAChange(): void
    {
        self::assertNull(ProductTypeChange::refusal(ProductType::SIMPLE, ProductType::SIMPLE, true, true));
        self::assertNull(ProductTypeChange::refusal(ProductType::VARIABLE, ProductType::VARIABLE, true, false));
    }

    /** A product that has not reached the shop can still be either kind. */
    public function testBeforeTheProductReachesTheShopEitherKindIsFree(): void
    {
        self::assertNull(ProductTypeChange::refusal(ProductType::SIMPLE, ProductType::VARIABLE, false, true));
        self::assertNull(ProductTypeChange::refusal(ProductType::VARIABLE, ProductType::SIMPLE, false, true));
    }

    /**
     * Once it is in the shop, neither direction.
     *
     * Simple to variable would leave our row saying variable beside a simple
     * WooCommerce product, and every combination built afterwards would stop
     * at `projectVariations()`'s `instanceof WC_Product_Variable` guard.
     * Variable to simple is worse: the shop's `product_variation` children
     * would be parented to a product that no longer admits them, and the
     * instruction forbids both rebuilding them and deleting them.
     */
    public function testOnceItIsInTheShopNeitherDirectionIsAllowed(): void
    {
        self::assertSame(
            ProductTypeChange::ALREADY_IN_SHOP,
            ProductTypeChange::refusal(ProductType::SIMPLE, ProductType::VARIABLE, true, true)
        );
        self::assertSame(
            ProductTypeChange::ALREADY_IN_SHOP,
            ProductTypeChange::refusal(ProductType::VARIABLE, ProductType::SIMPLE, true, true)
        );
    }

    /**
     * Ownership answers first, and keeps the name it has carried since
     * `alpha.106` — audit rows have been written with it.
     */
    public function testFiguresThatBelongToTheShopAnswerFirstAndWithTheOlderCode(): void
    {
        self::assertSame(
            ProductTypeChange::NOT_OURS,
            ProductTypeChange::refusal(ProductType::VARIABLE, ProductType::SIMPLE, true, false),
            'linked and not ours: the older, wider statement wins'
        );
        self::assertSame(
            ProductTypeChange::NOT_OURS,
            ProductTypeChange::refusal(ProductType::VARIABLE, ProductType::SIMPLE, false, false),
            'and it does not need the row to be linked'
        );
        self::assertSame('type_change_not_allowed', ProductTypeChange::NOT_OURS, 'the code is not renamed');
    }
}
