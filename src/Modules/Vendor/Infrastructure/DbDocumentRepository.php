<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorDocument;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables as T;

/** Uploaded documents. The bytes live in private storage; this keeps the map. */
final class DbDocumentRepository implements DocumentRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function forApplication(int $applicationId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE application_id = %d ORDER BY id ASC',
            [$applicationId]
        );
        return array_map(fn (array $row): VendorDocument => $this->hydrate($row), $rows);
    }

    public function find(int $documentId): ?VendorDocument
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$documentId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function add(int $applicationId, string $typeSlug, string $originalName, string $storedPath, string $mime, int $sizeBytes): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->table() . '` (application_id, type_slug, original_name, stored_path, mime, size_bytes, uploaded_at)
             VALUES (%d, %s, %s, %s, %s, %d, %s)',
            [$applicationId, $typeSlug, $originalName, $storedPath, $mime, $sizeBytes, $now]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar(
            'SELECT id FROM `' . $this->table() . '` WHERE application_id = %d AND type_slug = %s',
            [$applicationId, $typeSlug]
        );
    }

    public function deleteForType(int $applicationId, string $typeSlug): ?string
    {
        $row = $this->db->getRow(
            'SELECT stored_path FROM `' . $this->table() . '` WHERE application_id = %d AND type_slug = %s',
            [$applicationId, $typeSlug]
        );
        if ($row === null) {
            return null;
        }
        $this->db->execute(
            'DELETE FROM `' . $this->table() . '` WHERE application_id = %d AND type_slug = %s',
            [$applicationId, $typeSlug]
        );
        return (string) $row['stored_path'];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): VendorDocument
    {
        return new VendorDocument(
            (int) $row['id'],
            (int) $row['application_id'],
            (string) $row['type_slug'],
            (string) $row['original_name'],
            (string) $row['stored_path'],
            (string) $row['mime'],
            (int) $row['size_bytes'],
            (string) $row['uploaded_at']
        );
    }

    private function table(): string
    {
        return T::table($this->db, T::DOCUMENTS);
    }
}
