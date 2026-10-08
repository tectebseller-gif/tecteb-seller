<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
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
        private readonly OrderItemStateMachine $states,
        private readonly ?CapabilityCheckerInterface $capabilities = null
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
        if ($to === OrderItemStatus::Shipped || $to === OrderItemStatus::PartiallyShipped) {
            // Shipping is a quantity, so it goes through ShipItems, which
            // records the parcel and then works the status out from the rows.
            // Setting «ارسال‌شده» here would leave a line that claims to have
            // shipped with no parcel behind it and nothing for the customer to
            // track — the exact lie partial shipment was built to stop.
            return OperationResult::failure('use_shipment', ['item_id' => $itemId]);
        }
        if (!$this->states->canMove($item->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $item->status->value,
                'to' => $to->value,
            ]);
        }
        // Carrier and tracking belong to a parcel now; a plain status move
        // keeps whatever the last parcel recorded rather than clearing it.
        unset($carrier, $trackingCode);
        if (!$this->items->updateStatus($itemId, $to, $item->carrier, $item->trackingCode, $item->shippedAt)) {
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

    /**
     * A manager saying this sale is complete for the purpose of settlement.
     *
     * Deliberately NOT derived from the shipping status. ORDER-01 keeps three
     * axes apart — WooCommerce's payment status, the vendor's own shipping
     * status, and the financial one — and says only a manager or an approved
     * process may record the last. Reading it off «delivered» would be this
     * plugin deciding DEC-02 by implication, which is exactly what it must
     * not do while DEC-02 is open.
     *
     * The capability is the manager's, not the vendor's: a shop that could
     * declare its own sales complete could start its own settlement clock.
     */
    public function recordSettlementCompletion(int $itemId, bool $complete): OperationResult
    {
        if (!$this->capabilities->can(Capabilities::REVIEW_WITHDRAWALS)) {
            return OperationResult::failure('forbidden');
        }
        $item = $this->items->find($itemId);
        if ($item === null) {
            return OperationResult::failure('not_found');
        }
        if ($item->status === OrderItemStatus::Cancelled) {
            return OperationResult::failure('order_item_cancelled');
        }
        $actorId = $this->capabilities->currentUserId() ?? 0;
        $completedAt = $complete ? $this->clock->now()->format('Y-m-d H:i:s') : null;
        if (!$this->items->recordSettlementCompletion($itemId, $completedAt, $complete ? $actorId : null)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::ORDER_SETTLEMENT_RECORDED, $actorId, 'order_item', (string) $itemId, [
            'vendor_id' => $item->vendorUserId,
            'order_id' => $item->orderId,
            'item_id' => $itemId,
            'completed_at' => (string) $completedAt,
        ]);
        return OperationResult::success(
            $complete ? 'settlement_recorded' : 'settlement_cleared',
            ['item_id' => $itemId]
        );
    }

    /**
     * Order lines stored without their financial half — the ones a manager has
     * to be able to find.
     *
     * **Why this is a read a manager needs.** `alpha.38` could store a line
     * with a null share, a null rate and an empty `ledger_event`, and then
     * skip it for ever because the row existed. `alpha.39` repairs such a line
     * the next time WooCommerce fires the hook for its order, and that hook
     * may never fire again. So the rows are also listed, with the one fact
     * that decides what can be done about each: whether the ledger holds its
     * event.
     *
     *  - `recoverable` — the figures are on the books and a repair will take
     *    them from there. Firing the order's capture again is enough.
     *  - `rate_unknown` — nothing was ever accrued for this line. That is a
     *    decision (FIN-02 forbids a guessed rate), not a repair.
     *
     * Gated on the withdrawal-review capability rather than a new one: this is
     * the same question as «آیا این فروشنده طلبی دارد که ثبت نشده» and the
     * balance already reports its count.
     *
     * @return array{allowed:bool, lines:list<array{id:int, wc_order_id:int, wc_order_item_id:int, vendor_user_id:int, remedy:string}>}
     */
    public function incompleteCaptures(?RecordCommission $commissions = null, int $limit = 200): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::REVIEW_WITHDRAWALS)) {
            return ['allowed' => false, 'lines' => []];
        }
        $lines = [];
        foreach ($this->items->incompleteCaptures($limit) as $row) {
            $key = $row['ledger_event'] !== ''
                ? $row['ledger_event']
                : CaptureOrder::eventKey($row['wc_order_id'], $row['wc_order_item_id']);
            // Without the finance service the remedy cannot be told apart from
            // the decision, and `unknown` says that rather than guessing the
            // kinder answer.
            $remedy = 'unknown';
            if ($commissions !== null) {
                $remedy = $commissions->recoverRecorded($key) !== null ? 'recoverable' : 'rate_unknown';
            }
            $lines[] = [
                'id' => $row['id'],
                'wc_order_id' => $row['wc_order_id'],
                'wc_order_item_id' => $row['wc_order_item_id'],
                'vendor_user_id' => $row['vendor_user_id'],
                'remedy' => $remedy,
            ];
        }
        return ['allowed' => true, 'lines' => $lines];
    }
}
