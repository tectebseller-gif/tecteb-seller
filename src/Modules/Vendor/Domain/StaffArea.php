<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/** The five areas the permission matrix in Master Spec §3.1 divides a store into. */
enum StaffArea: string
{
    case Product = 'product';
    case Inventory = 'inventory';
    case Order = 'order';
    case Report = 'report';
    case Finance = 'finance';

    /** @return list<self> in the order the spec's table lists them */
    public static function all(): array
    {
        return [self::Product, self::Inventory, self::Order, self::Report, self::Finance];
    }
}
