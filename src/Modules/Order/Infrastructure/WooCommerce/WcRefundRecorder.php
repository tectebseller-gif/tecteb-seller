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
 * **How a crash is actually survived — and the claim that was wrong.**
 *
 * An earlier version of this file said the refund's id and this plugin's stamp
 * were «one insert». That was read off the hook order, not off the data store,
 * and it is false. `WC_Order_Refund_Data_Store_CPT::create()` — inherited from
 * `Abstract_WC_Order_Data_Store_CPT`, WooCommerce 11.0.1 — writes in THREE
 * separate, uncommitted-together steps:
 *
 *   1. `wp_insert_post()` — the refund row exists from here on.
 *   2. `update_post_meta()` for the internal props: `_refund_amount`,
 *      `_refund_reason`, `_refunded_by`.
 *   3. `$order->save_meta_data()` — everything else, including OUR stamp.
 *
 * Nothing wraps those in a transaction, and nothing in step 1 carries the
 * return id: a refund's `post_excerpt` is `''` (the refund store does not
 * override `get_post_excerpt()`, so the reason does NOT go in the row). So a
 * process that dies between 1 and 3 leaves a refund this class cannot
 * recognise, and a retry that trusted the stamp alone would make a second one.
 *
 * Hence the INTENT MARKER. Before `wc_create_refund()` is called, a note is
 * written on the PARENT order — which is a completed save of its own, so it is
 * either there or the refund was never attempted. A retry then has three cases
 * instead of two:
 *
 *   - A stamped refund exists → adopt it. `refund_recovered`, automatic.
 *   - The marker is set and unstamped refunds exist → **stop**.
 *     `refund_reconcile_required`, with the candidate ids. Never a second
 *     refund, never an adoption this class cannot justify: money is not a
 *     thing to guess about, and a person deciding is the correct outcome.
 *   - The marker is set and the order has no refunds at all → the crash landed
 *     before step 1. Nothing exists, so creating one is safe.
 *
 * The marker is cleared the moment `wc_create_refund()` returns, because from
 * then on the stamp is durable and does the job.
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
    /** The return a refund belongs to, stamped on the refund itself. */
    public const RETURN_META = '_tmc_return_id';

    /**
     * «A refund for this return is being created right now», on the PARENT
     * order. Written before the attempt and cleared after it, so the window in
     * which WooCommerce's three write steps can be interrupted is a window
     * somebody can see afterwards instead of guess at.
     */
    public const INTENT_META = '_tmc_refund_intent_';

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

    /**
     * The refund this return already has in WooCommerce, if any.
     *
     * The crash this exists for: `wc_create_refund()` succeeds, the process
     * dies, and the id never reaches the marketplace's own row. A retry that
     * simply called `wc_create_refund()` again would make a SECOND refund
     * against the same order — real money on the books twice.
     *
     * So every refund this class makes is stamped with the return it belongs
     * to, and a retry looks for that stamp first. The stamp is queued on the
     * `woocommerce_create_refund` hook, which fires before `$refund->save()` —
     * which makes it part of the same SAVE, but NOT part of the same insert:
     * see the class docblock for the three write steps and for the intent
     * marker that covers what this method cannot.
     *
     * A stamp found here is proof. A stamp missing here is not proof of
     * absence.
     */
    public function findExisting(int $wcOrderId, int $returnId): int
    {
        if (!$this->isAvailable() || $returnId <= 0) {
            return 0;
        }
        $order = \wc_get_order($wcOrderId);
        if (!$order || !method_exists($order, 'get_refunds')) {
            return 0;
        }
        foreach ($order->get_refunds() as $refund) {
            if ((string) $refund->get_meta(self::RETURN_META) === (string) $returnId) {
                return (int) $refund->get_id();
            }
        }
        return 0;
    }

    public function record(
        int $wcOrderId,
        int $wcOrderItemId,
        float $amount,
        int $quantity,
        string $reason,
        int $returnId
    ): array {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'reason' => 'refund_woocommerce_missing', 'refund_id' => 0];
        }
        $order = \wc_get_order($wcOrderId);
        if (!$order) {
            return ['ok' => false, 'reason' => 'refund_order_missing', 'refund_id' => 0];
        }
        // An orphan from a run that died between the create and the link.
        // Found and handed back rather than made again: the caller then links
        // THIS id, and nothing is created, restocked or reversed twice.
        $orphan = $this->findExisting($wcOrderId, $returnId);
        if ($orphan > 0) {
            $this->clearIntent($order, $returnId);
            return [
                'ok' => true,
                'reason' => 'refund_recovered',
                'refund_id' => $orphan,
                'money_moved' => false,
            ];
        }
        // No stamp — which is NOT the same as no refund. If a previous attempt
        // got as far as writing its intent, it may have created a refund and
        // died before the stamp was written; see the class docblock for the
        // three write steps that make that possible.
        if ($this->intentPending($order, $returnId)) {
            $candidates = $this->unstampedRefunds($order);
            if ($candidates !== []) {
                // Stop. Not a second refund, and not an adoption by
                // resemblance either — matching on an amount would be a guess,
                // and the thing being guessed about is money. A person decides.
                return [
                    'ok' => false,
                    'reason' => 'refund_reconcile_required',
                    'refund_id' => 0,
                    'candidates' => $candidates,
                ];
            }
            // The marker is there and the order has no refund at all, so the
            // interruption landed before WooCommerce wrote anything. Nothing
            // exists to duplicate.
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

        // The stamp goes on before the save, so the refund cannot exist without
        // it. Removed again straight after, so no other plugin's refund on
        // this request is marked with our return id.
        $stamp = static function ($refund) use ($returnId): void {
            if (is_object($refund) && method_exists($refund, 'update_meta_data')) {
                $refund->update_meta_data(self::RETURN_META, (string) $returnId);
            }
        };
        // The intent BEFORE the attempt, and its own completed save. Either it
        // is on the order — in which case a later run knows an attempt was
        // made — or nothing was attempted at all.
        $this->markIntent($order, $returnId);
        add_action('woocommerce_create_refund', $stamp, 10, 1);
        try {
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
        } finally {
            remove_action('woocommerce_create_refund', $stamp, 10);
        }

        if (is_wp_error($refund)) {
            // WooCommerce's own catch already deleted whatever it had made, so
            // there is nothing left for the marker to point at.
            $this->clearIntent($this->reload($wcOrderId), $returnId);
            return [
                'ok' => false,
                'reason' => 'refund_record_failed',
                'refund_id' => 0,
                'message' => $refund->get_error_message(),
            ];
        }
        // The stamp is durable from here, so the marker has nothing left to
        // cover. Cleared on a freshly loaded order: `wc_create_refund()` saves
        // the parent itself, and writing through a stale copy would undo that.
        $this->clearIntent($this->reload($wcOrderId), $returnId);
        return [
            'ok' => true,
            'reason' => 'refund_recorded',
            'refund_id' => (int) $refund->get_id(),
            'money_moved' => false,
        ];
    }

    // --- the intent marker ---------------------------------------------------

    private function intentKey(int $returnId): string
    {
        return self::INTENT_META . $returnId;
    }

    private function markIntent(object $order, int $returnId): void
    {
        try {
            $order->update_meta_data($this->intentKey($returnId), (string) time());
            $order->save();
        } catch (\Throwable) {
            // A marker that did not stick makes the retry more cautious, never
            // less: without it a retry falls back to the stamp alone, which is
            // the behaviour that existed before this marker did.
        }
    }

    private function clearIntent(?object $order, int $returnId): void
    {
        if ($order === null) {
            return;
        }
        try {
            $order->delete_meta_data($this->intentKey($returnId));
            $order->save();
        } catch (\Throwable) {
            // A leftover marker only makes the NEXT run ask a person about a
            // refund that is already linked, and `findExisting()` answers
            // first — so it never turns into a duplicate.
        }
    }

    private function intentPending(object $order, int $returnId): bool
    {
        return (string) $order->get_meta($this->intentKey($returnId)) !== '';
    }

    /**
     * Refunds on this order that carry no return stamp at all.
     *
     * Anybody's: a manager's own from wp-admin, a gateway plugin's, or one of
     * ours that died before its stamp. That is exactly why this list is handed
     * to a person instead of being adopted — from here they are
     * indistinguishable, and the difference matters.
     *
     * @return list<int>
     */
    private function unstampedRefunds(object $order): array
    {
        if (!method_exists($order, 'get_refunds')) {
            return [];
        }
        $ids = [];
        foreach ($order->get_refunds() as $refund) {
            if ((string) $refund->get_meta(self::RETURN_META) === '') {
                $ids[] = (int) $refund->get_id();
            }
        }
        return $ids;
    }

    private function reload(int $wcOrderId): ?object
    {
        $order = \wc_get_order($wcOrderId);
        return $order ?: null;
    }
}
