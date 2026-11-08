<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
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

    /**
     * Records the WooCommerce order status the plugin was told about, on every
     * line of that order.
     *
     * Not the line's own status — that is `updateStatus()` and it is a
     * vendor's. This is the ORDER's, mirrored so a detailed report can filter
     * on it without loading an order per row (migration 39).
     *
     * ⛔ **It records what it was told and infers nothing.** A row is `NULL`
     * until WooCommerce announces a change, and `NULL` means «nobody has told
     * us» rather than `pending`. The column is also not a guard: nothing in
     * this plugin decides anything from it, so a mirror that falls behind is a
     * stale FILTER and never a stale obligation — the obligation is the line's
     * own status and the ledger.
     *
     * @return int|null rows touched, or `null` when the write could not be
     *                  made at all — «I do not know» rather than nought, so a
     *                  caller can tell a failure from an order with no
     *                  marketplace lines (`alpha.8`).
     */
    public function recordWcOrderStatus(int $wcOrderId, string $status): ?int;

    /**
     * One page of the per-line report, and how many rows the same filter
     * matches.
     *
     * **Server-side, both halves.** «صفحه‌بندی سمت سرور» and «جست‌وجوی سریع
     * بدون بارگذاری همه سفارش‌ها»: the `LIMIT` is in the statement and the
     * count is a `COUNT(*)` over the same `WHERE`, so a shop with fifty
     * thousand lines costs the same as one with fifty. Nothing in this path
     * loads a WooCommerce order or a product.
     *
     * The count is asked SEPARATELY and FIRST by the caller rather than
     * derived from the page's length, because a short page and a failed read
     * look identical (`alpha.8`) — and a report that read «۰ ردیف» off a
     * broken query would say the shop had sold nothing.
     *
     * @return array{rows:list<array<string,mixed>>, total:int|null}
     *         `total` is `null` when the count could not be taken — «نامعلوم»,
     *         never nought.
     */
    public function detailed(OrderLedgerFilter $filter): array;

    /**
     * What this vendor has earned and owes — all three figures in ONE unit,
     * which the answer names.
     *
     * `currency` and `exponent` travel with the numbers because from
     * `alpha.67` a vendor's lines can be at two exponents of one currency
     * (the order's own, and the finer one an exact commission needs), and a
     * caller that prints `share` without the scale shows ۸۸۰٬۰۰۰ where
     * ۸۸٫۳۷۵ is owed.
     *
     * ⛔ A row that carries NO unit is at the vendor's FIRST-RECORDED one,
     * which lives in `tmc_vendor_money_unit` and which this contract cannot
     * see. So the caller supplies it, and when it does not the answer says
     * `resolved: false` and `exponent: null` rather than reading those rows
     * at «the coarsest exponent some other row happens to name» — a guess
     * that changes with the catalogue and was wrong by ten thousand on a
     * vendor whose only unit-bearing lines were at the finer exponent
     * (`alpha.68` §1). `groups` always holds the per-unit truth.
     *
     * @param int|null $fallbackExponent the vendor's first-recorded exponent
     * @return array{lines:int, base:int, commission:int, share:int,
     *               currency:string, exponent:int|null, resolved:bool,
     *               unit_unreadable:bool,
     *               groups:list<array{exponent:int|null, base:int, commission:int, share:int}>}
     */
    public function totalsForVendor(int $vendorUserId, ?int $fallbackExponent = null): array;

    /**
     * Every line that settlement has to reason about, with the three facts
     * that decide whether its money may be asked for yet.
     *
     * Declared here rather than only on the concrete class because
     * VendorBalance and ManageReturns both depend on this interface and both
     * call it: a method that exists only on the implementation is a contract
     * that a second implementation would silently fail to honour.
     *
     * `currency` and `exponent` are the row's OWN unit, or `null` when it
     * does not carry one — which is every line captured before `alpha.67` and
     * means «the vendor's first-recorded unit», the unit it was written in.
     *
     * `unit_unreadable` is the third answer: «I could not find out whether
     * this row carries a unit at all». It is NOT the same as carrying none,
     * and a reader that resolves it to the vendor's first-recorded unit may
     * be reading an amount at the finer exponent ten thousand times too
     * large (`alpha.68` §1).
     *
     * @return list<array{
     *   id:int, vendor_share_minor:?int, settlement_completed_at:?string,
     *   withdrawal_id:?int, paid:bool, currency:?string, exponent:?int,
     *   unit_unreadable:bool
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
        string $ledgerEvent,
        /**
         * The unit the two figures above are in, ADDED to a row that has
         * none.
         *
         * Written through `COALESCE`, so a row that already declares a unit
         * keeps it: a repair may fill in what was never recorded and may
         * never move a unit, because the row's base and tax are beside it in
         * the old one.
         */
        ?string $currency = null,
        ?int $exponent = null
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
