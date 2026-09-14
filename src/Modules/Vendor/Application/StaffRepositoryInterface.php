<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

interface StaffRepositoryInterface
{
    /** @return list<StaffMember> every membership of one store, suspended included */
    public function forVendor(int $vendorUserId): array;

    public function find(int $staffId): ?StaffMember;

    /** The membership of a user, whichever store it belongs to — or none. */
    public function findByUser(int $staffUserId): ?StaffMember;

    public function findByInviteHash(string $hash): ?StaffMember;

    public function countActiveAndInvited(int $vendorUserId): int;

    public function usernameTaken(string $username): bool;

    public function add(
        int $vendorUserId,
        int $staffUserId,
        string $displayName,
        string $username,
        string $email,
        string $mobile,
        StaffRolePreset $preset,
        StaffPermissions $permissions,
        string $inviteHash,
        string $inviteExpiresAt
    ): int;

    public function updateRole(int $staffId, StaffRolePreset $preset, StaffPermissions $permissions): bool;

    public function updateStatus(int $staffId, StaffStatus $status): bool;

    /** Clears the invitation and marks the membership active. */
    public function activate(int $staffId): bool;

    public function touchLastSeen(int $staffId): bool;
}
