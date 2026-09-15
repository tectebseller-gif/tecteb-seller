<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables as Settlement;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as T;

/**
 * Order lines on the plugin's own table.
 *
 * The unique key on `wc_order_item_id` is the idempotency: `record()` inserts
 * and ignores a duplicate rather than checking first, so two hooks racing
 * cannot both pass a check and then both write.
 */
final class DbOrderItemRepository implements OrderItemRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function record(VendorOrderItem $item): int
    {
        $existing = $this->findByOrderItem($item->orderItemId);
        if ($existing !== null) {
            return $existing->id;
        }
        $now = $this->now();
        $nullable = static fn (?int $value): string => $value === null ? 'NULL' : '%d';
        $params = [
            $item->orderId,
            $item->orderItemId,
            $item->wcProductId,
            $item->productId,
        ];
        $variation = $nullable($item->variationId);
        if ($item->variationId !== null) {
            $params[] = $item->variationId;
        }
        array_push(
            $params,
            $item->vendorUserId,
            $item->title,
            $item->sku,
            $item->quantity,
            $item->unitPriceMinor,
            $item->baseMinor,
            $item->taxMinor
        );
        $commission = $nullable($item->commissionMinor);
        if ($item->commissionMinor !== null) {
            $params[] = $item->commissionMinor;
        }
        $share = $nullable($item->vendorShareMinor);
        if ($item->vendorShareMinor !== null) {
            $params[] = $item->vendorShareMinor;
        }
        $rate = $nullable($item->rateBasisPoints);
        if ($item->rateBasisPoints !== null) {
            $params[] = $item->rateBasisPoints;
        }
        array_push($params, $item->rateSource, $item->ledgerEvent, $item->status->value, $now, $now);

        $written = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (wc_order_id, wc_order_item_id, wc_product_id, product_id, variation_id, vendor_user_id,
              title, sku, quantity, unit_price_minor, base_minor, tax_minor,
              commission_minor, vendor_share_minor, rate_bp, rate_source, ledger_event, status, created_at, updated_at)
             VALUES (%d, %d, %d, %d, ' . $variation . ', %d, %s, %s, %d, %d, %d, %d, '
             . $commission . ', ' . $share . ', ' . $rate . ', %s, %s, %s, %s, %s)',
            $params
        );
        if ($written === null) {
            return 0;
        }
        return $this->findByOrderItem($item->orderItemId)?->id ?? 0;
    }

    public function find(int $id): ?VendorOrderItem
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$id]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findByOrderItem(int $wcOrderItemId): ?VendorOrderItem
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE wc_order_item_id = %d', [$wcOrderItemId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function forVendor(int $vendorUserId, ?OrderItemStatus $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d';
        $params = [$vendorUserId];
        if ($status !== null) {
            $sql .= ' AND status = %s';
            $params[] = $status->value;
        }
        $sql .= ' ORDER BY wc_order_id DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);
        return array_map([$this, 'hydrate'], $this->db->getResults($sql, $params));
    }

    public function countForVendor(int $vendorUserId, ?OrderItemStatus $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . $this->table() . '` WHERE vendor_user_id = %d';
        $params = [$vendorUserId];
        if ($status !== null) {
            $sql .= ' AND status = %s';
            $params[] = $status->value;
        }
        return (int) $this->db->getVar($sql, $params);
    }

    public function countsByStatus(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT status, COUNT(*) AS n FROM `' . $this->table() . '` WHERE vendor_user_id = %d GROUP BY status',
            [$vendorUserId]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    public function forOrder(int $wcOrderId): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE wc_order_id = %d ORDER BY id ASC',
            [$wcOrderId]
        ));
    }

    public function updateStatus(int $id, OrderItemStatus $status, string $carrier, string $trackingCode, ?string $shippedAt): bool
    {
        $shipped = $shippedAt === null ? 'NULL' : '%s';
        $params = [$status->value, $carrier, $trackingCode];
        if ($shippedAt !== null) {
            $params[] = $shippedAt;
        }
        array_push($params, $this->now(), $id);
        return $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s, carrier = %s, tracking_code = %s,
             shipped_at = ' . $shipped . ', updated_at = %s WHERE id = %d',
            $params
        ) !== null;
    }

    public function totalsForVendor(int $vendorUserId): array
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS lines_count,
                    COALESCE(SUM(base_minor), 0) AS base,
                    COALESCE(SUM(commission_minor), 0) AS commission,
                    COALESCE(SUM(vendor_share_minor), 0) AS share
             FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND status <> %s',
            [$vendorUserId, OrderItemStatus::Cancelled->value]
        );
        return [
            'lines' => (int) ($row['lines_count'] ?? 0),
            'base' => (int) ($row['base'] ?? 0),
            'commission' => (int) ($row['commission'] ?? 0),
            'share' => (int) ($row['share'] ?? 0),
        ];
    }

    public function settlementView(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT i.id, i.vendor_share_minor, i.settlement_completed_at, i.withdrawal_id, w.status AS withdrawal_status
             FROM `' . $this->table() . '` i
             LEFT JOIN `' . $this->withdrawals() . '` w ON w.id = i.withdrawal_id
             WHERE i.vendor_user_id = %d AND i.status <> %s
             ORDER BY i.id ASC',
            [$vendorUserId, OrderItemStatus::Cancelled->value]
        );
        $view = [];
        foreach ($rows as $row) {
            $view[] = [
                'id' => (int) $row['id'],
                'vendor_share_minor' => $row['vendor_share_minor'] === null ? null : (int) $row['vendor_share_minor'],
                'settlement_completed_at' => $row['settlement_completed_at'] === null
                    ? null
                    : (string) $row['settlement_completed_at'],
                'withdrawal_id' => $row['withdrawal_id'] === null ? null : (int) $row['withdrawal_id'],
                'paid' => (string) ($row['withdrawal_status'] ?? '') === WithdrawalStatus::Paid->value,
            ];
        }
        return $view;
    }

    public function recordSettlementCompletion(int $id, ?string $completedAt, ?int $actorId): bool
    {
        // Two literals rather than placeholders: a placeholder cannot carry
        // NULL through wpdb::prepare(), and clearing a completion recorded by
        // mistake has to be possible while the money is still unlocked.
        $when = $completedAt === null ? 'NULL' : '%s';
        $who = $actorId === null ? 'NULL' : '%d';
        $params = [];
        if ($completedAt !== null) {
            $params[] = $completedAt;
        }
        if ($actorId !== null) {
            $params[] = $actorId;
        }
        array_push($params, $this->now(), $id);
        return $this->db->execute(
            'UPDATE `' . $this->table() . "` SET settlement_completed_at = {$when},
             settlement_completed_by = {$who}, updated_at = %s WHERE id = %d",
            $params
        ) !== null;
    }

    public function idsForWithdrawal(int $withdrawalId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->getResults(
                'SELECT id FROM `' . $this->table() . '` WHERE withdrawal_id = %d ORDER BY id ASC',
                [$withdrawalId]
            )
        );
    }

    private function withdrawals(): string
    {
        return Settlement::table($this->db, Settlement::WITHDRAWALS);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): VendorOrderItem
    {
        return new VendorOrderItem(
            (int) $row['id'],
            (int) $row['wc_order_id'],
            (int) $row['wc_order_item_id'],
            (int) $row['product_id'],
            (int) $row['vendor_user_id'],
            (string) $row['title'],
            (string) $row['sku'],
            (int) $row['quantity'],
            (int) $row['unit_price_minor'],
            (int) $row['base_minor'],
            (int) $row['tax_minor'],
            $row['commission_minor'] === null ? null : (int) $row['commission_minor'],
            $row['vendor_share_minor'] === null ? null : (int) $row['vendor_share_minor'],
            $row['rate_bp'] === null ? null : (int) $row['rate_bp'],
            (string) $row['rate_source'],
            (string) $row['ledger_event'],
            OrderItemStatus::tryFrom((string) $row['status']) ?? OrderItemStatus::Placed,
            (string) $row['carrier'],
            (string) $row['tracking_code'],
            $row['shipped_at'] === null ? null : (string) $row['shipped_at'],
            $row['variation_id'] === null ? null : (int) $row['variation_id'],
            (int) $row['wc_product_id'],
            (string) $row['created_at']
        );
    }

    private function table(): string
    {
        return T::table($this->db, T::ORDER_ITEMS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
