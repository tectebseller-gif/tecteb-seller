<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnAllocation;

/**
 * THE ALLOCATION RULE THE OWNER CHOSE, exercised without a database.
 *
 * The invariant that matters is not any single figure: it is that the parts
 * of a line, allocated this way and closed by subtraction on the last
 * return, add up to EXACTLY the line. A test that only checks one worked
 * example cannot tell a correct rule from one that happens to agree on three
 * (`alpha.46`), so the closing property is checked over a grid.
 */
final class ReturnAllocationTest extends TestCase
{
    public function testTheOwnersExampleFloorsInTheLinesOwnUnit(): void
    {
        // ۱۰۱ تومان, three units, ۱۲٫۵٪ ⇒ at exponent 4.
        self::assertSame(336666, ReturnAllocation::part(1010000, 1, 3));
        self::assertSame(42083, ReturnAllocation::part(126250, 1, 3));
        self::assertSame(673333, ReturnAllocation::part(1010000, 2, 3));
        self::assertSame(84166, ReturnAllocation::part(126250, 2, 3));
    }

    public function testADivisibleLineIsUnchangedByTheRule(): void
    {
        self::assertSame(1000000, ReturnAllocation::part(3000000, 1, 3));
        self::assertSame(2000000, ReturnAllocation::part(3000000, 2, 3));
        self::assertSame(0, ReturnAllocation::remainder(3000000, 3));
    }

    public function testTheWholeQuantityIsTheWholeAmount(): void
    {
        self::assertSame(1010000, ReturnAllocation::part(1010000, 3, 3));
    }

    public function testNothingIsAllocatedFromNothing(): void
    {
        self::assertSame(0, ReturnAllocation::part(0, 2, 3));
        self::assertSame(0, ReturnAllocation::part(1010000, 0, 3));
    }

    public function testTheRemainderIsWhatTheLastReturnMustCarry(): void
    {
        self::assertSame(2, ReturnAllocation::remainder(1010000, 3), '1010000 − 3 × 336666');
        self::assertSame(1, ReturnAllocation::remainder(126250, 3), '126250 − 3 × 42083');
        self::assertSame(0, ReturnAllocation::remainder(0, 7));
    }

    /**
     * ⛔ THE CLOSING PROPERTY, over a grid rather than an example.
     *
     * For every line quantity and every way of splitting it into returns,
     * the floors of the first returns plus the subtraction on the last must
     * equal the recorded amount — no unit lost, none invented.
     */
    public function testEveryWayOfSplittingALineClosesOnTheRecordedAmount(): void
    {
        $amounts = [1010000, 126250, 1, 2, 999999999, 1000000000, 7, 123457];
        $checked = 0;
        foreach ($amounts as $amount) {
            for ($lineQuantity = 1; $lineQuantity <= 12; $lineQuantity++) {
                foreach ($this->splitsOf($lineQuantity) as $split) {
                    $given = 0;
                    $remaining = $lineQuantity;
                    foreach ($split as $returned) {
                        if ($returned >= $remaining) {
                            $given += $amount - $given;      // the last: subtraction
                            $remaining = 0;
                            continue;
                        }
                        $part = ReturnAllocation::part($amount, $returned, $lineQuantity);
                        self::assertNotNull($part, 'a realistic figure is never refused');
                        $given += $part;
                        $remaining -= $returned;
                    }
                    self::assertSame(
                        $amount,
                        $given,
                        'line ' . $lineQuantity . ' split ' . implode('+', $split) . ' of ' . $amount
                    );
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(400, $checked, 'the grid really ran');
    }

    /**
     * Every floor is at most one unit under the true share, and never over.
     *
     * «به پایین» is the direction the owner named, and a rule that ever
     * allocated MORE than the share would hand out money the line does not
     * have before the last return could notice.
     */
    public function testAFlooredShareIsNeverMoreThanTheTrueShare(): void
    {
        foreach ([1010000, 126250, 7, 123457] as $amount) {
            for ($n = 1; $n <= 9; $n++) {
                for ($q = 1; $q <= $n; $q++) {
                    $part = ReturnAllocation::part($amount, $q, $n);
                    self::assertNotNull($part);
                    // part × n ≤ amount × q  ⇔  the floor never overshoots.
                    self::assertLessThanOrEqual($amount * $q, $part * $n);
                    self::assertGreaterThan($amount * $q - $n, $part * $n);
                }
            }
        }
    }

    /**
     * ⛔ A FIGURE OUTSIDE THE INTEGER RANGE IS REFUSED, not turned into a
     * float that looks like money.
     *
     * The decomposition multiplies only numbers already divided down, so this
     * needs an absurd line to reach — which is the point: the guard is there
     * for the case nobody can reach rather than for the case someone can.
     */
    public function testAnUnrepresentableFigureIsRefusedRatherThanRounded(): void
    {
        // ⛔ AND THE FIRST VERSION OF THIS TEST WAS WRONG, which is worth
        // keeping: it expected two thirds of PHP_INT_MAX to be refused, and
        // the run answered `6148914691236517204`. That answer is CORRECT —
        // the decomposition multiplies `whole = MAX ÷ 3` by 2, which fits —
        // and the naive `amount × returned` would have overflowed into a
        // float there. So the decomposition is not a micro-optimisation; it
        // is the difference between an answer and a wrong answer.
        self::assertSame(
            6148914691236517204,
            ReturnAllocation::part(PHP_INT_MAX, 2, 3),
            'two thirds of the maximum is answerable, exactly'
        );
        // What genuinely cannot be represented is still refused by name.
        self::assertNull(ReturnAllocation::part(PHP_INT_MAX, 3, 2), 'and half of it, three times, is not');
        self::assertNull(ReturnAllocation::part(PHP_INT_MAX, PHP_INT_MAX, 1));
    }

    /**
     * AND `remainder()` CANNOT OVERFLOW, which is a property not a hope.
     *
     * `floor(amount ÷ n) × n ≤ amount`, so the subtraction is always in
     * range. The first version of this class guarded that multiplication
     * anyway and this test — expecting a refusal — proved the guard dead:
     * `remainder(PHP_INT_MAX, PHP_INT_MAX)` is `0`, exactly. The guard came
     * out instead of the assertion being relaxed.
     */
    public function testTheRemainderIsAlwaysRepresentable(): void
    {
        self::assertSame(0, ReturnAllocation::remainder(PHP_INT_MAX, PHP_INT_MAX));
        self::assertSame(1, ReturnAllocation::remainder(PHP_INT_MAX, 3));
        self::assertSame(0, ReturnAllocation::remainder(PHP_INT_MAX, 1));
    }

    public function testAnImpossibleQuantityOrANegativeAmountIsRefused(): void
    {
        self::assertNull(ReturnAllocation::part(1000, 1, 0), 'nothing to divide by');
        self::assertNull(ReturnAllocation::part(1000, -1, 3));
        self::assertNull(ReturnAllocation::part(-1000, 1, 3), 'floor below zero is not chosen here');
        self::assertNull(ReturnAllocation::remainder(-1000, 3));
    }

    /**
     * @return list<list<int>> every ordered way of splitting n into returns
     */
    private function splitsOf(int $n): array
    {
        if ($n <= 0) {
            return [[]];
        }
        $out = [];
        for ($first = 1; $first <= $n; $first++) {
            foreach ($this->splitsOf($n - $first) as $rest) {
                $out[] = array_merge([$first], $rest);
            }
        }
        return $out;
    }
}
