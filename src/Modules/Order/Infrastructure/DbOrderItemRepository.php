<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Support\SiteTime;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables as Settlement;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderLifecycle;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0039WcOrderStatusOnRows as WcStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as P;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0033MoneyUnitOnRows as U;

/**
 * Order lines on the plugin's own table.
 *
 * The unique key on `wc_order_item_id` is the idempotency: `record()` inserts
 * and ignores a duplicate rather than checking first, so two hooks racing
 * cannot both pass a check and then both write.
 */
final class DbOrderItemRepository implements OrderItemRepositoryInterface
{
    /** @var array<string,string> per-instance answers: present, absent or unknown */
    private array $unitColumns = [];
    /** `null` until asked once: see `hasWcOrderStatusColumn()`. */
    private ?bool $wcStatusColumn = null;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock,
        /**
         * The zone the report's date filters are read in.
         *
         * Optional, and the default is UTC — which is what a build with no
         * setting to read should answer rather than inheriting a guess about
         * where the server is. Bootstrap passes the site's own setting, so a
         * manager filtering «from the 9th» gets the 9th as their calendar has
         * it. Every other query in this class is unaffected: `created_at` is
         * stored in UTC and stays that way.
         */
        private readonly ?SiteTime $time = null
    ) {
    }

    private function time(): SiteTime
    {
        return $this->time ?? SiteTime::utc();
    }

    /**
     * A value made safe for a `LIKE`, exactly as `wpdb::esc_like()` does it.
     *
     * Done here rather than by widening `DatabaseInterface`, because adding a
     * method to that interface breaks every double in the suite and every
     * evidence probe a previous round left behind (`alpha.32`, measured: a new
     * interface method stopped an older probe loading at all and reported
     * sixty-one failures about a build that was fine). WordPress's own
     * implementation is `addcslashes($text, '_%\\')` and this is that line.
     */
    private static function likeValue(string $value): string
    {
        return addcslashes($value, '_%\\');
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
            $item->quantity
        );
        // `NULL` is «there is no whole per-unit price», which `0` cannot say
        // (migration 34). A placeholder would bind nought.
        $unitPrice = $nullable($item->unitPriceMinor);
        if ($item->unitPriceMinor !== null) {
            $params[] = $item->unitPriceMinor;
        }
        array_push($params, $item->baseMinor, $item->taxMinor);
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
        array_push($params, $item->rateSource, $item->ledgerEvent, $item->status->value);
        // THE UNIT THE FIVE MONEY COLUMNS ARE IN, written in THIS insert.
        //
        // A parameter of the row rather than a follow-up write, for the
        // reason `alpha.42` settled: a row that exists without its unit for
        // even one request is a row whose amounts the next reader has to
        // guess at. `NULL` when the caller does not say — which is what every
        // writer before `alpha.67` effectively said, and which resolves to
        // the vendor's first-recorded unit.
        $currency = $item->currency === null || trim($item->currency) === '' ? 'NULL' : '%s';
        if ($currency !== 'NULL') {
            $params[] = strtoupper(trim((string) $item->currency));
        }
        $exponent = $nullable($item->exponent);
        if ($item->exponent !== null) {
            $params[] = $item->exponent;
        }
        array_push($params, $now, $now);

        // ⛔ A UNIT THAT CANNOT BE STORED IS A REFUSAL, not a column left out.
        // Between a file swap and the upgrade gate running (`alpha.46`) these
        // columns may be absent, and the amounts this release computes are at
        // four more decimal places: written into a row that cannot say so,
        // ۳۷٫۸۷۵ becomes a figure that reads as ۳۷۸٬۷۵۰ the moment the column
        // arrives. So nothing is written and the caller hears why — the
        // `alpha.49` rule, one table along: «نمی‌دانم» is an answer.
        // PRESENT is the only state a unit may be stored in. `ABSENT` and
        // `UNKNOWN` both refuse a unit-bearing row — one because the column
        // is not there, the other because we could not find out, and a
        // figure at the finer exponent in a row that cannot say so reads
        // correctly today and wrong the moment the column arrives.
        $carried = $this->unitColumnsOn($this->table()) === U::PRESENT;
        if (!$carried && ($item->currency !== null || $item->exponent !== null)) {
            return 0;
        }
        $written = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (wc_order_id, wc_order_item_id, wc_product_id, product_id, variation_id, vendor_user_id,
              title, sku, quantity, unit_price_minor, base_minor, tax_minor,
              commission_minor, vendor_share_minor, rate_bp, rate_source, ledger_event, status'
             . ($carried ? ', `' . U::CURRENCY . '`, `' . U::EXPONENT . '`' : '') . ',
              created_at, updated_at)
             VALUES (%d, %d, %d, %d, ' . $variation . ', %d, %s, %s, %d, ' . $unitPrice . ', %d, %d, '
             . $commission . ', ' . $share . ', ' . $rate . ', %s, %s, %s'
             . ($carried ? ', ' . $currency . ', ' . $exponent : '') . ', %s, %s)',
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
        string $ledgerEvent,
        ?string $currency = null,
        ?int $exponent = null
    ): bool {
        if ($id <= 0 || trim($ledgerEvent) === '') {
            return false;
        }
        $rate = $rateBasisPoints === null ? 'NULL' : '%d';
        $params = [$commissionMinor, $vendorShareMinor];
        if ($rateBasisPoints !== null) {
            $params[] = $rateBasisPoints;
        }
        // THE UNIT, written with the figures it belongs to. Left alone when
        // the caller does not say, because `COALESCE` keeps whatever the row
        // already holds: a repair is allowed to ADD the unit to a row that
        // never had one, and never to move a unit a row already declares —
        // that would reinterpret the four amounts beside it.
        $unitCurrency = $currency === null || trim($currency) === '' ? 'NULL' : '%s';
        if ($unitCurrency !== 'NULL') {
            $params[] = strtoupper(trim($currency));
        }
        $unitExponent = $exponent === null ? 'NULL' : '%d';
        if ($exponent !== null) {
            $params[] = $exponent;
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
        // The same refusal as `record()`, for the same reason, and the same
        // three states.
        $carried = $this->unitColumnsOn($this->table()) === U::PRESENT;
        if (!$carried && ($currency !== null || $exponent !== null)) {
            return false;
        }
        $unitSet = $carried
            ? '`' . U::CURRENCY . '` = COALESCE(`' . U::CURRENCY . "`, {$unitCurrency}),
             `" . U::EXPONENT . "` = COALESCE(`" . U::EXPONENT . "`, {$unitExponent}),"
            : '';
        $rows = $this->db->execute(
            'UPDATE `' . $this->table() . "` SET commission_minor = %d, vendor_share_minor = %d,
             rate_bp = {$rate},
             {$unitSet}
             rate_source = %s, ledger_event = %s, updated_at = %s
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

    /**
     * The mirrored status of the order this LINE belongs to, or null.
     *
     * One row, by the line's own id, so a caller holding an item never has to
     * learn the order id to ask. `null` carries three cases that are all «I
     * could not find out» and none of which may be read as a status: the
     * column is not there yet, nobody has announced one, or the read failed.
     *
     * Used by the settlement-completion guard as the SECOND source, after the
     * live reader. It is not a guard on its own and must not become one: the
     * capture does not write this column, so a paid order whose status never
     * changed after checkout carries nothing here.
     */
    public function wcOrderStatusOf(int $itemId): ?string
    {
        if (!$this->hasWcOrderStatusColumn()) {
            return null;
        }
        $row = $this->db->getRow(
            'SELECT `' . WcStatus::COLUMN . '` AS wc_order_status
             FROM `' . $this->table() . '` WHERE id = %d',
            [$itemId]
        );
        if ($row === null || ($row['wc_order_status'] ?? null) === null) {
            return null;
        }
        $status = trim((string) $row['wc_order_status']);
        return $status === '' ? null : $status;
    }

    public function recordWcOrderStatus(int $wcOrderId, string $status): ?int
    {
        // The column arrived in migration 39, so a build whose structure has
        // not caught up must not have its write reported as a success — and
        // must not throw either, because this runs on a customer's checkout.
        // `null` says «I could not», which is the one answer that is true in
        // both cases.
        if (!$this->hasWcOrderStatusColumn()) {
            return null;
        }
        // Trimmed to the column's width rather than refused: a status longer
        // than thirty-two characters is a plugin's own slug and the useful part
        // is that it is not one of ours. `WcOrderLifecycle` reads an unknown
        // slug as `Unknown` whether it was cut or not.
        $written = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET `' . WcStatus::COLUMN . '` = %s WHERE wc_order_id = %d',
            [substr(trim($status), 0, 32), $wcOrderId]
        );
        if ($written === null) {
            return null;
        }
        // Deliberately NOT `updated_at` and deliberately NOT a report
        // invalidation. This column is a filter and never a figure: bumping
        // the row's timestamp would make a mirror refresh look like an edit to
        // the line, and busting the report cache on it would throw a shop's
        // cached totals away for something none of them are computed from.
        return $written;
    }

    public function detailed(OrderLedgerFilter $filter): array
    {
        $carried = $this->unitColumnsOn($this->table()) === U::PRESENT;
        $mirror = $this->hasWcOrderStatusColumn();
        [$where, $params] = $this->detailedWhere($filter, $mirror);

        // COUNTED FIRST AND SEPARATELY. A page shorter than its limit and a
        // failed read are the same array, so the total cannot be derived from
        // the rows — and a report whose total came back nought off a broken
        // query would tell a manager the shop had sold nothing (`alpha.8`).
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` ' . $where,
            $params
        );
        $total = $counted === null ? null : (int) $counted;

        $rows = $this->db->getResults(
            'SELECT id, wc_order_id, wc_order_item_id, wc_product_id, variation_id, product_id,
                    vendor_user_id, title, sku, quantity, unit_price_minor, base_minor, tax_minor,
                    commission_minor, vendor_share_minor, rate_bp, rate_source, ledger_event,
                    status, carrier, tracking_code, shipped_at, created_at,
                    settlement_completed_at, withdrawal_id,
                    ' . ($mirror ? '`' . WcStatus::COLUMN . '` AS wc_order_status,' : 'NULL AS wc_order_status,') . '
                    ' . ($carried
                        ? '`' . U::CURRENCY . '` AS tmc_currency, `' . U::EXPONENT . '` AS tmc_exponent'
                        : 'NULL AS tmc_currency, NULL AS tmc_exponent') . '
             FROM `' . $this->table() . '` ' . $where . ' ORDER BY ' . self::orderBy($filter->sort) . '
             LIMIT %d OFFSET %d',
            [...$params, $filter->perPage, $filter->offset()]
        );

        return [
            'rows' => array_map(static function (array $row) use ($mirror): array {
                return [
                    'id' => (int) $row['id'],
                    'wc_order_id' => (int) $row['wc_order_id'],
                    'wc_order_item_id' => (int) $row['wc_order_item_id'],
                    'wc_product_id' => (int) $row['wc_product_id'],
                    'variation_id' => ($row['variation_id'] ?? null) === null ? null : (int) $row['variation_id'],
                    'product_id' => (int) $row['product_id'],
                    'vendor_user_id' => (int) $row['vendor_user_id'],
                    'title' => (string) $row['title'],
                    'sku' => (string) $row['sku'],
                    'quantity' => (int) $row['quantity'],
                    // Every money column stays `null` when the row holds
                    // `null`: an unresolvable rate records NOTHING rather than
                    // zero, and a report that cast it would print «۰ تومان
                    // commission» where the truth is «nobody worked it out»
                    // (FIN-02).
                    'unit_price_minor' => ($row['unit_price_minor'] ?? null) === null ? null : (int) $row['unit_price_minor'],
                    'base_minor' => (int) $row['base_minor'],
                    'tax_minor' => (int) $row['tax_minor'],
                    'commission_minor' => ($row['commission_minor'] ?? null) === null ? null : (int) $row['commission_minor'],
                    'vendor_share_minor' => ($row['vendor_share_minor'] ?? null) === null ? null : (int) $row['vendor_share_minor'],
                    'rate_bp' => ($row['rate_bp'] ?? null) === null ? null : (int) $row['rate_bp'],
                    'rate_source' => (string) $row['rate_source'],
                    'ledger_event' => (string) $row['ledger_event'],
                    'status' => (string) $row['status'],
                    'carrier' => (string) $row['carrier'],
                    'tracking_code' => (string) $row['tracking_code'],
                    'shipped_at' => ($row['shipped_at'] ?? null) === null ? null : (string) $row['shipped_at'],
                    'created_at' => (string) $row['created_at'],
                    'settlement_completed_at' => ($row['settlement_completed_at'] ?? null) === null
                        ? null
                        : (string) $row['settlement_completed_at'],
                    'withdrawal_id' => ($row['withdrawal_id'] ?? null) === null ? null : (int) $row['withdrawal_id'],
                    // ⛔ THREE ANSWERS, not two. Without the mirror column the
                    // honest answer is «I cannot tell», and `false` here would
                    // be read as «this order has no status» — which no order
                    // has. The reader turns it into «نامعلوم».
                    'wc_order_status' => !$mirror || ($row['wc_order_status'] ?? null) === null
                        ? null
                        : (string) $row['wc_order_status'],
                    'wc_status_readable' => $mirror,
                    'currency' => trim((string) ($row['tmc_currency'] ?? '')) === ''
                        ? null
                        : strtoupper(trim((string) $row['tmc_currency'])),
                    'exponent' => ($row['tmc_exponent'] ?? null) === null ? null : (int) $row['tmc_exponent'],
                ];
            }, $rows),
            'total' => $total,
        ];
    }

    /**
     * The whole filtered set, in figures — one query, no `LIMIT`.
     *
     * ⛔ **WHY THIS IS NOT A SECOND DEFINITION OF THE THREE TOTALS.** The card
     * above the table sums the PAGE in PHP, because a vendor's lines can sit
     * at two exponents of one currency and a bare `SUM()` across those is
     * wrong by a factor of ten thousand (`alpha.67`). This method answers
     * about the whole set instead, and it does it by refusing to combine:
     * `GROUP BY` currency and exponent, and the caller is handed the groups.
     * With ONE group — every real catalogue — there is no rescaling to get
     * wrong, so the figures are exact; with two, nothing is added up and the
     * page says so. The two readers therefore cannot disagree about a number,
     * because in the only case both answer, both are summing identical rows
     * at one scale (`alpha.87`: a list and its counter ask one question).
     *
     * The three figures are the SAME predicates `OrderLedgerReport::describe()`
     * derives, written in SQL: «historic» is every line's share, «valid» drops
     * the cancelled ones, and «pending» is `settlement_state` =
     * `awaiting_completion` — not cancelled, not reserved against a
     * withdrawal, not yet marked complete.
     *
     * ⛔ **`SUM()` SKIPS `NULL`, so the unrecorded are counted by name.** A
     * line whose rate could not be resolved holds `NULL` and would silently
     * read as «nothing was due» (FIN-02). `unrecorded` is that count, and a
     * page that prints a total beside a non-zero `unrecorded` is telling the
     * truth about both.
     *
     * ⛔ **Read as text, not cast.** `SUM()` is a `DECIMAL` wider than a PHP
     * integer and `(int)` SATURATES rather than failing, so a sum past the
     * range would answer `PHP_INT_MAX` and look like a figure. It goes
     * through `DecimalAmount` and a value that will not fit answers `null`
     * (`alpha.68` §2).
     *
     * @return array{readable:bool, groups:list<array<string,mixed>>}
     */
    public function detailedTotals(OrderLedgerFilter $filter): array
    {
        $carried = $this->unitColumnsOn($this->table()) === U::PRESENT;
        $mirror = $this->hasWcOrderStatusColumn();
        [$where, $params] = $this->detailedWhere($filter, $mirror);
        $cancelled = OrderItemStatus::Cancelled->value;

        $rows = $this->db->getResults(
            'SELECT ' . ($carried
                ? '`' . U::CURRENCY . '` AS tmc_currency, `' . U::EXPONENT . '` AS tmc_exponent,'
                : 'NULL AS tmc_currency, NULL AS tmc_exponent,') . '
                    COUNT(*) AS lines_count,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN vendor_share_minor IS NULL THEN 1 ELSE 0 END) AS unrecorded_count,
                    SUM(CASE WHEN ledger_event = %s THEN 1 ELSE 0 END) AS no_event_count,
                    CAST(COALESCE(SUM(base_minor), 0) AS CHAR) AS base_sum,
                    CAST(COALESCE(SUM(tax_minor), 0) AS CHAR) AS tax_sum,
                    CAST(COALESCE(SUM(commission_minor), 0) AS CHAR) AS commission_sum,
                    CAST(COALESCE(SUM(vendor_share_minor), 0) AS CHAR) AS historic_sum,
                    CAST(COALESCE(SUM(CASE WHEN status <> %s THEN vendor_share_minor ELSE 0 END), 0) AS CHAR)
                        AS valid_sum,
                    CAST(COALESCE(SUM(CASE WHEN status <> %s AND withdrawal_id IS NULL
                                            AND settlement_completed_at IS NULL
                                           THEN vendor_share_minor ELSE 0 END), 0) AS CHAR)
                        AS pending_sum
             FROM `' . $this->table() . '` ' . $where
             . ($carried ? ' GROUP BY `' . U::CURRENCY . '`, `' . U::EXPONENT . '`' : ''),
            // ⛔ THE SELECT'S PLACEHOLDERS COME FIRST, because `prepare()`
            // substitutes in order of APPEARANCE and the four `%s` above are
            // in the `SELECT`, before the `WHERE`. With the filter's params
            // first, an unfiltered query happened to be right and every
            // narrowed one silently compared `status` against a product id —
            // so the figures came back empty for exactly the reader who had
            // asked a question. Found by the test, not by reading.
            [$cancelled, '', $cancelled, $cancelled, ...$params]
        );

        // ⛔ A FAILED READ IS NOT AN EMPTY SET. `getResults()` answers `[]`
        // for both, and a report that printed nought off a broken query would
        // tell a manager the shop had sold nothing (`alpha.8`). The count is
        // asked separately, and `null` from it is what says «could not read».
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` ' . $where,
            $params
        );
        if ($counted === null) {
            return ['readable' => false, 'groups' => []];
        }

        // The sums come back at the group's OWN scale, so nothing is
        // converted here: the only question `DecimalAmount` is asked is
        // whether the integer fits, and `null` is the answer when it does not.
        $money = static function (array $row, string $key): ?int {
            $raw = trim((string) ($row[$key] ?? ''));
            return $raw === '' ? 0 : DecimalAmount::toMinor($raw, 0);
        };

        $groups = [];
        foreach ($rows as $row) {
            $exponent = ($row['tmc_exponent'] ?? null) === null ? null : (int) $row['tmc_exponent'];
            $currency = trim((string) ($row['tmc_currency'] ?? ''));
            $groups[] = [
                'currency' => $currency === '' ? null : strtoupper($currency),
                'exponent' => $exponent,
                'lines' => (int) $row['lines_count'],
                'cancelled' => (int) $row['cancelled_count'],
                'unrecorded' => (int) $row['unrecorded_count'],
                'unrecorded_event' => (int) $row['no_event_count'],
                'base' => $money($row, 'base_sum'),
                'tax' => $money($row, 'tax_sum'),
                'commission' => $money($row, 'commission_sum'),
                'historic' => $money($row, 'historic_sum'),
                'valid' => $money($row, 'valid_sum'),
                'pending' => $money($row, 'pending_sum'),
            ];
        }
        return ['readable' => true, 'groups' => $groups];
    }

    /**
     * The `WHERE` both halves of `detailed()` use — one clause list, one
     * parameter list.
     *
     * Built once and used by the count AND the page, because two spellings of
     * one filter are two filters that agree until somebody edits one
     * (`alpha.87`: a list and its own counter must ask the same question).
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function detailedWhere(OrderLedgerFilter $filter, bool $mirror): array
    {
        $clauses = [];
        $params = [];
        foreach ([
            'vendor_user_id' => $filter->vendorUserId,
            'wc_order_id' => $filter->wcOrderId,
            'wc_product_id' => $filter->wcProductId,
            'variation_id' => $filter->variationId,
        ] as $column => $value) {
            if ($value !== null) {
                $clauses[] = '`' . $column . '` = %d';
                $params[] = $value;
            }
        }
        if ($filter->title !== '') {
            $clauses[] = 'title LIKE %s';
            $params[] = '%' . self::likeValue($filter->title) . '%';
        }
        if ($filter->sku !== '') {
            $clauses[] = 'sku LIKE %s';
            $params[] = '%' . self::likeValue($filter->sku) . '%';
        }
        if ($filter->shipping !== null) {
            $clauses[] = 'status = %s';
            $params[] = $filter->shipping->value;
        }
        if ($filter->minAmountMinor !== null) {
            $clauses[] = 'base_minor >= %d';
            $params[] = $filter->minAmountMinor;
        }
        if ($filter->maxAmountMinor !== null) {
            $clauses[] = 'base_minor <= %d';
            $params[] = $filter->maxAmountMinor;
        }
        $from = $this->time()->dayStartUtc($filter->fromLocalDate);
        if ($from !== null) {
            $clauses[] = 'created_at >= %s';
            $params[] = $from;
        }
        $to = $this->time()->dayEndUtc($filter->toLocalDate);
        if ($to !== null) {
            $clauses[] = 'created_at <= %s';
            $params[] = $to;
        }
        // THE COMMISSION SHAPE, derived from the two columns that hold it.
        // `RecordCommission` writes `rate_bp` at the percentage's basis points
        // and `0` for a fixed rule, so «percent» and «fixed» are a reading
        // rather than a fifth column — and «unrecorded» is the FIN-02 state
        // where nothing was worked out at all.
        $clauses = array_merge($clauses, match ($filter->commissionKind) {
            'percent' => ['commission_minor IS NOT NULL AND rate_bp > 0'],
            'fixed' => ['commission_minor IS NOT NULL AND COALESCE(rate_bp, 0) = 0 AND commission_minor > 0'],
            'none' => ['commission_minor = 0'],
            'unrecorded' => ['commission_minor IS NULL'],
            default => [],
        });
        if ($filter->payment !== '' && $mirror) {
            // The slugs each state covers come from `WcOrderLifecycle`, which
            // is the one table that says what a WooCommerce status means — so
            // the filter and the column a row prints cannot disagree.
            $slugs = match ($filter->payment) {
                'paid' => WcOrderLifecycle::PAID,
                'unpaid' => array_merge(WcOrderLifecycle::AWAITING_PAYMENT, WcOrderLifecycle::PAYMENT_FAILED),
                'void' => WcOrderLifecycle::VOID,
                'refunded' => WcOrderLifecycle::REFUNDED,
                default => [],
            };
            if ($filter->payment === 'unknown') {
                // «Nobody has told us» is a row with no mirrored value, and a
                // slug this release does not map is the same question from the
                // other side: both are «I cannot say what this order is».
                $known = WcOrderLifecycle::known();
                $clauses[] = '(`' . WcStatus::COLUMN . '` IS NULL OR `' . WcStatus::COLUMN . '` NOT IN ('
                    . implode(',', array_fill(0, count($known), '%s')) . '))';
                $params = [...$params, ...$known];
            } elseif ($slugs !== []) {
                $clauses[] = '`' . WcStatus::COLUMN . '` IN ('
                    . implode(',', array_fill(0, count($slugs), '%s')) . ')';
                $params = [...$params, ...$slugs];
            }
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * The sort, from a fixed list — never from the request.
     *
     * Every one of these ends in `id`, because `created_at` is a `DATETIME`
     * with second precision and thirty rows in one second put a page boundary
     * somewhere different on every request (`alpha.32`'s measurement).
     */
    private static function orderBy(string $sort): string
    {
        return match ($sort) {
            'oldest' => 'created_at ASC, id ASC',
            'amount_high' => 'base_minor DESC, id DESC',
            'amount_low' => 'base_minor ASC, id ASC',
            'order' => 'wc_order_id DESC, id DESC',
            'share_high' => 'vendor_share_minor DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };
    }

    /**
     * Whether the status mirror exists yet.
     *
     * Memoised per INSTANCE rather than statically, for the reason
     * `ProductTableShape` is: a static memo outlives a test that changes the
     * table's shape and answers the stale question (`alpha.67`).
     */
    private function hasWcOrderStatusColumn(): bool
    {
        if ($this->wcStatusColumn === null) {
            $count = $this->db->getVar(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                [$this->table(), WcStatus::COLUMN]
            );
            // A read that failed answers `null`, and the conservative reading
            // of that is «no column»: the alternative is an `UPDATE` naming a
            // column that may not be there, on a checkout request.
            $this->wcStatusColumn = $count !== null && ((int) $count) > 0;
        }
        return $this->wcStatusColumn;
    }

    /**
     * The vendor's totals — summed PER UNIT and then combined exactly.
     *
     * ⛔ A single `SUM()` over these columns was right only while every line
     * of a vendor was at one exponent, which `MoneyUnitClaim` used to force.
     * From `alpha.67` a commission is recorded at four more decimal places
     * than the order's own unit, so a vendor who sold before the upgrade and
     * after it has lines at two exponents and one `SUM` would add ۸۸ to
     * ۸۸۰٬۰۰۰ as though they were the same number.
     *
     * So the sums are grouped by the row's own exponent and combined in PHP
     * by multiplying each group into the finest one — a power of ten, exact,
     * no `float` and no `POW()` (which returns a float in MySQL and is the
     * same mistake one layer down). The unit the answer is in comes back
     * with it.
     *
     * ⛔ **AND A ROW THAT SAYS NOTHING IS NOT AT «THE COARSEST ROW».**
     * `alpha.67` read the `NULL` group at the coarsest exponent any OTHER
     * row happened to name, which is a guess that changes with the
     * catalogue: on a vendor whose only unit-bearing rows were at exponent 4
     * it read every legacy row at exponent 4 too, and ۴۵۰٬۰۰۰ تومان became
     * ۴۵. What a unit-less row is actually in is the vendor's FIRST recorded
     * unit, and that lives in `tmc_vendor_money_unit`, which this repository
     * cannot see. So the caller passes it, and when it does not, the answer
     * SAYS it could not be resolved rather than inventing a scale
     * (`alpha.49`: «نمی‌دانم» is an answer).
     *
     * @param int|null $fallbackExponent the vendor's first-recorded exponent,
     *        which is what a row carrying no unit was written in. `null` is
     *        «not supplied», and the combination is then only possible when
     *        every row agrees on one exponent anyway.
     * @return array{
     *   lines:int, base:int, commission:int, share:int, currency:string,
     *   exponent:int|null, resolved:bool, unit_unreadable:bool,
     *   groups:list<array{exponent:int|null, base:int, commission:int, share:int}>
     * } `base`, `commission` and `share` are the combined figures and mean
     * something only while `resolved` is true; `groups` always holds the
     * per-unit truth.
     */
    public function totalsForVendor(int $vendorUserId, ?int $fallbackExponent = null): array
    {
        // ⛔ ASKED OF THE TABLE FIRST, in three states. Between a file swap
        // and the upgrade gate running, this release's code is on disk and
        // migration 33's columns are not — and naming them then is a hard SQL
        // error on a page a vendor opens. `ABSENT` is that window, and there
        // the whole catalogue is one unit, which is what every release up to
        // `alpha.66` assumed, so it is the previous correct answer rather
        // than a degradation. `UNKNOWN` is «could not find out», and it is
        // reported instead of being read as `ABSENT`: the columns may be
        // there, holding exponent 4, and substituting `NULL` for them turns
        // ۸۸٫۳۷۵ into ۸۸۳٬۷۵۰ (`alpha.68` §1).
        $state = $this->unitColumnsOn($this->table());
        $carried = $state === U::PRESENT;
        $unreadable = $state === U::UNKNOWN;
        $rows = $this->db->getResults(
            'SELECT ' . ($carried
                ? '`' . U::EXPONENT . '` AS tmc_exponent, MIN(`' . U::CURRENCY . '`) AS tmc_currency,'
                : 'NULL AS tmc_exponent, NULL AS tmc_currency,') . '
                    COUNT(*) AS lines_count,
                    COALESCE(SUM(base_minor), 0) AS base,
                    COALESCE(SUM(commission_minor), 0) AS commission,
                    COALESCE(SUM(vendor_share_minor), 0) AS share
             FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND status <> %s'
             . ($carried ? ' GROUP BY `' . U::EXPONENT . '`' : ''),
            [$vendorUserId, OrderItemStatus::Cancelled->value]
        );
        $groups = [];
        $currency = '';
        $lines = 0;
        $stated = [];           // the exponents rows actually name
        $hasUnitless = false;
        foreach ($rows as $row) {
            $exponent = ($row['tmc_exponent'] ?? null) === null ? null : (int) $row['tmc_exponent'];
            $lines += (int) $row['lines_count'];
            if ($currency === '' && trim((string) ($row['tmc_currency'] ?? '')) !== '') {
                $currency = strtoupper(trim((string) $row['tmc_currency']));
            }
            if ($exponent === null) {
                $hasUnitless = true;
            } else {
                $stated[$exponent] = true;
            }
            $groups[] = [
                'exponent' => $exponent,
                'base' => (int) $row['base'],
                'commission' => (int) $row['commission'],
                'share' => (int) $row['share'],
            ];
        }

        // WHAT A UNIT-LESS ROW IS IN. The caller's registry figure when it
        // supplied one; otherwise the single stated exponent, which can only
        // be the same number because nothing else names one; otherwise
        // nothing, and the answer says so.
        $nullExponent = $fallbackExponent;
        if ($nullExponent === null && $stated === []) {
            // Nothing in this catalogue names a unit, so there is one unit
            // and `0` is what every release up to `alpha.66` read it at.
            $nullExponent = 0;
        }
        $resolved = !$unreadable && ($nullExponent !== null || !$hasUnitless);
        $unitExponent = null;
        if ($resolved) {
            $candidates = array_keys($stated);
            if ($hasUnitless && $nullExponent !== null) {
                $candidates[] = $nullExponent;
            }
            $unitExponent = $candidates === [] ? 0 : max($candidates);
        }

        $totals = ['base' => 0, 'commission' => 0, 'share' => 0];
        if ($resolved && $unitExponent !== null) {
            foreach ($groups as $group) {
                $from = $group['exponent'] ?? $nullExponent ?? $unitExponent;
                $factor = 10 ** max(0, $unitExponent - $from);
                foreach (['base', 'commission', 'share'] as $key) {
                    // ⛔ ASKED BEFORE THE MULTIPLICATION, never after: an
                    // `int` that overflows becomes a FLOAT in PHP, and a
                    // float here would answer a figure that looks like a
                    // total and is not. Unresolvable rather than wrong.
                    if ($group[$key] !== 0
                        && intdiv(PHP_INT_MAX, abs($group[$key])) < $factor) {
                        $resolved = false;
                        $unitExponent = null;
                        $totals = ['base' => 0, 'commission' => 0, 'share' => 0];
                        break 2;
                    }
                    $totals[$key] += $group[$key] * $factor;
                }
            }
        }
        return [
            'lines' => $lines,
            'base' => $totals['base'],
            'commission' => $totals['commission'],
            'share' => $totals['share'],
            // THE UNIT the three figures above are in, so nothing downstream
            // has to infer the scale of a number it is printing. `null` when
            // the answer could not be taken in one unit at all, which is a
            // sentence a page can print and a number is not.
            'currency' => $currency,
            'exponent' => $unitExponent,
            'resolved' => $resolved,
            'unit_unreadable' => $unreadable,
            'groups' => $groups,
        ];
    }

    public function settlementView(int $vendorUserId): array
    {
        // The same question as `totalsForVendor()`, for the same window and
        // in the same three states. `UNKNOWN` travels ON EVERY ROW rather
        // than being swallowed, because this list is where `VendorBalance`
        // takes every figure from and a row reported as «no unit» would be
        // resolved to the vendor's historical exponent — the ten-thousandfold
        // mistake the owner's §1 names.
        $state = $this->unitColumnsOn($this->table());
        $carried = $state === U::PRESENT;
        $unreadable = $state === U::UNKNOWN;
        $rows = $this->db->getResults(
            'SELECT i.id, i.vendor_share_minor, i.settlement_completed_at, i.withdrawal_id,
                    ' . ($carried
                        ? 'i.`' . U::CURRENCY . '` AS tmc_currency, i.`' . U::EXPONENT . '` AS tmc_exponent,'
                        : 'NULL AS tmc_currency, NULL AS tmc_exponent,') . '
                    w.status AS withdrawal_status
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
                // THE UNIT this line's share is in. `null` is «this row does
                // not say», which `VendorBalance` resolves to the vendor's
                // first-recorded unit — what the row was written in.
                'currency' => trim((string) ($row['tmc_currency'] ?? '')) === ''
                    ? null
                    : strtoupper(trim((string) $row['tmc_currency'])),
                'exponent' => ($row['tmc_exponent'] ?? null) === null ? null : (int) $row['tmc_exponent'],
                // «I could not find out whether this row carries a unit» —
                // which is not «it carries none». A reader that resolves the
                // second one is right; a reader that resolves the first is
                // guessing with somebody's money.
                'unit_unreadable' => $unreadable,
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
            // `array_key_exists` rather than `??`: a column that is NULL and
            // a query that did not select it are both «this row does not say»
            // (`alpha.60`), and 0 is a price rather than an absence.
            array_key_exists('unit_price_minor', $row) && $row['unit_price_minor'] !== null
                ? (int) $row['unit_price_minor']
                : null,
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
            (string) $row['created_at'],
            // `array_key_exists` rather than `??`: a query that did not
            // SELECT the column and a row whose column is NULL are the same
            // answer here — «this row does not say» — and both have to read
            // as null rather than as an empty unit (`alpha.60`).
            array_key_exists(U::CURRENCY, $row) && trim((string) ($row[U::CURRENCY] ?? '')) !== ''
                ? strtoupper(trim((string) $row[U::CURRENCY]))
                : null,
            array_key_exists(U::EXPONENT, $row) && $row[U::EXPONENT] !== null
                ? (int) $row[U::EXPONENT]
                : null
        );
    }

    /**
     * A window of recorded lines, with only the columns reconciliation needs.
     *
     * Keyset by `id` rather than `OFFSET`, so a window is stable while rows
     * are being written — the same reason every cursor in this plugin is a
     * keyset (`alpha.32`).
     *
     * ⛔ **COUNTED SEPARATELY.** `getResults()` answers `[]` both for «no
     * more rows» and for «the statement failed», and a reconciliation that
     * read a failure as «nothing left to check» would report a clean bill
     * off a broken query — the one direction this control must never be
     * wrong in (`alpha.8`, `alpha.97`). `readable` is `false` then.
     *
     * @return array{readable:bool, rows:list<array<string,mixed>>}
     */
    public function linesToReconcile(int $afterId, int $limit, ?int $vendorUserId = null): array
    {
        $where = 'WHERE id > %d';
        $params = [$afterId];
        if ($vendorUserId !== null) {
            $where .= ' AND vendor_user_id = %d';
            $params[] = $vendorUserId;
        }
        $counted = $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table() . '` ' . $where,
            $params
        );
        if ($counted === null) {
            return ['readable' => false, 'rows' => []];
        }
        $rows = $this->db->getResults(
            'SELECT id, wc_order_id, wc_order_item_id, product_id, vendor_user_id,
                    status, ledger_event, vendor_share_minor, commission_minor, title
             FROM `' . $this->table() . '` ' . $where . ' ORDER BY id ASC LIMIT %d',
            [...$params, max(1, $limit)]
        );
        return [
            'readable' => true,
            'rows' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'wc_order_id' => (int) $row['wc_order_id'],
                'wc_order_item_id' => (int) $row['wc_order_item_id'],
                'product_id' => (int) $row['product_id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'status' => (string) $row['status'],
                'ledger_event' => (string) $row['ledger_event'],
                'share_recorded' => ($row['vendor_share_minor'] ?? null) !== null,
                'commission_recorded' => ($row['commission_minor'] ?? null) !== null,
                'title' => (string) $row['title'],
            ], $rows),
        ];
    }

    /**
     * Which of these accrual keys more than one line claims — one query.
     *
     * A financial document belongs to exactly one line. Two lines naming one
     * key means one sale was counted twice, which is the shape `alpha.98`
     * closed on the Dokan side and the shape a duplicated callback would make
     * here.
     *
     * @param list<string> $eventKeys
     * @return list<string>
     */
    public function eventsOnSeveralLines(array $eventKeys): array
    {
        $keys = [];
        foreach ($eventKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        if ($keys === []) {
            return [];
        }
        $keys = array_keys($keys);
        $rows = $this->db->getResults(
            'SELECT ledger_event FROM `' . $this->table() . '` WHERE ledger_event IN ('
            . implode(',', array_fill(0, count($keys), '%s'))
            . ') GROUP BY ledger_event HAVING COUNT(*) > 1',
            $keys
        );
        return array_map(static fn (array $row): string => (string) $row['ledger_event'], $rows);
    }

    /**
     * Which of these lines name a vendor the product row does not — one query.
     *
     * The line carries `vendor_user_id` as a snapshot of who sold it, and the
     * product row carries who owns it. They are allowed to be read separately
     * and must not be allowed to differ silently: a share paid to the wrong
     * shop is the worst outcome in this module, and it looks like nothing.
     *
     * A line whose product row has GONE is not reported here — that is a
     * different fact, with a different answer, and inventing «mismatch» for
     * it would send a manager looking for a shop that is not the problem.
     *
     * @param list<int> $lineIds
     * @return list<int>
     */
    public function vendorMismatchedLines(array $lineIds): array
    {
        $ids = [];
        foreach ($lineIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_keys($ids);
        $products = P::table($this->db, P::PRODUCTS);
        $rows = $this->db->getResults(
            'SELECT i.id FROM `' . $this->table() . '` i
              INNER JOIN `' . $products . '` p ON p.id = i.product_id
              WHERE i.id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')
                AND p.vendor_user_id <> i.vendor_user_id',
            $ids
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
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
    /**
     * WHICH OF THE THREE STATES a table's unit columns are in — asked once
     * per INSTANCE, which is once per request in practice.
     *
     * The memo lives here rather than in `M0033::unitColumnState()` for the
     * reason `ProductTableShape` keeps its own: a process-wide cache is
     * right for a request and wrong for anything that changes the table and
     * asks again.
     *
     * Three answers, because two of them made a wrong figure look like a
     * fact (`alpha.68` §1): `PRESENT` names the columns, `ABSENT` leaves
     * them out and is the truth about the upgrade window, and `UNKNOWN`
     * leaves them out AND says so, so that nothing downstream resolves a
     * unit it could not read.
     */
    private function unitColumnsOn(string $table): string
    {
        if (!array_key_exists($table, $this->unitColumns)) {
            $this->unitColumns[$table] = U::unitColumnState($this->db, $table);
        }
        return $this->unitColumns[$table];
    }

}
