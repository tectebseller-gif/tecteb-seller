<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;

/**
 * ONE PLACE that turns an integer and its exponent into money a person reads.
 *
 * **Why it exists.** Until `alpha.67` every amount in a vendor's books was at
 * the order's own exponent, so a view could print `number_format($minor)` and
 * be right. The owner's no-rounding decision ends that: a commission is
 * recorded at four more decimal places than the order's unit, so the same
 * code now prints ۸۸۰٬۰۰۰ where ۸۸٫۳۷۵ is owed. Three views formatted money
 * three ways; one question gets one answer (`alpha.66`).
 *
 * **Trailing zeros are dropped, and that is NOT «حذف اعشار».** The owner
 * ruled out dropping decimals — «نه حذف اعشار» — and that is about discarding
 * VALUE: turning ۸۸٫۳۷۵ into ۸۸ throws away ۰٫۳۷۵ that somebody is owed.
 * `۴۵۰٬۰۰۰٫۰۰۰۰` and `۴۵۰٬۰۰۰` are the same number written two ways, and
 * printing the zeros of a figure that happens to be whole would make every
 * ordinary amount on every page unreadable. So `88.3750` prints `۸۸٫۳۷۵` and
 * `450000.0000` prints `۴۵۰٬۰۰۰`, and the full stored string is available
 * through `exact()` for anything that needs to show what is on disk.
 *
 * Separators are the Persian ones: `٬` (U+066C) for thousands and `٫`
 * (U+066B) for the decimal point. An ASCII full stop in a right-to-left line
 * of Persian digits reads as the end of a sentence.
 */
final class MoneyText
{
    /** U+066C ARABIC THOUSANDS SEPARATOR. */
    public const THOUSANDS = '٬';
    /** U+066B ARABIC DECIMAL SEPARATOR. */
    public const DECIMAL = '٫';

    /**
     * An amount, in Persian digits, with its fraction only when it has one.
     *
     * `null` is answered for an exponent `DecimalAmount` cannot express —
     * rather than a guess at the scale, which is the whole mistake this class
     * is here to stop.
     */
    public static function format(int $minor, int $exponent): ?string
    {
        $decimal = DecimalAmount::toDecimal($minor, $exponent);
        if ($decimal === null) {
            return null;
        }
        $negative = str_starts_with($decimal, '-');
        $digits = $negative ? substr($decimal, 1) : $decimal;
        $parts = explode('.', $digits, 2);
        // `number_format()` is deliberately not used: it takes a float, and a
        // float is the one thing this module refuses. The grouping is done on
        // the DIGIT STRING, which cannot lose a unit however long the number
        // is.
        $whole = self::group($parts[0]);
        $fraction = rtrim($parts[1] ?? '', '0');
        $text = $whole . ($fraction === '' ? '' : self::DECIMAL . $fraction);
        return PersianDigits::toPersian(($negative ? '-' : '') . $text);
    }

    /**
     * The same amount with EVERY recorded place, zeros included.
     *
     * For a page that has to show what is on disk rather than what reads
     * well — a reconciliation screen, an audit line — so «the row says
     * ۸۸٫۳۷۵۰» and «the vendor is owed ۸۸٫۳۷۵» are both sayable.
     */
    public static function exact(int $minor, int $exponent): ?string
    {
        $decimal = DecimalAmount::toDecimal($minor, $exponent);
        if ($decimal === null) {
            return null;
        }
        $negative = str_starts_with($decimal, '-');
        $digits = $negative ? substr($decimal, 1) : $decimal;
        $parts = explode('.', $digits, 2);
        $text = self::group($parts[0]) . (($parts[1] ?? '') === '' ? '' : self::DECIMAL . $parts[1]);
        return PersianDigits::toPersian(($negative ? '-' : '') . $text);
    }

    /** Thousands separators inserted into a plain digit string, from the right. */
    private static function group(string $digits): string
    {
        $out = '';
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $out .= self::THOUSANDS;
            }
            $out .= $digits[$i];
        }
        return $out === '' ? '۰' : $out;
    }
}
