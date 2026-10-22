<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;
use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Product\Domain\QuantitySet;

/**
 * Whether two purchase-limit rules allow the same quantities — DECIDED, and
 * checked against brute force rather than against chosen examples.
 *
 * `alpha.70` answered this question by sampling the quantities 1…60, and the
 * two rules the owner named defeat any such bound: `1/100/1` against
 * `1/200/1` agrees on all sixty, and two minimums above sixty agree on all
 * sixty because both refuse everything below them. So the headline test here
 * is not a case list — it is the invariant, over the whole grid:
 *
 *     two rules compare EQUAL exactly when the quantities they allow are the
 *     same quantities.
 *
 * Both halves matter and both are measured. A `false` for two identical rules
 * blocks an exit nobody needed to block; a `true` for two different ones is
 * the defect this round closes. The grid holds both plugins' arithmetic, with
 * minimums and ceilings on either side of sixty on purpose, and the oracle is
 * an enumeration to 400 — far enough that every bounded rule in the grid is
 * contained whole.
 *
 * Every assertion below was falsified by putting a defect back: removing the
 * single-member normalisation produced false DIFFERENCES, dropping the
 * ceiling or the step from `equals()` produced false EQUALITIES, and taking
 * Min Max's step base as nought produced false differences again.
 */
final class QuantitySetTest extends TestCase
{
    /** Far enough out that every bounded rule in the grid is contained whole. */
    private const ORACLE_BOUND = 400;

    /** @return list<int> */
    private static function minimums(): array
    {
        return [-2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 11, 12, 59, 60, 61, 70, 80];
    }

    /** @return list<?int> */
    private static function ceilings(): array
    {
        return [null, 1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12, 20, 29, 30, 59, 60, 61, 100, 101, 200];
    }

    /** @return list<int> */
    private static function steps(): array
    {
        return [1, 2, 3, 4, 5, 6, 7];
    }

    /**
     * The quantities a rule allows, enumerated, plus whether it goes on for
     * ever — two rules that agree up to the bound and differ in that flag are
     * different sets, and nothing below is allowed to miss it.
     *
     * @param list<int> $members
     */
    private static function oracle(array $members, bool $unbounded): string
    {
        return ($unbounded ? 'inf:' : 'fin:') . implode(',', $members);
    }

    /**
     * @return array<string,array{set:QuantitySet, label:string}> keyed by the
     *         enumerated set, so every key is one real set of quantities
     */
    private static function grid(): array
    {
        $groups = [];
        foreach (self::minimums() as $min) {
            foreach (self::ceilings() as $max) {
                foreach (self::steps() as $step) {
                    // Min Max's own arithmetic, from the real plugin.
                    $rule = MinMaxRule::from('product', (string) $min, $max === null ? '' : (string) $max, (string) $step);
                    $members = [];
                    for ($q = 1; $q <= self::ORACLE_BOUND; $q++) {
                        if ($rule->sourceAllows($q)) {
                            $members[] = $q;
                        }
                    }
                    $key = self::oracle($members, $members !== [] && $rule->max === null);
                    $groups[$key][] = [
                        'set' => $rule->allowedSet(),
                        'label' => 'minmax ' . $min . '/' . ($max ?? 'null') . '/' . $step,
                    ];

                    // And this marketplace's.
                    $here = PurchaseQuantity::of($min, $max, $step);
                    $allowed = [];
                    for ($q = 1; $q <= self::ORACLE_BOUND; $q++) {
                        if ($here->allows($q)) {
                            $allowed[] = $q;
                        }
                    }
                    $key = self::oracle(
                        $allowed,
                        $allowed !== [] && $here->max === null && $here->isSatisfiable()
                    );
                    $groups[$key][] = [
                        'set' => QuantitySet::fromPurchaseQuantity($here),
                        'label' => 'here ' . $min . '/' . ($max ?? 'null') . '/' . $step,
                    ];
                }
            }
        }
        return $groups;
    }

