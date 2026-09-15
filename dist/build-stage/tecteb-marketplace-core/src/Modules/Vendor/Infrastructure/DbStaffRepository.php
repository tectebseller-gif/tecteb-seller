<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables as T;

/** Staff memberships. The uniqueness rules live in the schema, not here. */
final class DbStaffRepository implements StaffRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function forVendor(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d ORDER BY id ASC',
            [$vendorUserId]
        );
        return array_map(fn (array $row): StaffMember => $this->hydrate($row), $rows);
    }

    public function find(int $staffId): ?StaffMember
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$staffId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByUser(int $staffUserId): ?StaffMember
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE staff_user_id = %d', [$staffUserId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByInviteHash(string $hash): ?StaffMember
    {
        if ($hash === '') {
            return null;
        }
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE invite_hash = %s AND status = %s AND invite_expires_at > %s',
            [$hash, StaffStatus::Invited->value, $this->now()]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function countActiveAndInvited(int $vendorUserId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND status IN (%s, %s)',
            [$vendorUserId, StaffStatus::Active->value, StaffStatus::Invited->value]
        );
    }

    public function usernameTaken(string $username): bool
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE username = %s',
            [$username]
        ) > 0;
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
        $now = $this->now();
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (vendor_user_id, staff_user_id, display_name, username, email, mobile, role_preset, permissions,
              status, invite_hash, invite_expires_at, invited_at, created_at, updated_at)
             VALUES (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            [
                $vendorUserId, $staffUserId, $displayName, $username, $email, $mobile,
                $preset->value, json_encode($permissions->toArray(), JSON_UNESCAPED_UNICODE),
                StaffStatus::Invited->value, $inviteHash, $inviteExpiresAt, $now, $now, $now,
            ]
        );
        if ($ok === null) {
            return 0;
        }
        // Read back by the unique column rather than asking for the last
        // insert id: the gateway has no such call, and a unique key already
        // guarantees this finds the row just written and no other.
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->table() . '` WHERE staff_user_id = %d',
            [$staffUserId]
        );
    }

    public function updateRole(int $staffId, StaffRolePreset $preset, StaffPermissions $permissions): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET role_preset = %s, permissions = %s, updated_at = %s WHERE id = %d',
            [$preset->value, json_encode($permissions->toArray(), JSON_UNESCAPED_UNICODE), $this->now(), $staffId]
        ) !== null;
    }

    public function updateStatus(int $staffId, StaffStatus $status): bool
    {
        $column = $status === StaffStatus::Suspended ? 'suspended_at' : 'updated_at';
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s, `' . $column . '` = %s, updated_at = %s WHERE id = %d',
            [$status->value, $this->now(), $this->now(), $staffId]
        ) !== null;
    }

    public function activate(int $staffId): bool
    {
        $now = $this->now();
        return $this->db->execute(
            'UPDATE `' . $this->table() . '`
             SET status = %s, activated_at = %s, invite_hash = %s, invite_expires_at = NULL, updated_at = %s
             WHERE id = %d',
            [StaffStatus::Active->value, $now, '', $now, $staffId]
        ) !== null;
    }

    public function touchLastSeen(int $staffId): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET last_seen_at = %s WHERE id = %d',
            [$this->now(), $staffId]
        ) !== null;
    }

    private function table(): string
    {
        return T::table($this->db, T::STAFF);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): StaffMember
    {
        $stored = json_decode((string) ($row['permissions'] ?? ''), true);
        return new StaffMember(
            (int) $row['id'],
            (int) $row['vendor_user_id'],
            (int) $row['staff_user_id'],
            (string) $row['display_name'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['mobile'],
            StaffRolePreset::tryFrom((string) $row['role_preset']) ?? StaffRolePreset::Custom,
            StaffPermissions::fromArray(is_array($stored) ? $stored : []),
            StaffStatus::tryFrom((string) $row['status']) ?? StaffStatus::Suspended,
            $row['invited_at'] !== null ? (string) $row['invited_at'] : null,
            $row['activated_at'] !== null ? (string) $row['activated_at'] : null,
            $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null
        );
    }
}
