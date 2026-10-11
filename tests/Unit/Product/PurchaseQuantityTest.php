<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;

/**
 * WHAT THIS PROVES: the declared semantics, each one asserted, and the
 * arithmetic pinned to WooCommerce's.
 *
 * The step convention is the load-bearing part. WooCommerce's Store API
 * computes the block cart's limits out of `wc_get_quantity_input_args()` and
 * then rounds the minimum UP and the maximum DOWN to multiples of the step
 * (`QuantityLimits::get_add_to_cart_limits()`). If this class disagreed, the
 * classic product page and the block cart would enforce two different rules on
 * the same basket — so the expectations below are WooCommerce's answers, and
 * `PurchaseQuantityParityTest` measures them against the real thing.
 */
final class PurchaseQuantityTest extends TestCase
{
    /** `step = 1` is every product today, and nothing about it may change. */
    public function testWithoutAStepTheRuleIsExactlyTheMinimumAndMaximumAsGiven(): void
    {
        $rule = PurchaseQuantity::of(2, 10);
        self::assertSame(2, $rule->min);
        self::assertSame(10, $rule->max);
        self::assertSame(1, $rule->step);
        self::assertTrue($rule->allows(2));
        self::assertTrue($rule->allows(7));
        self::assertTrue($rule->allows(10));
        self::assertFalse($rule->allows(1));
        self::assertFalse($rule->allows(11));
    }

    public function testEmptyAndZeroMinimumBothMeanOne(): void
    {
        self::assertSame(1, PurchaseQuantity::of(0, null)->min);
        self::assertSame(1, PurchaseQuantity::of(-5, null)->min);
        self::assertTrue(PurchaseQuantity::of(0, null)->isUnrestricted());
    }

    public function testANullMaximumMeansNoMaximum(): void
    {
        $rule = PurchaseQuantity::of(1, null);
        self::assertNull($rule->max);
        self::assertNull($rule->largestAllowed());
        self::assertTrue($rule->allows(9999));
    }

    /**
     * A maximum below the minimum is dropped rather than honoured.
     *
     * It is refused on the way in, so a rule object only ever sees one if a
     * row was written before this round or by a hand that bypassed the form —
     * and «no maximum» sells while «a maximum of nought» would take the
     * product off sale without a word.
     */
    public function testAMaximumBelowTheMinimumIsDroppedNotHonoured(): void
    {
        self::assertNull(PurchaseQuantity::of(3, 2)->max);
        self::assertNull(PurchaseQuantity::of(1, 0)->max);
        self::assertTrue(PurchaseQuantity::of(3, 2)->allows(3));
    }

    /**
     * THE CONVENTION: multiples of the step, counted from zero, with the
     * minimum raised to the next multiple.
     *
     * `min = 2, step = 3` therefore allows 3, 6, 9 — and NOT 2. This is the
     * answer WooCommerce's own block cart gives, which is the only reason it
     * is the answer here.
     */
    public function testTheStepCountsInMultiplesOfItselfAndRaisesTheMinimum(): void
    {
        $rule = PurchaseQuantity::of(2, null, 3);
        self::assertSame(3, $rule->min, 'the minimum is raised to the next multiple of the step');
        self::assertFalse($rule->allows(2));
        self::assertTrue($rule->allows(3));
        self::assertFalse($rule->allows(4));
        self::assertTrue($rule->allows(6));
        self::assertTrue($rule->allows(9));
    }

    /** And the maximum is floored to a multiple, so the ceiling is reachable. */
    public function testTheMaximumIsFlooredToAMultipleSoTheCeilingIsReachable(): void
    {
        $rule = PurchaseQuantity::of(3, 10, 3);
        self::assertSame(9, $rule->max);
        self::assertSame(9, $rule->largestAllowed());
        self::assertTrue($rule->allows(9));
        self::assertFalse($rule->allows(10), '10 is not a multiple of 3');
    }

    /**
     * A maximum that floors below the minimum is raised to it, never left
     * below — WooCommerce's `max( $minimum, … )`.
     *
     * Without that line `min = 5, step = 5, max = 7` would floor the maximum
     * to 5 and… happen to agree. `min = 6, step = 6, max = 7` floors to 6 and
     * agrees too. The case that needs the line is one where flooring lands
     * under the raised minimum, and this is it.
     */
    public function testAMaximumThatFloorsBelowTheMinimumIsRaisedToIt(): void
    {
        $rule = PurchaseQuantity::of(10, 11, 4);
        self::assertSame(12, $rule->min, 'ceil(10/4)*4');
        self::assertSame(12, $rule->max, 'floor(11/4)*4 = 8, raised to the minimum');
        self::assertTrue($rule->allows(12));
    }

    /** The reasons are named, and the minimum outranks the step. */
    public function testTheReasonNamesWhatTheBuyerCanActOn(): void
    {
        $rule = PurchaseQuantity::of(6, 12, 3);
        self::assertSame(PurchaseQuantity::BELOW_MIN, $rule->check(3), 'below the minimum, though a multiple');
        self::assertSame(PurchaseQuantity::BELOW_MIN, $rule->check(4), 'below the minimum AND off the step');
        self::assertSame(PurchaseQuantity::OFF_STEP, $rule->check(7));
        self::assertSame(PurchaseQuantity::ABOVE_MAX, $rule->check(15));
        self::assertSame(PurchaseQuantity::OK, $rule->check(9));
    }

    /**
     * `nearestAllowed()` only ever names a quantity the same rule accepts.
     *
     * It exists to put a usable number in a message. A value it returned that
     * the rule then refused would send a buyer round in a circle, so every
     * answer is asserted back through `allows()`.
     */
    public function testEveryNearestAllowedValueIsItselfAllowed(): void
    {
        foreach ([
            PurchaseQuantity::of(1, null),
            PurchaseQuantity::of(2, 10),
            PurchaseQuantity::of(2, null, 3),
            PurchaseQuantity::of(3, 10, 3),
            PurchaseQuantity::of(10, 11, 4),
        ] as $rule) {
            foreach (range(0, 20) as $wanted) {
                $near = $rule->nearestAllowed($wanted);
                self::assertTrue(
                    $rule->allows($near),
                    'nearestAllowed(' . $wanted . ') = ' . $near . ' is refused by the same rule'
                );
            }
        }
    }

    public function testUnrestrictedIsTheRuleThatAllowsWhatAPlainShopWould(): void
    {
        self::assertTrue(PurchaseQuantity::unrestricted()->isUnrestricted());
        self::assertFalse(PurchaseQuantity::of(2, null)->isUnrestricted());
        self::assertFalse(PurchaseQuantity::of(1, 5)->isUnrestricted());
        self::assertFalse(PurchaseQuantity::of(1, null, 2)->isUnrestricted());
    }
}
