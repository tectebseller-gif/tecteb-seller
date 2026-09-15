<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * Where a wholesale-buyer application has got to.
 *
 * The approved rule is one sentence — «خریدار عمده پس از تأیید مدیر قیمت
 * پلکانی و حداقل تعداد را می‌بیند» — and these are exactly the states that
 * sentence needs. There is no «expired»: how long an approval lasts is part of
 * the wholesale terms DEC-05 leaves open.
 */
enum WholesaleStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Requested, self::Approved, self::Rejected, self::Suspended];
    }

    /** The one question the price resolver asks. */
    public function maySeeWholesalePrices(): bool
    {
        return $this === self::Approved;
    }
}
