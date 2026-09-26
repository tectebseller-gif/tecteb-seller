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

    /**
     * Which of THESE products have an unanswered proposal.
     *
     * One query for the page's rows rather than `pendingFor()` twenty times,
     * and a subset rather than a flag per row: the caller marks the ids it
     * gets back and leaves every other row alone. An empty input means no
     * query at all — asking the database about nothing is how an `IN ()`
     * syntax error reaches a page that had nothing to show anyway.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public function pendingProductIds(array $productIds): array;

    /** @param array<string,mixed> $payload @return int revision id, or 0 on failure */
    public function create(int $productId, int $vendorUserId, array $payload): int;

    public function decide(int $revisionId, string $status, int $reviewerId, string $note): bool;
}
