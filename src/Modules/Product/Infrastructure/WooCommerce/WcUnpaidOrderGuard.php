<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;

/**
 * Stops payment on every unpaid order that holds a marketplace item — in a way
 * that survives this plugin being switched off.
 *
 * WooCommerce decides whether an order may take money by three questions, and
 * only three: is the order id real, does the key in the URL match the key on
 * the order, and does the order still need payment. It never asks again
 * whether the goods may still be sold (measured on WooCommerce 11.0.1,
 * `WC_Form_Handler::pay_action()` and `WC_Shortcode_Checkout`).
 *
 * **This class used to rotate the order key, and that was not enough.** It
 * killed the link that had been e-mailed, and nothing more: a customer who
 * opens «حساب من ← سفارش‌ها» gets a FRESH pay link built from the order's
 * CURRENT key, and with this plugin deactivated nothing refuses it.
 *
 * So the order is moved to `on-hold`, which is the one thing WooCommerce
 * itself understands as "not payable": `WC_Order::needs_payment()` answers
 * true only for `pending` and `failed`. No code of ours is involved in the
 * refusal, so removing our code does not remove it.
 *
 * Four rules, each of which this class got wrong once:
 *
 *  1. **The STATUS is the fact, never the meta.** A note saying "this was
 *     held" is not a hold. An earlier version skipped any order carrying the
 *     restore meta, so an order whose meta was written and whose status change
 *     then failed became invisible: `stillPayable()` called it handled, the
 *     stop reported success, and «تلاش دوبارهٔ توقف» skipped it forever. Every
 *     decision here is now made from the status, re-read from storage.
 *  2. **The restore note is deleted only after the status really moved.** The
 *     release used to delete the meta first and change the status after, so a
 *     failed save destroyed the only record of where the order belonged. Now
 *     the status moves, the move is verified, and only then is the note
 *     dropped — a release that fails leaves everything it needs to be retried.
 *  3. **Never iterate a query you are mutating.** `hold()` paged through the
 *     payable orders while moving them OUT of payable, so page 2 began where
 *     the shrunken result set had already moved past: with 200 per page and
 *     more than 200 eligible orders, roughly every second page was skipped
 *     silently. The ids are now snapshotted by a read-only pass and the writes
 *     happen afterwards, one freshly loaded order at a time.
 *  4. **Stock is WooCommerce's to move, and the pair has to cancel out.** The
 *     hold records whether the order had ALREADY reduced stock; the release
 *     lets WooCommerce increase only when the hold actually reduced. Nothing
 *     here writes a stock number, so a sale, a return or a vendor's own edit
 *     between the two survives untouched — and an order whose stock does not
 *     come back where it started is reported for a person to look at rather
 *     than counted as released. See `stockNote()`.
 *
 * What is NOT done, deliberately: the order is never cancelled, never emptied,
 * never refunded. It keeps every line, every note and its total, the previous
 * status is remembered so a release restores exactly it, and a manager can
 * still take payment by hand from wp-admin at any time.
 *
 * Scope is the usual one: an order with no marketplace item is not read past
 * the question "is any of this ours?", and is never written.
 */
final class WcUnpaidOrderGuard implements UnpaidOrderGuardInterface
{
    /** The status the order had before the hold, so a release restores it. */
    public const PREVIOUS_STATUS_META = '_tmc_stop_prev_status';

    /**
     * Whether THIS ORDER had already reduced stock before the hold touched it.
     *
     * The whole stock correctness of a stop hangs on this one fact, so it is
     * written down rather than re-derived: by the time the release runs, the
     * order is `on-hold` and every order in that status has reduced stock, so
     * there is no way to tell afterwards which of them arrived that way.
     */
    public const STOCK_WAS_REDUCED_META = '_tmc_stop_stock_was_reduced';

    /** What the hold itself moved: '1' when our transition reduced, else '0'. */
    public const STOCK_MOVED_META = '_tmc_stop_stock_moved';

    /** Set when a hold or release ended somewhere a person has to look at. */
    public const RECONCILE_META = '_tmc_stop_reconcile';

    /** The status WooCommerce itself will not take payment for. */
    public const HELD_STATUS = 'on-hold';

    /** Order statuses WooCommerce will take payment for (WC_Order::needs_payment). */
    private const PAYABLE_STATUSES = ['pending', 'failed'];

