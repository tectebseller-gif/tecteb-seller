<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\RefundRecorderInterface;

/**
 * Creates the WooCommerce refund RECORD, and never the payment.
 *
 * The owner asked for these two to be told apart — «وضعیت ثبت refund ووکامرس
 * را جدا از انتقال واقعی وجه بررسی کن و وابستگی هرکدام را دقیق توضیح بده» —
 * and the honest answer, read out of WooCommerce 11.0.1 rather than assumed,
 * is that they are genuinely separate and depend on different things:
 *
 * **The record** is `wc_create_refund()`. Its `refund_payment` argument
 * defaults to FALSE, so creating a `WC_Order_Refund` touches no gateway at
 * all: it writes a refund order, attaches negative line items, recalculates
 * what the order still owes and fires `woocommerce_order_refunded`. It depends
 * on exactly three things — the order existing, the amount not exceeding
 * `get_remaining_refund_amount()`, and somebody deciding to do it. None of
 * those is a gateway.
 *
 * **The money** is `wc_refund_payment()`, which `wc_create_refund()` calls
 * only when asked. That one depends on the order having a payment method
 * whose gateway object exists, on `$gateway->supports('refunds')`, and on a
 * stored transaction id to refund against. This build has no real gateway
 * adapter at all (Alpha: no sender, no gateway), so it is never asked for and
 * would fail if it were.
 *
 * So this class does the first and refuses the second, and says which in its
 * result rather than in a sentence somebody has to remember.
 *
 * Two deliberate arguments, both of which would otherwise cause a real double
 * count:
 *
 *  - `restock_items => false`. The marketplace already puts the goods back on
 *    the shelf when the manager records them RECEIVED. Letting WooCommerce do
 *    it again here would add the quantity twice.
 *  - `line_items` names OUR order line and nothing else. On an order that also
 *    carries the shop's own goods or a Dokan vendor's, a whole-order refund
 *    would attribute somebody else's money to this return.
 */
final class WcRefundRecorder implements RefundRecorderInterface
{
    public function isAvailable(): bool
    {
        return function_exists('wc_create_refund') && function_exists('wc_get_order');
    }

    public function canTransferMoney(int $wcOrderId): bool
    {
        // Asked of the ORDER, because the answer is a property of how it was
        // paid. It is false in this build no matter what the order says — the
        // method exists so a build that gains an adapter changes one place.
        return false;
    }

    /**
     * Why the money could not move, named precisely rather than «درگاه نیست».
     *
     * @return list<string> reason keys, empty when nothing is missing
     */
    public function moneyBlockers(int $wcOrderId): array
    {
        $blockers = ['refund_no_gateway_adapter'];
        if (!$this->isAvailable()) {
            return ['refund_woocommerce_missing'];
        }
        $order = \wc_get_order($wcOrderId);
        if (!$order) {
            return ['refund_order_missing'];
        }
        if ((string) $order->get_transaction_id() === '') {
            $blockers[] = 'refund_no_transaction_id';
        }
        $gateways = function_exists('WC') && WC()->payment_gateways !== null
            ? WC()->payment_gateways->payment_gateways()
            : [];
        $gateway = $gateways[(string) $order->get_payment_method()] ?? null;
        if ($gateway === null) {
            $blockers[] = 'refund_gateway_not_installed';
        } elseif (!$gateway->supports('refunds')) {
            $blockers[] = 'refund_gateway_no_refund_support';
        }
        return $blockers;
    }

    public function record(int $wcOrderId, int $wcOrderItemId, float $amount, int $quantity, string $reason): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'reason' => 'refund_woocommerce_missing', 'refund_id' => 0];
        }
        $order = \wc_get_order($wcOrderId);
        if (!$order) {
            return ['ok' => false, 'reason' => 'refund_order_missing', 'refund_id' => 0];
        }
        $remaining = (float) $order->get_remaining_refund_amount();
        if ($amount <= 0 || $amount > $remaining) {
            // WooCommerce throws on this; asking first turns an exception into
            // a sentence the manager's screen can show.
            return [
                'ok' => false,
                'reason' => 'refund_amount_exceeds_remaining',
                'refund_id' => 0,
                'remaining' => $remaining,
            ];
        }

        $refund = \wc_create_refund([
            'order_id' => $wcOrderId,
            'amount' => $amount,
            'reason' => $reason,
            'line_items' => $wcOrderItemId > 0 ? [
                $wcOrderItemId => ['qty' => $quantity, 'refund_total' => $amount],
            ] : [],
            // The two that matter. See the class docblock.
            'refund_payment' => false,
            'restock_items' => false,
        ]);

        if (is_wp_error($refund)) {
            return [
                'ok' => false,
                'reason' => 'refund_record_failed',
                'refund_id' => 0,
                'message' => $refund->get_error_message(),
            ];
        }
        return [
            'ok' => true,
            'reason' => 'refund_recorded',
            'refund_id' => (int) $refund->get_id(),
            'money_moved' => false,
        ];
    }
}
