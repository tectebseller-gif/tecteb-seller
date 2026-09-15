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
 *  4. **Stock is WooCommerce's to move, and it is not symmetric.** See
 *     `stockNote()` below: what the release gives back depends on the status
 *     the order is going back to and on whether it had already reduced stock
 *     before we touched it. The honest claim is measured, not "it comes back".
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

            // The note is written BEFORE the move and re-written on a retry:
            // the order is in `$status` right now and payable, so `$status` is
            // the truthful place to put it back. A stale value from a hold
            // that never took effect would move it somewhere it never was.
            try {
                $order->update_meta_data(self::PREVIOUS_STATUS_META, $status);
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
            $held++;
        }
        return ['held' => $held, 'examined' => $examined, 'stuck' => $stuck];
    }

    public function release(): array
    {
        $released = 0;
        $stuck = [];
        foreach ($this->snapshotHeldOrders() as $orderId) {
            $order = $this->load($orderId);
            if ($order === null) {
                continue;
            }
            $previous = (string) $order->get_meta(self::PREVIOUS_STATUS_META);
            if ($previous === '') {
                continue;       // not ours to put back
            }
            if ((string) $order->get_status() !== self::HELD_STATUS) {
                // Somebody moved it on while it was held — paid it by hand,
                // cancelled it, completed it. That decision outranks ours, so
                // the note is dropped and the order is left exactly as it is.
                $this->forget($order, $orderId);
                continue;
            }
            try {
                // The status first, the note second. A save that fails here
                // leaves the note in place, so the order is still in the list
                // this method reads and the next attempt finds it.
                $order->update_status($previous, $this->releaseNote(), true);
            } catch (\Throwable) {
                // Measured, not assumed.
            }
            if ($this->statusOf($orderId) !== $previous) {
                $stuck[] = $orderId;
                continue;
            }
            $this->forget($this->load($orderId), $orderId);
            $released++;
        }
        return ['released' => $released, 'stuck' => $stuck];
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
            $order->delete_meta_data(self::PREVIOUS_STATUS_META);
            $order->save();
        } catch (\Throwable) {
            return false;
        }
        $fresh = $this->load($orderId);
        return $fresh !== null && (string) $fresh->get_meta(self::PREVIOUS_STATUS_META) === '';
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
     * guard is what makes the pair asymmetric in two cases worth saying out
     * loud rather than covering with «موجودی برمی‌گردد»:
     *
     *  - An order that had ALREADY reduced stock before the hold (an async
     *    gateway leaves such orders `failed` or `on-hold`) reduces nothing when
     *    we hold it — the flag is already set — but the release still increases,
     *    because the release is a status change into a releasing status. Net
     *    effect of one stop-and-release cycle on such an order: stock goes UP.
     *  - `woocommerce_order_status_failed` only became a releasing status in
     *    WooCommerce 11.0.0. On an older WooCommerce, an order held from
     *    `failed` has its stock reduced by the hold and NOT given back by the
     *    release.
     *
     * A repeated stop/release cycle on an ordinary `pending` order is symmetric
     * and does not drift; that case is measured too, and is the common one.
     */
    public static function stockNote(): string
    {
        return __('جابه‌جایی موجودی هنگام نگه‌داشتن و بازگرداندن سفارش کارِ خود ووکامرس است، نه این افزونه. برای سفارش pending معمولی، توقف و بازگرداندن یکدیگر را خنثی می‌کنند. ولی سفارشی که پیش از توقف هم موجودی را کم کرده بود، با بازگرداندن موجودی را زیاد می‌کند بی‌آنکه توقف چیزی کم کرده باشد؛ و روی ووکامرس پیش از ۱۱٫۰٫۰ سفارشی که از وضعیت failed نگه داشته شود، موجودی‌اش با بازگرداندن برنمی‌گردد. پیش از بازگرداندن دسته‌ای، موجودی را یک‌بار بررسی کنید.', 'tecteb-marketplace-core');
    }

    private function note(string $reason): string
    {
        return sprintf(
            /* translators: %s: the recorded reason selling stopped */
            __('این سفارش در انتظار نگه داشته شد چون فروش بازارگاه متوقف است (%s). سفارش، اقلام و مبلغش تغییری نکرده و لغو نشده؛ فقط تا تعیین تکلیف، پرداخت تازه‌ای روی آن انجام نمی‌شود. با «بازگرداندن سفارش‌های نگه‌داشته» همین سفارش به وضعیت قبلی‌اش برمی‌گردد و دوباره قابل پرداخت می‌شود.', 'tecteb-marketplace-core'),
            $reason
        );
    }

    private function releaseNote(): string
    {
        return __('این سفارش به وضعیت پیش از توقف فروش بازگردانده شد و دوباره قابل پرداخت است — حتی اگر افزونهٔ بازارگاه بعداً غیرفعال شود.', 'tecteb-marketplace-core');
    }
}
