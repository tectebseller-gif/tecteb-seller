<?php
/**
 * `alpha.63`: two findings on the bytes of `alpha.62`, measured.
 *
 * **Its own file, named for its round** (`alpha.54`, guarded by
 * `EvidenceToolsAreNotOverwrittenTest`). Everything added this round is
 * reached through `class_exists()` / `method_exists()` or through the real
 * hook names, so this file LOADS on the `alpha.62` tree too (`alpha.32`) —
 * which is the point: both verbs are meant to answer DIFFERENTLY there.
 *
 * ### `pay-paths` — the free order
 *
 * `alpha.62` filtered `woocommerce_order_needs_payment` to `false` for a
 * refused order. This verb does not argue about that; it evaluates the
 * EXPRESSIONS CORE EVALUATES, read from WooCommerce 11.0.1's own source:
 *
 *   - `apply_filters('woocommerce_cart_needs_payment', $order->needs_payment(), WC()->cart)`
 *     — `includes/class-wc-checkout.php:1444`, whose `false` branch is
 *     `process_order_without_payment()`,
 *   - `$order->needs_payment()` — `src/StoreApi/Routes/V1/Checkout.php:682`,
 *     whose `false` branch is `process_without_payment()`, which is
 *     `$order->payment_complete()`
 *     (`src/StoreApi/Utilities/CheckoutTrait.php:73`),
 *   - `wc_notice_count('error')` after `woocommerce_before_pay_action` —
 *     `includes/class-wc-form-handler.php:504,543`, because `pay_action()`
 *     calls `process_payment()` only when that count is nought,
 *   - and whether `woocommerce_store_api_checkout_order_processed` throws.
 *
 * So `classic_branch=without_payment` is not an opinion about a hook: it is
 * the branch that site would take, and it ends in a completed unpaid order.
 *
 * ### `partial-void` — an item nobody holds
 *
 * Two partial payments against one obligation, then the first is voided.
 * `alpha.62` wrote no row at all for the second payment and cleared the live
 * key of the first, so the item ended up claimed by nobody while a real
 * payment still covered it — and `reserve()` could take it.
 *
 * Needs `cart-arrange` / `cart-rule` from `alpha62-probe.php` to have run,
 * because the refused product is that fixture's and this round deliberately
 * does not build a second one. Prints ONE key per line (`alpha.41`), and
 * `reset` gives back what it spends (`alpha.33`).
 *
 *   wp eval-file tests/Support/alpha63-probe.php <verb> [args]
 *     pay-paths · pay-control · partial-void · partial-void-both · report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

// --- refuses to run anywhere but a disposable install ---------------------
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

const TMC_P63_VENDOR = 9631;
const TMC_P63_ORDER = 96310;
const TMC_P63_ITEM = 96311;
const TMC_P63_SKU_CART = 'WC-A62-CART';     // the alpha.62 fixture's product

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adminId);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

global $wpdb;
$db = new WpDatabase($wpdb);
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . ($value === '' || $value === null ? '__none__' : (string) $value) . "\n";
};
$items = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
$orderItems = $db->prefix() . M0006CatalogAndOrders::ORDER_ITEMS;

/** The refused product of the `alpha.62` cart fixture. */
$cartProduct = static function () use ($wpdb): int {
    return (int) ($wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} pm
          INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND p.post_type = 'product'
          ORDER BY p.ID ASC LIMIT 1",
        TMC_P63_SKU_CART
    )) ?? 0);
};

// =========================================================== §1 the pay paths