    /** Read in pages: a shop's order table is not something to load whole. */
    private const PAGE_SIZE = 200;

    public function hold(string $reason): array
    {
        $held = 0;
        $examined = 0;
        $stuck = [];
        // Snapshot first, write second. The list is built by a pass that
        // changes nothing, so no page can slide past a row this method has
        // just moved out of the result set.
        foreach ($this->snapshotPayableMarketplaceOrders() as $orderId) {
            $order = $this->load($orderId);
            if ($order === null) {
                continue;
            }
            $status = (string) $order->get_status();
            if ($status === self::HELD_STATUS) {
                continue;       // already held, by this stop or an earlier one
            }
            if (!in_array($status, self::PAYABLE_STATUSES, true)) {
                continue;       // somebody paid or cancelled it since the snapshot
            }
            $examined++;

            // Whether the stock of this order was ALREADY reduced before we
            // touched it, read before anything moves. Everything the release
            // does about stock is decided by this one value, and after the
            // hold it is unrecoverable: every `on-hold` order has reduced
            // stock, so they all look the same from then on.
            $wasReduced = $this->stockReduced($orderId);

            // The note is written BEFORE the move and re-written on a retry:
            // the order is in `$status` right now and payable, so `$status` is
            // the truthful place to put it back. A stale value from a hold
            // that never took effect would move it somewhere it never was.
            try {
                $order->update_meta_data(self::PREVIOUS_STATUS_META, $status);
                $order->update_meta_data(self::STOCK_WAS_REDUCED_META, $wasReduced ? '1' : '0');
                $order->save();
                // update_status(), not set_status(): the transition hooks are
                // the point. WooCommerce's own rules about a held order —
                // including the stock it holds — are what make this survive us.
                $order->update_status(self::HELD_STATUS, $this->note($reason), true);
            } catch (\Throwable) {
                // Fall through to the verification below: what the order says
                // afterwards is the only answer that counts.
            }
            if ($this->statusOf($orderId) !== self::HELD_STATUS) {
                $stuck[] = $orderId;
                continue;
            }
            // What the hold ACTUALLY moved, measured rather than assumed: it
            // reduced only if the order was not already reduced and is now.
            $this->remember($orderId, self::STOCK_MOVED_META,
                (!$wasReduced && $this->stockReduced($orderId)) ? '1' : '0');
            $held++;
        }
        return ['held' => $held, 'examined' => $examined, 'stuck' => $stuck];
    }

