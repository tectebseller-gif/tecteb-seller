<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\ReviewMessages;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StorePage;

/**
 * The buyer's half of «نظرات و امتیاز»: where a shopper rates the SHOP, and
 * where a shop's standing is shown to shoppers.
 *
 * Two placements, both on hooks WooCommerce already fires:
 *
 *  - **«حساب من» → the orders a shopper has actually placed.** The form is
 *    attached to a specific purchased line, not to a shop, so there is no way
 *    to reach it without having bought something — and the thing rated is the
 *    thing bought. A line already rated shows what was said instead of a
 *    second form.
 *  - **The product page → the shop's standing**, next to the product's own
 *    stars and never mixed into them. Two numbers, two labels, because Master
 *    A.5 says «امتیاز محصول و فروشنده جداست» and a shopper reading one average
 *    would not know which.
 *
 * No endpoint is registered and no permalink is flushed, so deactivating this
 * plugin cannot leave a 404 behind — the same rule the wholesale screens keep.
 *
 * Nothing here posts a PRODUCT review. WooCommerce's own review form already
 * does that, on its own page, with its own moderation; adding a second one
 * would be a second place for the same row to come from. What this plugin adds
 * to that form is a rule, not a field — see `PurchaseOnlyReviews`.
 */
final class RatingStorefront
{
    public const ACTION = 'tmc_rate_vendor';
    public const NONCE_FIELD = 'tmc_rating_nonce';
    public const NOTICE_ARG = 'tmc_rating';

    public static function register(ContainerInterface $container): void
    {
        add_action('template_redirect', static function () use ($container): void {
            self::handle($container);
        }, 5);

        add_action('woocommerce_order_details_after_order_table', static function ($order) use ($container): void {
            echo self::orderSection($container, $order);   // phpcs:ignore WordPress.Security.EscapeOutput
        }, 20);

        add_action('woocommerce_single_product_summary', static function () use ($container): void {
            echo self::vendorStanding($container);         // phpcs:ignore WordPress.Security.EscapeOutput
        }, 26);
    }

