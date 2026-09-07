<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Environment;

enum EnvironmentType: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';
    case Local = 'local';
    case Unknown = 'unknown';

    public static function fromInput(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        foreach (self::cases() as $case) {
            if ($case !== self::Unknown && $case->value === $value) {
                return $case;
            }
        }
        return null;
    }
}