if ($verb === 'pay-paths' || $verb === 'pay-control') {
    $wcId = $cartProduct();
    if ($wcId <= 0) {
        $say('fixture', '__missing__');
        $say('hint', 'run alpha62-probe.php cart-arrange first');
        return;
    }
    // `pay-control` asks the SAME questions about a rule the line satisfies,
    // so that every number below is read against a pair rather than alone.
    $value = $verb === 'pay-control' ? '10000' : '150000';
    update_post_meta($wcId, '_tmc_commission_type', 'fixed');
    update_post_meta($wcId, '_tmc_commission_value', $value);
    wp_cache_delete($wcId, 'post_meta');
    $say('verb', $verb);
    $say('commission', 'fixed/' . $value);
    // THE SALE GATE IS OPENED FIRST, and put back at the end. Without it the
    // first run of this verb measured `pay_action_errors=1` on BOTH trees —
    // a true sentence about `CartGuard` refusing a closed marketplace, and
    // nothing at all about the commission rule. A control that cannot
    // distinguish the two trees is not a control (`alpha.46`).
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $trial->request(true);
    $say('sale_gate_open', $c->get(
        \Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class
    )->decide($wcId)['decision']);

    // AN UNPAID ORDER carrying that product at 100,000 — the figure the
    // basket would have produced. Built with WooCommerce's own CRUD so that
    // `get_items()`, `get_total()` and `needs_payment()` are the real ones.
    $order = wc_create_order(['status' => 'pending']);
    $order->add_product(wc_get_product($wcId), 1, ['total' => '100000', 'subtotal' => '100000']);
    $order->set_currency(get_woocommerce_currency());
    $order->calculate_totals(false);
    $order->save();
    $orderId = (int) $order->get_id();
    update_option('tmc_p63_order', $orderId);
    $order = wc_get_order($orderId);
    $say('order', $orderId);
    $say('line_total', (string) $order->get_total());

    // 1. THE RAW ANSWER, and the two core expressions that read it.
    $needs = (bool) $order->needs_payment();
    $say('needs_payment', $needs);
    $classic = (bool) apply_filters('woocommerce_cart_needs_payment', $order->needs_payment(), WC()->cart);
    $say('classic_branch', $classic ? 'payment' : 'without_payment');
    $say('store_api_branch', $order->needs_payment() ? 'payment' : 'without_payment');

    // 2. THE CLASSIC PAY LINK. `pay_action()` reaches `process_payment()`
    //    only when the error count is nought, so this count IS the verdict.
    if (function_exists('wc_clear_notices')) {
        wc_clear_notices();
    }
    do_action('woocommerce_before_pay_action', $order);
    $errors = function_exists('wc_notice_count') ? (int) wc_notice_count('error') : -1;
    $say('pay_action_errors', $errors);
    $say('pay_action_would_charge', $errors === 0);
    $notice = '';
    if ($errors > 0 && function_exists('wc_get_notices')) {
        $all = wc_get_notices('error');
        $notice = isset($all[0]) ? (string) ($all[0]['notice'] ?? $all[0]) : '';
    }
    $say('pay_notice', str_replace(' ', '_', wp_strip_all_tags($notice)));
    if (function_exists('wc_clear_notices')) {
        wc_clear_notices();
    }

    // 3. BOTH STORE API ROUTES, at the hook that fires before the payment
    //    branch in each of them.
    $threw = 'passed';
    try {
        do_action('woocommerce_store_api_checkout_order_processed', $order);
    } catch (\Throwable $e) {
        $threw = 'threw:' . (new \ReflectionClass($e))->getShortName();
    }
    $say('store_api_order_processed', $threw);

    // 4. AND THE ORDER IS UNTOUCHED either way — this guard moves nothing.
    $after = wc_get_order($orderId);
    $say('status_after', (string) $after->get_status());
    $say('date_paid', $after->get_date_paid() ? 'set' : '__none__');
    $trial->request(false);
    return;
}

// ============================================ §1b the two readings of a line

