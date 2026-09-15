<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;

/**
 * The marketplace's answer to WooCommerce's "can this be bought?".
 *
 * Every filter below returns `$default` UNCHANGED for anything that is not a
 * marketplace product. That is not a nicety: the shop's own products and
 * Dokan's products must behave exactly as they did before this plugin was
 * installed, including while the marketplace itself refuses to sell.
 *
 * Registered from the PRODUCT module, not the order module, and deliberately:
 * the order module does not register at all while it is blocked, and "blocked"
 * is precisely when the catalogue must refuse to sell.
 */
final class PurchaseGuard
{
    public static function register(ContainerInterface $container): void
    {
        $policy = static fn (): PurchasePolicy => $container->get(PurchasePolicy::class);

        add_filter('woocommerce_is_purchasable', static function (mixed $purchasable, mixed $product) use ($policy): mixed {
            return self::decide($policy(), $product, $purchasable);
        }, 20, 2);

        add_filter('woocommerce_variation_is_purchasable', static function (mixed $purchasable, mixed $variation) use ($policy): mixed {
            return self::decide($policy(), $variation, $purchasable);
        }, 20, 2);

        // WooCommerce refuses an unpurchasable product ITSELF, before it ever
        // runs the validation filter below, and the sentence it uses is its
        // own generic English one. Found by adding a refused product to a
        // basket in a browser: the shopper was told «Sorry, this product
        // cannot be purchased» on a Persian marketplace, with no hint that a
        // suspension or an unsettled commission rule was the reason. These
        // two filters replace that message — and ONLY for our own products.
        add_filter('woocommerce_cart_product_cannot_be_purchased_message', static function (mixed $message, mixed $product) use ($policy): mixed {
            return self::explain($policy(), $product, $message);
        }, 20, 2);

        add_filter('woocommerce_cart_product_out_of_stock_message', static function (mixed $message, mixed $product) use ($policy): mixed {
            return self::explain($policy(), $product, $message);
        }, 20, 2);

        // …and the page itself says it, where the shopper actually is.
        //
        // An unpurchasable product renders NO add-to-cart button, so without
        // this the page shows a price and no way to buy it and never says
        // why. The notice above only reaches a shopper who already has a
        // WooCommerce session; somebody whose first click is on a refused
        // product has none, and WooCommerce drops the queued notice.
        add_action('woocommerce_single_product_summary', static function () use ($policy): void {
            $product = function_exists('wc_get_product') ? wc_get_product() : null;
            $decision = self::ask($policy(), $product);
            if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
                return;
            }
            // WooCommerce's own notice class, so the theme styles it without
            // this plugin shipping a stylesheet to the storefront.
            printf(
                '<div class="woocommerce-info tmc-purchase-blocked" role="status">%s</div>',
                esc_html(self::reason($decision))
            );
        }, 31);

        // The last line of defence: a product can become unsellable between
        // the page render and the click, and a stale "add to cart" must fail
        // with a sentence rather than succeed.
        add_filter('woocommerce_add_to_cart_validation', static function (
            mixed $passed,
            mixed $productId,
            mixed $quantity = 1,
            mixed $variationId = 0
        ) use ($policy, $container): mixed {
            if ($passed === false) {
                return $passed;
            }
            $candidate = (int) $variationId > 0 ? (int) $variationId : (int) $productId;
            $decision = $policy()->decide($candidate);
            if ($decision['decision'] === PurchasePolicy::NOT_OURS && (int) $variationId > 0) {
                $decision = $policy()->decide((int) $productId);
            }
            if (in_array($decision['decision'], [PurchasePolicy::NOT_OURS, PurchasePolicy::ALLOWED], true)) {
                return $passed;
            }
            if (function_exists('wc_add_notice')) {
                wc_add_notice(self::reason($decision['decision']), 'error');
            }
            $product = $decision['product'];
            if ($product !== null) {
                $container->get(AuditLogger::class)->log(
                    AuditEventCatalog::ORDER_BLOCKED,
                    0,
                    'product',
                    (string) $product->id,
                    [
                        'product_id' => $product->id,
                        'vendor_id' => $product->vendorUserId,
                        'reason' => $decision['decision'],
                    ]
                );
            }
            return false;
        }, 20, 4);
    }

    /**
     * Our own sentence in place of WooCommerce's refusal — for our products.
     *
     * Anything else keeps the message the shop already showed, which is the
     * same rule the filters above follow: the marketplace never speaks for
     * somebody else's catalogue.
     *
     * @return mixed WooCommerce's own message for anything that is not ours
     */
    private static function explain(PurchasePolicy $policy, mixed $product, mixed $message): mixed
    {
        $decision = self::ask($policy, $product);
        if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
            return $message;
        }
        return self::reason($decision);
    }

    /**
     * The catalogue's verdict on a WooCommerce product object, asking the
     * parent for a variation, or null when it is not a marketplace product.
     */
    private static function ask(PurchasePolicy $policy, mixed $product): ?string
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return null;
        }
        $decision = $policy->decide((int) $product->get_id());
        if ($decision['decision'] !== PurchasePolicy::NOT_OURS) {
            return $decision['decision'];
        }
        // A variation carries the parent's marketplace link, so ask again
        // with the parent before concluding this is somebody else's.
        $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        if ($parentId <= 0) {
            return null;
        }
        $parent = $policy->decide($parentId);
        return $parent['decision'] === PurchasePolicy::NOT_OURS ? null : $parent['decision'];
    }

    /** @return mixed the untouched default for anything that is not ours */
    private static function decide(PurchasePolicy $policy, mixed $product, mixed $default): mixed
    {
        $decision = self::ask($policy, $product);
        if ($decision === null || $decision === PurchasePolicy::ALLOWED) {
            return $default;
        }
        return false;
    }

    private static function reason(string $decision): string
    {
        return match ($decision) {
            PurchasePolicy::ORDERS_BLOCKED => __('فروش محصولات بازارگاه هنوز فعال نشده است: تا تعیین نرخ کمیسیون و بسته‌شدن تصمیم‌های مالی، این کالا قابل خرید نیست.', 'tecteb-marketplace-core'),
            PurchasePolicy::VENDOR_STOPPED => __('فروشندهٔ این کالا فعلاً تعلیق است و کالاهایش قابل خرید نیستند.', 'tecteb-marketplace-core'),
            PurchasePolicy::NOT_PUBLISHED => __('این کالا در حال حاضر منتشر نیست.', 'tecteb-marketplace-core'),
            PurchasePolicy::OUT_OF_STOCK => __('این کالا ناموجود است.', 'tecteb-marketplace-core'),
            default => __('این کالا قابل خرید نیست.', 'tecteb-marketplace-core'),
        };
    }
}
