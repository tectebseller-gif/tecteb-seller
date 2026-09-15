<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;

/**
 * Retires the pay link of every unpaid order that holds a marketplace item.
 *
 * WooCommerce decides whether an `order-pay` link may take money by three
 * questions, and only three: is the order id real, does the key in the URL
 * match the key on the order, and does the order still need payment. It never
 * asks again whether the goods may still be sold — measured on WooCommerce
 * 11.0.1, `WC_Form_Handler::pay_action()` and `WC_Shortcode_Checkout`.
 *
 * So the link is retired by rotating the order key, and that choice is worth
 * the sentence it takes to explain, because three more obvious ones are worse:
 *
 *  - **Cancelling the order** destroys a customer's order over a marketplace
 *    problem, and returns stock the shop may have counted on. Never.
 *  - **Moving it to `on-hold`** works — WooCommerce refuses payment for that
 *    status — but `on-hold` REDUCES stock on the way in and increases it on
 *    the way back, so a temporary stop would quietly move inventory numbers
 *    that belong to WooCommerce (ADR-008).
 *  - **A filter on `woocommerce_order_needs_payment`** is what this plugin
 *    already does while it runs, and is exactly the defence the owner ruled
 *    insufficient: it is gone the moment the plugin is.
 *
 * Rotating the key moves no money, no stock and no status. The order keeps
 * every line, every note and its whole history; a manager can still take
 * payment from wp-admin; and the old key is kept so that resuming puts back
 * the very link that was retired.
 *
 * Scope is the usual one: an order with no marketplace item is not read past
 * the question "is any of this ours?", and is never written.
 */
final class WcUnpaidOrderGuard implements UnpaidOrderGuardInterface
{
    /** Where the retired link is kept, so resume() can hand it back. */
    public const PREVIOUS_KEY_META = '_tmc_stop_prev_order_key';

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
            if ((string) $order->get_meta(self::PREVIOUS_KEY_META) !== '') {
                continue;       // already retired by an earlier stop
            }
            $previous = (string) $order->get_order_key();
            try {
                $order->update_meta_data(self::PREVIOUS_KEY_META, $previous);
                $order->set_order_key(\wc_generate_order_key());
                $order->add_order_note($this->note($reason));
                $order->save();
            } catch (\Throwable) {
                // Fall through to the verification below: what the order says
                // afterwards is the only answer that counts.
            }
            if ($this->linkStillWorks($orderId, $previous)) {
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
        foreach ($this->retiredOrders() as $order) {
            $orderId = (int) $order->get_id();
            $previous = (string) $order->get_meta(self::PREVIOUS_KEY_META);
            if ($previous === '') {
                continue;
            }
            try {
                $order->set_order_key($previous);
                $order->delete_meta_data(self::PREVIOUS_KEY_META);
                $order->save();
            } catch (\Throwable) {
                // Same as above: measured, not assumed.
            }
            if (!$this->linkStillWorks($orderId, $previous)) {
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
            if ((string) $order->get_meta(self::PREVIOUS_KEY_META) === '') {
                $open[] = (int) $order->get_id();
            }
        }
        return $open;
    }

    /**
     * Re-read from storage: whether the link that was mailed out still opens
     * this order. `wc_get_order` is called with the cache cleared so that the
     * object we just saved is not the one answering for itself.
     */
    private function linkStillWorks(int $orderId, string $mailedKey): bool
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($orderId, 'orders');
            wp_cache_delete($orderId, 'posts');
        }
        $fresh = \wc_get_order($orderId);
        if (!$fresh) {
            return false;
        }
        return hash_equals((string) $fresh->get_order_key(), $mailedKey);
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

    /** @return iterable<object> the orders whose link this guard retired */
    private function retiredOrders(): iterable
    {
        // Any status: an order paid by hand in wp-admin while selling was
        // stopped still has our meta on it and still deserves its key back.
        foreach ($this->ordersByStatus(array_keys(\wc_get_order_statuses())) as $order) {
            if ((string) $order->get_meta(self::PREVIOUS_KEY_META) !== '') {
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
            __('لینک پرداخت این سفارش بازنشسته شد چون فروش بازارگاه متوقف است (%s). سفارش، اقلام و سابقهٔ آن تغییری نکرده و با از سرگیری فروش، همان لینک قبلی بازمی‌گردد.', 'tecteb-marketplace-core'),
            $reason
        );
    }
}
