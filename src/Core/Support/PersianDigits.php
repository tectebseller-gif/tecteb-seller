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
}
