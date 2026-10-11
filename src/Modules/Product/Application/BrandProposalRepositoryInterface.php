<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;

/** A vendor's request for a brand that is not in the manager's list yet. */
interface BrandProposalRepositoryInterface
{
    public function find(int $proposalId): ?BrandProposal;

    /**
     * The open request on this product, or null.
     *
     * «Open» is pending OR registered-but-not-applied, because both still need
     * something done and a screen that showed only the first would hide the
     * half-finished one.
     */
    public function openFor(int $productId): ?BrandProposal;

    /**
     * Records a request, or returns 0 when the same name is already open.
     *
     * The uniqueness is the DATABASE'S, on the normalised key, so two vendors
     * proposing «آمبو بگ» and «آمبوبگ» in the same second cannot both open one
     * — the second gets 0 and is told which request is already waiting
     * (`alpha.39`: a refused insert on a unique index is an answer, not a
     * failure).
     */
    public function open(int $productId, int $vendorUserId, string $name, string $normalised): int;

    /** @return list<BrandProposal> oldest first, for the manager's queue */
    public function pending(int $limit = 200): array;

    /** @return list<BrandProposal> registered but not yet on their product */
    public function unfinished(int $limit = 200): array;

    public function countPending(): int;

    /**
     * Product ids with an open request, for one query per list page.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public function openProductIds(array $productIds): array;

    /**
     * The manager's decision, guarded on the status it is deciding.
     *
     * Guarded because two managers can have the same request open: the second
     * decision must lose rather than overwrite the first (`alpha.39`), and
     * zero rows here is a lost race, not a broken table.
     */
    public function decide(int $proposalId, string $status, int $reviewerId, string $note, int $termId, string $expectedStatus): bool;
}
