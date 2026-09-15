<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\EngagementRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\MarketplaceMessages;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * The two places a coupon and a wholesale ladder stop being rows in a table
 * and start being money in a real basket.
 *
 * Until this existed, both were services with screens and no connection to a
 * purchase, and the delivery said «ساخته‌شده» about them. The owner asked for
 * the two to be reported apart — «وضعیت رابط و اتصال به مسیر واقعی خرید را جدا
 * از وجود سرویس‌ها گزارش کن» — and connecting them is the honest way to close
 * the gap rather than describe it.
 *
 * **Wholesale is a per-item price**, so it is applied in
 * `woocommerce_before_calculate_totals`, where WooCommerce expects a plugin to
 * set a cart item's price and where the quantity is already known. Setting a
 * price and letting WooCommerce do the arithmetic is what keeps tax, shipping
 * and totals consistent; computing a discount ourselves would not.
 *
 * **A coupon is a WooCommerce coupon**, declared through
 * `woocommerce_get_shop_coupon_data` — the extension point WooCommerce
 * provides for exactly this: a code that is not a `shop_coupon` post. The
 * first version of this class claimed the submitted code straight out of the
 * raw request instead and paid for the discount with a negative cart fee. It
 * worked, and it was wrong twice over: it read raw request input outside the
 * one audited reader (the architecture test said so), and the discount was a fee
 * rather than a coupon, so WooCommerce never carried it onto the order, never
 * offered a «حذف» link, and never re-validated it when the basket changed.
 *
 * Scoping is what makes «فروشنده برای محصولات خودش» true in the cart and not
 * only in the service: the virtual coupon carries `product_ids` listing that
 * one vendor's WooCommerce products in this basket, so WooCommerce discounts
 * those lines and nothing else. The shop's own goods and Dokan's keep their
 * price whoever prices them today.
 *
 * The amount is expressed as a **percentage of that vendor's own subtotal**,
 * whatever shape the marketplace coupon has. A percentage is the one form
 * WooCommerce applies per line and re-derives on every recalculation, so a
 * fixed-amount code stays fixed when the quantity changes (the quote is asked
 * again from the new subtotal) and a per-line rounding never compounds. The
 * cost, stated rather than hidden: WooCommerce rounds each line, so its
 * displayed discount can differ from `discount_minor` by one minor unit. The
 * ledger does not inherit that — `recordUses()` re-quotes from the saved order
 * and records the marketplace's own number.
 *
 * A code this plugin does not own is never touched, and neither is a code
 * WooCommerce itself already has a post for: the host shop's coupons keep
 * working exactly as they do today.
 */
final class CartPricing
{
    /**
     * Why a marketplace code was refused, between the moment the coupon is
     * built and the moment WooCommerce asks for the error message.
     *
     * WooCommerce's two filters are separate calls and the refusal reason is
     * not passed between them, so it is remembered here, keyed by code.
     *
     * @var array<string,string>
     */
    private static array $refusals = [];

    public static function register(ContainerInterface $container): void
    {
        // 1. The wholesale ladder: a price per item, before totals are worked out.
        add_action('woocommerce_before_calculate_totals', static function (mixed $cart) use ($container): void {
            if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
                return;
            }
            $wholesale = $container->get(ManageWholesale::class);
            $products = $container->get(ProductRepositoryInterface::class);
            $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            if ($userId <= 0 || !$wholesale->mayBuyWholesale($userId)) {
                return;     // not an approved wholesale buyer: ordinary prices
            }
            foreach ($cart->get_cart() as $item) {
                $product = $item['data'] ?? null;
                if (!is_object($product) || !method_exists($product, 'set_price')) {
                    continue;
                }
                $ours = $products->findByWcProduct((int) $product->get_id());
                if ($ours === null) {
                    continue;   // somebody else's product, priced by somebody else
                }
                $unit = $wholesale->priceFor($userId, $ours->id, (int) ($item['quantity'] ?? 0));
                if ($unit !== null) {
                    // Set, never subtract. WooCommerce recalculates totals
                    // several times per request, and a subtraction would
                    // compound; assigning the ladder's own number is
                    // idempotent however often this runs.
                    $product->set_price((string) ($unit / self::unit()));
                }
            }
        }, 20);

