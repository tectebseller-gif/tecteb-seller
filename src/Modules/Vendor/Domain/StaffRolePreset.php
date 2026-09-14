<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * The five ready-made roles of Master Spec §3.1, plus «سفارشی» for a set the
 * vendor builds by hand.
 *
 * The matrix is transcribed from the spec and not invented here. Note what
 * NONE of them grants: finance beyond viewing, and nothing at all that would
 * make a staff member an owner — the store manager preset deliberately stops
 * short of banking and settlement (UX §9.2).
 */
enum StaffRolePreset: string
{
    case StoreManager = 'store_manager';
    case ProductAndInventory = 'product_inventory';
    case OrderAndShipping = 'order_shipping';
    case Accountant = 'accountant';
    case CustomerSupport = 'support';
    case Custom = 'custom';

    /** @return list<self> presets a vendor can pick, in the spec's order */
    public static function presets(): array
    {
        return [self::StoreManager, self::ProductAndInventory, self::OrderAndShipping, self::Accountant, self::CustomerSupport];
    }

    public function permissions(): StaffPermissions
    {
        return match ($this) {
            self::StoreManager => StaffPermissions::of([
                'product' => StaffLevel::Edit,
                'inventory' => StaffLevel::Edit,
                'order' => StaffLevel::Edit,
                'report' => StaffLevel::View,
                'finance' => StaffLevel::None,
            ]),
            self::ProductAndInventory => StaffPermissions::of([
                'product' => StaffLevel::Edit,
                'inventory' => StaffLevel::Edit,
            ]),
            self::OrderAndShipping => StaffPermissions::of([
                'product' => StaffLevel::View,
                'inventory' => StaffLevel::View,
                'order' => StaffLevel::Edit,
            ]),
            self::Accountant => StaffPermissions::of([
                'order' => StaffLevel::View,
                'report' => StaffLevel::View,
                'finance' => StaffLevel::View,
            ]),
            self::CustomerSupport => StaffPermissions::of([
                'product' => StaffLevel::View,
                'order' => StaffLevel::Respond,
            ]),
            self::Custom => StaffPermissions::none(),
        };
    }
}
