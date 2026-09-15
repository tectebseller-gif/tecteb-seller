<?php
/**
 * Makes an UNPAID WooCommerce order on a disposable site, and asks whether it
 * can still be paid — the one door into a purchase that no cart guard covers.
 *
 * WooCommerce mails `order-pay` links that stay valid until the order is
 * cancelled, so one can be opened days after the marketplace stopped selling.
 *
 * No `declare(strict_types=1)`: `wp eval-file` wraps this in eval().
 *
 *   wp eval-file tools/safe-stop-order.php create <product-id> [product-id…]
 *   wp eval-file tools/safe-stop-order.php payable <order-id>
 */

$command = (string) ($args[0] ?? '');

if ($command === 'create') {
    $order = wc_create_order(['status' => 'pending']);
    foreach (array_slice($args, 1) as $productId) {
        $product = wc_get_product((int) $productId);
        if ($product) {
            $order->add_product($product, 1);
        }
    }
    $order->set_address([
        'first_name' => 'خریدار',
        'last_name' => 'آزمایشی',
        'address_1' => 'خیابان آزمایش',
        'city' => 'تهران',
        'postcode' => '1234567890',
        'country' => 'IR',
    ], 'billing');
    $order->calculate_totals();
    $order->save();
    printf("order=%d total=%s pay_url=%s\n", $order->get_id(), $order->get_total(), $order->get_checkout_payment_url());
    return;
}

if ($command === 'payable') {
    $order = wc_get_order((int) ($args[1] ?? 0));
    if (!$order) {
        echo "no such order\n";
        return;
    }
    printf("needs_payment=%s\n", $order->needs_payment() ? 'true' : 'false');
    return;
}

echo "usage: create <product-id>… | payable <order-id>\n";
