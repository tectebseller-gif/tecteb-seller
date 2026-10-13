<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * «Min Max Control» 8.1.0's rule, argued with directly — and the one thing
 * that has to hold over every combination rather than over chosen examples:
 *
 *     a rule this marketplace says it CAN hold allows exactly the quantities
 *     Min Max allowed. Not a superset, not a subset. The same set.
 *
 * That invariant is the whole safety property of the migration. «تبدیل نباید
 * محدودیت را بی‌صدا حذف یا گشاد کند» is unprovable from a handful of cases —
 * `alpha.46` paid for exactly that mistake, where a unit test asserting the
 * defect's own output kept a suite green while a ceiling was being raised — so
 * it is checked over a grid of 14 × 14 × 6 windows, and the first version of
 * this file FAILED it, which is how the step-base difference was pinned down
 * rather than guessed at.
 */
final class MinMaxRuleTest extends TestCase
{
    public function testAnEmptyMinimumBecomesOneAndAStringZeroSurvives(): void
    {
        self::assertSame(1, MinMaxRule::from(MinMaxRule::AT_PRODUCT, '', '', '')->min);
        self::assertSame(0, MinMaxRule::from(MinMaxRule::AT_PRODUCT, '0', '', '')->min);
        // `finalizeArgs()`'s test is `empty($min) && $min !== '0'`, strictly —
        // so an INTEGER zero arriving as the string `0.0` is not the kept zero.
        self::assertSame(1, MinMaxRule::from(MinMaxRule::AT_PRODUCT, '0.0', '', '')->min);
    }

    public function testAnEmptyOrNegativeMaximumIsNoCeilingOfMinMaxsOwn(): void
    {
        self::assertNull(MinMaxRule::from(MinMaxRule::AT_PRODUCT, '2', '', '1')->max);
        // The GLOBAL default maximum is -1 and it reaches the cart as -1,
        // where `update_cart_validation()` reads `max < 0` as unlimited.
        self::assertNull(MinMaxRule::from(MinMaxRule::AT_GLOBAL, '1', '-1', '1')->max);
        // Zero is replaced by stock in `finalizeArgs()`, which is no ceiling
        // of Min Max's own — stock stays WooCommerce's number.
        self::assertNull(MinMaxRule::from(MinMaxRule::AT_PRODUCT, '1', '0', '1')->max);
        self::assertSame(6, MinMaxRule::from(MinMaxRule::AT_PRODUCT, '2', '6', '2')->max);
    }