    /**
     * Puts back exactly the orders this guard held — and moves no stock that
     * the hold did not move.
     *
     * The stock problem this solves, measured before it was fixed: WooCommerce
     * reduces on the way into `on-hold` and increases on the way out, but both
     * are guarded by the order's own `_order_stock_reduced` flag. An order that
     * had ALREADY reduced stock before the hold — what an asynchronous gateway
     * leaves behind — is not reduced again by the hold, because the flag is
     * already set. The release then increases anyway, and one stop-and-release
     * cycle leaves the product with **one unit of stock that nobody returned**.
     *
     * The fix is a delta, never a restore. This method does not write a stock
     * number anywhere and never puts a product back to a remembered total: a
     * sale, a return or a vendor's own edit between the hold and the release
     * must survive, and an absolute write would silently undo all three. What
     * it does instead is decide, per order, whether WooCommerce's increase
     * should happen at all — and when our hold reduced nothing, it takes
     * `wc_maybe_increase_stock_levels` off that one transition and puts it
     * straight back.
     *
     * Anything that does not end where it should goes to `reconcile`, which is
     * NOT counted as released: an order a person has to look at is not a
     * success, and reporting it as one is how a phantom unit gets believed.
     *
     * @return array{released:int, stuck:list<int>, moved_on:list<int>, reconcile:list<int>}
     */
    public function release(): array
    {
        $released = 0;
        $stuck = [];
        $movedOn = [];
        $reconcile = [];
        foreach ($this->snapshotHeldOrders() as $orderId) {
            $order = $this->load($orderId);
            if ($order === null) {
                continue;
            }
            $previous = (string) $order->get_meta(self::PREVIOUS_STATUS_META);
            if ($previous === '') {
                continue;       // not ours to put back
            }
            // FIRST, before anything else looks at the status.
            //
            // A reconciled-pending order has ALREADY been put back: its status
            // is `$previous`, not `on-hold`. So the `moved_on` branch below
            // matched it on the very next run, called it somebody else's
            // decision, and `forget()` wiped the reconcile mark and the
            // «where it was and what its stock did» trail along with it — the
            // one run that reported the problem was also the last run that
            // knew about it.
            //
            // Nothing here is touched and nothing is forgotten. The mark is
            // cleared by `resolveReconciliation()` and by nothing else: either
            // a person has actually put the stock right, or a person has
            // recorded a decision to accept it. A later release must not be
            // able to do it by accident.
            if ((string) $order->get_meta(self::RECONCILE_META) !== '') {
                $reconcile[] = $orderId;
                continue;
            }
            if ((string) $order->get_status() !== self::HELD_STATUS) {
                // Somebody moved it on while it was held — paid it by hand,
                // cancelled it, completed it. That decision outranks ours, so
                // the status is left exactly as it is and only our notes go.
                //
                // Its stock is WooCommerce's business now: whatever transition
                // that person made has already applied its own rule, and a
                // correction from us on top would be the second movement for
                // one decision. Reported separately from `released`, because a
                // stop that ends with somebody having paid is worth seeing.
                $movedOn[] = $orderId;
                $this->forget($order, $orderId);
                continue;
            }

            // Three answers, not two. An ABSENT flag is «we do not know», and
            // guessing false there is the phantom unit all over again: the
            // release would let WooCommerce increase stock on an order whose
            // hold may well have reduced none.
            //
            // It is absent on two real orders. One held by `alpha.11`, before
            // this flag existed, and released by this build. One whose hold
            // wrote the previous status and then failed before the second
            // `save()` — the status moved, the trail did not.
            //
            // Both go to reconciliation. A stop this build cannot account for
            // is a person's to look at, not a number to guess.
            $recorded = (string) $order->get_meta(self::STOCK_WAS_REDUCED_META);
            if ($recorded !== '1' && $recorded !== '0') {
                $reconcile[] = $orderId;
                $this->remember($orderId, self::RECONCILE_META, 'stock_state_unknown');
                continue;
            }
            $wasReduced = $recorded === '1';
            // When the hold reduced nothing, the release must increase
            // nothing. Taking the hook off for exactly this one transition is
            // narrower than any alternative: no filter of ours runs during
            // somebody else's status change, and the hook is back before the
            // next line of this loop.
            $suppress = $wasReduced;
            if ($suppress) {
                remove_action('woocommerce_order_status_' . $previous, 'wc_maybe_increase_stock_levels');
            }
            try {
                // The status first, the note second. A save that fails here
                // leaves the note in place, so the order is still in the list
                // this method reads and the next attempt finds it.
                $order->update_status($previous, $this->releaseNote(), true);
            } catch (\Throwable) {
                // Measured, not assumed.
            } finally {
                if ($suppress) {
                    add_action('woocommerce_order_status_' . $previous, 'wc_maybe_increase_stock_levels');
                }
            }
            if ($this->statusOf($orderId) !== $previous) {
                $stuck[] = $orderId;
                continue;
            }
            // The order is back. Is its stock back where it started? The flag
            // is the question: it must read exactly what it read before the
            // hold. Anything else means the two movements did not cancel, and
            // that is a person's problem, not a number to report as fine.
            if ($this->stockReduced($orderId) !== $wasReduced) {
                $reconcile[] = $orderId;
                $this->remember($orderId, self::RECONCILE_META, 'stock_mismatch');
                continue;       // the notes stay: a retry must still find it
            }
            $this->forget($this->load($orderId), $orderId);
            $released++;
        }
        return [
            'released' => $released,
            'stuck' => $stuck,
            'moved_on' => $movedOn,
            'reconcile' => $reconcile,
        ];
    }

    /**
     * Orders that hold a marketplace item and can still be paid right now.
     *
     * Asked of the STATUS and nothing else. An order carrying the restore note
     * whose status never moved is still payable and is reported as such — that
     * is the whole point of the question, and reading the note instead is what
     * let a half-finished stop call itself finished.
     */
    public function stillPayable(): array
    {
        return $this->snapshotPayableMarketplaceOrders();
    }

