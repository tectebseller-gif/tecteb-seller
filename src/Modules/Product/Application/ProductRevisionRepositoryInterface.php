<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;

interface ProductRevisionRepositoryInterface
{
    public function find(int $revisionId): ?ProductRevision;

    public function pendingFor(int $productId): ?ProductRevision;

    /** @return list<ProductRevision> */
    public function pending(int $limit = 200): array;

    public function countPending(): int;

    /** @param array<string,mixed> $payload @return int revision id, or 0 on failure */
    public function create(int $productId, int $vendorUserId, array $payload): int;

    public function decide(int $revisionId, string $status, int $reviewerId, string $note): bool;
}
