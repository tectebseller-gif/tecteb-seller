<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ChangeRequestRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequest;
use Tecteb\Marketplace\Modules\Vendor\Domain\ChangeRequestStatus;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables as T;

/** The manager's queue of fields a vendor may not change alone. */
final class DbChangeRequestRepository implements ChangeRequestRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function open(int $vendorUserId, string $field, string $currentValue, string $requestedValue): int
    {
        $now = $this->now();
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->table() . '` (vendor_user_id, field, current_value, requested_value, status, created_at)
             VALUES (%d, %s, %s, %s, %s, %s)',
            [$vendorUserId, $field, $currentValue, $requestedValue, ChangeRequestStatus::Pending->value, $now]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND field = %s AND status = %s ORDER BY id DESC LIMIT 1',
            [$vendorUserId, $field, ChangeRequestStatus::Pending->value]
        );
    }

    public function find(int $id): ?ChangeRequest
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$id]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function pending(int $limit = 50): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE status = %s ORDER BY created_at ASC LIMIT %d',
            [ChangeRequestStatus::Pending->value, max(1, min(200, $limit))]
        );
        return array_map(fn (array $row): ChangeRequest => $this->hydrate($row), $rows);
    }

    public function forVendor(int $vendorUserId, int $limit = 20): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d ORDER BY id DESC LIMIT %d',
            [$vendorUserId, max(1, min(100, $limit))]
        );
        return array_map(fn (array $row): ChangeRequest => $this->hydrate($row), $rows);
    }

    public function pendingFor(int $vendorUserId, string $field): ?ChangeRequest
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND field = %s AND status = %s ORDER BY id DESC LIMIT 1',
            [$vendorUserId, $field, ChangeRequestStatus::Pending->value]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function decide(int $id, ChangeRequestStatus $status, int $reviewerId, string $note): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s, reviewed_by = %d, reviewed_at = %s, note = %s
             WHERE id = %d AND status = %s',
            [$status->value, $reviewerId, $this->now(), $note, $id, ChangeRequestStatus::Pending->value]
        ) === 1;
    }

    private function table(): string
    {
        return T::table($this->db, T::CHANGE_REQUESTS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ChangeRequest
    {
        return new ChangeRequest(
            (int) $row['id'],
            (int) $row['vendor_user_id'],
            (string) $row['field'],
            (string) ($row['current_value'] ?? ''),
            (string) ($row['requested_value'] ?? ''),
            ChangeRequestStatus::tryFrom((string) $row['status']) ?? ChangeRequestStatus::Pending,
            (string) ($row['note'] ?? ''),
            $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
            (string) $row['created_at']
        );
    }
}
