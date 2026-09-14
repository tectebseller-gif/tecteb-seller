<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Simple and variable only. External and grouped products are out of v1
 * (A.1), so they are absent rather than rejected later.
 *
 * No labels here: the Persian names live in ProductMessages, because this
 * layer may not speak WordPress and a domain that carries its own translation
 * calls stops being testable without one.
 */
final class ProductType
{
    public const SIMPLE = 'simple';
    public const VARIABLE = 'variable';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SIMPLE, self::VARIABLE];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
