<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config\Sanitizer;

/**
 * Integer within [min, max]. Persian/Arabic digits accepted; decimals,
 * text, arrays, booleans, empty input and out-of-range values rejected.
 */
final class BoundedInteger
{
    public const ERR_TYPE = 'int_type';
    public const ERR_EMPTY = 'int_empty';
    public const ERR_FORMAT = 'int_format';
    public const ERR_RANGE = 'int_range';

    public static function parse(mixed $raw, int $min, int $max): ParseResult
    {
        if (is_int($raw)) {
            $text = (string) $raw;
        } elseif (is_string($raw)) {
            $text = DigitNormalizer::normalize($raw);
        } else {
            return ParseResult::error(self::ERR_TYPE);
        }
        if ($text === '') {
            return ParseResult::error(self::ERR_EMPTY);
        }
        if (preg_match('/^-?\d{1,12}$/', $text) !== 1) {
            return ParseResult::error(self::ERR_FORMAT);
        }
        $value = (int) $text;
        if ($value < $min || $value > $max) {
            return ParseResult::error(self::ERR_RANGE);
        }
        return ParseResult::ok($value);
    }
}
