<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0019BaselineAndDecisions as T;

/**
 * The decision trail, on its own table.
 *
 * There is no UPDATE and no DELETE in this file, and that is the design. The
 * scope is enforced in the WHERE clause rather than by the caller
 * remembering: `forProduct()` with a vendor id joins the product's owner, so
 * a guessed product id from another shop returns nothing rather than
 * somebody else's correspondence.
 */
final class DbProductDecisionRepository implements ProductDecisionRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(
        int $productId,
        int $vendorUserId,
        int $actorId,
        string $decision,
        string $note
    ): bool {
        if ($productId <= 0 || trim($decision) === '') {
            return false;
        }
        return $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
                (product_id, vendor_user_id, actor_id, decision, note, created_at)
             VALUES (%d, %d, %d, %s, %s, %s)',
            [$productId, max(0, $vendorUserId), max(0, $actorId), $decision, $note, $this->now()]
        ) !== null;
    }

    public function forProduct(int $productId, ?int $vendorUserId = null, int $limit = 50): array
    {
        $sql = 'SELECT * FROM `' . $this->table() . '` WHERE product_id = %d';
        $params = [$productId];
        if ($vendorUserId !== null) {
            $sql .= ' AND vendor_user_id = %d';
            $params[] = $vendorUserId;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = max(1, min(200, $limit));

        return array_map([$this, 'hydrate'], $this->db->getResults($sql, $params));
    }

    public function latestForVendor(int $productId, int $vendorUserId): ?ProductDecision
    {
        foreach ($this->forProduct($productId, $vendorUserId, 20) as $decision) {
            if ($decision->isForVendor()) {
                return $decision;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ProductDecision
    {
        return new ProductDecision(
            (int) ($row['id'] ?? 0),
            (int) ($row['product_id'] ?? 0),
            (int) ($row['vendor_user_id'] ?? 0),
            (int) ($row['actor_id'] ?? 0),
            (string) ($row['decision'] ?? ''),
            (string) ($row['note'] ?? ''),
            (string) ($row['created_at'] ?? '')
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function table(): string
    {
        return T::table($this->db, T::DECISIONS);
    }
}
