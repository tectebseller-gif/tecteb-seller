<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * How a fractional minor unit becomes a whole one, and which version of that
 * answer was used (FIN-01: «سیاست گردکردن و نسخه آن در snapshot ثبت شود»).
 *
 * The version is recorded with every calculation so that a future change of
 * policy cannot silently reinterpret past money: an old line says which rule
 * produced it, and a recalculation that disagrees is visible instead of
 * plausible.
 */
final class RoundingPolicy
{
    public const HALF_UP = 'half_up';
    public const VERSION = 1;

    /**
     * Half up for positive amounts, as the spec requires. A refund does not
     * round again: it reverses exactly what was recorded, so there is no
     * second rounding to disagree with the first.
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

    public static function describe(): string
    {
        return self::HALF_UP . '@v' . self::VERSION;
    }
}
