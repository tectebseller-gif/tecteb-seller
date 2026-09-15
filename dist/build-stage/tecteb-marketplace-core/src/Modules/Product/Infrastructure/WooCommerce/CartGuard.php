<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Presentation\PurchaseMessages;

/**
 * The three ways a refused product can still be bought if only the product
 * page is guarded — and the answer to each.
 *
 * PurchaseGuard answers "may this be added?". That leaves:
 *
 *  1. **A basket filled before the stop.** WooCommerce keeps the cart in the
 *     customer's session for days. Without this, a shopper who added a
 *     product on Monday still checks out on Wednesday, after the vendor was
 *     suspended, with no line in the ledger to show for it.
 *  2. **The block cart and checkout.** They do not go through the classic
 *     form handler at all; they speak to the Store API, which has its own
 *     validation points.
 *  3. **The pay link of an unpaid order.** WooCommerce mails `order-pay` links
 *     that stay valid until the order is cancelled. That link can be opened
 *     long after the marketplace stopped selling.
 *
 * Every hook below is scoped the same way the rest of this plugin is: an item
 * that is not the marketplace's own is left exactly as WooCommerce left it —
 * not removed, not flagged, not mentioned. A shop's own cart and Dokan's own
 * orders behave as if this plugin were not installed.
 */
final class CartGuard
{
    /**
     * Store API error codes that mean "this product may not be bought".
     *
     * Only these are rewritten. A quantity error or an out-of-stock error is
     * WooCommerce's own true statement and is left alone.
     *
     * @var list<string>
     */
    private const STORE_API_REFUSALS = ['woocommerce_rest_product_not_purchasable'];

    public static function register(ContainerInterface $container): void
    {
        $policy = static fn (): PurchasePolicy => $container->get(PurchasePolicy::class);

        // 1. The basket that was filled before the stop.
        add_action('woocommerce_cart_loaded_from_session', static function (mixed $cart) use ($policy): void {
            self::pruneCart($policy(), $cart);
        }, 20);

        // …and again when the cart or checkout page actually validates, which
        // is what the block cart calls and what a stale session reaches.
        add_action('woocommerce_check_cart_items', static function () use ($policy): void {
            if (!function_exists('WC') || WC()->cart === null) {
                return;
            }
            self::pruneCart($policy(), WC()->cart);
        }, 20);

        // 2. The Store API, which the block cart and checkout use. Both points
        //    are documented as "throw to refuse"; RouteException is the type
        //    the caller catches and turns into a proper error response.
        add_action('woocommerce_store_api_validate_add_to_cart', static function (mixed $product) use ($policy): void {
            self::refuseOrPass($policy(), $product);
        }, 20, 1);

        add_action('woocommerce_store_api_validate_cart_item', static function (mixed $product) use ($policy): void {
            self::refuseOrPass($policy(), $product);
        }, 20, 1);

        // …and the Store API's OWN refusal, in our words.
        //
        // CartController::validate_add_to_cart() asks `is_purchasable()` first
        // and throws its own English sentence, so the hook above is never
        // reached for a product our filter already refused — the same shape of
        // problem the classic cart had. Measured on a real block cart: the
        // shopper got «"…" is not available for purchase.» and no reason.
        // This rewrites only the MESSAGE of a refusal WooCommerce already
        // made, and only for our own products; the code and the HTTP status
        // stay exactly as the block UI expects them.
        add_filter('rest_request_after_callbacks', static function (mixed $response, mixed $handler, mixed $request) use ($policy): mixed {
            if (!is_object($request) || !method_exists($request, 'get_route')
                || !str_contains((string) $request->get_route(), '/wc/store/')) {
                return $response;
            }
            $productId = (int) ($request['id'] ?? 0);
            if ($productId <= 0 || !function_exists('wc_get_product')) {
                return $response;
            }
            $decision = PurchaseGuard::ask($policy(), wc_get_product($productId));
            if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
                return $response;       // not ours, or refused for some other reason
            }
            $ours = PurchaseMessages::shopper($decision);

            // The route has usually already turned its RouteException into a
            // WP_REST_Response by the time this filter runs (AbstractRoute
            // calls error_to_response before returning), so both shapes are
            // handled rather than guessing which one arrives.
            if ($response instanceof \WP_Error) {
                $code = $response->get_error_code();
                return in_array($code, self::STORE_API_REFUSALS, true)
                    ? new \WP_Error($code, $ours, $response->get_error_data($code))
                    : $response;
            }
            if ($response instanceof \WP_REST_Response) {
                $data = $response->get_data();
                if (is_array($data) && in_array((string) ($data['code'] ?? ''), self::STORE_API_REFUSALS, true)) {
                    $data['message'] = $ours;
                    $response->set_data($data);
                }
            }
            return $response;
        }, 20, 3);