    private static function handle(ContainerInterface $container): void
    {
        $request = Request::capture();
        if (!$request->isPost() || $request->postKey('tmc_action') !== self::ACTION) {
            return;
        }
        if (!$request->nonceOk(self::NONCE_FIELD, self::ACTION)) {
            return;
        }
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            return;
        }
        $result = $container->get(ManageReviews::class)->rateVendor(
            $userId,
            $request->postInt('order_item_id'),
            $request->postInt('stars'),
            $request->postTextarea('rating_body')
        );
        // Back to the page they were on. PRG, so a refresh does not re-post a
        // rating the unique index would then refuse and confuse them with.
        $back = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg(self::NOTICE_ARG, rawurlencode($result->code), $back));
        exit;
    }

    /** One order's marketplace lines, each with its rating or its form. */
    private static function orderSection(ContainerInterface $container, $order): string
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0 || !is_object($order) || !method_exists($order, 'get_id')) {
            return '';
        }
        $lines = $container->get(OrderItemRepositoryInterface::class)->forOrder((int) $order->get_id());
        if ($lines === []) {
            // Not a marketplace order — the shop's own or a Dokan vendor's.
            // Nothing is printed at all, rather than an empty heading.
            return '';
        }
        $reviews = $container->get(ManageReviews::class);
        $html = '<section class="tmc-rating"><h2>'
            . esc_html__('امتیاز به فروشگاه', 'tecteb-marketplace-core') . '</h2>';

        $notice = Request::capture()->queryText(self::NOTICE_ARG);
        if ($notice !== '') {
            $tone = in_array($notice, ReviewMessages::errorCodes(), true) ? 'woocommerce-error' : 'woocommerce-message';
            $html .= '<p class="' . esc_attr($tone) . '" role="status">'
                . esc_html(ReviewMessages::notice($notice) ?? $notice) . '</p>';
        }

        // One shop per line, and the shop's name rather than its user id: the
        // shopper bought from «داروخانهٔ ...», not from user 42.
        $anything = false;
        foreach ($lines as $line) {
            if (!$reviews->mayRateVendor($userId, $line->id)) {
                continue;
            }
            $anything = true;
            $html .= self::form($line->id, (string) $line->title, self::shopName($line->vendorUserId));
        }
        if (!$anything) {
            $html .= '<p>' . esc_html__('برای کالاهای این سفارش امتیاز ثبت شده است. امتیاز هر خرید یک‌بار ثبت می‌شود.', 'tecteb-marketplace-core') . '</p>';
        }
        return $html . '</section>';
    }

    private static function form(int $orderItemId, string $title, string $shop): string
    {
        $id = 'tmc-rating-' . $orderItemId;
        $html = '<form method="post" class="woocommerce-form tmc-rating__form">'
            . wp_nonce_field(self::ACTION, self::NONCE_FIELD, true, false)
            . '<input type="hidden" name="tmc_action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="order_item_id" value="' . esc_attr((string) $orderItemId) . '">'
            . '<p>' . esc_html(sprintf(
                /* translators: 1: the product's title, 2: the shop's name */
                __('«%1$s» را از فروشگاه %2$s خریدید.', 'tecteb-marketplace-core'),
                $title,
                $shop
            )) . '</p>'
            . '<p class="woocommerce-form-row"><label for="' . esc_attr($id) . '-stars">'
            . esc_html__('امتیاز شما به این فروشگاه', 'tecteb-marketplace-core')
            . '</label><select id="' . esc_attr($id) . '-stars" name="stars" class="woocommerce-Input" required>';
        for ($star = VendorRating::MAX_STARS; $star >= VendorRating::MIN_STARS; $star--) {
            $html .= '<option value="' . esc_attr((string) $star) . '">'
                . esc_html(ReviewMessages::stars($star * 100)) . '</option>';
        }
        return $html . '</select></p>'
            . '<p class="woocommerce-form-row"><label for="' . esc_attr($id) . '-body">'
            . esc_html__('توضیح (اختیاری)', 'tecteb-marketplace-core')
            . '</label><textarea id="' . esc_attr($id) . '-body" name="rating_body" rows="3" class="woocommerce-Input input-text"></textarea></p>'
            . '<p class="tmc-rating__hint">'
            . esc_html__('امتیاز شما پس از بررسی مدیر روی صفحهٔ فروشگاه نشان داده می‌شود. هر خرید یک‌بار.', 'tecteb-marketplace-core')
            . '</p>'
            . '<p><button type="submit" class="button">'
            . esc_html__('ثبت امتیاز', 'tecteb-marketplace-core') . '</button></p></form>';
    }

    /**
     * The selling shop's standing, on the product page, beside the product's
     * own — never merged with it.
     */
    private static function vendorStanding(ContainerInterface $container): string
    {
        $productId = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
        if ($productId <= 0) {
            return '';
        }
        $product = $container->get(ProductRepositoryInterface::class)->findByWcProduct($productId);
        if ($product === null) {
            // Somebody else's product. Nothing is added, nothing is read.
            return '';
        }
        $standing = $container->get(ManageReviews::class)->standing($product->vendorUserId);
        if ($standing['vendor']['count'] <= 0) {
            // No score yet — but the shop still has a page, and a shopper
            // still wants to see who they are buying from.
            return '<p class="tmc-vendor-standing"><a href="'
                . esc_url(StorePage::url($product->vendorUserId)) . '">'
                . esc_html(sprintf(
                    /* translators: %s: the shop's name */
                    __('صفحهٔ فروشگاه %s', 'tecteb-marketplace-core'),
                    self::shopName($product->vendorUserId)
                ))
                . '</a></p>';
        }
        // The shop's name links to its public page. Without this the page
        // built for A.5 would exist and be reachable by nobody: a shopper has
        // no way to guess a query var, and there is no menu on a storefront.
        return '<p class="tmc-vendor-standing">'
            . esc_html(sprintf(
                /* translators: 1: the shop's star average, 2: how many ratings */
                __('امتیاز فروشگاه: %1$s از ۵ (%2$s)', 'tecteb-marketplace-core'),
                ReviewMessages::stars($standing['vendor']['average_hundredths']),
                ReviewMessages::count($standing['vendor']['count'])
            ))
            . ' <a class="tmc-vendor-standing__link" href="'
            . esc_url(StorePage::url($product->vendorUserId)) . '">'
            . esc_html(sprintf(
                /* translators: %s: the shop's name */
                __('صفحهٔ فروشگاه %s', 'tecteb-marketplace-core'),
                self::shopName($product->vendorUserId)
            ))
            . '</a> <span class="tmc-vendor-standing__note">'
            . esc_html__('(جدا از امتیاز خود کالا)', 'tecteb-marketplace-core')
            . '</span></p>';
    }

    private static function shopName(int $vendorUserId): string
    {
        $user = get_userdata($vendorUserId);
        return $user ? (string) $user->display_name : (string) $vendorUserId;
    }
}
