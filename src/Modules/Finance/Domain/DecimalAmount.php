<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * The conversion between a storefront's decimal string and `Money`'s integer
 * minor units — exactly, with no float in the path.
 *
 * **Why this class exists.** `alpha.38` had exactly one hardcoded money scale
 * in the whole financial surface, `ManageReturns::recordWooCommerceRefund()`'s
 * `/ 100`, and it disagreed with the only scale anything else used. Orders are
 * captured with `exponent = 0` (ریال and تومان have no minor unit), so a line
 * stored as `100000` IS one hundred thousand — and dividing it by a hundred
 * asked WooCommerce to refund **۱٬۰۰۰**. Measured on the shipped bytes before
 * this class existed.
 *
 * Two rules follow from that, and both are enforced here rather than left to
 * each caller:
 *
 *  - **The exponent travels with the amount.** `Money` already carries it, and
 *    `tmc_ledger` already stores `currency` and `exponent` per line, so the
 *    unit of any recorded amount is a fact on disk — not something to infer
 *    from «the shop shows تومان».
 *  - **A conversion that cannot be exact is refused, not rounded.**
 *    `(int) round((float) '1.005' * 100)` is a silent answer to a question
 *    nobody asked. `toMinor()` returns `null` instead, and the caller says so
 *    with a named reason.
 *
 * All of it is string and integer arithmetic. No `float` appears in this file,
 * which is the point: `0.1 + 0.2` is not `0.3` and money is not a place to
 * find that out.
 */
final class DecimalAmount
{
    /** `Money` refuses an exponent outside this, so the same bound applies here. */
    public const MAX_EXPONENT = 6;

    /**
     * A decimal string as an exact integer number of minor units.
     *
     * Returns `null` — never a rounded guess — when the text is not a plain
     * decimal number, when the exponent is out of range, when the value does
     * not fit a PHP integer, or when the fraction carries more SIGNIFICANT
     * places than the exponent can hold. Trailing zeros are not significant,
     * so `'100000.00'` converts cleanly at exponent 0; `'1.005'` at exponent 2
     * does not.
     */
    public static function toMinor(string $decimal, int $exponent): ?int
    {
        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            return null;
        }
        $text = trim($decimal);
        if ($text === '') {
            return null;
        }
        // Deliberately strict: no thousands separators, no exponent notation,
        // no currency symbol. WooCommerce hands over `wc_format_decimal()`
        // output, and anything else is a caller mistake worth seeing.
        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $m) !== 1) {
            return null;
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $whole = $m[2];
        $fraction = rtrim($m[3] ?? '', '0');
        if (strlen($fraction) > $exponent) {
            return null;        // real precision, and it would be thrown away
        }
        $digits = $whole . str_pad($fraction, $exponent, '0');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        // `(int)` on an over-long numeric string saturates silently, so the
        // round trip is what decides whether it fitted.
        $minor = (int) $digits;
        if ((string) $minor !== $digits) {
            return null;
        }
        return $sign * $minor;
    }

    /**
     * The same conversion, rounding **towards zero** instead of refusing.
     *
     * For one use only, and the direction is the reason: a ceiling read from
     * somebody else — «how much of this order is still refundable» — may carry
     * more places than we store, and the safe reading of a ceiling is the
     * smaller one. Never use this for an amount being recorded.
     */
    public static function toMinorFloor(string $decimal, int $exponent): ?int
    {
        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            return null;
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', trim($decimal), $m) !== 1) {
            return null;
        }
        $fraction = $m[3] ?? '';
        $cut = $m[1] . $m[2] . ($exponent > 0 ? '.' . substr($fraction, 0, $exponent) : '');
        return self::toMinor($cut, $exponent);
    }

    /**
     * Minor units back to the decimal string a storefront understands.
     *
     * A string and not a float, because this value is handed to
     * `wc_create_refund()` and the whole file exists to keep one conversion
     * away from `float`. Exponent 0 prints no separator at all: `'100000'`,
     * which is what a ریال amount is.
     */
    public static function toDecimal(int $minor, int $exponent): ?string
    {
        if ($exponent < 0 || $exponent > self::MAX_EXPONENT) {
            return null;
        }
        $sign = $minor < 0 ? '-' : '';
        $digits = (string) abs($minor);
        if ($exponent === 0) {
            return $sign . $digits;
        }
        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);
        return $sign . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }
}
