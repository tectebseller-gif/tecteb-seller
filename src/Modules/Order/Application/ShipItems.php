<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\Shipment;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Shipping some of a line, more than once, and never more than is left.
 *
 * An order line is «۳ عدد». A vendor who has two in stock sends two today and
 * one on Thursday, and the customer needs both tracking codes. Marking the
 * whole line «ارسال شد» on the first parcel is a lie the customer acts on, so
 * each parcel is its own record and the line's status is DERIVED from them:
 *
 *   nothing shipped              → whatever the vendor set (placed/preparing)
 *   some shipped, some left      → partially_shipped
 *   all shipped                  → shipped
 *
 * "Never more than remains" is the one arithmetic rule, and remaining is
 * `quantity − shipped − returned`: goods that came back are not goods the
 * vendor may now ship again on this line.
 *
 * Nothing here touches stock. Stock left when WooCommerce reduced it at
 * checkout (ADR-008 keeps stock on WooCommerce's side); shipping is about
 * where the goods are, not how many exist.
 */
final class ShipItems
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * Records one parcel.
     *
     * @param int $quantity how many units are in THIS parcel, not in total
     */
    public function ship(
        int $actorId,
        int $vendorUserId,
        int $itemId,
        int $quantity,
        string $carrier,
        string $trackingCode = '',
        string $trackingUrl = '',
        string $note = ''
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $item = $this->items->find($itemId);
        // Ownership is checked on the ROW, so another shop's item id is
        // indistinguishable from one that does not exist (AC-PRIV).
        if ($item === null || !$item->belongsTo($vendorUserId)) {
            return OperationResult::failure('not_found');
        }
        if ($item->status === OrderItemStatus::Cancelled) {
            return OperationResult::failure('order_item_cancelled');
        }
        $carrier = trim($carrier);
        if ($carrier === '') {
            return OperationResult::failure('carrier_required');
        }
        $remaining = $this->remaining($item->id, $item->quantity);
        if ($quantity < 1) {
            return OperationResult::failure('quantity_required');
        }
        if ($quantity > $remaining) {
            return OperationResult::failure('quantity_exceeds_remaining', [
                'requested' => $quantity,
                'remaining' => $remaining,
            ]);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $shipmentId = $this->shipments->addShipment(new Shipment(
            0,
            $item->id,
            $vendorUserId,
            $quantity,
            $carrier,
            trim($trackingCode),
            trim($trackingUrl),
            trim($note),
            $now,
            $actorId
        ));
        if ($shipmentId === 0) {
            return OperationResult::failure('storage_failed');
        }

        // Re-read the sum rather than adding to the number we just checked:
        // the status has to describe what the rows say, not what this call
        // expected them to say.
        $shipped = $this->shipments->shippedQuantity($item->id);
        $status = $this->statusFor($item->quantity, $shipped, $item->id);
        $firstShipmentAt = $item->shippedAt ?? $now;
        if (!$this->items->updateStatus($item->id, $status, $carrier, trim($trackingCode), $firstShipmentAt)) {
            return OperationResult::failure('storage_failed');
        }

        $this->audit->log(AuditEventCatalog::ORDER_ITEM_SHIPPED, $actorId, 'order_item', (string) $item->id, [
            'vendor_id' => $vendorUserId,
            'order_id' => $item->orderId,
            'shipment_id' => $shipmentId,
            'quantity' => $quantity,
            'shipped_total' => $shipped,
            'line_quantity' => $item->quantity,
            'carrier' => $carrier,
            'to' => $status->value,
        ]);
        return OperationResult::success('order_item_shipped', [
            'item_id' => $item->id,
            'shipment_id' => $shipmentId,
            'quantity' => $quantity,
            'shipped_total' => $shipped,
            'remaining' => max(0, $remaining - $quantity),
        ]);
    }

    /** What is left to ship on this line: quantity − shipped − returned. */
    public function remaining(int $itemId, int $lineQuantity): int
    {
        return max(
            0,
            $lineQuantity
            - $this->shipments->shippedQuantity($itemId)
            - $this->shipments->returnedQuantity($itemId)
        );
    }

    /** @return list<\Tecteb\Marketplace\Modules\Order\Domain\Shipment> */
    public function parcelsOf(int $actorId, int $vendorUserId, int $itemId): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::View)) {
            return [];
        }
        $item = $this->items->find($itemId);
        if ($item === null || !$item->belongsTo($vendorUserId)) {
            return [];
        }
        return $this->shipments->shipmentsFor($itemId);
    }

    /**
     * The status the rows imply.
     *
     * A line whose goods all came back before anything shipped is not
     * «partially shipped» — it has shipped nothing — so returned units are
     * excluded from the "is it complete" question rather than counted as
     * shipped.
     */
    private function statusFor(int $lineQuantity, int $shipped, int $itemId): OrderItemStatus
    {
        if ($shipped <= 0) {
            return OrderItemStatus::Preparing;
        }
        $returned = $this->shipments->returnedQuantity($itemId);
        return $shipped >= max(0, $lineQuantity - $returned)
            ? OrderItemStatus::Shipped
            : OrderItemStatus::PartiallyShipped;
    }
}
