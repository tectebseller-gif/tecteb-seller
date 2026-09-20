<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\Shipment;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0008ShipmentsAndReturns as T;

/**
 * Parcels and returns on the plugin's own tables.
 *
 * Every quantity this class reports is a SUM over rows, computed when asked.
 * There is no cached counter to drift, and the only way to change a total is
 * to insert a row that everybody can see.
 *
 * `recordReversal()` is the one write that moves money, and it is deliberately
 * an UPDATE with `reversal_event_key IS NULL` in its WHERE clause rather than a
 * read-then-write: the row itself decides whether this is the first refund,
 * and the unique index behind it makes two concurrent attempts impossible to
 * both succeed. A second attempt affects zero rows and is reported as refused.
 */
final class DbShipmentRepository implements ShipmentRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function addShipment(Shipment $shipment): int
    {
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT INTO `' . $this->shipments() . '`
             (order_item_id, vendor_user_id, quantity, carrier, tracking_code, tracking_url, note, shipped_at, created_by, created_at)
             VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %d, %s)',
            [
                $shipment->orderItemId,
                $shipment->vendorUserId,
                $shipment->quantity,
                $shipment->carrier,
                $shipment->trackingCode,
                $shipment->trackingUrl,
                $shipment->note,
                $shipment->shippedAt !== '' ? $shipment->shippedAt : $now,
                $shipment->createdBy,
                $now,
            ]
        );
        if ($written === null) {
            return 0;
        }
        $this->figuresChanged($shipment->vendorUserId);
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function shipmentsFor(int $orderItemId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->shipments() . '` WHERE order_item_id = %d ORDER BY id ASC',
            [$orderItemId]
        );
        return array_map([$this, 'hydrateShipment'], $rows);
    }

    public function shippedQuantity(int $orderItemId): int
    {
        return (int) $this->db->getVar(
            'SELECT COALESCE(SUM(quantity), 0) FROM `' . $this->shipments() . '` WHERE order_item_id = %d',
            [$orderItemId]
        );
    }

    public function shippedQuantities(array $orderItemIds): array
    {
        return $this->sumBy($this->shipments(), 'quantity', $orderItemIds, '');
    }

    public function openReturn(ReturnRequest $request): int
    {
        $now = $this->now();
        $written = $this->db->execute(
            'INSERT INTO `' . $this->returns() . '`
             (order_item_id, vendor_user_id, quantity, status, reason, note, requested_by, requested_at, created_at, updated_at)
             VALUES (%d, %d, %d, %s, %s, %s, %d, %s, %s, %s)',
            [
                $request->orderItemId,
                $request->vendorUserId,
                $request->quantity,
                $request->status->value,
                $request->reason,
                $request->note,
                $request->requestedBy,
                $request->requestedAt !== '' ? $request->requestedAt : $now,
                $now,
                $now,
            ]
        );
        if ($written === null) {
            return 0;
        }
        $this->figuresChanged($request->vendorUserId);
        return (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
    }

    public function findReturn(int $id): ?ReturnRequest
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->returns() . '` WHERE id = %d', [$id]);
        return $row === null ? null : $this->hydrateReturn($row);
    }

    public function returnsFor(int $orderItemId): array
    {
        $rows = $this->db->getResults(
            'SELECT * FROM `' . $this->returns() . '` WHERE order_item_id = %d ORDER BY id DESC',
            [$orderItemId]
        );
        return array_map([$this, 'hydrateReturn'], $rows);
    }

    public function returnsForVendor(int $vendorUserId, ?ReturnStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->returns() . '` WHERE vendor_user_id = %d';
        $params = [$vendorUserId];
        if ($status !== null) {
            $sql .= ' AND status = %s';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        array_push($params, max(1, $limit), max(0, $offset));
        return array_map([$this, 'hydrateReturn'], $this->db->getResults($sql, $params));
    }

    public function allReturns(?ReturnStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->returns() . '`';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = %s';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        array_push($params, max(1, $limit), max(0, $offset));
        return array_map([$this, 'hydrateReturn'], $this->db->getResults($sql, $params));
    }

    public function returnedQuantity(int $orderItemId): int
    {
        return (int) $this->db->getVar(
            'SELECT COALESCE(SUM(quantity), 0) FROM `' . $this->returns() . '`
             WHERE order_item_id = %d AND status IN (' . $this->reservingList() . ')',
            [$orderItemId]
        );
    }

    public function returnedQuantities(array $orderItemIds): array
    {
        return $this->sumBy(
            $this->returns(),
            'quantity',
            $orderItemIds,
            ' AND status IN (' . $this->reservingList() . ')'
        );
    }

    public function reversedTotals(int $orderItemId): array
    {
        $row = $this->db->getRow(
            'SELECT COALESCE(SUM(refund_minor), 0) AS refund,
                    COALESCE(SUM(tax_refund_minor), 0) AS tax,
                    COALESCE(SUM(commission_reversed_minor), 0) AS commission,
                    COALESCE(SUM(vendor_share_reversed_minor), 0) AS vendor_share,
                    COALESCE(SUM(CASE WHEN refunded_at IS NULL THEN 0 ELSE quantity END), 0) AS quantity
             FROM `' . $this->returns() . '` WHERE order_item_id = %d',
            [$orderItemId]
        );
        return [
            'refund' => (int) ($row['refund'] ?? 0),
            'tax' => (int) ($row['tax'] ?? 0),
            'commission' => (int) ($row['commission'] ?? 0),
            'vendor_share' => (int) ($row['vendor_share'] ?? 0),
            'quantity' => (int) ($row['quantity'] ?? 0),
        ];
    }

    public function updateReturnStatus(
        int $id,
        ReturnStatus $status,
        int $actorId,
        string $note,
        ?string $decidedAt,
        ?string $receivedAt
    ): bool {
        $sets = ['status = %s', 'updated_at = %s', 'decided_by = %d'];
        $params = [$status->value, $this->now(), $actorId];
        if ($note !== '') {
            $sets[] = 'note = %s';
            $params[] = $note;
        }
        if ($decidedAt !== null) {
            $sets[] = 'decided_at = %s';
            $params[] = $decidedAt;
        }
        if ($receivedAt !== null) {
            $sets[] = 'received_at = %s';
            $params[] = $receivedAt;
        }
        $params[] = $id;
        $written = $this->db->execute(
            'UPDATE `' . $this->returns() . '` SET ' . implode(', ', $sets) . ' WHERE id = %d',
            $params
        ) !== null;
        if ($written) {
            $this->figuresChanged((int) ($this->findReturn($id)?->vendorUserId ?? 0));
        }
        return $written;
    }

    public function linkWcRefund(int $id, int $wcRefundId): bool
    {
        // `wc_refund_id IS NULL` is inside the statement for the same reason
        // the reversal guard is: a return that already carries one matches
        // nothing, so a second attempt affects zero rows rather than
        // overwriting the first link. The unique index does the rest — a
        // refund id already attached to another return makes this fail.
        $affected = $this->db->execute(
            'UPDATE `' . $this->returns() . '`
             SET wc_refund_id = %d, updated_at = %s
             WHERE id = %d AND wc_refund_id IS NULL',
            [$wcRefundId, $this->now(), $id]
        );
        return $affected !== null && $affected > 0;
    }

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
    ): bool {
        $refund = $wcRefundId === null ? 'NULL' : '%d';
        $params = [
            $eventKey,
            $refundMinor,
            $taxRefundMinor,
            $commissionMinor,
            $vendorShareMinor,
        ];
        if ($wcRefundId !== null) {
            $params[] = $wcRefundId;
        }
        array_push($params, $refundedAt, $actorId, $this->now(), $id);

        // `reversal_event_key IS NULL` is the guard, and it is INSIDE the
        // statement: a return that already carries a reversal matches nothing,
        // so a second refund affects zero rows instead of overwriting the
        // first one's numbers.
        $affected = $this->db->execute(
            'UPDATE `' . $this->returns() . '`
             SET status = \'' . ReturnStatus::Refunded->value . '\',
                 reversal_event_key = %s,
                 refund_minor = %d,
                 tax_refund_minor = %d,
                 commission_reversed_minor = %d,
                 vendor_share_reversed_minor = %d,
                 wc_refund_id = ' . $refund . ',
                 refunded_at = %s,
                 decided_by = %d,
                 updated_at = %s
             WHERE id = %d AND reversal_event_key IS NULL',
            $params
        );
        return $affected !== null && $affected > 0;
    }

    public function recordRestock(int $id, int $quantity): bool
    {
        return $this->db->execute(
            'UPDATE `' . $this->returns() . '` SET restocked_quantity = %d, updated_at = %s WHERE id = %d',
            [max(0, $quantity), $this->now(), $id]
        ) !== null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,int>
     */
    private function sumBy(string $table, string $column, array $ids, string $extra): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->getResults(
            'SELECT order_item_id, COALESCE(SUM(' . $column . '), 0) AS total
             FROM `' . $table . '` WHERE order_item_id IN (' . $placeholders . ')' . $extra . '
             GROUP BY order_item_id',
            $ids
        );
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['order_item_id']] = (int) $row['total'];
        }
        return $totals;
    }

    /** The statuses that still hold quantity against the line, as SQL. */
    private function reservingList(): string
    {
        $quoted = [];
        foreach (ReturnStatus::all() as $status) {
            if ($status->reservesQuantity()) {
                $quoted[] = "'" . $status->value . "'";
            }
        }
        return implode(', ', $quoted);
    }

    /** @param array<string,mixed> $row */
    private function hydrateShipment(array $row): Shipment
    {
        return new Shipment(
            (int) $row['id'],
            (int) $row['order_item_id'],
            (int) $row['vendor_user_id'],
            (int) $row['quantity'],
            (string) $row['carrier'],
            (string) $row['tracking_code'],
            (string) $row['tracking_url'],
            (string) $row['note'],
            (string) $row['shipped_at'],
            (int) $row['created_by'],
            (string) $row['created_at']
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateReturn(array $row): ReturnRequest
    {
        $int = static fn (string $key) => $row[$key] === null ? null : (int) $row[$key];
        $str = static fn (string $key) => $row[$key] === null ? null : (string) $row[$key];
        return new ReturnRequest(
            (int) $row['id'],
            (int) $row['order_item_id'],
            (int) $row['vendor_user_id'],
            (int) $row['quantity'],
            ReturnStatus::tryFrom((string) $row['status']) ?? ReturnStatus::Requested,
            (string) $row['reason'],
            (string) ($row['note'] ?? ''),
            $int('refund_minor'),
            $int('tax_refund_minor'),
            $int('commission_reversed_minor'),
            $int('vendor_share_reversed_minor'),
            (int) $row['restocked_quantity'],
            $str('reversal_event_key'),
            $int('wc_refund_id'),
            (int) $row['requested_by'],
            (string) $row['requested_at'],
            $int('decided_by'),
            $str('decided_at'),
            $str('received_at'),
            $str('refunded_at')
        );
    }

    private function shipments(): string
    {
        return T::table($this->db, T::SHIPMENTS);
    }

    private function returns(): string
    {
        return T::table($this->db, T::RETURNS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * The one hook a report cache listens to.
     *
     * Fired from the repository rather than from the service above it,
     * because Application may not call WordPress — and fired from the WRITE
     * rather than from the caller, which is the mistake `alpha.16` made with
     * the store page: forgetting before the save let a read land in between
     * and re-cache the stale answer under the new version.
     */
    private function figuresChanged(int $vendorUserId): void
    {
        if ($vendorUserId > 0 && function_exists('do_action')) {
            do_action('tmc_vendor_figures_changed', $vendorUserId);
        }
    }
}
