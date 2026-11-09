<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * THE ORDER'S OWN STATUS, READ LIVE — one method, and no way to write one.
 *
 * `SyncOrderLifecycle` already mirrors the status onto the marketplace's rows,
 * and that mirror is a FILTER: it exists so the ledger report can be narrowed,
 * and schema 39 added it with no backfill precisely so that a row nobody has
 * announced a status for says nothing rather than claiming `pending`.
 *
 * A financial decision cannot be taken on a filter. A mirror can lag — the
 * capture does not write it, so an order that has never changed status since
 * checkout carries nothing — and «nothing» would refuse a sale that was in
 * fact paid. So the decision asks the live fact and falls back to the mirror
 * only to distinguish «not paid» from «I could not find out».
 *
 * ⛔ **No writer, deliberately.** Everything downstream of this reads a status
 * to decide whether money may be declared settleable; a port that could also
 * SET one would be a path by which the plugin made its own answer true. The
 * only writer of a WooCommerce order status is WooCommerce, driven by a
 * person or a gateway.
 */
interface OrderPaymentStatusInterface
{
    /**
     * The WooCommerce status of this order, or null when it could not be read.
     *
     * `null` means exactly one thing: the question was asked and no answer
     * came back — no WooCommerce, no such order, a store that threw. It never
     * means «unpaid», and the rule that consumes it refuses rather than
     * guessing (`SettlementEligibility::STATUS_UNRECORDED`).
     */
    public function statusOf(int $wcOrderId): ?string;
}