    public function testRulesAllowingTheSameQuantitiesCompareEqualOverTheWholeGrid(): void
    {
        $groups = self::grid();
        $examined = 0;
        foreach ($groups as $members) {
            $first = $members[0];
            foreach ($members as $candidate) {
                $examined++;
                self::assertTrue(
                    $candidate['set']->equals($first['set']),
                    $candidate['label'] . ' and ' . $first['label']
                        . ' allow exactly the same quantities, and equals() said no'
                );
            }
        }
        // The grid is only evidence if it is big enough to be evidence.
        self::assertGreaterThan(5000, $examined);
    }

    public function testRulesAllowingDifferentQuantitiesNeverCompareEqual(): void
    {
        $representatives = [];
        foreach (self::grid() as $members) {
            $representatives[] = $members[0];
        }
        $pairs = 0;
        $count = count($representatives);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairs++;
                self::assertFalse(
                    $representatives[$i]['set']->equals($representatives[$j]['set']),
                    $representatives[$i]['label'] . ' and ' . $representatives[$j]['label']
                        . ' allow different quantities, and equals() said yes'
                );
            }
        }
        self::assertGreaterThan(100000, $pairs);
    }

    /**
     * The owner's first case, by name: a ceiling that differs only above the
     * bound `alpha.70` sampled to.
     */
    public function testACeilingThatDiffersOnlyAboveSixtyIsNotEquality(): void
    {
        $minmax = MinMaxRule::from('product', '1', '100', '1');
        $here = QuantitySet::fromPurchaseQuantity(PurchaseQuantity::of(1, 200, 1));

        for ($q = 1; $q <= 60; $q++) {
            self::assertSame(
                $minmax->sourceAllows($q),
                PurchaseQuantity::of(1, 200, 1)->allows($q),
                'the two rules agree about ' . $q . ', which is why sixty samples were not enough'
            );
        }
        self::assertFalse($minmax->allowedSet()->equals($here));
        self::assertSame('1..100/1', $minmax->allowedSet()->describe());
        self::assertSame('1..200/1', $here->describe());
    }

    /**
     * The owner's second case: two minimums above the bound. Every one of the
     * sixty samples matches because both sides refuse all sixty.
     */
    public function testTwoMinimumsAboveSixtyAreNotEqualBecauseBothRefuseTheSamples(): void
    {
        $minmax = MinMaxRule::from('product', '70', '', '1');
        $here = QuantitySet::fromPurchaseQuantity(PurchaseQuantity::of(80, null, 1));

        for ($q = 1; $q <= 60; $q++) {
            self::assertFalse($minmax->sourceAllows($q));
            self::assertFalse(PurchaseQuantity::of(80, null, 1)->allows($q));
        }
        self::assertFalse($minmax->allowedSet()->equals($here));
        self::assertSame('70..∞/1', $minmax->allowedSet()->describe());
        self::assertSame('80..∞/1', $here->describe());
    }

    /** A ceiling appearing out of nothing beyond the bound: the `last === null` branch. */
    public function testACeilingAppearingBeyondTheBoundIsNotEquality(): void
    {
        $minmax = MinMaxRule::from('product', '2', '', '1');
        $here = QuantitySet::fromPurchaseQuantity(PurchaseQuantity::of(2, 500, 1));

        self::assertSame('2..∞/1', $minmax->allowedSet()->describe());
        self::assertSame('2..500/1', $here->describe());
        self::assertFalse($minmax->allowedSet()->equals($here));
    }

    /**
     * THE CONTROL that matters most: a rule genuinely held here unchanged
     * still compares equal. A decision that blocks everything is not a
     * decision (`alpha.60`).
     */
    public function testARuleHeldUnchangedStillComparesEqual(): void
    {
        $minmax = MinMaxRule::from('product', '4', '10', '2');
        self::assertSame([], $minmax->problems());
        self::assertTrue($minmax->allowedSet()->equals($minmax->convertedSet()));
        self::assertSame('4..10/2', $minmax->allowedSet()->describe());
    }

    /**
     * Equality is about the SET and never about the three numbers
     * (`alpha.53`): Min Max says «at most 11» and this marketplace stores 10,
     * because 11 is not a multiple of the step. Both allow 4, 6, 8, 10.
     */
    public function testTheSameSetWrittenWithDifferentNumbersIsStillEqual(): void
    {
        $minmax = MinMaxRule::from('product', '4', '11', '2');
        $stored = PurchaseQuantity::of(4, 10, 2);

        self::assertSame(11, $minmax->max);
        self::assertSame(10, $stored->max);
        self::assertTrue($minmax->allowedSet()->equals(QuantitySet::fromPurchaseQuantity($stored)));
    }

    /** A single allowed quantity has no common difference, so the step cannot divide them. */
    public function testOneAllowedQuantityIsOneSetHoweverTheStepWasWritten(): void
    {
        $five = QuantitySet::of(5, 5, 0, 1);
        self::assertSame('5..5/1', $five->describe());
        self::assertTrue($five->equals(QuantitySet::of(5, 5, 5, 7)));
        self::assertTrue($five->equals(QuantitySet::of(3, 9, 5, 100)));
        self::assertFalse($five->equals(QuantitySet::of(5, 10, 0, 5)));
    }

    /** Two ways of allowing nothing are one set, and both sides can reach it. */
    public function testAllowingNothingIsOneSet(): void
    {
        // Min Max keeps the STRING minimum `'0'` (`finalizeArgs()`), and a
        // zero minimum with a step of four admits 4, 8, 12 … — none of which
        // is inside a ceiling of three. A real state, not a contrived one.
        $minmax = MinMaxRule::from('product', '0', '3', '4');
        self::assertSame('none', $minmax->allowedSet()->describe());
        self::assertTrue(
            $minmax->allowedSet()->equals(QuantitySet::fromPurchaseQuantity(PurchaseQuantity::of(5, 2, 1)))
        );
        self::assertFalse(PurchaseQuantity::of(5, 2, 1)->isSatisfiable());
    }

    /**
     * «I cannot tell» is a third answer, and it equals nothing — including
     * another «I cannot tell». `min_purchase`, `max_purchase` and
     * `purchase_step` are signed `INT` columns, so a rule holding
     * 5,000,000,000 describes a limit this marketplace cannot express, and the
     * honest verdict about it is that equality is not provable.
     */
    public function testAQuantityNoColumnCanHoldIsNotProvableRatherThanEqual(): void
    {
        $tooBig = QuantitySet::of(5000000000, null, 0, 1);
        self::assertFalse($tooBig->known());
        self::assertSame('unknown', $tooBig->describe());
        self::assertFalse($tooBig->equals($tooBig));
        self::assertFalse($tooBig->equals(QuantitySet::of(5000000000, null, 0, 1)));
        self::assertFalse(QuantitySet::of(1, null, 0, 1)->equals($tooBig));

        // And the edge itself is storable, so the guard refuses nothing real.
        self::assertTrue(QuantitySet::of(2147483647, null, 0, 1)->known());
        self::assertFalse(QuantitySet::of(2147483648, null, 0, 1)->known());
    }

    /** Min Max counts its multiples from its own minimum, and we count from nought. */
    public function testTheTwoPluginsCountMultiplesFromDifferentBases(): void
    {
        $minmax = MinMaxRule::from('product', '2', '11', '3');
        self::assertSame('2..11/3', $minmax->allowedSet()->describe());        // 2, 5, 8, 11
        self::assertSame('3..9/3', $minmax->convertedSet()->describe());       // 3, 6, 9
        self::assertFalse($minmax->allowedSet()->equals($minmax->convertedSet()));
        self::assertContains(MinMaxRule::STEP_BASE_DIFFERS, $minmax->problems());
    }
}
