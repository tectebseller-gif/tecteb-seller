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

// --- refuses to run anywhere but the disposable install -------------------
//
// This file touches WordPress and writes. On the owner's site that is not a
// tool, it is damage, and a docblock saying «disposable» stops nobody who
// pastes the command at the wrong shell. Two independent facts, the same
// pair tools/disposable-site.sh trusts — NOT wp_get_environment_type(),
// which reports `production` on the disposable container itself.
if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


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

/**
 * The EARLIER window, and the one the first probe cannot reach.
 *
 * WooCommerce creates a refund in THREE separate, uncommitted-together steps —
 * the same shape on both storage backends:
 *
 *   HPOS   `OrdersTableDataStore::persist_save()`:
 *          persist_order_to_db() → update_order_meta() → save_meta_data()
 *   legacy `Abstract_WC_Order_Data_Store_CPT::create()`:
 *          wp_insert_post() → update_post_meta() → save_meta_data()
 *
 * Our `_tmc_return_id` stamp lands in step 3. Nothing wraps the three in a
 * transaction, and nothing in step 1 carries the return id. So there is a real
 * state the first probe never produces: a refund row that exists and is NOT
 * stamped.
 *
 * This one produces it, by dying while WooCommerce is writing `_refund_amount`
 * — after the row, before the stamp.
 *
 * The hook took three wrong guesses to find, and every one of them was SILENT:
 * a probe that never fires reads exactly like a probe whose condition never
 * happened, which is why the check beside it asserts the exit code rather than
 * only the outcome.
 *
 *   - Not `added_post_meta`: that is the legacy-posts path, and this site runs
 *     HPOS.
 *   - Not `added_order_meta`: a refund is its own WooCommerce object type, so
 *     the action is `added_order_refund_meta`.
 *   - Not on `_refund_amount`: under HPOS the amount and the reason are
 *     COLUMNS of `wp_wc_orders`, written inside step 1, so they never reach a
 *     meta hook at all.
 *
 * All three actions are registered, and both meta keys are accepted, so this
 * probe stays honest on either storage backend.
 *
 *   wp option update tmc_probe_crash_unstamped 1
 */
$tmc_unstamped_crash = static function ($metaId, $objectId, $metaKey): void {
    // Under HPOS the amount and the reason are COLUMNS of `wp_wc_orders`, not
    // meta rows, so they are part of step 1 and never reach a meta hook.
    // `_refund_type` is a real meta and is queued before this plugin's stamp,
    // so it is the last thing written before the window opens. On the legacy
    // backend `_refund_amount` is the equivalent. Either one is the signal.
    if (!in_array($metaKey, ['_refund_type', '_refund_amount'], true)
        || (int) get_option('tmc_probe_crash_unstamped', 0) !== 1) {
        return;
    }
    delete_option('tmc_probe_crash_unstamped');
    fwrite(STDERR, sprintf("probe: killed after refund %d existed, before it was stamped\n", (int) $objectId));
    exit(8);
};

add_action('added_order_refund_meta', $tmc_unstamped_crash, 1, 3);
add_action('added_order_meta', $tmc_unstamped_crash, 1, 3);
add_action('added_post_meta', $tmc_unstamped_crash, 1, 3);
