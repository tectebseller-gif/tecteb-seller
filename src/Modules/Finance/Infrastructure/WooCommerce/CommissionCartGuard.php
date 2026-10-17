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
 *    بازنویسی شود.» Nothing here cancels, refunds or rewrites anything. Every
 *    hook in this class runs on a basket or on the pay attempt of an order
 *    that has not been paid; none of them reads or writes a settled order. An
 *    inconsistency on a settled order is the manager's to look at, and
 *    `incompleteCaptures()` is where it is listed.
 *
 * ### ⛔ Why `woocommerce_order_needs_payment` is NOT used
 *
 * `alpha.62` filtered that to `false` for a refused order. **It was a payment
 * bypass and it was shipped.** `needs_payment() === false` does not mean
 * «رد شد» to WooCommerce; it means «این سفارش پولی لازم ندارد», and two core
 * branches act on exactly that:
 *
 *  - `WC_Checkout::process_checkout()` — `class-wc-checkout.php:1444` —
 *    `apply_filters('woocommerce_cart_needs_payment', $order->needs_payment(), …)`
 *    and on `false` calls `process_order_without_payment()`,
 *  - the block checkout — `StoreApi/Routes/V1/Checkout.php:682` — which on
 *    `false` calls `process_without_payment()`, and that is
 *    `$order->payment_complete()` (`StoreApi/Utilities/CheckoutTrait.php:73`).
 *
 * So the answer meant to stop a sale was the answer core reads as a licence to
 * finish it for nothing.
 *
 * ⛔ **How far that got, stated accurately** — because the first report of
 * this was overstated. Reaching either branch needs the cart guard below NOT
 * to refuse in the same request, and it refuses the same lines: measured on
 * `alpha.62`'s own bytes, a refused line in a real cart raises one error
 * notice, and `process_checkout()` throws on that before it reaches line
 * 1444 (the Store API turns it into a 409 the same way). The divergence
 * between the two readings was offered as the way through and it is NOT:
 * a line total that differs from its rounding is fractional, a fractional
 * amount is not an exact minor amount, and `CommissionLineCheck` ABSTAINS on
 * one rather than refusing — measured on four pairs, `verdicts_agree=yes`
 * every time. **No end-to-end free order was demonstrated.**
 *
 * What was: with `needs_payment()` false, the pay link and the order-pay page
 * refused with WooCommerce's own «this order's status is …» instead of this
 * rule's sentence, so the shopper was told nothing they could act on; and
 * `pay_action()`'s own `else` branch is `$order->payment_complete()`
 * (`class-wc-form-handler.php:560`), which is one per-call third-party filter
 * away from being reached. A guard that says «no money is due» about an order
 * that owes money is wrong in kind, whatever today's call graph does with it.
 *
 * **And the two readings are equal now anyway** (`alpha.64`): the cart uses
 * the same call the order item's setter uses, and the classic checkout has
 * the order-level refusal the Store API routes have. The second one is not
 * redundant: a plugin on `woocommerce_checkout_create_order_line_item` can
 * change a line total after the cart was checked, and an order's stored
 * currency need not be the shop's current one.
 *
 * The rule the repository already had, three times over: **در یک ورودی
 * نامعتبر، جهتِ محافظه‌کارانه هیچ‌وقت «بیشتر» نیست** (`alpha.46`), and «قفل یا
 * گاردی که طرفِ دیگر نمی‌گیرد گارد نیست — و این را از سورسِ طرفِ دیگر بپرسید»
 * (`alpha.50`). The source was asked about `woocommerce_check_cart_items` and
 * not about what `false` means downstream.
 *
 * `needs_payment()` is therefore left alone — it stays `true`, so
 * `payment_complete()` is unreachable through this plugin — and each pay path
 * is refused on a hook that means refusal:
 *
 *  - `woocommerce_before_pay_action` for the classic pay link. Core itself
 *    then skips the gateway: `pay_action()` calls `process_payment()` only
 *    `if ( 0 === wc_notice_count( 'error' ) )`
 *    (`class-wc-form-handler.php:543`), so an error notice added here is the
 *    refusal, raised before one byte reaches a gateway.
 *  - `woocommerce_store_api_checkout_order_processed` for both Store API
 *    routes, which fires after the order is written and **before** the
 *    payment branch (`Checkout.php:675` and `CheckoutOrder.php:158`). A
 *    `RouteException` there is turned into a real status code by
 *    `AbstractRoute::get_response()`.
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

        // THE CLASSIC PAY LINK of an order that was never paid. The notice IS
        // the refusal: `pay_action()` reaches `process_payment()` only when
        // the error count is nought, so nothing is handed to a gateway.
        add_action('woocommerce_before_pay_action', static function (mixed $order) use ($policy, $check): void {
            if (!function_exists('wc_add_notice')) {
                return;
            }
            $name = self::refusedLineOf($policy(), $check(), $order);
            if ($name !== '') {
                self::$refused = true;
                wc_add_notice(self::message($name), 'error');
            }
        }, 25, 1);

        // And the same sentence on a plain visit to the pay page, where no
        // POST happened and so nothing queued a notice. Said once: on a POST
        // the queued notice above is printed by WooCommerce's own
        // `woocommerce_output_all_notices` at priority 10.
        add_action('before_woocommerce_pay', static function () use ($policy, $check): void {
            $orderId = isset($GLOBALS['wp']->query_vars['order-pay'])
                ? absint($GLOBALS['wp']->query_vars['order-pay'])
                : 0;
            if (self::$refused || $orderId <= 0 || !function_exists('wc_get_order') || !function_exists('wc_print_notice')) {
                return;
            }
            $order = wc_get_order($orderId);
            $name = $order ? self::refusedLineOf($policy(), $check(), $order) : '';
            if ($name !== '') {
                self::$refused = true;
                wc_print_notice(self::message($name), 'error');
            }
        }, 25);

        // THE CLASSIC CHECKOUT, at the same point in its flow as the Store
        // API hook below: after the order is written and before the payment
        // branch (`class-wc-checkout.php:1427`, then `:1444`). The whole of
        // `process_checkout()` is inside a `try` whose `catch` turns the
        // message into an error notice, so throwing here refuses the
        // checkout without one byte reaching a gateway.
        //
        // Belt as well as braces: with the cart reading above now identical
        // to the figure the order carries, the basket already refuses every
        // line this would. It is here because «the two cannot disagree» is a
        // claim about two call sites in somebody else's code, and the cost of
        // being wrong about it was measured once already.
        add_action('woocommerce_checkout_order_processed', static function (mixed $orderId, mixed $data, mixed $order) use ($policy, $check): void {
            $name = self::refusedLineOf($policy(), $check(), $order);
            if ($name !== '') {
                throw new \Exception(self::message($name));
            }
        }, 25, 3);

        // BOTH STORE API ROUTES, after the order is written and before the
        // payment branch. Throwing here is the only answer that cannot be
        // read as «پولی لازم نیست»: the route turns it into a status code and
        // neither `process_payment()` nor `process_without_payment()` runs.
        add_action('woocommerce_store_api_checkout_order_processed', static function (mixed $order) use ($policy, $check): void {
            $name = self::refusedLineOf($policy(), $check(), $order);
            if ($name === '') {
                return;
            }
            $message = self::message($name);
            $routeException = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
            if (class_exists($routeException)) {
                throw new $routeException('woocommerce_rest_tmc_commission_exceeds_line', $message, 409);
            }
            // No `RouteException` on this build: still refuse. The route
            // catches `\Exception` too and answers 500 — an ugly refusal, and
            // a refusal is the conservative direction.
            throw new \RuntimeException($message);
        }, 25, 1);
    }

    /** Said once per request, on whichever pay path got there first. */
    private static bool $refused = false;

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
            // THE SAME CALL THE ORDER ITEM'S SETTER MAKES, so the two
            // readings cannot disagree.
            //
            // ⛔ `alpha.62`/`alpha.63` passed `$exponent` here and the order
            // does not: `create_order_line_items()` sets `'total' =>
            // $values['line_total']` (`class-wc-checkout.php:571`) and
            // `WC_Order_Item_Product::set_total()` runs
            // `wc_format_decimal($value)` with NO dp
            // (`class-wc-order-item-product.php:136`) — which normalises and
            // does not round. So a line total of `49999.6` was measured here
            // as `50000` and carried as `49999.6`, and a fixed commission of
            // `50000` passed the basket and failed on the order. Rounding to
            // the currency's exponent is the right thing to SHOW a shopper
            // and the wrong thing to compare a rule against.
            $total = function_exists('wc_format_decimal')
                ? (string) wc_format_decimal($item['line_total'] ?? 0)
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
