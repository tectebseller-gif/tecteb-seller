<?php
/**
 * A deliberate ORDER save failure, installed OUTSIDE the plugin.
 *
 * The owner asked for the restore information to be proved recoverable:
 * «خطای ذخیره را در هر مرحله تزریق و بازیابی را آزمایش کن». A failure the
 * plugin knows how to produce would only prove our own branch, so this is a
 * separate mu-plugin behaving like any third-party plugin that refuses a
 * write — an exception out of `$order->save()`, which is what a validation
 * hook, a full disk or a dropped connection looks like from the caller's side.
 *
 * It fires at ONE step of ONE order, chosen by two options, and is inert
 * otherwise, so it can be left in place between runs:
 *
 *   wp option update tmc_probe_order_id <order-id>
 *   wp option update tmc_probe_order_step hold_all|hold_status|release_status|release_meta
 *   wp option delete tmc_probe_order_step
 *
 * The step is recognised from the state of the order being saved rather than
 * from a counter, so it does not care how many saves WooCommerce makes on the
 * way or in what order the evidence runs:
 *
 *   hold_all       every save of this order, i.e. storage is simply refusing
 *   hold_status    the order is being moved INTO on-hold
 *   release_status the order is being moved OUT of on-hold, note still attached
 *   release_meta   the note is being dropped after a successful move back
 *
 * There is deliberately no `hold_meta` step, and the reason is a measured fact
 * worth keeping: `WC_Order::save()` wraps its own body in try/catch and calls
 * `handle_exception()`, so an exception out of this hook never reaches the
 * caller — the write is simply skipped and `save()` returns normally. The note
 * written in memory therefore survives on the same object and is persisted by
 * the NEXT save, the one that changes the status. A failed note-write alone is
 * not a reachable state; a storage that refuses everything is, and that is
 * what `hold_all` is. It is also why the guard verifies by re-reading the
 * order instead of trusting that `save()` returned.
 *
 * Copied into wp-content/mu-plugins/ by tools/guard-check.sh on a DISPOSABLE
 * WordPress. Never install this anywhere else: it makes order saves fail.
 */

add_action('woocommerce_before_order_object_save', static function ($order): void {
    $target = (int) get_option('tmc_probe_order_id', 0);
    $step = (string) get_option('tmc_probe_order_step', '');
    if ($target <= 0 || $step === '' || !is_object($order)) {
        return;
    }
    if ((int) $order->get_id() !== $target) {
        return;
    }
    $note = (string) $order->get_meta('_tmc_stop_prev_status');
    $status = (string) $order->get_status();
    $held = $status === 'on-hold';

    $fail = match ($step) {
        // Storage refusing outright: no save of this order goes through.
        'hold_all' => true,
        // The move itself.
        'hold_status' => $held,
        // The move back: out of on-hold while the note is still attached.
        'release_status' => $note !== '' && !$held,
        // Dropping the note after the move back succeeded.
        'release_meta' => $note === '',
        default => false,
    };
    if ($fail) {
        throw new \RuntimeException('tmc probe: injected order save failure at step ' . $step);
    }
}, 1, 1);
