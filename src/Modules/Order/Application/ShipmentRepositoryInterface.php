<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\Shipment;

/**
 * The parcels and the returns of one order line.
 *
 * `shippedQuantity()` and `returnedQuantity()` are SUMs over the rows, never
 * counters kept beside them: a counter that can disagree with the rows it
 * counts is a bug waiting for a crash between two writes, and these two
 * numbers are what stops a vendor shipping eight of five.
 */
interface ShipmentRepositoryInterface
{
    /** @return int row id, 0 on failure */
    public function addShipment(Shipment $shipment): int;

    /** @return list<Shipment> oldest parcel first */
    public function shipmentsFor(int $orderItemId): array;

    public function shippedQuantity(int $orderItemId): int;

    /** @return array<int,int> order item id => shipped quantity */
    public function shippedQuantities(array $orderItemIds): array;

    /** @return int row id, 0 on failure */
    public function openReturn(ReturnRequest $request): int;

    public function findReturn(int $id): ?ReturnRequest;

    /** @return list<ReturnRequest> newest first */
    public function returnsFor(int $orderItemId): array;

    /** @return list<ReturnRequest> */
    public function returnsForVendor(int $vendorUserId, ?ReturnStatus $status = null, int $limit = 100, int $offset = 0): array;

    /** @return list<ReturnRequest> every vendor's returns, for the manager */
    public function allReturns(?ReturnStatus $status = null, int $limit = 100, int $offset = 0): array;

    /** Quantity claimed by returns that are still counted against the line. */
    public function returnedQuantity(int $orderItemId): int;

    /** @return array<int,int> order item id => returned quantity */
    public function returnedQuantities(array $orderItemIds): array;

    /** Money already reversed on this line, so the last return closes it exactly. */
    public function reversedTotals(int $orderItemId): array;

    public function updateReturnStatus(
        int $id,
        ReturnStatus $status,
        int $actorId,
        string $note,
        ?string $decidedAt,
        ?string $receivedAt
    ): bool;

    /**
     * Attaches a WooCommerce refund record to a return that already has one
     * recorded in the ledger.
     *
     * Separate from `recordReversal()` because the two happen at different
     * moments and depend on different things: the ledger reversal is this
     * plugin's own and needs nothing outside it, while a `WC_Order_Refund` is
     * a manager's deliberate act on the order. The unique index on
     * `wc_refund_id` is what keeps one WooCommerce refund from being claimed
     * by two returns.
     *
     * @return bool false when this return already carries one, or when that
     *         refund id is already attached elsewhere
     */
    public function linkWcRefund(int $id, int $wcRefundId): bool;

    /**
     * Writes the refund once.
     *
     * Returns false when this return already carries a reversal — enforced by
     * the unique index on `reversal_event_key`, so two concurrent refunds
     * cannot both pass a check and then both write.
     */
    public function recordReversal(
        int $id,
        string $eventKey,
        int $refundMinor,
        int $taxRefundMinor,
        int $commissionMinor,
        int $vendorShareMinor,
        ?int $wcRefundId,
        string $refundedAt,
        int $actorId
    ): bool;

    public function recordRestock(int $id, int $quantity): bool;
}
