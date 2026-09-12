<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables as T;

/**
 * Applications and profiles on the plugin's own tables.
 *
 * Written against DatabaseInterface rather than $wpdb, so the database suite
 * exercises the real SQL on real MariaDB (the same choice the audit
 * repository made in phase 1). Table names are built from the prefix and are
 * never parameters; every value is.
 */
final class DbVendorRepository implements VendorRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function findApplicationByUser(int $userId): ?VendorApplication
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->applications() . '` WHERE user_id = %d', [$userId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findApplication(int $applicationId): ?VendorApplication
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->applications() . '` WHERE id = %d', [$applicationId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function listApplications(?ApplicationStatus $status = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        if ($status === null) {
            $rows = $this->db->getResults(
                'SELECT * FROM `' . $this->applications() . '` ORDER BY updated_at DESC LIMIT %d',
                [$limit]
            );
        } else {
            $rows = $this->db->getResults(
                'SELECT * FROM `' . $this->applications() . '` WHERE status = %s ORDER BY updated_at DESC LIMIT %d',
                [$status->value, $limit]
            );
        }
        return array_map(fn (array $row): VendorApplication => $this->hydrate($row), $rows);
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->db->getResults('SELECT status, COUNT(*) AS n FROM `' . $this->applications() . '` GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        return $out;
    }

    public function saveDraft(int $userId, ApplicantDetails $details): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $existing = $this->findApplicationByUser($userId);
        $values = $details->toRow();
        if ($existing === null) {
            $ok = $this->db->execute(
                'INSERT INTO `' . $this->applications() . '`
                 (user_id, status, store_name, legal_name, contact_email, contact_mobile, address, terms_accepted, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
                [
                    $userId, ApplicationStatus::Draft->value,
                    $values['store_name'], $values['legal_name'], $values['contact_email'],
                    $values['contact_mobile'], $values['address'], $values['terms_accepted'],
                    $now, $now,
                ]
            );
            if ($ok === null) {
                return 0;
            }
            $id = (int) $this->db->getVar('SELECT id FROM `' . $this->applications() . '` WHERE user_id = %d', [$userId]);
            return $id;
        }

        $ok = $this->db->execute(
            'UPDATE `' . $this->applications() . '`
             SET store_name = %s, legal_name = %s, contact_email = %s, contact_mobile = %s,
                 address = %s, terms_accepted = %d, updated_at = %s
             WHERE id = %d',
            [
                $values['store_name'], $values['legal_name'], $values['contact_email'],
                $values['contact_mobile'], $values['address'], $values['terms_accepted'],
                $now, $existing->id,
            ]
        );
        return $ok === null ? 0 : $existing->id;
    }

    public function updateStatus(int $applicationId, ApplicationStatus $status, ?int $reviewerId = null, ?string $note = null): bool
    {
        // Built from what is actually being set. An earlier version used one
        // fixed statement with CASE WHEN to leave columns alone, which made
        // the parameter order hard to read and easy to break.
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $sets = ['status = %s', 'updated_at = %s'];
        $params = [$status->value, $now];
        if ($status === ApplicationStatus::Submitted) {
            $sets[] = 'submitted_at = %s';
            $params[] = $now;
        }
        if ($reviewerId !== null) {
            $sets[] = 'reviewed_by = %d';
            $params[] = $reviewerId;
            $sets[] = 'reviewed_at = %s';
            $params[] = $now;
        }
        if ($note !== null) {
            $sets[] = 'review_note = %s';
            $params[] = $note;
        }
        $params[] = $applicationId;

        return $this->db->execute(
            'UPDATE `' . $this->applications() . '` SET ' . implode(', ', $sets) . ' WHERE id = %d',
            $params
        ) !== null;
    }

    public function findProfileByUser(int $userId): ?VendorProfile
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->profiles() . '` WHERE user_id = %d', [$userId]);
        if ($row === null) {
            return null;
        }
        return new VendorProfile(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['store_name'],
            (bool) $row['can_sell'],
            (bool) $row['can_publish_directly'],
            (string) $row['created_at']
        );
    }

    public function upsertProfile(int $userId, string $storeName, bool $canSell, bool $canPublishDirectly): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // Approving twice must not create a second vendor: the unique key on
        // user_id turns the retry into an update.
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->profiles() . '` (user_id, store_name, can_sell, can_publish_directly, created_at, updated_at)
             VALUES (%d, %s, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE store_name = VALUES(store_name), can_sell = VALUES(can_sell),
                                     can_publish_directly = VALUES(can_publish_directly), updated_at = VALUES(updated_at)',
            [$userId, $storeName, $canSell ? 1 : 0, $canPublishDirectly ? 1 : 0, $now, $now]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar('SELECT id FROM `' . $this->profiles() . '` WHERE user_id = %d', [$userId]);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): VendorApplication
    {
        return new VendorApplication(
            (int) $row['id'],
            (int) $row['user_id'],
            ApplicationStatus::from((string) $row['status']),
            ApplicantDetails::fromRow($row),
            $row['review_note'] === null ? null : (string) $row['review_note'],
            $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            (string) ($row['updated_at'] ?? '')
        );
    }

    private function applications(): string
    {
        return T::table($this->db, T::APPLICATIONS);
    }

    private function profiles(): string
    {
        return T::table($this->db, T::PROFILES);
    }
}
