<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * How much of an area a staff member may touch.
 *
 * `Respond` exists because the spec gives customer support «پاسخ/مشاهده» on
 * orders: they may answer, and they may not change the order. Folding that
 * into Edit would hand support the ability to cancel a shipment.
 */
enum StaffLevel: string
{
    case None = 'none';
    case View = 'view';
    case Respond = 'respond';
    case Edit = 'edit';

    public function allows(self $needed): bool
    {
        return match ($needed) {
            self::None => true,
            self::View => $this !== self::None,
            self::Respond => $this === self::Respond || $this === self::Edit,
            self::Edit => $this === self::Edit,
        };
    }
}
