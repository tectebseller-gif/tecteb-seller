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

    public function vendorUserIds(bool $sellingOnly = false, int $limit = 500): array
    {
        $sql = 'SELECT user_id FROM `' . $this->profiles() . '`';
        if ($sellingOnly) {
            $sql .= ' WHERE can_sell = 1';
        }
        $sql .= ' ORDER BY user_id ASC LIMIT %d';
        $ids = [];
        foreach ($this->db->getResults($sql, [max(1, min(2000, $limit))]) as $row) {
            $ids[] = (int) $row['user_id'];
        }
        return $ids;
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

    public function upsertProfile(
        int $userId,
        string $storeName,
        bool $canSell,
        bool $canPublishDirectly,
        string $importRunId = ''
    ): int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // Approving twice must not create a second vendor: the unique key on
        // user_id turns the retry into an update.
        //
        // `import_run_id` is written HERE rather than by a follow-up UPDATE.
        // A profile created by an import and stamped a statement later is a
        // profile a process can die in the middle of — and an unstamped shop
        // is one `rollback()` cannot find once the manifest is gone, which is
        // the failure alpha.14's own evidence run walked into.
        //
        // `IF(VALUES(...) = '', import_run_id, VALUES(...))` and not a plain
        // assignment: an ordinary approval passes no run id, and that must not
        // wipe the stamp an earlier import left. The first stamp wins and
        // only an import can set one.
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->profiles() . '` (user_id, store_name, can_sell, can_publish_directly, import_run_id, created_at, updated_at)
             VALUES (%d, %s, %d, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE store_name = VALUES(store_name), can_sell = VALUES(can_sell),
                                     can_publish_directly = VALUES(can_publish_directly),
                                     import_run_id = IF(VALUES(import_run_id) = \'\', import_run_id, VALUES(import_run_id)),
                                     updated_at = VALUES(updated_at)',
            [$userId, $storeName, $canSell ? 1 : 0, $canPublishDirectly ? 1 : 0, mb_substr($importRunId, 0, 64), $now, $now]
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

    public function stampImportRun(int $userId, string $runId): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->profiles() . '` SET import_run_id = %s WHERE user_id = %d',
            [mb_substr($runId, 0, 64), $userId]
        ) !== null;
    }

    public function idsFromImportRun(string $runId): array
    {
        if ($runId === '') {
            // `import_run_id` defaults to '', so an empty id would match every
            // profile that predates the column — every shop on the site.
            return [];
        }
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->getResults(
                'SELECT user_id FROM `' . $this->profiles() . '` WHERE import_run_id = %s ORDER BY user_id ASC',
                [$runId]
            )
        );
    }

    public function importRunIds(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['import_run_id'],
            $this->db->getResults(
                'SELECT DISTINCT import_run_id FROM `' . $this->profiles() . '`
                 WHERE import_run_id <> %s ORDER BY import_run_id DESC',
                ['']
            )
        );
    }

    public function approvedVendorUserIds(int $limit = 50, int $offset = 0): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->getResults(
                'SELECT user_id FROM `' . $this->applications() . '`
                  WHERE status = %s ORDER BY user_id ASC LIMIT %d OFFSET %d',
                [ApplicationStatus::Approved->value, max(1, min(2000, $limit)), max(0, $offset)]
            )
        );
    }

    public function countApprovedVendors(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->applications() . '` WHERE status = %s',
            [ApplicationStatus::Approved->value]
        );
    }

    public function deleteEmptyProfile(int $userId): bool
    {
        if ($this->findApplicationByUser($userId) !== null) {
            return false;
        }
        $prefix = $this->db->prefix();
        // Asked of the tables directly rather than through the product and
        // staff repositories: this runs inside a rollback, and a rollback that
        // needed three more services to answer "is this shop empty?" would be
        // a rollback that could fail for a reason unrelated to the data.
        foreach (['tmc_products' => 'vendor_user_id', 'tmc_vendor_staff' => 'vendor_user_id'] as $table => $column) {
            $name = $prefix . $table;
            if ((string) $this->db->getVar('SHOW TABLES LIKE %s', [$name]) !== $name) {
                continue;
            }
            if ((int) $this->db->getVar('SELECT COUNT(*) FROM `' . $name . '` WHERE ' . $column . ' = %d', [$userId]) > 0) {
                return false;
            }
        }
        $removed = $this->db->execute('DELETE FROM `' . $this->profiles() . '` WHERE user_id = %d', [$userId]);
        return $removed !== null && $removed > 0;
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
