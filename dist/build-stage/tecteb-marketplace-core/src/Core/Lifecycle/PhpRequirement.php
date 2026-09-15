<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Lifecycle;

final class PhpRequirement
{
    public const MINIMUM = '8.1.0';

    public static function isSatisfied(string $phpVersion): bool
    {
        return version_compare($phpVersion, self::MINIMUM, '>=');
    }
}
