<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

/**
 * Presentation-only digit conversion (UX §24: "تبدیل رقم فارسی فقط presentation است").
 */
final class PersianDigits
{
    private const ASCII = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    public static function toPersian(string $text): string
    {
        return str_replace(self::ASCII, self::PERSIAN, $text);
    }

    /**
     * The other direction, for input.
     *
     * A manager typing «۱۲٫۳۴» into a rate field means 12.34, and refusing it
     * because the digits are Persian would be a strange thing for a Persian
     * admin screen to do.
     */
    public static function toLatin(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
