<?php
/**
 * Kills the process in the ONE window that matters: after WooCommerce has
 * written the refund, before its id reaches the marketplace's own row.
 *
 * The owner's instruction is «ثبت refund ووکامرس را با قطع اجرا پس از
 * ساخته‌شدن refund و پیش از ثبت شناسهٔ آن در TMC آزمایش کن», and the crash has
 * to be a real one. An exception is NOT a crash here: `wc_create_refund()`
 * catches everything it throws and calls `$refund->delete(true)` on the way
 * out, so an exception TIDIES THE REFUND AWAY and leaves nothing to recover.
 * Measured in WooCommerce 11.0.1, includes/wc-order-functions.php:745.
 *
 * So this exits. `exit` unwinds no stack, runs no catch block and returns to
 * no caller — the refund row stays exactly where it was committed, which is
 * what a killed PHP-FPM worker, an OOM or a lost connection actually leaves
 * behind.
 *
 * The kill point is `woocommerce_order_partially_refunded`, the earliest hook
 * that fires after `$refund->save()` — earlier than `woocommerce_refund_created`
 * and earlier than the parent order's own re-save. Anything this plugin can
 * survive from there, it survives from the later points too.
 *
 * Installed OUTSIDE the plugin, as a third party. Inert unless the option is
 * set, so it can be left in mu-plugins between runs.
 *
 *   wp option update tmc_probe_crash_refund 1
 *   wp option delete tmc_probe_crash_refund
 */

$tmc_refund_crash = static function ($orderId, $refundId): void {
    if ((int) get_option('tmc_probe_crash_refund', 0) !== 1) {
        return;
    }
    // Consumed, so the retry that follows runs against an unmodified site.
    // A probe that fired twice would be testing itself, not the plugin.
    delete_option('tmc_probe_crash_refund');
    // stderr, because stdout belongs to wp-cli's own output and the evidence
    // parses it.
    fwrite(STDERR, sprintf("probe: killed after refund %d on order %d\n", (int) $refundId, (int) $orderId));
    exit(9);
};

add_action('woocommerce_order_partially_refunded', $tmc_refund_crash, 1, 2);
add_action('woocommerce_order_fully_refunded', $tmc_refund_crash, 1, 2);
