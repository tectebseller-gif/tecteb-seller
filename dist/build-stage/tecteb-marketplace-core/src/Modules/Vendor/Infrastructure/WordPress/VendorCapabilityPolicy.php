<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;

/**
 * Grants `tmc_apply_vendor` at runtime instead of writing it into roles.
 *
 * Any signed-in user who can `read` may ask to become a vendor — that is the
 * master spec's rule (§4.1: registration always creates a customer, who may
 * then apply). Doing it with a filter means this plugin never edits the
 * `subscriber`, `customer`, Dokan or administrator roles, and deactivating
 * it removes the permission with nothing to clean up.
 */
final class VendorCapabilityPolicy
{
    public static function register(): void
    {
        add_filter('user_has_cap', [self::class, 'grantApply'], 10, 4);
    }

    /**
     * @param array<string,bool> $allcaps
     * @param list<string> $caps
     * @param array<int,mixed> $args
     * @param \WP_User|object $user
     * @return array<string,bool>
     */
    public static function grantApply(array $allcaps, array $caps, array $args, mixed $user): array
    {
        if (!in_array(VendorCapabilities::APPLY, $caps, true)) {
            return $allcaps;
        }
        // `read` is what every real, signed-in account has; it keeps the
        // permission away from logged-out visitors and from pseudo-users.
        if (!empty($allcaps['read'])) {
            $allcaps[VendorCapabilities::APPLY] = true;
        }
        return $allcaps;
    }
}
