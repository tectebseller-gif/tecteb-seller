<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * Which rule produced a recorded commission — and, since `alpha.67`, the fact
 * that there is no longer a rule to apply.
 *
 * FIN-01 asked for «سیاست گردکردن و نسخه آن در snapshot ثبت شود», and the
 * version is recorded with every calculation so that a change of policy
 * cannot silently reinterpret past money: an old line says which rule
 * produced it, and a recalculation that disagrees is visible instead of
 * plausible.
 *
 * ⛔ **The owner has now decided that no rounding is permitted at all**:
 * «هیچ گردکردنی مجاز نیست؛ نه به هزار تومان، نه به تومان کامل و نه حذف
 * اعشار», with a worked example that is exact to a thousandth of a تومان. So
 * `apply()` is no longer called by either calculator: the commission is
 * computed at the exponent `CommissionPrecision` derives, where the division
 * leaves no remainder for a policy to have an opinion about.
 *
 * **`HALF_UP` and `apply()` stay, and are not deprecated out of the file**,
 * because they are the only explanation of the lines already on disk. Every
 * `CommissionSnapshot` recorded before this release says `half_up@v1`, and
 * that sentence has to keep meaning what it meant — «مبلغ و واحد تاریخی
 * دفترکل و سفارش‌های قبلی بازتفسیر یا بازنویسی نشوند». Deleting the method
 * would leave a version string nothing in the codebase could explain, which
 * is worse than a method with one historical reader; keeping it and never
 * calling it is enforced by a test rather than by this paragraph
 * (`alpha.22`: the sentence «we do not do this» is itself the test).
 */
final class RoundingPolicy
{
    public const HALF_UP = 'half_up';
    /**
     * NO ROUNDING: the figure is exact at its own exponent.
     *
     * Recorded in the snapshot of every commission from `alpha.67` on, so a
     * line can be told from one produced under `half_up@v1` without reading
     * the release notes.
     */
    public const EXACT = 'exact';
    public const VERSION = 2;

    /**
     * Half up — THE HISTORICAL RULE, kept to explain rows already on disk.
     *
     * Not called by any calculator since `alpha.67`. A refund never called it
     * either: a reversal reverses exactly what was recorded, so there was
     * never a second rounding to disagree with the first.
     */
    public static function apply(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new \InvalidArgumentException('denominator must not be zero');
        }
        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);
        $rounded = intdiv(2 * $n + $d, 2 * $d);
        return $negative ? -$rounded : $rounded;
    }

    /**
     * What the CURRENT rule is, for a snapshot being written now.
     *
     * `exact@v2`. A historical row says `half_up@v1` and keeps saying it: no
     * stored snapshot is rewritten by this release.
     */
    public static function describe(): string
    {
        return self::EXACT . '@v' . self::VERSION;
    }

    /** What rows recorded before `alpha.67` say, kept so a reader can match it. */
    public static function describeHistoricalHalfUp(): string
    {
        return self::HALF_UP . '@v1';
    }
}