    /**
     * Drops the restore note, and says whether the drop stuck.
     *
     * Failing to forget is not the same kind of failure as failing to move:
     * the order is where it belongs, and a leftover note only means the next
     * release looks at it again and finds nothing to do.
     */
    private function forget(?object $order, int $orderId): bool
    {
        if ($order === null) {
            return false;
        }
        try {
            // All of them, together. A leftover «stock was reduced» on an
            // order this guard no longer holds would be read by the NEXT stop
            // as that stop's own measurement, and the release after it would
            // suppress an increase it should have made.
            $order->delete_meta_data(self::PREVIOUS_STATUS_META);
            $order->delete_meta_data(self::STOCK_WAS_REDUCED_META);
            $order->delete_meta_data(self::STOCK_MOVED_META);
            $order->delete_meta_data(self::RECONCILE_META);
            $order->save();
        } catch (\Throwable) {
            return false;
        }
        $fresh = $this->load($orderId);
        return $fresh !== null && (string) $fresh->get_meta(self::PREVIOUS_STATUS_META) === '';
    }

    /**
     * Whether WooCommerce considers this order's stock already taken.
     *
     * Asked of the data store rather than of a meta read, because that is the
     * same place `wc_maybe_reduce_stock_levels` and `wc_maybe_increase_stock_levels`
     * ask — and a second way of reading one fact is a second answer waiting to
     * disagree.
     */
    private function stockReduced(int $orderId): bool
    {
        $order = $this->load($orderId);
        if ($order === null || !method_exists($order, 'get_data_store')) {
            return false;
        }
        try {
            return (bool) $order->get_data_store()->get_stock_reduced($orderId);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Writes one note on an order, and says nothing when it cannot. */
    private function remember(int $orderId, string $key, string $value): void
    {
        $order = $this->load($orderId);
        if ($order === null) {
            return;
        }
        try {
            $order->update_meta_data($key, $value);
            $order->save();
        } catch (\Throwable) {
            // A note that did not stick is not worth failing a hold for: the
            // status is the fact, and this is only the trail beside it.
        }
    }

    /**
     * Orders a person has to look at: the stop moved stock in a way that did
     * not cancel out, and nobody should call that finished.
     *
     * @return list<int>
     */
    public function needsReconciliation(): array
    {
        $ids = [];
        foreach ($this->ordersByStatus($this->allStatuses()) as $order) {
            if ((string) $order->get_meta(self::RECONCILE_META) !== '') {
                $ids[] = (int) $order->get_id();
            }
        }
        return $ids;
    }

    /**
     * A manager says this one is dealt with — the ONLY thing that clears a
     * reconcile mark.
     *
     * Not a retry, not a later release, not «it looks fine now». The mark
     * exists because a number did not add up and a person has to decide what
     * that meant; code deciding it for them is how the problem stops being
     * visible without stopping being real.
     *
     * The decision is written into the order's own notes before the mark goes,
     * so the record outlives this plugin: an order whose stock was corrected
     * by hand says so on the order, where anybody looking at it will see it,
     * rather than only in a log this build happens to keep.
     *
     * @param string $note what the person actually did. Required — an
     *        unexplained resolution is the same as no resolution.
     */
    public function resolveReconciliation(int $orderId, int $actorId, string $note): bool
    {
        $note = trim($note);
        if ($orderId <= 0 || $note === '') {
            return false;
        }
        $order = $this->load($orderId);
        if ($order === null || (string) $order->get_meta(self::RECONCILE_META) === '') {
            return false;
        }
        $mark = (string) $order->get_meta(self::RECONCILE_META);
        try {
            // The note first. If the save below fails, the mark is still
            // there — an order that keeps its mark and gains an extra note is
            // recoverable; one that loses its mark and gains nothing is not.
            $order->add_order_note($this->resolutionNote($mark, $actorId, $note), false, false);
            $order->delete_meta_data(self::RECONCILE_META);
            $order->delete_meta_data(self::PREVIOUS_STATUS_META);
            $order->delete_meta_data(self::STOCK_WAS_REDUCED_META);
            $order->delete_meta_data(self::STOCK_MOVED_META);
            $order->save();
        } catch (\Throwable) {
            return false;
        }
        // Verified by re-reading, because `WC_Order::save()` swallows its own
        // exception and a successful return proves nothing.
        $fresh = $this->load($orderId);
        return $fresh !== null && (string) $fresh->get_meta(self::RECONCILE_META) === '';
    }

    /**
     * What this guard knows about one order's stock, for a screen to show.
     *
     * @return array{held:bool, was_reduced:?bool, moved:?bool, reconcile:string}
     */
    public function stockTrail(int $orderId): array
    {
        $order = $this->load($orderId);
        if ($order === null) {
            return ['held' => false, 'was_reduced' => null, 'moved' => null, 'reconcile' => ''];
        }
        $wasReduced = (string) $order->get_meta(self::STOCK_WAS_REDUCED_META);
        $moved = (string) $order->get_meta(self::STOCK_MOVED_META);
        return [
            'held' => (string) $order->get_meta(self::PREVIOUS_STATUS_META) !== '',
            'was_reduced' => $wasReduced === '' ? null : $wasReduced === '1',
            'moved' => $moved === '' ? null : $moved === '1',
            'reconcile' => (string) $order->get_meta(self::RECONCILE_META),
        ];
    }

    /**
     * The order's status, re-read from storage.
     *
     * Deliberately the STATUS and not `needs_payment()`, and the difference
     * cost a wrong answer in both directions before it was noticed:
     *
     *  - Verifying a hold with `needs_payment()` would let a FAILED hold look
     *    successful, because this plugin's own live filter on
     *    `woocommerce_order_needs_payment` already answers false while selling
     *    is stopped. The hold would report success and the order would go back
     *    to taking money the moment the plugin was removed — which is the
     *    entire failure this class exists to prevent.
     *  - Verifying a release with it reported a correct release as STUCK, for
     *    the same reason: the order really was back to `pending`, and our own
     *    filter was still saying "no payment needed" because the marketplace
     *    was still shut.
     *
     * The status is the fact that outlives this plugin, so the status is what
     * is checked. The cache is cleared first so the object just saved is not
     * the one answering for itself.
     */
    private function statusOf(int $orderId): string
    {
        $fresh = $this->load($orderId);
        return $fresh !== null ? (string) $fresh->get_status() : '';
    }

    /** One order, read past every cache that might be holding an old copy. */
    private function load(int $orderId): ?object
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($orderId, 'orders');
            wp_cache_delete($orderId, 'posts');
        }
        $order = \wc_get_order($orderId);
        return is_object($order) && method_exists($order, 'get_status') ? $order : null;
    }

