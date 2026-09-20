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
 *   wp eval-file tools/safe-stop-order.php customer [login] [password]
 *   wp eval-file tools/safe-stop-order.php create <product-id> [product-id…]
 *   wp eval-file tools/safe-stop-order.php create-for <customer-id> <product-id>…
 *   wp eval-file tools/safe-stop-order.php payable <order-id>
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


$command = (string) ($args[0] ?? '');

if ($command === 'customer') {
    // A real WordPress customer, because the fresh pay link this evidence
    // needs lives behind «حساب من ← سفارش‌ها» and a guest order has no such
    // page. Idempotent: the same login is reused across runs.
    $login = (string) ($args[1] ?? 'tmcbuyer');
    $password = (string) ($args[2] ?? 'TmcBuyer!2026');
    $user = get_user_by('login', $login);
    if (!$user) {
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => $login . '@example.test',
            'role' => 'customer',
        ]);
        $user = get_user_by('id', $userId);
    } else {
        wp_set_password($password, $user->ID);
    }
    printf("customer=%d login=%s\n", (int) $user->ID, $login);
    return;
}

if ($command === 'create' || $command === 'create-for') {
    // `create-for <customer-id> <product-id>…` — a separate verb rather than a
    // trailing optional id, because a product id and a user id are both just
    // integers and guessing which one was meant is how a fixture silently
    // stops testing what it claims to.
    $order = wc_create_order(['status' => 'pending']);
    $productArgs = array_slice($args, 1);
    if ($command === 'create-for') {
        $order->set_customer_id((int) ($args[1] ?? 0));
        $productArgs = array_slice($args, 2);
    }
    foreach ($productArgs as $productId) {
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
