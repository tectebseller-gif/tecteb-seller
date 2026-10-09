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
            // A REFUSED INSERT IS NOT THE SAME AS NO ROW.
            //
            // `wc_order_item_id` is unique, and the read at the top of this
            // method is a courtesy: two WooCommerce callbacks inside the
            // capture at the same moment both get past it and the index
            // decides. The loser's `execute()` says `null`, and until
            // `alpha.39` it answered `0` — which `CaptureOrder` reports as
            // `item_not_stored`, a storage failure, about a line that is
            // stored and complete. «موفق شد» را از خودِ داده بپرسید
            // (`alpha.28`): if the row is there now, the caller has what it
            // asked for, whoever wrote it.
            $winner = $this->findByOrderItem($item->orderItemId);
            return $winner?->id ?? 0;
        }
        $this->figuresChanged($item->vendorUserId);
        return $this->findByOrderItem($item->orderItemId)?->id ?? 0;
    }

    /**
     * Fills in the financial half of a line that was stored without it — and
     * only while it is still missing.
     *
     * **The state this exists for.** `alpha.38` wrote the line before reading
     * whether the ledger write had succeeded, and turned a refused write into
     * `needsConfiguration('already_recorded')`. So a site could hold an order
     * line with a null share, a null rate and an empty `ledger_event` beside a
     * perfectly good ledger event — and every later callback SKIPPED it,
     * because a row existed. `alpha.39` cannot produce that state any more,
     * but it cannot un-produce the rows already on disk either, and a vendor
     * is owed an answer about them.
     *
     * **Why the `WHERE` carries the incompleteness.** This is the one write in
     * the module that fills in figures after the fact, and it must never be
     * able to change figures that are already there: «نرخ تازه نباید تاریخ را
     * بازنویسی کند». So the condition is the defect, and a row that somebody
     * else completed in the meantime matches nothing and is reported as not
     * repaired. Zero changed rows is `false` HERE — the operation expected to
     * change exactly one row — which is the `alpha.8` rule read against what
     * this statement wanted.
     *
     * **And «incomplete» was too wide a condition to write under.** Until
     * `alpha.40` it was `vendor_share_minor IS NULL OR ledger_event = ''`, so a
     * row holding a share and a rate but no event link — the second shape
     * `alpha.38` could leave behind — had its FIGURES overwritten on the way to
     * writing the link. Those figures are recorded money: if they agree with
     * the event there is nothing to change about them, and if they disagree
     * then no write here is correct, because one of the two is wrong and a
     * person has to say which. So the guard is now three conditions, each
     * naming a thing this write may not do:
     *
     *  - `ledger_event = '' OR ledger_event = %s` — never repoint a line at a
     *    different event than the one it already names.
     *  - `vendor_share_minor IS NULL OR vendor_share_minor = %d` and the same
     *    for `commission_minor` — fill them when they are missing, and
     *    otherwise only proceed when what is there is what the event says.
     *
     * A row that fails the figure half matches nothing, this returns `false`,
     * and `CaptureOrder::repair()` reads the row back and calls it
     * `line_reconcile_required` rather than `line_incomplete` — «ناسازگاری
     * غیرقابل‌بازیابی باید نام‌گذاری شود» and the two are different jobs for
     * whoever picks them up.
     */
    public function completeFinancials(
        int $id,
        int $commissionMinor,
        int $vendorShareMinor,
        ?int $rateBasisPoints,
        string $rateSource,
        string $ledgerEvent
    ): bool {
        if ($id <= 0 || trim($ledgerEvent) === '') {
            return false;
        }
        $rate = $rateBasisPoints === null ? 'NULL' : '%d';
        $params = [$commissionMinor, $vendorShareMinor];
        if ($rateBasisPoints !== null) {
            $params[] = $rateBasisPoints;
        }
        array_push(
            $params,
            $rateSource,
            $ledgerEvent,
            $this->now(),
            $id,
            // The three guards, in the order the docblock states them.
            $ledgerEvent,
            $vendorShareMinor,
            $commissionMinor
        );
        $rows = $this->db->execute(
            'UPDATE `' . $this->table() . "` SET commission_minor = %d, vendor_share_minor = %d,
             rate_bp = {$rate}, rate_source = %s, ledger_event = %s, updated_at = %s
             WHERE id = %d
               AND (ledger_event = '' OR ledger_event = %s)
               AND (vendor_share_minor IS NULL OR vendor_share_minor = %d)
               AND (commission_minor IS NULL OR commission_minor = %d)",
            $params
        );
        if ($rows === null || $rows === 0) {
            return false;
        }
        $this->figuresChanged((int) ($this->find($id)?->vendorUserId ?? 0));
        return true;
    }

    /**
     * Every line stored without its financial half — the detection half of
     * the remedy above.
     *
     * Read by `ManageOrderItems::incompleteCaptures()` so a manager can see
     * them, because «رکوردهای نیمه‌تمام موجود نباید بی‌صدا نادیده گرفته
     * شوند» and a repair nobody can find is not a remedy. The `ledger_event`
     * is returned as stored — empty for the rows this is about — alongside the
     * key the capture WOULD have used, which is what a repair looks the event
     * up by.
     *
     * @return list<array{id:int, wc_order_id:int, wc_order_item_id:int, vendor_user_id:int, ledger_event:string, has_share:bool}>
     */
    public function incompleteCaptures(int $limit = 200): array
    {
        $rows = $this->db->getResults(
            'SELECT id, wc_order_id, wc_order_item_id, vendor_user_id, ledger_event, vendor_share_minor
             FROM `' . $this->table() . "` WHERE vendor_share_minor IS NULL OR ledger_event = ''
             ORDER BY id ASC LIMIT %d",
            [max(1, min(1000, $limit))]
        );
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'wc_order_id' => (int) $row['wc_order_id'],
                'wc_order_item_id' => (int) $row['wc_order_item_id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'ledger_event' => (string) $row['ledger_event'],
                'has_share' => $row['vendor_share_minor'] !== null,
            ],
            $rows
        );
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
        $written = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s, carrier = %s, tracking_code = %s,
             shipped_at = ' . $shipped . ', updated_at = %s WHERE id = %d',
            $params
        ) !== null;
        if ($written) {
            // Read back rather than passed in: the caller has an id, and the
            // shop the row belongs to is the row's own answer.
            $this->figuresChanged((int) ($this->find($id)?->vendorUserId ?? 0));
        }
        return $written;
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
        $written = $this->db->execute(
            'UPDATE `' . $this->table() . "` SET settlement_completed_at = {$when},
             settlement_completed_by = {$who}, updated_at = %s WHERE id = %d",
            $params
        ) !== null;
        if ($written) {
            $this->figuresChanged((int) ($this->find($id)?->vendorUserId ?? 0));
        }
        return $written;
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
