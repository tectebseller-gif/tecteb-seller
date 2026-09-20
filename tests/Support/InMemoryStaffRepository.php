<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;

/**
 * Memberships in memory.
 *
 * It keeps the one rule the real table enforces with a unique key — one person
 * belongs to one store — because the imported-staff path depends on it: a
 * fake that let the same user join twice would let a test pass that the
 * database would refuse.
 */
final class InMemoryStaffRepository implements StaffRepositoryInterface
{
    /** @var array<int,StaffMember> */
    public array $members = [];
    public bool $failWrites = false;

    private int $nextId = 1;

    public function forVendor(int $vendorUserId): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (StaffMember $m): bool => $m->vendorUserId === $vendorUserId
        ));
    }

    public function find(int $staffId): ?StaffMember
    {
        return $this->members[$staffId] ?? null;
    }

    public function findByUser(int $staffUserId): ?StaffMember
    {
        foreach ($this->members as $member) {
            if ($member->staffUserId === $staffUserId) {
                return $member;
            }
        }
        return null;
    }

    public function findByInviteHash(string $hash): ?StaffMember
    {
        // Mirrors DbStaffRepository: an empty hash matches nothing, which is
        // what makes an adopted membership impossible to activate by token.
        if ($hash === '') {
            return null;
        }
        return $this->byHash[$hash] ?? null;
    }

    /** @var array<string,StaffMember> */
    private array $byHash = [];

    public function countActiveAndInvited(int $vendorUserId): int
    {
        return count(array_filter(
            $this->forVendor($vendorUserId),
            static fn (StaffMember $m): bool => $m->status !== StaffStatus::Suspended
        ));
    }

    public function usernameTaken(string $username): bool
    {
        foreach ($this->members as $member) {
            if ($member->username === $username) {
                return true;
            }
        }
        return false;
    }

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
    ): int {
        $id = $this->store($vendorUserId, $staffUserId, $displayName, $username, $email, $mobile, $preset, $permissions);
        if ($id > 0 && $inviteHash !== '') {
            $this->byHash[$inviteHash] = $this->members[$id];
        }
        return $id;
    }

    public function adopt(
        int $vendorUserId,
        int $staffUserId,
        string $displayName,
        string $username,
        string $email,
        StaffRolePreset $preset,
        StaffPermissions $permissions
    ): int {
        return $this->store($vendorUserId, $staffUserId, $displayName, $username, $email, '', $preset, $permissions);
    }

    public function updateRole(int $staffId, StaffRolePreset $preset, StaffPermissions $permissions): bool
    {
        $member = $this->find($staffId);
        if ($member === null || $this->failWrites) {
            return false;
        }
        $this->members[$staffId] = $this->with($member, $preset, $permissions, $member->status);
        return true;
    }

    public function updateStatus(int $staffId, StaffStatus $status): bool
    {
        $member = $this->find($staffId);
        if ($member === null || $this->failWrites) {
            return false;
        }
        $this->members[$staffId] = $this->with($member, $member->preset, $member->permissions, $status);
        return true;
    }

    public function activate(int $staffId): bool
    {
        return $this->updateStatus($staffId, StaffStatus::Active);
    }

    public function touchLastSeen(int $staffId): bool
    {
        return $this->find($staffId) !== null;
    }

    private function store(
        int $vendorUserId,
        int $staffUserId,
        string $displayName,
        string $username,
        string $email,
        string $mobile,
        StaffRolePreset $preset,
        StaffPermissions $permissions
    ): int {
        if ($this->failWrites || $this->findByUser($staffUserId) !== null) {
            return 0;
        }
        $id = $this->nextId++;
        $this->members[$id] = new StaffMember(
            $id,
            $vendorUserId,
            $staffUserId,
            $displayName,
            $username,
            $email,
            $mobile,
            $preset,
            $permissions,
            StaffStatus::Invited
        );
        return $id;
    }

    private function with(
        StaffMember $member,
        StaffRolePreset $preset,
        StaffPermissions $permissions,
        StaffStatus $status
    ): StaffMember {
        return new StaffMember(
            $member->id,
            $member->vendorUserId,
            $member->staffUserId,
            $member->displayName,
            $member->username,
            $member->email,
            $member->mobile,
            $preset,
            $permissions,
            $status,
            $member->invitedAt,
            $member->activatedAt,
            $member->lastSeenAt
        );
    }
}
