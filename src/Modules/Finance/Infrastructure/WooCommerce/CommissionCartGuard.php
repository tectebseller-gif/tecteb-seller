<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\PurchaseGuard;

/**
 * STOPPING A LINE WHOSE COMMISSION IS BIGGER THAN THE LINE — before payment,
 * in both baskets, and nowhere else.
 *
 * ### Where this is asked, and why there
 *
 * `woocommerce_check_cart_items` and only that, plus the pay link. The action
 * runs:
 *
 *  - on the classic cart page (`class-wc-shortcode-cart.php:93`),
 *  - on the classic checkout page (`class-wc-shortcode-checkout.php:349`),
 *  - inside `WC_Checkout::process_checkout()` (`class-wc-checkout.php:1009`
 *    → `:355`), which is the classic POST that takes the money,
 *  - and inside the Store API's `CartController::validate_cart()`
 *    (`src/StoreApi/Utilities/CartController.php:525`), which the block cart
 *    and the block checkout both go through — and which turns the notices
 *    raised here into `WP_Error`s with a 409.
 *
 * So the two baskets behave identically because they are running the SAME
 * callback, not because two callbacks were written to agree. That is the
 * difference between «سبد کلاسیک و Store API یک رفتار داشته باشند» as a
 * property and as a hope.
 *
 * **Not `woocommerce_add_to_cart_validation`, deliberately.** At add-to-cart
 * there is no coupon applied yet, so the only basis available is the line
 * subtotal — a different number from the one the order will carry. A guard
 * there would refuse baskets a discount would have made fine, and allow ones
 * it would have broken. The question is asked where the answer is true.
 *
 * ### What it does not touch
 *
 *  - **a paid order.** «سفارش گذشتهٔ پرداخت‌شده نباید با این قاعده لغو یا
 *    بازنویسی شود.» Nothing here cancels, refunds or rewrites anything:
 *    `woocommerce_order_needs_payment` is consulted by WooCommerce only for
 *    an order that still needs paying, and there is no other order hook in
 *    this class. An inconsistency on a settled order is the manager's to look
 *    at, and `incompleteCaptures()` is where it is listed.
 *  - **anything that is not this marketplace's.** Every branch asks
 *    `PurchasePolicy` first and returns untouched for `NOT_OURS`, exactly as
 *    `CartGuard` does. The shop's own products and Dokan's are unaffected.
 *  - **a product with no commission, or a commission of nought.** «کمیسیون
 *    خالی یا صفر … مجاز بماند و به‌معنای بدون کمیسیون باشد» — the calculator
 *    answers `0` for both and `0` is never bigger than a line.
 */
final class CommissionCartGuard
{
    public static function register(ContainerInterface $container): void
    {
        $policy = static fn (): PurchasePolicy => $container->get(PurchasePolicy::class);
        $check = static fn (): CommissionLineCheck => $container->get(CommissionLineCheck::class);

        add_action('woocommerce_check_cart_items', static function () use ($policy, $check): void {
            if (!function_exists('WC') || WC()->cart === null) {
                return;
            }
            self::inspectCart($policy(), $check(), WC()->cart);
        }, 25);

        // The pay link of an order that was never paid. Same question, same
        // sentence, and still before any money moves.
        add_filter('woocommerce_order_needs_payment', static function (mixed $needsPayment, mixed $order) use ($policy, $check): mixed {
            if ($needsPayment !== true || self::refusedLineOf($policy(), $check(), $order) === '') {
                return $needsPayment;
            }
            return false;
        }, 25, 2);

        add_action('before_woocommerce_pay', static function () use ($policy, $check): void {
            $orderId = isset($GLOBALS['wp']->query_vars['order-pay'])
                ? absint($GLOBALS['wp']->query_vars['order-pay'])
                : 0;
            if ($orderId <= 0 || !function_exists('wc_get_order') || !function_exists('wc_print_notice')) {
                return;
            }
            $order = wc_get_order($orderId);
            $name = $order ? self::refusedLineOf($policy(), $check(), $order) : '';
            if ($name !== '') {
                wc_print_notice(self::message($name), 'error');
            }
        }, 25);
    }

