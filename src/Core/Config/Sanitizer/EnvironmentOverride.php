<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config\Sanitizer;

use Tecteb\Marketplace\Core\Config\SettingsSchema;

final class EnvironmentOverride
{
    public const ERR_VALUE = 'environment_value';

    public static function parse(mixed $raw): ParseResult
    {
        if (!is_string($raw)) {
            return ParseResult::error(self::ERR_VALUE);
        }
        $value = strtolower(trim($raw));
        if (!in_array($value, SettingsSchema::ENVIRONMENT_OVERRIDE_VALUES, true)) {
            return ParseResult::error(self::ERR_VALUE);
        }
        return ParseResult::ok($value);
    }
}
