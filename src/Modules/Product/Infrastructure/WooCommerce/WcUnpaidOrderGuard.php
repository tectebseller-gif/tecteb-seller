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
 * CURRENT key, and with this plugin deactivated nothing refuses it. The owner
 * named that exactly — «تغییر order_key به‌تنهایی اثبات توقف پرداخت نیست» — and
 * they were right. The evidence now buys with the fresh link, after
 * deactivation, and it is the check that failed before this rewrite.
 *
 * So the order is moved to `on-hold`, which is the one thing WooCommerce
 * itself understands as "not payable": `WC_Order::needs_payment()` answers
 * true only for `pending` and `failed`. No code of ours is involved in the
 * refusal, so removing our code does not remove it.
 *
 * The honest cost, measured and reported rather than hidden: WooCommerce
 * reduces stock on the way into `on-hold` and increases it on the way out
 * (`wc_maybe_reduce_stock_levels` is hooked to `woocommerce_order_status_on-hold`).
 * That is WooCommerce acting on its own rules in response to a status, not
 * this plugin writing stock — but it is a real movement and the release puts
 * it back. Weighed against an unrecorded sale, holding the stock of an order
 * the marketplace has stopped honouring is the safer half of the trade.
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
        foreach ($this->unpaidMarketplaceOrders() as $order) {
            $examined++;
            $orderId = (int) $order->get_id();
            if ((string) $order->get_meta(self::PREVIOUS_STATUS_META) !== '') {
                continue;       // already held by an earlier stop
            }
            $previous = (string) $order->get_status();
            try {
                $order->update_meta_data(self::PREVIOUS_STATUS_META, $previous);
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
        foreach ($this->heldOrders() as $order) {
            $orderId = (int) $order->get_id();
            $previous = (string) $order->get_meta(self::PREVIOUS_STATUS_META);
            if ($previous === '') {
                continue;
            }
            if ((string) $order->get_status() !== self::HELD_STATUS) {
                // Somebody moved it on while it was held — paid it by hand,
                // cancelled it, completed it. That decision outranks ours, so
                // the note is dropped and the order is left exactly as it is.
                $order->delete_meta_data(self::PREVIOUS_STATUS_META);
                $order->save();
                continue;
            }
            try {
                $order->delete_meta_data(self::PREVIOUS_STATUS_META);
                $order->save();
                $order->update_status($previous, $this->releaseNote(), true);
            } catch (\Throwable) {
                // Measured, not assumed.
            }
            if ($this->statusOf($orderId) !== $previous) {
                $stuck[] = $orderId;
                continue;
            }
            $released++;
        }
        return ['released' => $released, 'stuck' => $stuck];
    }

    public function stillPayable(): array
    {
        $open = [];
        foreach ($this->unpaidMarketplaceOrders() as $order) {
            if ((string) $order->get_meta(self::PREVIOUS_STATUS_META) === '') {
                $open[] = (int) $order->get_id();
            }
        }
        return $open;
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
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($orderId, 'orders');
            wp_cache_delete($orderId, 'posts');
        }
        $fresh = \wc_get_order($orderId);
        return $fresh ? (string) $fresh->get_status() : '';
    }

    /**
     * Every unpaid order that holds at least one marketplace product.
     *
     * @return iterable<object>
     */
    private function unpaidMarketplaceOrders(): iterable
    {
        foreach ($this->ordersByStatus(self::PAYABLE_STATUSES) as $order) {
            if ($this->holdsMarketplaceItem($order)) {
                yield $order;
            }
        }
    }

    /** @return iterable<object> the orders this guard put on hold */
    private function heldOrders(): iterable
    {
        // Any status, not just `on-hold`: an order somebody paid by hand while
        // selling was stopped still carries our note, and the note has to go
        // even though the status must not be touched.
        foreach ($this->ordersByStatus(array_keys(\wc_get_order_statuses())) as $order) {
            if ((string) $order->get_meta(self::PREVIOUS_STATUS_META) !== '') {
                yield $order;
            }
        }
    }

    /**
     * @param list<string> $statuses
     * @return iterable<object>
     */
    private function ordersByStatus(array $statuses): iterable
    {
        if (!function_exists('wc_get_orders')) {
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

    private function note(string $reason): string
    {
        return sprintf(
            /* translators: %s: the recorded reason selling stopped */
            __('این سفارش در انتظار نگه داشته شد چون فروش بازارگاه متوقف است (%s). سفارش، اقلام و مبلغش تغییری نکرده و لغو نشده؛ فقط تا تعیین تکلیف، پرداخت تازه‌ای روی آن انجام نمی‌شود. با «بازگرداندن سفارش‌های نگه‌داشته» همین سفارش به وضعیت قبلی‌اش برمی‌گردد.', 'tecteb-marketplace-core'),
            $reason
        );
    }

    private function releaseNote(): string
    {
        return __('این سفارش به وضعیت پیش از توقف فروش بازگردانده شد و دوباره قابل پرداخت است.', 'tecteb-marketplace-core');
    }
}
