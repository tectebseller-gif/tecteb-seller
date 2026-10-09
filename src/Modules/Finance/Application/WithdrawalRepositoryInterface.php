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

    /**
     * Moves a request's status — and, when `$expected` is given, ONLY while it
     * is still in that status.
     *
     * `$expected` is how a caller that validated a transition against a status
     * it read earlier makes that validation hold at the moment of writing.
     * Without it the stale-cancel race is open: a vendor's page reads
     * «Approved», a manager moves the request on, and the vendor's click
     * overwrites the newer status with a decision made about the older one.
     *
     * `false` means the row was NOT moved — the statement failed, or the
     * status was no longer `$expected`. Zero changed rows is not success here,
     * which is the other half of the `alpha.8` rule: zero means «nothing
     * matched», and whether that is a success depends on what was expected.
     */
    public function updateStatus(
        int $withdrawalId,
        WithdrawalStatus $status,
        ?int $reviewerId,
        string $note,
        string $reference = '',
        ?WithdrawalStatus $expected = null
    ): bool;

    /** Frees the lines of a request that ended without a payment. */
    public function release(int $withdrawalId): bool;

    /**
     * Closed, unpaid requests that still hold money.
     *
     * The detection half of the atomic cancel: `alpha.39` wrote the status and
     * the release separately, so a failed release left a finished request with
     * order items still pointing at it — money invisible to the vendor's
     * balance and to every later release. A remedy nobody can find is not a
     * remedy, so the rows are listed with both counts, because the two tables
     * can disagree.
     *
     * A Paid request is never stranded: it keeps its lines as the record of
     * what the payment covered.
     *
     * @return list<array{withdrawal_id:int, vendor_user_id:int, status:string, amount_minor:int, claimed_items:int, reserve_lines:int}>
     */
    public function strandedReservations(int $limit = 200): array;

    /**
     * Tells a report cache this vendor's figures moved.
     *
     * Declared because a service that wraps several writes in one transaction
     * has to fire it ONCE, after its own commit: the inner writes stay silent
     * while nested, since their commits are no-ops and the outer unit of work
     * can still roll back.
     */
    public function figuresChanged(int $vendorUserId): void;
}