    /**
     * Every payable order holding a marketplace item, as ids, read-only.
     *
     * @return list<int>
     */
    private function snapshotPayableMarketplaceOrders(): array
    {
        $ids = [];
        foreach ($this->ordersByStatus(self::PAYABLE_STATUSES) as $order) {
            if ($this->holdsMarketplaceItem($order)) {
                $ids[] = (int) $order->get_id();
            }
        }
        return $ids;
    }

    /**
     * Every order carrying this guard's restore note, as ids, read-only.
     *
     * Any status, not just `on-hold`: an order somebody paid by hand while
     * selling was stopped still carries our note, and the note has to go even
     * though the status must not be touched. That makes this a scan of the
     * order table, which is the honest cost of not keeping a second list that
     * could drift out of step with the orders themselves.
     *
     * @return list<int>
     */
    private function snapshotHeldOrders(): array
    {
        $ids = [];
        foreach ($this->ordersByStatus($this->allStatuses()) as $order) {
            if ((string) $order->get_meta(self::PREVIOUS_STATUS_META) !== '') {
                $ids[] = (int) $order->get_id();
            }
        }
        return $ids;
    }

    /** @return list<string> */
    private function allStatuses(): array
    {
        return function_exists('wc_get_order_statuses')
            ? array_keys(\wc_get_order_statuses())
            : [self::HELD_STATUS, ...self::PAYABLE_STATUSES];
    }

