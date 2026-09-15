<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\Withdrawal;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;

/**
 * Storage for withdrawal requests and the order lines each one locked.
 *
 * `reserve()` is the method that carries FIN-05. It creates the request and
 * claims its lines in one go, and it must fail — not half-succeed — when
 * another request already holds any of them. The unique index on
 * `order_item_id` is what makes that true under two tabs and two workers; this
 * interface exists so the caller never has to know that.
 */
interface WithdrawalRepositoryInterface
{
    public function find(int $withdrawalId): ?Withdrawal;

    /** The vendor's open request, if they have one. v1 allows at most one. */
    public function openFor(int $vendorUserId): ?Withdrawal;

    /** @return list<Withdrawal> newest first */
    public function forVendor(int $vendorUserId, int $limit = 50): array;

    /** @return list<Withdrawal> the manager's queue, newest first */
    public function withStatus(?WithdrawalStatus $status = null, int $limit = 50): array;

    /** @return array<string,int> status value => count */
    public function countsByStatus(): array;

    /**
     * Creates the request and claims the given order lines for it, atomically.
     *
     * @param list<int> $orderItemIds
     * @return int the new withdrawal id, or 0 when any line was already claimed
     */
    public function reserve(
        int $vendorUserId,
        array $orderItemIds,
        int $amountMinor,
        string $iban,
        string $accountHolder
    ): int;

    /** @return list<int> the order-item ids this request holds */
    public function lineIds(int $withdrawalId): array;

    public function updateStatus(
        int $withdrawalId,
        WithdrawalStatus $status,
        ?int $reviewerId,
        string $note,
        string $reference = ''
    ): bool;

    /** Frees the lines of a request that ended without a payment. */
    public function release(int $withdrawalId): bool;
}
