<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;

/** Pending edits to live products. The payload is JSON: a snapshot, not a diff. */
final class DbProductRevisionRepository implements ProductRevisionRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function find(int $revisionId): ?ProductRevision
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$revisionId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function pendingFor(int $productId): ?ProductRevision
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE product_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
            [$productId, ProductRevision::PENDING]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function pending(int $limit = 200): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT %d',
            [ProductRevision::PENDING, max(1, $limit)]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function countPending(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE status = %s',
            [ProductRevision::PENDING]
        );
    }

    public function create(int $productId, int $vendorUserId, array $payload): int
    {
        $ok = $this->db->execute(
            'INSERT INTO `' . $this->table() . '` (product_id, vendor_user_id, payload, status, note, created_at)
             VALUES (%d, %d, %s, %s, %s, %s)',
            [
                $productId, $vendorUserId,
                (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
                ProductRevision::PENDING, '', $this->now(),
            ]
        );
        if ($ok === null) {
            return 0;
        }
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function decide(int $revisionId, string $status, int $reviewerId, string $note): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s, note = %s, reviewed_by = %d, reviewed_at = %s
             WHERE id = %d AND status = %s',
            [$status, $note, $reviewerId, $this->now(), $revisionId, ProductRevision::PENDING]
        ) !== null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ProductRevision
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        return new ProductRevision(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['vendor_user_id'],
            is_array($payload) ? $payload : [],
            (string) $row['status'],
            (string) ($row['note'] ?? ''),
            (string) $row['created_at']
        );
    }

    private function table(): string
    {
        return T::table($this->db, T::REVISIONS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