    /**
     * @param list<string> $statuses
     * @return iterable<object>
     */
    private function ordersByStatus(array $statuses): iterable
    {
        if (!function_exists('wc_get_orders') || $statuses === []) {
            return;
        }
        $page = 1;
        do {
            $batch = \wc_get_orders([
                'status' => $statuses,
                'limit' => self::PAGE_SIZE,
                'page' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            if (!is_array($batch) || $batch === []) {
                return;
            }
            foreach ($batch as $order) {
                if (is_object($order) && method_exists($order, 'get_items')) {
                    yield $order;
                }
            }
            $page++;
        } while (count($batch) === self::PAGE_SIZE);
    }

    /** The one question asked of a foreign order, and the only one. */
    private function holdsMarketplaceItem(object $order): bool
    {
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $productId = (int) $item->get_product_id();
            if ($productId > 0 && (string) get_post_meta($productId, WooCommerceProjector::PRODUCT_META, true) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * What the hold and the release really do to stock — measured, not assumed.
     *
     * WooCommerce, not this plugin, moves the stock: `wc_maybe_reduce_stock_levels`
     * is hooked to `woocommerce_order_status_on-hold`, and
     * `wc_maybe_increase_stock_levels` to `pending`, `failed` and `cancelled`.
     * Both are guarded by the order's own `_order_stock_reduced` flag, and that
     * guard used to make the pair asymmetric: an order that had ALREADY reduced
     * stock before the hold was not reduced again — the flag was set — yet the
     * release increased anyway, so one cycle left the product with a unit
     * nobody had returned.
     *
     * **That is fixed, not documented.** The hold writes down whether the
     * order's stock was already reduced, and the release uses that to decide
     * whether WooCommerce's increase should run at all. Nothing here writes a
     * stock number: a sale, a return or a vendor's edit between the two must
     * survive, and restoring a remembered total would quietly undo all three.
     *
     * What is left, and why it is a sentence rather than a silence: an order
     * somebody paid or cancelled while it was held keeps whatever stock
     * movement THAT transition made, because it was their decision and a
     * correction from us would be a second movement for one act.
     */
    public static function stockNote(): string
    {
        return __('جابه‌جایی موجودی هنگام نگه‌داشتن و بازگرداندن سفارش کارِ خود ووکامرس است. این افزونه هیچ عدد موجودی‌ای نمی‌نویسد و هیچ موجودی‌ای را به مقدار قدیمی برنمی‌گرداند؛ فقط جلوی افزایشی را می‌گیرد که توقفش کاهشی نداشته. نتیجه: یک دور توقف و بازگرداندن، موجودی را تغییر نمی‌دهد — حتی اگر سفارش پیش از توقف هم موجودی را کم کرده بود، و حتی اگر در این فاصله فروش یا مرجوعی دیگری ثبت شده باشد. سفارشی که کسی در همین فاصله پرداخت یا لغو کند، همان حرکتی را نگه می‌دارد که تصمیم خودش ساخته است.', 'tecteb-marketplace-core');
    }

    private function note(string $reason): string
    {
        return sprintf(
            /* translators: %s: the recorded reason selling stopped */
            __('این سفارش در انتظار نگه داشته شد چون فروش بازارگاه متوقف است (%s). سفارش، اقلام و مبلغش تغییری نکرده و لغو نشده؛ فقط تا تعیین تکلیف، پرداخت تازه‌ای روی آن انجام نمی‌شود. با «بازگرداندن سفارش‌های نگه‌داشته» همین سفارش به وضعیت قبلی‌اش برمی‌گردد و دوباره قابل پرداخت می‌شود.', 'tecteb-marketplace-core'),
            $reason
        );
    }

    /**
     * The manager's decision, written onto the WooCommerce order.
     *
     * On the ORDER rather than only in this plugin's audit log, because the
     * whole point of the note is that it outlives this package: somebody
     * reading that order after a rollback still finds out why a flagged
     * difference was accepted.
     */
    private function resolutionNote(string $mark, int $actorId, string $note): string
    {
        $why = $mark === 'stock_state_unknown'
            ? __('این سفارش پیش از ثبت ردِ موجودی نگه داشته شده بود.', 'tecteb-marketplace-core')
            : __('موجودی پس از بازگرداندن، همان چیزی نشد که پیش از توقف بود.', 'tecteb-marketplace-core');
        return sprintf(
            /* translators: 1: why it was flagged, 2: the manager's user id, 3: what they did */
            __('بازارگاه تک‌طب — بررسی دستی موجودی: %1$s مدیر (شناسهٔ %2$s) این را بررسی و ثبت کرد: %3$s', 'tecteb-marketplace-core'),
            $why,
            (string) $actorId,
            $note
        );
    }

    private function releaseNote(): string
    {
        return __('این سفارش به وضعیت پیش از توقف فروش بازگردانده شد و دوباره قابل پرداخت است — حتی اگر افزونهٔ بازارگاه بعداً غیرفعال شود.', 'tecteb-marketplace-core');
    }
}