    public function testTheShippedGlobalDefaultRestrictsNothing(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_GLOBAL, '1', '-1', '1');
        self::assertTrue($rule->isUnrestricted());
        self::assertSame([], $rule->problems());
    }

    public function testAProductLevelAnswersWhenAnyLimitIsSetAndDefaultQuantityAlone(): void
    {
        // `assignInputArg()` takes the product level on any of the THREE
        // limits, and `default_quantity` is not one of them.
        self::assertTrue(MinMaxRule::from(MinMaxRule::AT_PRODUCT, '', '5', '')->answers());
        self::assertFalse(MinMaxRule::from(MinMaxRule::AT_PRODUCT, '', '', '', '4')->answers());
        self::assertFalse(MinMaxRule::from(MinMaxRule::AT_PRODUCT, '', '', '', '4')->isEmpty());
    }

    /** THE DIFFERENCE. The step counts from the minimum, not from zero. */
    public function testAStepCountedFromTheMinimumIsRefusedByName(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '2', '11', '3');
        self::assertSame([2, 5, 8, 11], $rule->allowedUpTo(12)['source']);
        self::assertSame([3, 6, 9], $rule->allowedUpTo(12)['converted']);
        self::assertSame([MinMaxRule::STEP_BASE_DIFFERS], $rule->problems());
        self::assertFalse($rule->convertible());
    }

    public function testAStepThatDividesTheMinimumConvertsUnchanged(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '4', '10', '2');
        self::assertSame([], $rule->problems());
        $sets = $rule->allowedUpTo(14);
        self::assertSame([4, 6, 8, 10], $sets['source']);
        self::assertSame($sets['source'], $sets['converted']);
    }

    public function testEveryStepOfOneConverts(): void
    {
        foreach ([['1', ''], ['3', '9'], ['7', '7'], ['2', '-1']] as [$min, $max]) {
            $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, $min, $max, '1');
            self::assertSame([], $rule->problems(), "min={$min} max={$max}");
        }
    }

    public function testAMaximumBelowTheMinimumIsRefusedByName(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '5', '2', '1');
        self::assertSame([MinMaxRule::MAX_BELOW_MIN], $rule->problems());
        // And Min Max itself allows nothing through that window, so refusing
        // removes no permission. Measured rather than asserted from reading.
        self::assertSame([], $rule->allowedUpTo(40)['source']);
    }

    public function testADefaultQuantityThatDiffersFromTheMinimumIsRefusedByName(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '2', '10', '1', '6');
        self::assertSame([MinMaxRule::DEFAULT_QUANTITY_UNSUPPORTED], $rule->problems());
    }

    public function testADefaultQuantityEqualToTheMinimumIsNoDifference(): void
    {
        // WooCommerce's quantity input already starts at the minimum, so this
        // configuration asks for what happens anyway.
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '3', '9', '1', '3');
        self::assertSame([], $rule->problems());
    }

    public function testAZeroMinimumIsFlooredLoudly(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '0', '5', '1');
        self::assertSame([MinMaxRule::MIN_ZERO_FLOORED], $rule->notes());
        self::assertSame([], $rule->problems());
        self::assertSame(1, $rule->minAfterConversion());
    }

    /**
     * The term level's MAXIMUM default is `1`, not «unlimited» — and that is
     * reproduced rather than corrected.
     *
     * `setTermwiseArgs()` reads `$termwise_args['_max'] ?? 1`. A term rule
     * configured with only a minimum therefore enforces a maximum of ONE on
     * every product in that term. Correcting it here would migrate a rule the
     * shop never enforced.
     */
    public function testATermRuleWithOnlyAMinimumCarriesAMaximumOfOne(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_TERM, '3', '1', '1');
        self::assertSame(1, $rule->max);
        self::assertSame([MinMaxRule::MAX_BELOW_MIN], $rule->problems());
    }

    /**
     * THE INVARIANT, over 14 × 14 × 6 windows: a rule reported convertible
     * allows exactly what Min Max allowed, and nothing reported convertible
     * ever widens or narrows the set.
     */
    public function testAConvertibleRuleAllowsExactlyWhatMinMaxAllowed(): void
    {
        $checked = 0;
        $convertible = 0;
        for ($min = 0; $min <= 13; $min++) {
            for ($max = 0; $max <= 13; $max++) {
                for ($step = 1; $step <= 6; $step++) {
                    $rule = MinMaxRule::from(
                        MinMaxRule::AT_PRODUCT,
                        (string) $min,
                        (string) $max,
                        (string) $step
                    );
                    $checked++;
                    $sets = $rule->allowedUpTo(80);
                    if (!$rule->convertible()) {
                        continue;
                    }
                    $convertible++;
                    // A zero minimum is floored to one, and quantity nought is
                    // not a quantity WooCommerce adds to a cart — so the
                    // comparison starts at one, which `allowedUpTo()` already
                    // does.
                    self::assertSame(
                        $sets['source'],
                        $sets['converted'],
                        sprintf('min=%d max=%d step=%d', $min, $max, $step)
                    );
                }
            }
        }
        self::assertSame(14 * 14 * 6, $checked);
        // MEASURED, not assumed (`alpha.22`'s rule). Of the 1,176 windows in
        // the grid, 244 convert. The rest are refused, and the three reasons
        // account for all of them — asserted below rather than left as a
        // round number nobody can check.
        self::assertSame(244, $convertible);
    }

    /**
     * And the other half: every rule that is NOT convertible really does
     * differ, so nothing is refused for no reason.
     *
     * A refusal costs the owner work — the Min Max exit stays blocked for that
     * rule — so a refusal that was unnecessary is a defect of its own.
     */
    public function testEveryRefusedStepRuleReallyDiffers(): void
    {
        $refused = 0;
        for ($min = 1; $min <= 13; $min++) {
            for ($max = 1; $max <= 13; $max++) {
                for ($step = 2; $step <= 6; $step++) {
                    $rule = MinMaxRule::from(
                        MinMaxRule::AT_PRODUCT,
                        (string) $min,
                        (string) $max,
                        (string) $step
                    );
                    if ($rule->problems() !== [MinMaxRule::STEP_BASE_DIFFERS]) {
                        continue;
                    }
                    $refused++;
                    $sets = $rule->allowedUpTo(80);
                    self::assertNotSame(
                        $sets['source'],
                        $sets['converted'],
                        sprintf('refused for nothing: min=%d max=%d step=%d', $min, $max, $step)
                    );
                }
            }
        }
        self::assertGreaterThan(20, $refused);
    }

    /**
     * The source values are kept byte for byte — «مقدارهای منبع حفظ شوند» —
     * because the ledger stores them and the owner has to be able to read the
     * shop's own configuration back out of this plugin's table.
     */
    public function testTheSourceStringsAreKeptAsGiven(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, ' 2 ', '06', '3.0', '');
        self::assertSame(' 2 ', $rule->sourceMin);
        self::assertSame('06', $rule->sourceMax);
        self::assertSame('3.0', $rule->sourceStep);
        self::assertSame(2, $rule->min);
        self::assertSame(6, $rule->max);
        self::assertSame(3, $rule->step);
    }

    /**
     * And the refusals add up: every window in the grid is either convertible
     * or refused for a named reason, with nothing falling between.
     *
     * A number in the test above with no breakdown beside it is a number that
     * changes silently the next time the rule does.
     */
    public function testEveryWindowInTheGridIsAccountedFor(): void
    {
        $tally = ['convertible' => 0];
        for ($min = 0; $min <= 13; $min++) {
            for ($max = 0; $max <= 13; $max++) {
                for ($step = 1; $step <= 6; $step++) {
                    $problems = MinMaxRule::from(
                        MinMaxRule::AT_PRODUCT,
                        (string) $min,
                        (string) $max,
                        (string) $step
                    )->problems();
                    if ($problems === []) {
                        $tally['convertible']++;
                        continue;
                    }
                    $tally[implode('+', $problems)] = ($tally[implode('+', $problems)] ?? 0) + 1;
                }
            }
        }
        self::assertSame(1176, array_sum($tally));
        self::assertSame(244, $tally['convertible']);
        self::assertArrayHasKey(MinMaxRule::MAX_BELOW_MIN, $tally);
        self::assertArrayHasKey(MinMaxRule::STEP_BASE_DIFFERS, $tally);
        // No window is refused for a reason this round did not name.
        foreach (array_keys($tally) as $reason) {
            self::assertContains($reason, [
                'convertible',
                MinMaxRule::MAX_BELOW_MIN,
                MinMaxRule::STEP_BASE_DIFFERS,
                MinMaxRule::MAX_BELOW_MIN . '+' . MinMaxRule::STEP_BASE_DIFFERS,
            ], 'unnamed refusal reason: ' . $reason);
        }
    }
}