if ($verb === 'rounding') {
    // THE DIVERGENCE ITSELF, measured rather than argued.
    //
    // The basket read `wc_format_decimal($line_total, $exponent)` and the
    // order carries what `WC_Order_Item_Product::set_total()` stores, which
    // is `wc_format_decimal($value)` with NO dp. On a currency with nought
    // decimals those are different strings for any fractional line total,
    // and a fixed commission between them passes one reading and fails the
    // other — which on `alpha.62` was a free order and on `alpha.63` a line
    // that gets PAID for although §1 says it must not sell.
    $raw = (string) ($args[1] ?? '49999.6');
    $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 0;
    $say('currency_exponent', $exponent);
    $say('raw_line_total', $raw);
    $rounded = (string) wc_format_decimal($raw, $exponent);
    $asOrdered = (string) wc_format_decimal($raw);
    $say('cart_read_alpha63', $rounded);
    $say('order_carries', $asOrdered);
    $say('readings_agree', $rounded === $asOrdered);

    // And what the rule answers about each — the whole point of the pair.
    $checkClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\CommissionLineCheck';
    if (!class_exists($checkClass)) {
        $say('absent', 1);
        return;
    }
    $wcId = $cartProduct();
    if ($wcId <= 0) {
        $say('fixture', '__missing__');
        return;
    }
    update_post_meta($wcId, '_tmc_commission_type', 'fixed');
    update_post_meta($wcId, '_tmc_commission_value', (string) ($args[2] ?? '50000'));
    wp_cache_delete($wcId, 'post_meta');
    $check = $c->get($checkClass);
    $currency = (string) get_woocommerce_currency();
    $onRounded = $check->check($wcId, 0, $rounded, 1, $currency, $exponent);
    $onOrdered = $check->check($wcId, 0, $asOrdered, 1, $currency, $exponent);
    $say('rule_on_rounded', ($onRounded['ok'] ?? false) ? 'sells' : 'refused');
    $say('rule_on_ordered', ($onOrdered['ok'] ?? false) ? 'sells' : 'refused');
    $say('verdicts_agree', (bool) ($onRounded['ok'] ?? false) === (bool) ($onOrdered['ok'] ?? false));
    update_post_meta($wcId, '_tmc_commission_type', 'none');
    update_post_meta($wcId, '_tmc_commission_value', '0');
    wp_cache_delete($wcId, 'post_meta');
    return;
}

// ====================================================== §2 the partial void

if ($verb === 'partial-void' || $verb === 'partial-void-both') {
    // The obligation model is this round's and the round before's; on an
    // older tree the verb says so rather than failing (`alpha.32`).
    $obligationsClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\ManageSupplierObligations';
    if (!class_exists($obligationsClass)) {
        $say('absent', 1);
        return;
    }
    (new DbVendorRepository($db, new SystemClock()))->upsertProfile(TMC_P63_VENDOR, 'تأمین‌کنندهٔ ۶۳', true, false);
    $lines = new DbOrderItemRepository($db, new SystemClock());
    // A DELIVERED, SETTLEMENT-COMPLETED sale with its ledger lines — the
    // only shape a supplier payment may be recorded against. Built through
    // the repositories rather than with SQL, so the columns are whatever the
    // migrations say they are (`alpha.10`).
    (new DbLedgerRepository($db, new SystemClock()))->record(
        (new LedgerTransaction('a63:' . TMC_P63_ITEM, TMC_P63_VENDOR, 'a63:' . TMC_P63_ITEM, (string) TMC_P63_ITEM))
            ->add(LedgerAccount::CentralPayment, Money::of(1000000), 'item_paid')
            ->add(LedgerAccount::Commission, Money::of(100000)->negate(), 'commission_due')
            ->add(LedgerAccount::VendorEarning, Money::of(900000)->negate(), 'vendor_earned')
    );
    $itemId = (int) $lines->record(new VendorOrderItem(
        0,
        TMC_P63_ORDER,
        TMC_P63_ITEM,
        0,
        TMC_P63_VENDOR,
        'کالای آزمایشی ۶۳',
        'SKU-A63',
        1,
        1000000,
        1000000,
        0,
        100000,
        900000,
        1000,
        'product',
        'a63:' . TMC_P63_ITEM,
        OrderItemStatus::Delivered
    ));
    if ($itemId > 0) {
        $lines->recordSettlementCompletion($itemId, '2026-10-16 00:00:00', $adminId);
    }
    $say('line', $itemId);
    if ($itemId <= 0) {
        return;
    }

    $agreements = $c->get($obligationsClass);
    $agreed = $agreements->agree($itemId, '900000', 'توافق ۶۳');
    $say('agree', (string) $agreed->code);

    $service = $c->get(RecordSupplierPayment::class);
    $first = $service->record(TMC_P63_VENDOR, [$itemId], '500000', '2026-10-17', 'A63-P1');
    $second = $service->record(TMC_P63_VENDOR, [$itemId], '400000', '2026-10-18', 'A63-P2');
    $say('first', (string) $first->code);
    $say('second', (string) $second->code);
    $firstId = (int) ($first->context['payment_id'] ?? 0);
    $secondId = (int) ($second->context['payment_id'] ?? 0);
    $say('rows_for_item', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE order_item_id = %d',
        [$itemId]
    ) ?? 0));

    $voided = $service->void($firstId, 'شمارهٔ حوالهٔ اول اشتباه بود');
    $say('void_first', (string) $voided->code);
    if ($verb === 'partial-void-both') {
        $say('void_second', (string) $service->void($secondId, 'دومی هم اشتباه بود')->code);
    }

    // WHO HOLDS THE ITEM NOW — the only question that matters, asked of the
    // rows rather than of an outcome code.
    $live = (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE live_item_id = %d',
        [$itemId]
    ) ?? 0);
    $payments = new DbSupplierPaymentRepository($db, new SystemClock());
    $holder = (int) ($payments->forOrderItem($itemId)?->id ?? 0);
    $say('live_claims', $live);
    $say('holder', $holder === 0 ? '__none__' : ($holder === $secondId ? 'surviving_payment' : 'voided_payment'));
    $say('rows_still_on_disk', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE order_item_id = %d',
        [$itemId]
    ) ?? 0));

    // …and whether the withdrawal cycle can take it. A non-nought id here on
    // `partial-void` is the double settlement: Tecteb paid the supplier
    // 400,000 for this line and the vendor is about to be paid for it too.
    $withdrawals = new DbWithdrawalRepository($db, new SystemClock());
    $reserved = (int) $withdrawals->reserve(TMC_P63_VENDOR, [$itemId], 900000, 'IR000000000000000000000000', 'a63');
    $say('withdrawal_reserved', $reserved === 0 ? 'refused' : ('TAKEN:' . $reserved));
    return;
}

