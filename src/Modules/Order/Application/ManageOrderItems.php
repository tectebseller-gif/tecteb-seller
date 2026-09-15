<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * What a vendor — or their staff, within their own permissions — may do to
 * their part of an order.
 *
 * Reading is «مشاهده», moving a status is «ویرایش», and the support role's
 * «پاسخ» sits between them: it may see the order and answer about it, and it
 * may not ship or cancel it. That distinction exists in the permission matrix
 * (Master Spec §3.1) and it is enforced here rather than in a template.
 */
final class ManageOrderItems
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly OrderItemStateMachine $states
    ) {
    }

    /** @return list<VendorOrderItem> */
    public function listFor(int $actorId, int $vendorUserId, ?OrderItemStatus $status = null, int $limit = 50, int $offset = 0): array
    {
        if (!$this->mayView($actorId, $vendorUserId)) {
            return [];
        }
        return $this->items->forVendor($vendorUserId, $status, $limit, $offset);
    }

    public function mayView(int $actorId, int $vendorUserId): bool
    {
        return $this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::View);
    }

    public function mayAct(int $actorId, int $vendorUserId): bool
    {
        return $this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::Edit);
    }

    public function move(
        int $actorId,
        int $vendorUserId,
        int $itemId,
        OrderItemStatus $to,
        string $carrier = '',
        string $trackingCode = ''
    ): OperationResult {
        if (!$this->mayAct($actorId, $vendorUserId)) {
            return OperationResult::failure('forbidden');
        }
        $item = $this->items->find($itemId);
        // Ownership is checked on the ROW, so another shop's item id is
        // indistinguishable from one that does not exist (AC-PRIV).
        if ($item === null || !$item->belongsTo($vendorUserId)) {
            return OperationResult::failure('not_found');
        }
        if (!$this->states->canMove($item->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $item->status->value,
                'to' => $to->value,
            ]);
        }
        if ($this->states->requiresCarrier($to) && trim($carrier) === '') {
            return OperationResult::failure('carrier_required');
        }
        $shippedAt = $to === OrderItemStatus::Shipped
            ? $this->clock->now()->format('Y-m-d H:i:s')
            : $item->shippedAt;

        $carrier = $to === OrderItemStatus::Shipped ? trim($carrier) : $item->carrier;
        $trackingCode = $to === OrderItemStatus::Shipped ? trim($trackingCode) : $item->trackingCode;

        if (!$this->items->updateStatus($itemId, $to, $carrier, $trackingCode, $shippedAt)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::ORDER_ITEM_STATUS_CHANGED, $actorId, 'order_item', (string) $itemId, [
            'vendor_id' => $vendorUserId,
            'order_id' => $item->orderId,
            'item_id' => $itemId,
            'from' => $item->status->value,
            'to' => $to->value,
        ]);
        return OperationResult::success('order_item_' . $to->value, ['item_id' => $itemId]);
    }

    /** @return array<string,int> */
    public function totals(int $actorId, int $vendorUserId): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Finance, StaffLevel::View)) {
            return [];
        }
        return $this->items->totalsForVendor($vendorUserId);
    }
}
