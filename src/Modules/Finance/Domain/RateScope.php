<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * Where a rate can be set, narrowest first.
 *
 * Inheritance is the whole point (FIN-02: «مقدار خالی یعنی ارث‌بری»): a
 * product with no rate of its own falls back to its vendor, then to its
 * category, then to the marketplace default. What it must never fall back to
 * is zero.
 */
enum RateScope: string
{
    case Product = 'product';
    case Vendor = 'vendor';
    case Category = 'category';
    case General = 'general';

    /** @return list<self> the order a lookup walks */
    public static function precedence(): array
    {
        return [self::Product, self::Vendor, self::Category, self::General];
    }
}