// ================================================================= the rest

if ($verb === 'report') {
    $itemId = (int) ($db->getVar(
        'SELECT id FROM `' . $orderItems . '` WHERE wc_order_item_id = %d',
        [TMC_P63_ITEM]
    ) ?? 0);
    $say('line', $itemId === 0 ? '__none__' : $itemId);
    $say('order', (int) get_option('tmc_p63_order', 0));
    $say('cart_product', $cartProduct());
    return;
}

if ($verb === 'reset') {
    // GIVES BACK WHAT IT SPENT (`alpha.33`): the order it created, the
    // obligation, the payments and their rows, and the commission rule it
    // wrote on a product that belongs to the other probe's fixture.
    $orderId = (int) get_option('tmc_p63_order', 0);
    if ($orderId > 0 && function_exists('wc_get_order') && wc_get_order($orderId)) {
        wp_delete_post($orderId, true);
    }
    delete_option('tmc_p63_order');
    $itemId = (int) ($db->getVar(
        'SELECT id FROM `' . $orderItems . '` WHERE wc_order_item_id = %d',
        [TMC_P63_ITEM]
    ) ?? 0);
    if ($itemId > 0) {
        foreach (['tmc_supplier_payment_allocations', 'tmc_supplier_obligation_history'] as $table) {
            $db->execute('DELETE FROM `' . $db->prefix() . $table . '` WHERE 1 = 1');
        }
        $db->execute('DELETE FROM `' . $items . '` WHERE order_item_id = %d', [$itemId]);
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'tmc_supplier_obligations` WHERE order_item_id = %d',
            [$itemId]
        );
        $db->execute('DELETE FROM `' . $orderItems . '` WHERE id = %d', [$itemId]);
    }
    $db->execute(
        'DELETE FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS)
        . '` WHERE supplier_user_id = %d',
        [TMC_P63_VENDOR]
    );
    $db->execute(
        'DELETE FROM `' . $db->prefix() . 'tmc_withdrawals` WHERE vendor_user_id = %d',
        [TMC_P63_VENDOR]
    );
    $wcId = $cartProduct();
    if ($wcId > 0) {
        // Back to what `alpha62-probe.php cart-arrange` leaves behind.
        update_post_meta($wcId, '_tmc_commission_type', 'none');
        update_post_meta($wcId, '_tmc_commission_value', '0');
        wp_cache_delete($wcId, 'post_meta');
    }
    $say('reset', 'done');
    return;
}

$say('usage', 'pay-paths|pay-control|rounding|partial-void|partial-void-both|report|reset');
