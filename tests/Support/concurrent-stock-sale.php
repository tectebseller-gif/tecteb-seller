<?php
/**
 * A SECOND WRITER, in its own process and on its own connection.
 *
 * This is the only honest way to measure the window `alpha.50` closes. A
 * closure, a `FakeCatalogProjector` or an `InterferingDatabase` all write on
 * the connection the code under test is using, and the moment that code holds
 * anything open they stop modelling another actor at all (`alpha.40`). So this
 * is a separate `wp` invocation: separate PHP process, separate MySQL
 * connection, separate transaction state.
 *
 * And it reduces the stock the way WOOCOMMERCE does, not the way we would:
 *
 *   order     create an order for the product and move it to `processing`,
 *             which is what `wc_maybe_reduce_stock_levels()` listens to. The
 *             whole real path — `wc_reduce_stock_levels()` →
 *             `wc_update_product_stock($p, $qty, 'decrease')` → the single
 *             relative statement on `_stock`.
 *   decrease  the same `wc_update_product_stock()` call on its own, for a
 *             measurement that does not need an order to exist.
 *
 * Prints ONE key per line (the `alpha.41` rule), so the caller can read the
 * numbers rather than the exit code.
 *
 *   wp eval-file tests/Support/concurrent-stock-sale.php order <wc_id> <qty>
 *   wp eval-file tests/Support/concurrent-stock-sale.php decrease <wc_id> <qty>
 */

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};
$say('writer', 'ran');

$mode = (string) ($args[0] ?? 'decrease');
$wcId = (int) ($args[1] ?? 0);
$qty = max(1, (int) ($args[2] ?? 1));

if (!function_exists('wc_get_product')) {
    $say('writer_error', 'no_woocommerce');
    return;
}
$product = wc_get_product($wcId);
if (!$product instanceof WC_Product) {
    $say('writer_error', 'no_such_product');
    return;
}
$say('before', (string) (int) $product->get_stock_quantity());

if ($mode === 'order') {
    $order = wc_create_order(['status' => 'pending']);
    if (!$order instanceof WC_Order) {
        $say('writer_error', 'no_order');
        return;
    }
    $order->add_product($product, $qty);
    $order->calculate_totals();
    $order->save();
    $say('order_id', $order->get_id());
    // `processing` is one of the four statuses `wc_maybe_reduce_stock_levels()`
    // hooks, so WooCommerce reduces the stock itself — nothing here writes it.
    $order->update_status('processing', 'alpha.50 second writer');
    $say('order_stock_reduced', $order->get_data_store()->get_stock_reduced($order->get_id()) ? 'yes' : 'no');
} else {
    wc_update_product_stock($product, $qty, 'decrease');
    $say('order_id', '__none__');
}

// Read the ROW, not an object: this process is about to exit and the number it
// leaves behind is the only thing that matters.
global $wpdb;
$raw = $wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
    $wcId
));
$say('row_after', $raw === null ? '__absent__' : $raw);
$say('after', $raw === null ? '__absent__' : (string) (int) round((float) $raw));