    /**
     * One notice per refused line, and nothing said about the rest.
     *
     * The notice is an ERROR rather than a removal: taking the line out of
     * the basket would be this plugin deciding what the shopper wanted, and
     * on a line whose price is right and whose rule is wrong the thing to fix
     * is the rule. An error notice stops the checkout — both the classic one
     * and the Store API's — and leaves the basket as the shopper built it.
     */
    private static function inspectCart(PurchasePolicy $policy, CommissionLineCheck $check, mixed $cart): void
    {
        if (!is_object($cart) || !method_exists($cart, 'get_cart') || !function_exists('wc_add_notice')) {
            return;
        }
        ['currency' => $currency, 'exponent' => $exponent] = self::unit();
        if ($currency === '' || $exponent < 0) {
            return;             // «نمی‌دانم» — no claim either way
        }
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (PurchaseGuard::marketplaceProduct($policy, $product) === null) {
                continue;       // not ours; say nothing about it, ever
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $variationId = (int) ($item['variation_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            // FORMATTED THE WAY WOOCOMMERCE FORMATS IT when it writes the
            // order line (`WC_Checkout::create_order_line_items()` calls
            // `wc_format_decimal()` on this same value), so the answer here
            // is about the figure the order would carry rather than about a
            // float this plugin rounded its own way.
            $total = function_exists('wc_format_decimal')
                ? (string) wc_format_decimal($item['line_total'] ?? 0, $exponent)
                : '';
            $answer = $check->check($productId, $variationId, $total, $quantity, $currency, $exponent);
            if ($answer['ok']) {
                continue;
            }
            $name = is_object($product) && method_exists($product, 'get_name')
                ? (string) $product->get_name()
                : '';
            wc_add_notice(self::message($name), 'error');
        }
    }

    /**
     * The name of the first line of an UNPAID order this rule refuses, or ''.
     *
     * Reads the order's own stored totals, which are already exact decimal
     * text, so nothing is re-derived from a float here.
     */
    private static function refusedLineOf(PurchasePolicy $policy, CommissionLineCheck $check, mixed $order): string
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return '';
        }
        $currency = method_exists($order, 'get_currency') ? (string) $order->get_currency() : '';
        $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : -1;
        if ($currency === '' || $exponent < 0) {
            return '';
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            if (PurchaseGuard::marketplaceProduct($policy, $product) === null) {
                continue;
            }
            $answer = $check->check(
                (int) $item->get_product_id(),
                method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0,
                (string) $item->get_total(),
                (int) $item->get_quantity(),
                $currency,
                $exponent
            );
            if (!$answer['ok']) {
                return (string) $item->get_name();
            }
        }
        return '';
    }

    /** @return array{currency:string, exponent:int} */
    private static function unit(): array
    {
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
        $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : -1;
        return ['currency' => $currency, 'exponent' => $exponent];
    }

    /**
     * ONE SENTENCE A SHOPPER CAN ACT ON — and no rate, no code, no account.
     *
     * The same rule the storefront has followed since `alpha.8`: the decision
     * code, the commission and the names of the open decisions go to the
     * manager's screen and the audit trail, never to a buyer. What the buyer
     * gets is what is true for them — this item cannot be bought right now —
     * and who to ask.
     */
    public static function message(string $productName): string
    {
        return $productName === ''
            ? __(
                'خرید یکی از کالاهای سبد در حال حاضر امکان‌پذیر نیست؛ تنظیمات فروش آن نیاز به بازبینی دارد. لطفاً با پشتیبانی تماس بگیرید.',
                'tecteb-marketplace-core'
            )
            : sprintf(
                /* translators: %s: product name */
                __(
                    'خرید «%s» در حال حاضر امکان‌پذیر نیست؛ تنظیمات فروش این کالا نیاز به بازبینی دارد. لطفاً با پشتیبانی تماس بگیرید.',
                    'tecteb-marketplace-core'
                ),
                $productName
            );
    }
}
