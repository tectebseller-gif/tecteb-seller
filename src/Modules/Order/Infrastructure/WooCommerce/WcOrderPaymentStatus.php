<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\OrderPaymentStatusInterface;

/**
 * Asks WooCommerce what one order's status is, and says nothing when it
 * cannot.
 *
 * Every branch that is not an answer returns `null`, because the rule above
 * this one treats `null` as «I could not find out» and refuses — so a missing
 * WooCommerce, a deleted order or a store that throws all land on the
 * conservative side without this class deciding anything.
 */
final class WcOrderPaymentStatus implements OrderPaymentStatusInterface
{
    public function statusOf(int $wcOrderId): ?string
    {
        if ($wcOrderId <= 0 || !function_exists('wc_get_order')) {
            return null;
        }
        try {
            $order = wc_get_order($wcOrderId);
        } catch (\Throwable) {
            // A store that threw is not an unpaid order.
            return null;
        }
        if (!is_object($order) || !method_exists($order, 'get_status')) {
            return null;
        }
        $status = (string) $order->get_status();
        return trim($status) === '' ? null : $status;
    }
}
