<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

/**
 * The namespaces a report is cached under — one per shop, one for the
 * marketplace, and no way to write one that spans two shops.
 *
 * It is a class rather than a string built at the call site because the
 * failure it prevents is a silent one: a key assembled by hand in two places
 * that agree until somebody changes one of them, and then one shop's finance
 * card is served under another shop's id. Everything that reads or invalidates
 * a report asks here.
 */
final class ReportCacheKey
{
    public const VENDOR_PREFIX = 'tmc_report_vendor_';
    public const MARKETPLACE = 'tmc_report_marketplace';

    public static function forVendor(int $vendorUserId): string
    {
        return self::VENDOR_PREFIX . max(0, $vendorUserId);
    }
}