        // 3. The pay link of an order that was never paid.
        add_filter('woocommerce_order_needs_payment', static function (mixed $needsPayment, mixed $order) use ($policy): mixed {
            if ($needsPayment !== true || !self::orderHoldsARefusedItem($policy(), $order)) {
                return $needsPayment;
            }
            // "Does not need payment" is WooCommerce's own way of saying an
            // order cannot be paid for; the pay page then refuses rather than
            // taking money for something that may not be sold.
            return false;
        }, 20, 2);

        add_action('woocommerce_before_pay_action', static function (mixed $order) use ($policy): void {
            if (self::orderHoldsARefusedItem($policy(), $order) && function_exists('wc_add_notice')) {
                wc_add_notice(PurchaseMessages::orderNotPayable(), 'error');
            }
        }, 20, 1);

        // …and on the pay PAGE, which a shopper reaches by opening the link
        // rather than by submitting the form. WooCommerce's own refusal there
        // reads «This order's status is "pending" — it cannot be paid for»,
        // which is both English and untrue-sounding: the status is fine, the
        // product is not. Ours is printed first and says which.
        add_action('before_woocommerce_pay', static function () use ($policy): void {
            $orderId = isset($GLOBALS['wp']->query_vars['order-pay'])
                ? absint($GLOBALS['wp']->query_vars['order-pay'])
                : 0;
            if ($orderId <= 0 || !function_exists('wc_get_order')) {
                return;
            }
            $order = wc_get_order($orderId);
            if ($order && self::orderHoldsARefusedItem($policy(), $order) && function_exists('wc_print_notice')) {
                wc_print_notice(PurchaseMessages::orderNotPayable(), 'error');
            }
        }, 20);
    }

    /**
     * Removes the marketplace items that may no longer be sold, and says so
     * once per item. Anything else in the basket is untouched.
     */
    private static function pruneCart(PurchasePolicy $policy, mixed $cart): void
    {
        if (!is_object($cart) || !method_exists($cart, 'get_cart') || !method_exists($cart, 'remove_cart_item')) {
            return;
        }
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            $decision = PurchaseGuard::ask($policy, $product);
            if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
                continue;       // not ours, or still sellable
            }
            $name = is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : '';
            $cart->remove_cart_item($key);
            if (function_exists('wc_add_notice')) {
                wc_add_notice(PurchaseMessages::removedFromCart($name), 'error');
            }
        }
    }

    /** Throws for a refused marketplace product; returns silently otherwise. */
    private static function refuseOrPass(PurchasePolicy $policy, mixed $product): void
    {
        $decision = PurchaseGuard::ask($policy, $product);
        if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
            return;
        }
        $message = PurchaseMessages::shopper($decision);
        $routeException = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
        if (class_exists($routeException)) {
            // The type the Store API's own validator catches. A plain
            // exception here escapes validate_cart_items() uncaught and the
            // shopper gets a 500 instead of a sentence.
            throw new $routeException('tmc_product_not_purchasable', $message, 409);
        }
        throw new \RuntimeException($message);
    }

    private static function orderHoldsARefusedItem(PurchasePolicy $policy, mixed $order): bool
    {
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return false;
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $decision = PurchaseGuard::ask($policy, $item->get_product());
            if ($decision !== null && $decision !== PurchasePolicy::ALLOWED) {
                return true;
            }
        }
        return false;
    }
}
