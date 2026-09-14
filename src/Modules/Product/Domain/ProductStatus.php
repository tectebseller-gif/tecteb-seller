<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/** §4.2: پیش‌نویس ← ارسال برای بررسی ← نیازمند اصلاح/تأیید ← منتشرشده ← تعلیق/بایگانی */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Published = 'published';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /** Whether the vendor may still edit the product itself (not its stock). */
    public function isEditableByVendor(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }

    /** Whether buyers can see it at all. */
    public function isLive(): bool
    {
        return $this === self::Published;
    }
}