        // 2. A marketplace code, declared to WooCommerce as a coupon it can use.
        add_filter('woocommerce_get_shop_coupon_data', static function (mixed $data, mixed $code) use ($container): mixed {
            return self::couponData($container, $data, is_string($code) ? $code : '');
        }, 10, 2);

        // 3. …and refused, when it is ours but does not apply to this basket.
        add_filter('woocommerce_coupon_is_valid', static function (mixed $valid, mixed $coupon): mixed {
            if (is_object($coupon) && method_exists($coupon, 'get_code')
                && isset(self::$refusals[self::key((string) $coupon->get_code())])) {
                return false;
            }
            return $valid;
        }, 10, 2);

        // 4. …in the marketplace's own words rather than «کد تخفیف وجود ندارد».
        add_filter('woocommerce_coupon_error', static function (mixed $message, mixed $code, mixed $coupon): mixed {
            if (!is_object($coupon) || !method_exists($coupon, 'get_code')) {
                return $message;
            }
            $reason = self::$refusals[self::key((string) $coupon->get_code())] ?? null;
            if ($reason === null) {
                return $message;
            }
            return MarketplaceMessages::notice($reason) ?? $message;
        }, 10, 3);

        // 5. Once the order exists, the use is counted — once per order, by a
        //    unique index rather than by a counter.
        add_action('woocommerce_checkout_order_processed', static function (mixed $orderId) use ($container): void {
            self::recordUses($container, (int) $orderId);
        }, 20, 1);
    }

    /**
     * What WooCommerce should treat this code as, or `$data` untouched.
     *
     * Returning anything truthy makes WooCommerce accept the code as a virtual
     * coupon, so the guards come first and they are deliberately narrow:
     * without a cart there is nothing to scope a vendor's discount to, and a
     * code the host shop already has a post for is the host shop's.
     */
    private static function couponData(ContainerInterface $container, mixed $data, string $rawCode): mixed
    {
        $code = self::key($rawCode);
        unset(self::$refusals[$code]);
        if ($code === '' || !function_exists('WC')) {
            return $data;
        }
        $cart = WC()->cart ?? null;
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return $data;       // wp-admin, WP-CLI: no basket, no scope
        }
        if (function_exists('wc_get_coupon_id_by_code') && (int) wc_get_coupon_id_by_code($rawCode) > 0) {
            return $data;       // WooCommerce's own coupon, WooCommerce's job
        }
        if ($container->get(EngagementRepositoryInterface::class)->findCouponByCode($code) === null) {
            return $data;       // not ours either
        }

        $coupons = $container->get(ManageCoupons::class);
        $customerId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $lines = self::vendorLines($container, $cart);
        $reason = 'coupon_other_vendor';    // nothing of that vendor's is here
        foreach ($lines as $vendorUserId => $line) {
            $quote = $coupons->quote($code, $vendorUserId, $line['subtotal_minor'], $customerId);
            if (!$quote['ok']) {
                $reason = $quote['reason'];
                continue;
            }
            return [
                'discount_type' => 'percent',
                'amount' => self::percentOf($quote['discount_minor'], $line['subtotal_minor']),
                'product_ids' => $line['product_ids'],
                'individual_use' => false,
                'free_shipping' => false,
                'usage_limit' => 0,
            ];
        }

        // Ours, and it does not apply. It is still declared to WooCommerce —
        // a code that "does not exist" is a different and wronger sentence
        // than the reason it was refused for, and the reason is what the
        // shopper can act on.
        self::$refusals[$code] = $reason;
        return ['discount_type' => 'percent', 'amount' => 0, 'product_ids' => []];
    }

    /**
     * Counts this order against each marketplace code it carries.
     *
     * Re-quoted from the ORDER rather than from the basket or the session:
     * what is counted has to be what the customer was actually charged, and
     * the marketplace's own number is what the ledger needs — not WooCommerce's
     * per-line rounding of it.
     */
    private static function recordUses(ContainerInterface $container, int $orderId): void
    {
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order || !method_exists($order, 'get_coupon_codes')) {
            return;
        }
        $coupons = $container->get(ManageCoupons::class);
        $repository = $container->get(EngagementRepositoryInterface::class);
        $products = $container->get(ProductRepositoryInterface::class);
        $customerId = (int) $order->get_customer_id();

        $byVendor = [];
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $ours = $products->findByWcProduct((int) $item->get_product_id());
            if ($ours === null) {
                continue;
            }
            $byVendor[$ours->vendorUserId] = ($byVendor[$ours->vendorUserId] ?? 0)
                + (int) round((float) $item->get_subtotal() * self::unit());
        }

        foreach ($order->get_coupon_codes() as $rawCode) {
            $code = self::key((string) $rawCode);
            $coupon = $repository->findCouponByCode($code);
            if ($coupon === null) {
                continue;       // the host shop's coupon, counted by the host shop
            }
            foreach ($byVendor as $vendorUserId => $subtotal) {
                $quote = $coupons->quote($code, $vendorUserId, $subtotal, $customerId);
                if ($quote['ok'] && $quote['discount_minor'] > 0) {
                    $coupons->recordUse($coupon->id, $orderId, $customerId, $quote['discount_minor']);
                }
            }
        }
    }

    /**
     * Each marketplace vendor's own lines in this basket.
     *
     * Per vendor, never for the whole cart: a code belongs to one shop, and
     * handing it another shop's subtotal is how a vendor's discount ends up
     * paid for by somebody else.
     *
     * The subtotal is computed from the product's CURRENT price rather than
     * from `line_subtotal`, because this runs while WooCommerce is still
     * working the totals out and `line_subtotal` is not written yet. The
     * current price is also the one the wholesale ladder above has already
     * set, so an approved buyer's coupon is quoted against what they are
     * really being charged.
     *
     * @return array<int,array{subtotal_minor:int, product_ids:list<int>}>
     */
    private static function vendorLines(ContainerInterface $container, mixed $cart): array
    {
        $products = $container->get(ProductRepositoryInterface::class);
        $lines = [];
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!is_object($product) || !method_exists($product, 'get_id')) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? $product->get_id());
            $ours = $products->findByWcProduct($productId);
            if ($ours === null) {
                continue;
            }
            $amount = (float) $product->get_price() * (int) ($item['quantity'] ?? 0);
            $vendor = $ours->vendorUserId;
            $lines[$vendor] ??= ['subtotal_minor' => 0, 'product_ids' => []];
            $lines[$vendor]['subtotal_minor'] += (int) round($amount * self::unit());
            if (!in_array($productId, $lines[$vendor]['product_ids'], true)) {
                $lines[$vendor]['product_ids'][] = $productId;
            }
        }
        return $lines;
    }

    /**
     * The marketplace's discount, said as a percentage WooCommerce can apply.
     *
     * Kept at four decimal places: a fixed-amount code on a large subtotal
     * needs the precision, and WooCommerce stores the amount as a decimal
     * string rather than an integer.
     */
    private static function percentOf(int $discountMinor, int $subtotalMinor): string
    {
        if ($subtotalMinor <= 0) {
            return '0';
        }
        return (string) round(min(100, $discountMinor / $subtotalMinor * 100), 4);
    }

    /** The one spelling of a code used everywhere: upper case, trimmed. */
    private static function key(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * How many minor units are in one of WooCommerce's price units.
     *
     * The marketplace holds money in minor units; WooCommerce prices are
     * decimal. `wc_get_price_decimals()` is the site's own answer, so a shop
     * configured for Rials (0 decimals) and one for a 2-decimal currency both
     * come out right instead of one of them being wrong by a hundred.
     */
    private static function unit(): int
    {
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 0;
        return (int) (10 ** max(0, $decimals));
    }
}
