<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0025BrandRegistry as T;

/**
 * Brand requests, with «one open request per name» enforced by the index.
 *
 * `open_key` holds the normalised name while the request is open and `#<id>`
 * once it is decided, so the one unique index says both things at once: two
 * open requests for one brand are impossible, and a name that was rejected can
 * be asked for again.
 */
final class DbBrandProposalRepository implements BrandProposalRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function find(int $proposalId): ?BrandProposal
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$proposalId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function openFor(int $productId): ?BrandProposal
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '`
              WHERE product_id = %d AND status IN (%s, %s)
              ORDER BY id DESC LIMIT 1',
            [$productId, BrandProposal::PENDING, BrandProposal::REGISTERED]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function open(int $productId, int $vendorUserId, string $name, string $normalised): int
    {
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '`
                (product_id, vendor_user_id, name, normalised, open_key, status, term_id, decided_by, note, created_at)
             VALUES (%d, %d, %s, %s, %s, %s, 0, 0, NULL, %s)',
            [
                $productId,
                $vendorUserId,
                $name,
                $normalised,
                $normalised,
                BrandProposal::PENDING,
                $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
        if ($written === null) {
            return 0;
        }
        // Zero rows is the unique index refusing a second open request for the
        // same brand — an ANSWER, not a failure (`alpha.8`, `alpha.39`). The
        // caller asks who already has it open rather than reporting an error
        // about a table that is working perfectly.
        if ($written === 0) {
            return 0;
        }
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function pending(int $limit = 200): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT %d',
            [BrandProposal::PENDING, max(1, $limit)]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function unfinished(int $limit = 200): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE status = %s ORDER BY id ASC LIMIT %d',
            [BrandProposal::REGISTERED, max(1, $limit)]
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function countPending(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE status = %s',
            [BrandProposal::PENDING]
        );
    }

    public function openProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT DISTINCT product_id FROM `' . $this->table() . '`
              WHERE status IN (%s, %s) AND product_id IN (' . $placeholders . ')',
            [BrandProposal::PENDING, BrandProposal::REGISTERED, ...$ids]
        );
        return array_values(array_map(static fn (array $row): int => (int) $row['product_id'], $rows));
    }

    public function decide(int $proposalId, string $status, int $reviewerId, string $note, int $termId, string $expectedStatus): bool
    {
        // The status it is deciding is in the WHERE, so a second manager's
        // decision loses rather than overwriting the first — and `open_key`
        // becomes unique-by-construction, which is what frees the name for a
        // later request.
        $openKey = $status === BrandProposal::PENDING || $status === BrandProposal::REGISTERED
            ? null                       // still open: the key stays the name
            : '#' . $proposalId;
        $sql = 'UPDATE `' . $this->table() . '`
                   SET status = %s, decided_by = %d, note = %s, term_id = %d, decided_at = %s';
        $params = [$status, $reviewerId, $note, $termId, $this->clock->now()->format('Y-m-d H:i:s')];
        if ($openKey !== null) {
            $sql .= ', open_key = %s';
            $params[] = $openKey;
        }
        $sql .= ' WHERE id = %d AND status = %s';
        $params[] = $proposalId;
        $params[] = $expectedStatus;
        $rows = $this->db->execute($sql, $params);
        // Zero is a LOST RACE here, not a success: this statement is guarded on
        // the status, so nothing changing means somebody else decided first
        // (`alpha.39`: judge the zero against what the statement expected).
        return $rows !== null && $rows > 0;
    }

    private function table(): string
    {
        return T::table($this->db, T::PROPOSALS);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): BrandProposal
    {
        return new BrandProposal(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['vendor_user_id'],
            (string) $row['name'],
            (string) $row['normalised'],
            (string) $row['status'],
            (int) ($row['term_id'] ?? 0),
            (int) ($row['decided_by'] ?? 0),
            (string) ($row['note'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['decided_at'] ?? '')
        );
    }
}
