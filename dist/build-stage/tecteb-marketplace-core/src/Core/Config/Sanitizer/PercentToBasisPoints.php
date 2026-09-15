<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config\Sanitizer;

use Tecteb\Marketplace\Core\Config\SettingsSchema;

/**
 * Percent INPUT (string) → basis points (int) with NO floating point.
 *
 *  "12.34" → 1234   "12" → 1200   "0" → 0   "" / null → null (not set)
 *  "100.01", "-1", "1.234", "abc", arrays, floats → error
 *
 * This parser is only ever fed the form input. Stored values never pass
 * through it, so re-saving cannot double-convert (owner correction 4).
 */
final class PercentToBasisPoints
{
    public const ERR_TYPE = 'commission_type';
    public const ERR_FORMAT = 'commission_format';
    public const ERR_DECIMALS = 'commission_decimals';
    public const ERR_RANGE = 'commission_range';

    public static function parse(mixed $raw): ParseResult
    {
        if ($raw === null) {
            return ParseResult::ok(null);
        }
        if (is_int($raw)) {
            $raw = (string) $raw;
        } elseif (!is_string($raw)) {
            // floats are rejected on purpose: the contract forbids float math
            return ParseResult::error(self::ERR_TYPE);
        }
        $text = DigitNormalizer::normalize($raw);
        if ($text === '') {
            return ParseResult::ok(null);
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d+))?$/', $text, $m) !== 1) {
            return ParseResult::error(self::ERR_FORMAT);
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > 2) {
            return ParseResult::error(self::ERR_DECIMALS);
        }
        $bp = ((int) $m[1]) * 100 + (int) str_pad($frac, 2, '0', STR_PAD_RIGHT);
        if ($bp < SettingsSchema::COMMISSION_BP_MIN || $bp > SettingsSchema::COMMISSION_BP_MAX) {
            return ParseResult::error(self::ERR_RANGE);
        }
        return ParseResult::ok($bp);
    }
}
