<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;

/** Adds the four phase-1 capabilities to Administrator only; touches no other role. */
final class CapabilityInstaller
{
    /** @return bool false when the administrator role is missing */
    public static function install(): bool
    {
        $role = get_role(Capabilities::TARGET_ROLE);
        if ($role === null) {
            return false;
        }
        foreach (Capabilities::all() as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap, true);
            }
        }
        return true;
    }
}
