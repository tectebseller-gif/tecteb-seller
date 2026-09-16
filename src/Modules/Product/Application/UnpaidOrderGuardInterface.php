<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * The one door out of a stopped marketplace that drafting a product does not
 * close: an order that was placed and never paid.
 *
 * Taking a product to `draft` is durable — WooCommerce refuses to sell a
 * draft product by itself, with none of our code present. An unpaid order is
 * different. `WC_Form_Handler::pay_action()` checks the order key, the order
 * status and the gateway; it never asks again whether the products are still
 * purchasable. So an unpaid order still takes money afterwards — and while
 * this plugin is active a filter of ours refuses it, which is exactly the
 * kind of defence the owner ruled insufficient: «پرچمی که فقط افزونهٔ فعال
 * می‌خواند کافی نیست».
 *
 * Nor is killing the e-mailed link enough, which is the second thing this
 * interface learned: a customer who opens «حساب من ← سفارش‌ها» is handed a
 * FRESH pay link built from the order's current key. Anything that only
 * invalidates one URL leaves that door open.
 *
 * The durable act is therefore about the ORDER, not about a link: move it to a
 * status WooCommerce itself will not take payment for, and remember which ones
 * were moved so a release restores exactly those and nothing else.
 *
 * Two rules bound every implementation:
 *
 *  - **Only orders holding a marketplace item.** An order of the shop's own
 *    goods, or a Dokan vendor's, is not read, not flagged and not moved.
 *  - **Nothing is cancelled, refunded or deleted.** No money moves and no
 *    commercial term is decided here; the order keeps every line, every note
 *    and its whole history, and a manager can pay it by hand at any time.
 */
interface UnpaidOrderGuardInterface
{
    /**
     * Makes every unpaid order that holds a marketplace item unpayable.
     *
     * @return array{held:int, examined:int, stuck:list<int>}
     *         `stuck` are the orders that are STILL payable afterwards — the
     *         failure the caller has to report, not a footnote.
     */
    public function hold(string $reason): array;

    /**
     * Puts back exactly the orders this guard held, and no others — moving no
     * stock the hold did not move.
     *
     * Four outcomes, and only the first is a success:
     *
     *  - `released` — back where it was, and its stock is where it started.
     *  - `stuck` — the status would not move. Retryable.
     *  - `moved_on` — somebody paid, cancelled or completed it while it was
     *    held. Their decision stands; only this guard's notes are removed.
     *  - `reconcile` — the status moved but the stock did not end where it
     *    began. **Never counted as released**, because a stop that leaves a
     *    product with stock nobody returned is a person's problem, not a
     *    number to report as fine.
     *
     * @return array{released:int, stuck:list<int>, moved_on:list<int>, reconcile:list<int>}
     */
    public function release(): array;

    /**
     * Orders whose stock did not come back where it started.
     *
     * @return list<int>
     */
    public function needsReconciliation(): array;

    /**
     * What this guard knows about one order's stock — so a screen can say
     * «این سفارش پیش از توقف هم موجودی را کم کرده بود» instead of a shrug.
     *
     * @return array{held:bool, was_reduced:?bool, moved:?bool, reconcile:string}
     */
    public function stockTrail(int $orderId): array;

    /**
     * Orders that hold a marketplace item and can still be paid right now.
     *
     * Asked by the manager's screen, which must be able to say "this is not
     * finished" without changing anything.
     *
     * @return list<int>
     */
    public function stillPayable(): array;
}
