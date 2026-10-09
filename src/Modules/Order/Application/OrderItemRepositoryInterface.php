<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;

/**
 * What the marketplace recorded about a sale.
 *
 * `record()` is idempotent on the WooCommerce order-item id: a checkout hook
 * that fires twice, a payment callback that retries, or a cron catching up
 * must all produce one row (and, through the ledger's own unique key, one set
 * of ledger lines).
 */
interface OrderItemRepositoryInterface
{
    /** @return int row id, 0 on failure; the existing id when already recorded */
    public function record(VendorOrderItem $item): int;

    public function find(int $id): ?VendorOrderItem;

    public function findByOrderItem(int $wcOrderItemId): ?VendorOrderItem;

    /** @return list<VendorOrderItem> this vendor's lines, newest order first */
    public function forVendor(int $vendorUserId, ?OrderItemStatus $status = null, int $limit = 100, int $offset = 0): array;

    public function countForVendor(int $vendorUserId, ?OrderItemStatus $status = null): int;

    /** @return array<string,int> status value => count */
    public function countsByStatus(int $vendorUserId): array;

    /** @return list<VendorOrderItem> every vendor's lines on one order */
    public function forOrder(int $wcOrderId): array;

    public function updateStatus(int $id, OrderItemStatus $status, string $carrier, string $trackingCode, ?string $shippedAt): bool;

    /** @return array<string,int> what this vendor has earned and owes, by account */
    public function totalsForVendor(int $vendorUserId): array;

    /**
     * Every line that settlement has to reason about, with the three facts
     * that decide whether its money may be asked for yet.
     *
     * Declared here rather than only on the concrete class because
     * VendorBalance and ManageReturns both depend on this interface and both
     * call it: a method that exists only on the implementation is a contract
     * that a second implementation would silently fail to honour.
     *
     * @return list<array{
     *   id:int, vendor_share_minor:?int, settlement_completed_at:?string,
     *   withdrawal_id:?int, paid:bool
     * }>
     */
    public function settlementView(int $vendorUserId): array;

    /** ORDER-01: a manager (never a process of ours) calling a sale complete. */
    public function recordSettlementCompletion(int $id, ?string $completedAt, ?int $actorId): bool;

    /**
     * Fills in the financial half of a line stored without it, and ONLY what
     * is still missing — so a repair can never rewrite figures that are
     * already recorded, nor repoint a line at a different document. A figure
     * already present must equal the one being written or the write matches
     * nothing and this answers `false`; the caller then names it as needing
     * reconciliation rather than picking a winner.
     *
     * Declared here because `CaptureOrder` calls it: a repeated WooCommerce
     * callback is the natural place for the remedy, and `CaptureOrder` holds
     * this interface rather than the concrete repository.
     */
    public function completeFinancials(
        int $id,
        int $commissionMinor,
        int $vendorShareMinor,
        ?int $rateBasisPoints,
        string $rateSource,
        string $ledgerEvent
    ): bool;

    /**
     * Every line stored without its financial half.
     *
     * The detection half: a remedy nobody can find is not a remedy.
     *
     * @return list<array{id:int, wc_order_id:int, wc_order_item_id:int, vendor_user_id:int, ledger_event:string, has_share:bool}>
     */
    public function incompleteCaptures(int $limit = 200): array;
}
