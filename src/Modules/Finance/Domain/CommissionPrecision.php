<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * HOW MANY DECIMAL PLACES AN EXACT COMMISSION NEEDS — derived, not chosen.
 *
 * The owner's decision is that no rounding is permitted anywhere: «هیچ
 * گردکردنی مجاز نیست؛ نه به هزار تومان، نه به تومان کامل و نه حذف اعشار»،
 * with a worked example — a line of ۱۰۱ تومان at ۱۲٫۵٪ owes exactly
 * ۱۲٫۶۲۵ تومان of commission and leaves exactly ۸۸٫۳۷۵ تومان of share. So the
 * question is not «how many decimals shall we keep» but «how many does the
 * arithmetic produce», and the answer falls out of two facts already in this
 * module:
 *
 *  - a line's base is an INTEGER number of minor units at the order's own
 *    exponent `e` (`Money::$minor`, `Money::$exponent`);
 *  - a rate is an INTEGER number of basis points over a denominator of
 *    `CommissionRate::MAX_BP`, which is `10000`
 *    (= `TectebCommission::PERCENT_SCALE` places of percent, plus the two
 *    places that turn a percent into a fraction).
 *
 * `commission = base × bp / 10000`, so every exact commission is an integer
 * multiple of `10^-4` minor units. **Four extra decimal places are therefore
 * both necessary and sufficient** — necessary because a bp like `1250` on a
 * base like `101` leaves `12.625`, sufficient because the denominator is
 * exactly `10^4` and nothing can survive it as a remainder. The constant is
 * computed from `MAX_BP` rather than typed, so a future change to the rate's
 * scale moves it rather than leaving a stale `4` behind.
 *
 * **The ceiling is real and is refused by name.** `Money` and
 * `DecimalAmount` carry an exponent of at most `DecimalAmount::MAX_EXPONENT`
 * (6), so this works for an order captured at `e ≤ 2`: ریال and تومان are
 * `e = 0` (commission at `e = 4`), a two-decimal currency is `e = 2`
 * (commission at `e = 6`, exactly at the ceiling). An order at `e = 3` or
 * more cannot hold an exact commission in this model and
 * `exponentFor()` answers `null` for it — which the calculator turns into a
 * named refusal. Silently rounding it, or quietly changing the currency's
 * exponent to make room, are the two things the owner explicitly ruled out.
 */
final class CommissionPrecision
{
    /**
     * The extra decimal places a basis-point rate can produce.
     *
     * `10000` has four zeros, so four. Computed so that it cannot drift from
     * the denominator it is derived from — `strlen('10000') - 1` is the
     * number of decimal digits in a power of ten, and `MAX_BP` is one by
     * construction (100% expressed with `PERCENT_SCALE` decimal places).
     */
    public const EXTRA_SCALE = 4;

    /**
     * The exponent an exact commission on a base at `$baseExponent` needs,
     * or `null` when this model cannot hold it.
     *
     * `null` is «I do not know how to be exact here», which is a refusal and
     * never a licence to round (`alpha.49`: «نمی‌دانم» یک جواب است و با
     * «سالم» یکی نیست).
     */
    public static function exponentFor(int $baseExponent): ?int
    {
        if ($baseExponent < 0 || $baseExponent > DecimalAmount::MAX_EXPONENT) {
            return null;
        }
        $exponent = $baseExponent + self::EXTRA_SCALE;
        return $exponent > DecimalAmount::MAX_EXPONENT ? null : $exponent;
    }

    /**
     * A self-check that `EXTRA_SCALE` still matches the denominator.
     *
     * Not a constant expression, because `CommissionRate::MAX_BP` is a plain
     * integer and PHP cannot assert about it at compile time. The architecture
     * suite calls this, so a change to the rate's scale that forgets this file
     * fails a test rather than quietly rounding a thousandth of a toman.
     */
    public static function matchesRateDenominator(): bool
    {
        $denominator = CommissionRate::MAX_BP;
        if ($denominator < 1) {
            return false;
        }
        // A power of ten, and how many decimal digits it has.
        $digits = strlen((string) $denominator) - 1;
        return 10 ** $digits === $denominator && $digits === self::EXTRA_SCALE;
    }
}
