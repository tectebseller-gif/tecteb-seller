<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * ONE PART OF A LINE, ALLOCATED EXACTLY — floored, in the unit the line was
 * recorded in.
 *
 * **The owner decided this, and the decision is narrow.** `alpha.68` refused
 * a partial return whose share did not divide evenly, and put the allocation
 * question with a worked example. The answer: allocate in the SMALLEST unit
 * of the scale the line itself carries — for a تومان line at `exponent = 4`
 * that unit is ۰٫۰۰۰۱ تومان — floor each part there, and let the LAST return
 * of what is left carry the remainder so the parts sum back to the original
 * document exactly.
 *
 * So this class answers one question and nothing else:
 *
 *     floor(amount × returned ÷ lineQuantity)
 *
 * **It is NOT a licence to round anything else.** The commission itself is
 * still exact to the last place it can be (`CommissionPrecision`), and this
 * figure is never used for a gateway amount, a bank payment, a sales
 * commission or a printed number. It is the division of an amount that is
 * already recorded, between returns of the same line.
 *
 * ⛔ **WITHOUT FLOAT AND WITHOUT AN OVERFLOWING MULTIPLICATION, and the
 * second half is why this is not a one-line `intdiv()`.** `amount × returned`
 * can leave the integer range, and in PHP an `int` that overflows becomes a
 * **float** — which would answer a figure that looks like money and is not.
 * The identity
 *
 *     amount = whole × n + rest      (whole = amount ÷ n, rest = amount mod n)
 *     amount × q ÷ n = whole × q + (rest × q ÷ n)
 *
 * is exact for integers and multiplies by `q` only numbers that are already
 * divided down, so the realistic range is enormously wider. What remains is
 * still asked BEFORE each multiplication and before the one addition, and a
 * figure that cannot be represented is **refused by name** (`null`) rather
 * than rounded — the rule this whole release is built on.
 *
 * **Negative amounts are refused rather than floored in a direction nobody
 * chose.** `base_minor`, `tax_minor` and `commission_minor` are recorded
 * non-negative, `intdiv()` truncates towards zero, and towards-zero is NOT
 * floor below zero. A guard that silently picked one would be this class
 * deciding something the owner did not.
 */
final class ReturnAllocation
{
    /**
     * This return's share of one recorded amount, floored.
     *
     * `null` means «this cannot be answered as an exact integer»: a
     * quantity that makes no sense, a negative amount, or a figure outside
     * the integer range. It never means «rounded».
     */
    public static function part(int $amount, int $returned, int $lineQuantity): ?int
    {
        if ($lineQuantity < 1 || $returned < 0 || $amount < 0) {
            return null;
        }
        if ($returned === 0 || $amount === 0) {
            return 0;
        }
        $whole = intdiv($amount, $lineQuantity);
        $rest = $amount % $lineQuantity;

        // Both multiplications asked before they happen, and both operands
        // are non-negative here, so one direction is the whole question.
        if ($whole !== 0 && intdiv(PHP_INT_MAX, $returned) < $whole) {
            return null;
        }
        if ($rest !== 0 && intdiv(PHP_INT_MAX, $returned) < $rest) {
            return null;
        }
        $full = $whole * $returned;
        $fraction = intdiv($rest * $returned, $lineQuantity);
        if ($full > PHP_INT_MAX - $fraction) {
            return null;
        }
        return $full + $fraction;
    }

    /**
     * What the final return of this line will have to carry, for one amount.
     *
     * Not used to write anything — it exists so a screen can say «the last
     * return of this line carries ۰٫۰۰۰۲ تومان more» instead of leaving a
     * manager to discover it. `null` propagates the same refusal as
     * `part()`.
     */
    public static function remainder(int $amount, int $lineQuantity): ?int
    {
        if ($lineQuantity < 1 || $amount < 0) {
            return null;
        }
        $per = self::part($amount, 1, $lineQuantity);
        if ($per === null) {
            return null;
        }
        // ⛔ NO OVERFLOW GUARD HERE, and the absence is deliberate rather
        // than forgotten. `$per` is `floor(amount ÷ lineQuantity)`, so
        // `$per × lineQuantity ≤ amount ≤ PHP_INT_MAX` by construction —
        // a guard on this multiplication could never fire. The first version
        // had one and a test proved it dead, which is its own small lesson:
        // a guard that cannot fire reads like protection and is noise.
        return $amount - ($per * $lineQuantity);
    }
}
