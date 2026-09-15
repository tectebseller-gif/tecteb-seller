<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/** Capability names, in one place so a typo cannot silently grant access. */
final class VendorCapabilities
{
    /** Ask to become a vendor. Granted to every signed-in customer role. */
    public const APPLY = 'tmc_apply_vendor';

    /** Approve, reject or ask an applicant for changes. */
    public const REVIEW = 'tmc_review_vendor';

    /** Define which documents applicants must upload. */
    public const MANAGE_DOCUMENTS = 'tmc_manage_vendor_documents';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::APPLY, self::REVIEW, self::MANAGE_DOCUMENTS];
    }
}
