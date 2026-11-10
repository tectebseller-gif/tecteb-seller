<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * A WooCommerce order carrying a marketplace line that nothing could record.
 *
 * ⛔ **WHY THIS EXISTS, and what it is honest about.** The owner's §2-A asks
 * for proof that no order with transferred items can pass through WooCommerce
 * while the order module is blocked and «become a normal sale» with no
 * marketplace record. Reading the source gives a split answer, and both halves
 * have to be said:
 *
 *   - **Every door a SHOPPER uses is already refused.** `PurchasePolicy`
 *     answers `ORDERS_BLOCKED` the moment the gate is not ready, and
 *     `PurchaseGuard` + `CartGuard` register regardless of the order module's
 *     own state — deliberately, with that reason written above the call. The
 *     product page, the session basket, the classic checkout, both Store API
 *     validators and the pay link of an unpaid order all refuse.
 *   - **One door is not a shopper's.** `woocommerce_new_order` is hooked ONLY
 *     inside `OrderHooks`, which never registers while the module is blocked.
 *     So an order built in wp-admin, or by any other plugin calling
 *     `wc_create_order()`, is created with a marketplace line, no
 *     `tmc_order_items` row, no audit entry and no refusal. Measured by
 *     reading the two files, not inferred.
 *
 * **This class does not claim to close that door, because it cannot.** All
 * three creation hooks fire AFTER the order row exists; throwing there would
 * leave an order somebody may already have paid for in a state nobody
 * designed. A guard that runs after the write it is meant to prevent is not a
 * guard (`alpha.50`), and naming it one would be worse than the gap.
 *
 * What it does claim is the property the owner actually asked for in the same
 * sentence — «قابل شناسایی، مهار و تطبیق» — and it is a different claim:
 *
 *   IDENTIFIABLE  the order carries a meta mark naming the reason, forever,
 *                 and one audit row names it with its vendors. Two permanent
 *                 records, neither of which a cleared option can take away.
 *   CONTAINED     no `tmc_order_items` row exists, and settlement is built
 *                 entirely on those rows — so the money path cannot reach it
 *                 by construction rather than by a check. `SettlementFlowTest`
 *                 and `ReconcileOrders` class one both depend on that.
 *   RECONCILABLE  `ReconcileOrders` already scans recent WooCommerce orders
 *                 for a marketplace product with no line and names it
 *                 `order_without_line`; the mark written here says WHY, which
 *                 that scan cannot work out after the fact.
 *
 * **The question is asked of the module, not of the gate.** The gate answers
 * «may the marketplace sell», and the first version of this class asked it.
 * That is a narrower question than «did anything register the capture hooks»:
 * the order module also stays blocked when a dependency failed or its
 * `register()` threw, and in both of those the gate reads ready. Asking
 * `LoadReport::status('order')` covers all three with one question.
 *
 * Pure: no WordPress, no database, no clock. The wiring is
 * `UnrecordableOrderGuard`.
 */
final class UnrecordableOrder
{
    /**
     * Written on the WooCommerce order itself, so the fact survives an option
     * being cleared, the plugin being deactivated and the package being
     * replaced. It is the one record that travels with the thing it describes.
     */
    public const META_KEY = '_tmc_unrecorded_order';

    /**
     * A bounded list of recent order ids, for the admin notice only.
     *
     * Bounded on purpose: this is a convenience for the next admin page, not
     * the record. The record is the meta above and the audit row, and both are
     * unbounded. A notice that tried to hold every id would grow without limit
     * in a single option row.
     */
    public const REGISTER_OPTION = 'tmc_unrecorded_orders';
    public const REGISTER_LIMIT = 50;

    /** Capture is registered; `OrderHooks` owns this order and this class says nothing. */
    public const RECORDED = 'recorded';
    /** Nothing of the marketplace's in it — the host shop's own sale, or Dokan's. */
    public const NOT_OURS = 'not_ours';
    /** Ours, and nothing could record it. */
    public const UNRECORDABLE = 'unrecordable';

    /**
     * @param bool       $captureActive whether the order module registered its hooks
     * @param string     $reason        why it did not, verbatim from the loader or the gate
     * @param int        $ourLines      marketplace lines counted in this order
     * @param list<int>  $vendorUserIds the shops those lines belong to, de-duplicated
     *
     * @return array{verdict:string, reason:string, lines:int, vendors:list<int>}
     */
    public static function judge(
        bool $captureActive,
        string $reason,
        int $ourLines,
        array $vendorUserIds
    ): array {
        // Asked FIRST, and this ordering is the scoping guarantee in reverse:
        // when capture is live there is nothing to say about ANY order, ours
        // or not, so no amount of line counting can make this class act on a
        // working site.
        if ($captureActive) {
            return ['verdict' => self::RECORDED, 'reason' => '', 'lines' => 0, 'vendors' => []];
        }
        if ($ourLines <= 0) {
            // The host shop's own goods and Dokan's own goods, in the state
            // where the marketplace is deliberately switched off. Saying
            // anything here — a mark, a notice, an audit row — would be this
            // plugin commenting on a sale that is none of its business.
            return ['verdict' => self::NOT_OURS, 'reason' => '', 'lines' => 0, 'vendors' => []];
        }
        $vendors = [];
        foreach ($vendorUserIds as $vendorUserId) {
            if ($vendorUserId > 0 && !in_array($vendorUserId, $vendors, true)) {
                $vendors[] = $vendorUserId;
            }
        }
        sort($vendors);
        return [
            'verdict' => self::UNRECORDABLE,
            // Never empty: a mark that cannot say why is a mark somebody will
            // read as «we do not know what happened here».
            'reason' => $reason !== '' ? $reason : 'order_module_inactive',
            'lines' => $ourLines,
            'vendors' => $vendors,
        ];
    }

    /**
     * The bounded id list, newest last, with no duplicates.
     *
     * @param list<int> $existing
     * @return list<int>
     */
    public static function register(array $existing, int $orderId): array
    {
        $ids = [];
        foreach ($existing as $id) {
            $id = (int) $id;
            if ($id > 0 && $id !== $orderId) {
                $ids[] = $id;
            }
        }
        $ids[] = $orderId;
        if (count($ids) > self::REGISTER_LIMIT) {
            // Oldest dropped, because the newest is the one the person reading
            // the notice has to deal with — and the dropped id is still on its
            // own order and in the audit table.
            $ids = array_slice($ids, -self::REGISTER_LIMIT);
        }
        return array_values($ids);
    }
}
