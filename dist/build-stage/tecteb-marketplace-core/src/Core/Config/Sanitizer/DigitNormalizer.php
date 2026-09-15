<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config\Sanitizer;

/**
 * Maps Persian (U+06F0–U+06F9) and Arabic-Indic (U+0660–U+0669) digits to
 * ASCII, and the Arabic decimal separator (U+066B) to '.'. Trims surrounding
 * whitespace including NBSP/ZWNJ. Nothing else is touched.
 */
final class DigitNormalizer
{
    private const FROM = [
        '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹',
        '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩',
        '٫',
    ];
    private const TO = [
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        '.',
    ];

    public static function normalize(string $input): string
    {
        $normalized = str_replace(self::FROM, self::TO, $input);
        return trim($normalized, " \t\n\r\0\x0B\u{00A0}\u{200C}\u{200B}");
    }
}
