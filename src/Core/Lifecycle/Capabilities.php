<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Lifecycle;

/**
 * The capabilities this plugin CREATES, all of them added to the
 * administrator role and to no other (CORE-03).
 *
 * `tmc_apply_vendor` is deliberately absent: every signed-in customer needs
 * it, and granting it would mean editing roles the site already has —
 * including Dokan's. It is answered at runtime instead, by
 * Modules\Vendor\Infrastructure\WordPress\VendorCapabilityPolicy, so
 * deactivating the plugin takes it away again with nothing left behind.
 */
final class Capabilities
{
    public const VIEW_DASHBOARD = 'tmc_view_dashboard';
    public const VIEW_HEALTH = 'tmc_view_health';
    public const MANAGE_SETTINGS = 'tmc_manage_settings';
    public const VIEW_MODULES = 'tmc_view_modules';
    /** Vendor phase: decide on applications, and define required documents. */
    public const REVIEW_VENDOR = 'tmc_review_vendor';
    public const MANAGE_VENDOR_DOCUMENTS = 'tmc_manage_vendor_documents';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD,
            self::VIEW_HEALTH,
            self::MANAGE_SETTINGS,
            self::VIEW_MODULES,
            self::REVIEW_VENDOR,
            self::MANAGE_VENDOR_DOCUMENTS,
        ];
    }

    /** Role that receives the capabilities on single-site installs. */
    public const TARGET_ROLE = 'administrator';
}
