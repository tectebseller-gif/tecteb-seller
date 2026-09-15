<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/** One membership: a WordPress user attached to exactly one store. */
final class StaffMember
{
    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly int $staffUserId,
        public readonly string $displayName,
        public readonly string $username,
        public readonly string $email,
        public readonly string $mobile,
        public readonly StaffRolePreset $preset,
        public readonly StaffPermissions $permissions,
        public readonly StaffStatus $status,
        public readonly ?string $invitedAt = null,
        public readonly ?string $activatedAt = null,
        public readonly ?string $lastSeenAt = null
    ) {
    }

    /**
     * The mobile is recorded, never asserted. Same rule as the applicant's
     * own number: without a provider there is no verification, so no screen
     * may imply one (plan §4.1).
     */
    public function mobileIsVerified(): bool
    {
        return false;
    }
}
