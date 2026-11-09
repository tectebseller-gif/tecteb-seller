<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck;
use Tecteb\Marketplace\Modules\Finance\Application\VendorFundedDiscount;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Marketplace\Application\EngagementRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
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
        // Held so the discount half can resolve the coupon rows without a
        // sixth argument on five hook signatures. Assigned here rather than
        // read from a global: the hooks below already close over `$container`,
        // and the static is only for the helpers they call.
        self::$container = $container;
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
            $refusal = self::refusalOn($policy(), $check(), $order);
            if ($refusal !== null) {
                self::$refused = true;
                wc_add_notice($refusal['message'], 'error');
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
            $refusal = $order ? self::refusalOn($policy(), $check(), $order) : null;
            if ($refusal !== null) {
                self::$refused = true;
                wc_print_notice($refusal['message'], 'error');
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
            $refusal = self::refusalOn($policy(), $check(), $order);
            if ($refusal !== null) {
                throw new \Exception($refusal['message']);
            }
        }, 25, 3);

        // BOTH STORE API ROUTES, after the order is written and before the
        // payment branch. Throwing here is the only answer that cannot be
        // read as «پولی لازم نیست»: the route turns it into a status code and
        // neither `process_payment()` nor `process_without_payment()` runs.
        add_action('woocommerce_store_api_checkout_order_processed', static function (mixed $order) use ($policy, $check): void {
            $refusal = self::refusalOn($policy(), $check(), $order);
            if ($refusal === null) {
                return;
            }
            $message = $refusal['message'];
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
            $vendor = PurchaseGuard::marketplaceProduct($policy, $product);
            if ($vendor === null) {
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
            $name = is_object($product) && method_exists($product, 'get_name')
                ? (string) $product->get_name()
                : '';
            if (!$answer['ok']) {
                wc_add_notice(self::message($name), 'error');
                continue;
            }
            // ⛔ AND THE SECOND QUESTION, asked at the same point and in the
            // same loop: did somebody else's coupon take money off this
            // vendor's line? See `VendorFundedDiscount` for the arithmetic —
            // a discount on a marketplace line is split between Tecteb's
            // commission and the vendor's share, so an admin's site-wide
            // `shop_coupon` is funded partly by a vendor who never agreed.
            $discount = self::discountAnswerForCart($cart, $item, $vendor);
            if ($discount !== null && !$discount['ok']) {
                wc_add_notice(self::discountMessage($name), 'error');
            }
        }
    }

    /**
     * The discount question for one CART line, or null when it cannot be
     * asked at all.
     *
     * Null rather than a refusal when the cart cannot say what a line cost
     * before coupons: `VendorFundedDiscount` refuses an unreadable PAIR of
     * amounts, which is the case where one is known and the other is not;
     * a basket that exposes neither key is a WooCommerce this release has not
     * met, and refusing every line of it would break a shop over a shape
     * rather than over money.
     *
     * @param array<string,mixed> $item
     * @return array{ok:bool, reason:string, discount_minor:int, codes:list<string>}|null
     */
    private static function discountAnswerForCart(mixed $cart, array $item, Product $vendor): ?array
    {
        if (!array_key_exists('line_subtotal', $item) && !array_key_exists('line_total', $item)) {
            return null;
        }
        ['currency' => $currency, 'exponent' => $exponent] = self::unit();
        if ($exponent < 0) {
            return null;
        }
        unset($currency);
        $applied = method_exists($cart, 'get_applied_coupons') ? (array) $cart->get_applied_coupons() : [];
        return VendorFundedDiscount::check(
            self::minor($item['line_subtotal'] ?? null, $exponent),
            self::minor($item['line_total'] ?? null, $exponent),
            self::foreignCodes($applied, $vendor->vendorUserId),
            $applied !== []
        );
    }

    /**
     * Exact minor units, or null when the figure is not exactly representable.
     *
     * The same posture as everywhere else money is read in this plugin: a
     * fractional amount is not rounded into one that looks exact
     * (`alpha.67`). Null travels into the rule, which refuses on it.
     */
    private static function minor(mixed $amount, int $exponent): ?int
    {
        if ($amount === null) {
            return null;
        }
        $text = function_exists('wc_format_decimal')
            ? (string) wc_format_decimal($amount)
            : (is_scalar($amount) ? (string) $amount : '');
        return $text === '' ? null : DecimalAmount::toMinor($text, $exponent);
    }

    /**
     * Applied codes that are NOT this vendor's own marketplace codes.
     *
     * Two tests, cheapest first, and the first one is WooCommerce's own:
     * `wc_get_coupon_id_by_code()` answers above nought for a real
     * `shop_coupon` post, which is exactly the test
     * `CartPricing::couponData()` uses to step aside for the host shop's
     * coupons. Such a code can never be a marketplace one, so it is foreign
     * without asking the database.
     *
     * Anything else may be a marketplace code, and whose it is comes from the
     * coupon row. **A code nothing can resolve is foreign** — the direction
     * that refuses rather than the one that sells — and so is every code when
     * the marketplace module is not even registered, which is right: with no
     * marketplace coupons in existence, no discount can be one.
     *
     * @param list<mixed> $applied
     * @return list<string>
     */
    private static function foreignCodes(array $applied, int $vendorUserId): array
    {
        $foreign = [];
        $repository = self::coupons();
        foreach ($applied as $raw) {
            $code = is_scalar($raw) ? trim((string) $raw) : '';
            if ($code === '') {
                continue;
            }
            if (function_exists('wc_get_coupon_id_by_code') && (int) wc_get_coupon_id_by_code($code) > 0) {
                $foreign[] = $code;         // the host shop's own coupon post
                continue;
            }
            $coupon = $repository?->findCouponByCode(strtolower($code));
            if ($coupon === null || $coupon->vendorUserId !== $vendorUserId) {
                $foreign[] = $code;
            }
        }
        return $foreign;
    }

    /**
     * The coupon rows, or null when nothing has bound them.
     *
     * Held per request rather than passed through five hook signatures. Null
     * is a real state — the marketplace module can be switched off — and the
     * caller reads it as «no code is a marketplace code», which is true.
     */
    private static ?ContainerInterface $container = null;

    private static function coupons(): ?EngagementRepositoryInterface
    {
        $c = self::$container;
        if ($c === null || !$c->has(EngagementRepositoryInterface::class)) {
            return null;
        }
        try {
            $repository = $c->get(EngagementRepositoryInterface::class);
        } catch (\Throwable) {
            return null;
        }
        return $repository instanceof EngagementRepositoryInterface ? $repository : null;
    }

    /**
     * ONE SENTENCE FOR THE DISCOUNT REFUSAL — and it names the one thing the
     * shopper can do.
     *
     * Different from `message()` on purpose. That one is «this item's selling
     * settings need review», which is true of a commission rule and useless
     * here: a shopper holding a coupon that cannot be used needs to be told
     * that it is the coupon, because removing it is a thing they can do.
     * Neither sentence names a rate, a share or an account.
     */
    public static function discountMessage(string $productName): string
    {
        return $productName === ''
            ? __(
                'کد تخفیفی که وارد کرده‌اید روی یکی از کالاهای سبد قابل استفاده نیست. آن را بردارید یا با پشتیبانی تماس بگیرید.',
                'tecteb-marketplace-core'
            )
            : sprintf(
                /* translators: %s: product name */
                __(
                    'کد تخفیفی که وارد کرده‌اید روی «%s» قابل استفاده نیست. آن را بردارید یا با پشتیبانی تماس بگیرید.',
                    'tecteb-marketplace-core'
                ),
                $productName
            );
    }

    /**
     * The first line of an UNPAID order this rule refuses, and WHICH sentence
     * to say about it — or null when nothing is refused.
     *
     * Reads the order's own stored totals, which are already exact decimal
     * text, so nothing is re-derived from a float here.
     *
     * ⛔ The sentence travels with the refusal rather than being chosen by the
     * caller. The two refusals need different words — one is «this item's
     * selling settings need review», the other «your coupon cannot be used
     * here» — and a caller that picked for itself would hand a shopper
     * holding a bad coupon a sentence they can do nothing about.
     *
     * @return array{name:string, message:string}|null
     */
    private static function refusalOn(PurchasePolicy $policy, CommissionLineCheck $check, mixed $order): ?array
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return null;
        }
        $currency = method_exists($order, 'get_currency') ? (string) $order->get_currency() : '';
        $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : -1;
        if ($currency === '' || $exponent < 0) {
            return null;
        }
        $applied = method_exists($order, 'get_coupon_codes') ? (array) $order->get_coupon_codes() : [];
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $vendor = PurchaseGuard::marketplaceProduct($policy, $product);
            if ($vendor === null) {
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
                $name = (string) $item->get_name();
                return ['name' => $name, 'message' => self::message($name)];
            }
            // ⛔ The discount question on the ORDER's own stored amounts, and
            // it is not redundant with the cart's for exactly the reason the
            // commission half is not: a plugin on
            // `woocommerce_checkout_create_order_line_item` can change a line
            // total after the basket was checked, and an order's stored
            // currency need not be the shop's current one.
            if (!method_exists($item, 'get_subtotal')) {
                continue;
            }
            $discount = VendorFundedDiscount::check(
                self::minor($item->get_subtotal(), $exponent),
                self::minor($item->get_total(), $exponent),
                self::foreignCodes($applied, $vendor->vendorUserId),
                $applied !== []
            );
            if (!$discount['ok']) {
                $name = (string) $item->get_name();
                return ['name' => $name, 'message' => self::discountMessage($name)];
            }
        }
        return null;
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
