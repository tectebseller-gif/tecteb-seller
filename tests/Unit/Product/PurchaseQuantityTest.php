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
     * A maximum below the minimum is NOT «no maximum».
     *
     * `alpha.45` dropped it to `null`, and `null` is unlimited — so a vendor
     * who typed «at most 2» got a rule allowing 9,999. The question is never
     * which reading sells; it is which reading can only ever sell LESS than
     * the vendor asked for.
     */
    public function testAMaximumBelowTheMinimumIsRefusedNotTurnedIntoUnlimited(): void
    {
        foreach ([[3, 2], [1, 0], [10, 1]] as [$min, $max]) {
            $rule = PurchaseQuantity::of($min, $max);
            self::assertFalse($rule->isSatisfiable(), "{$min}/{$max} cannot be satisfied");
            self::assertNotNull($rule->max, 'the stated maximum is kept for the message, not dropped');
            self::assertFalse($rule->allows(9999), 'an invalid maximum must never become unlimited');
            self::assertFalse($rule->allows($min));
            self::assertSame(PurchaseQuantity::UNSATISFIABLE, $rule->check($min));
        }
    }

    /**
     * THE CEILING IS NEVER RAISED — the whole of §1, as its own test.
     *
     * Deliberately NOT the parity measurement against WooCommerce's
     * `QuantityLimits`: that compares two implementations and both of them
     * raise, so it cannot see this. WooCommerce's
     * `$maximum = max( $minimum, … )` is the one line this object does not
     * follow, and the property has to be asserted directly.
     */
    public function testTheStatedCeilingIsNeverExceeded(): void
    {
        foreach (range(1, 14) as $min) {
            foreach (range(1, 14) as $max) {
                foreach (range(1, 6) as $step) {
                    $rule = PurchaseQuantity::of($min, $max, $step);
                    if (!$rule->isSatisfiable()) {
                        // Nothing is allowed, so nothing can exceed anything.
                        self::assertFalse($rule->allows($max + 1));
                        continue;
                    }
                    self::assertNotNull($rule->largestAllowed());
                    self::assertLessThanOrEqual(
                        $max,
                        $rule->largestAllowed(),
                        "of({$min}, {$max}, {$step}) raised the vendor's ceiling"
                    );
                    // …and nothing above the stated maximum is ever allowed.
                    foreach (range($max + 1, $max + 8) as $over) {
                        self::assertFalse(
                            $rule->allows($over),
                            "of({$min}, {$max}, {$step}) allows {$over}, above the stated maximum"
                        );
                    }
                }
            }
        }
    }

    /**
     * A window with no multiple in it is refused, not widened.
     *
     * `min = 10, max = 11, step = 4`: `alpha.45` answered `12/12` — eleven
     * became twelve. There is no fourth multiple between ten and eleven, so
     * the only honest answers are «refuse» or «raise», and raising is the one
     * thing a ceiling must not do.
     */
    public function testAWindowWithNoMultipleInItIsUnsatisfiable(): void
    {
        $rule = PurchaseQuantity::of(10, 11, 4);
        self::assertFalse($rule->isSatisfiable());
        self::assertFalse($rule->allows(12), 'twelve is above the stated maximum of eleven');
        self::assertFalse($rule->allows(11));
        self::assertFalse($rule->allows(8), 'eight is below the stated minimum of ten');
        self::assertFalse($rule->isUnrestricted());
        self::assertNull($rule->largestAllowed());
    }

    /**
     * `validate()` is the one rule all four doors ask, and it names what is
     * wrong.
     */
    public function testValidateNamesEachRefusalAndPassesWhatIsWorkable(): void
    {
        self::assertNull(PurchaseQuantity::validate(1, null, 1));
        self::assertNull(PurchaseQuantity::validate(3, 10, 3));
        self::assertNull(PurchaseQuantity::validate(2, 6, 2));
        self::assertNull(PurchaseQuantity::validate(5, 5, 5));

        self::assertSame(PurchaseQuantity::INVALID_MIN, PurchaseQuantity::validate(0, null, 1));
        self::assertSame(PurchaseQuantity::INVALID_STEP, PurchaseQuantity::validate(1, null, 0));
        self::assertSame(PurchaseQuantity::INVALID_MAX, PurchaseQuantity::validate(5, 2, 1));
        self::assertSame(PurchaseQuantity::INVALID_MAX, PurchaseQuantity::validate(1, 0, 1));
        self::assertSame(PurchaseQuantity::NO_MULTIPLE, PurchaseQuantity::validate(10, 11, 4));

        // Every combination `validate()` passes must produce a satisfiable
        // rule, and every one it refuses must not. Two statements about the
        // same pair of methods that would otherwise be free to drift.
        foreach (range(1, 14) as $min) {
            foreach (range(1, 14) as $max) {
                foreach (range(1, 6) as $step) {
                    self::assertSame(
                        PurchaseQuantity::validate($min, $max, $step) === null,
                        PurchaseQuantity::of($min, $max, $step)->isSatisfiable(),
                        "validate() and of() disagree about {$min}/{$max}/{$step}"
                    );
                }
            }
        }
    }

    /** A refusal names two maxima that would work, so it can be acted on. */
    public function testWorkableAroundNamesValuesTheRuleWouldAccept(): void
    {
        [$below, $above] = PurchaseQuantity::workableAround(11, 4);
        self::assertSame(8, $below);
        self::assertSame(12, $above);
        self::assertNull(PurchaseQuantity::validate(8, $above, 4), 'the value named above must work');
        self::assertNull(PurchaseQuantity::validate(4, $below, 4), 'the value named below must work');
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
     * A maximum that floors to exactly the raised minimum is fine.
     *
     * The boundary worth pinning: `min = 5, step = 5, max = 7` floors to 5,
     * which IS the effective minimum, so the rule is satisfiable and allows
     * exactly one quantity. One step further out — `min = 10, max = 11,
     * step = 4` — and there is nothing in the window at all, which the test
     * above covers.
     */
    public function testAMaximumThatFloorsToExactlyTheMinimumAllowsThatOneQuantity(): void
    {
        $rule = PurchaseQuantity::of(5, 7, 5);
        self::assertTrue($rule->isSatisfiable());
        self::assertSame(5, $rule->min);
        self::assertSame(5, $rule->max, 'floored to 5, never raised to 7');
        self::assertTrue($rule->allows(5));
        self::assertFalse($rule->allows(7), 'seven is not a multiple of five');
        self::assertFalse($rule->allows(10), 'ten is above the stated maximum of seven');
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
            PurchaseQuantity::of(5, 7, 5),
            PurchaseQuantity::of(12, 20, 4),
        ] as $rule) {
            self::assertTrue($rule->isSatisfiable(), 'this test is about rules that allow something');
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
        // An unsatisfiable rule is not «unrestricted» either, however the
        // numbers read: nothing is allowed under it.
        self::assertFalse(PurchaseQuantity::of(10, 11, 4)->isUnrestricted());
    }
}
