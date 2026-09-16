<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ProductReviewGatewayInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * «نظر فقط برای خریدار واقعی است» (Master A.5) — enforced on marketplace
 * products, and on nothing else.
 *
 * WooCommerce has a site-wide setting for this
 * (`woocommerce_review_rating_verification_required`), and using it would have
 * been one line. It is not used, for the reason every other guard in this
 * plugin exists: that setting belongs to the SHOP. Turning it on would change
 * the rules for the shop's own products and for every Dokan vendor's, which is
 * not this plugin's to decide — and turning it off again later would silently
 * take the marketplace's rule away with it.
 *
 * So the rule is applied per product, and only where this marketplace owns the
 * product. Three places, because WooCommerce offers three different doors into
 * the same act:
 *
 *  1. `preprocess_comment` — the post itself. The last gate before a comment
 *     row exists, and the only one a direct POST cannot go around.
 *  2. `woocommerce_product_review_comment_form_args` — the FORM. Refusing a
 *     review after somebody has written it is a worse experience than not
 *     offering the box, so the box is not offered.
 *  3. `woocommerce_review_gravatar_size` is NOT hooked, and neither is
 *     anything cosmetic: this class changes who may write, never how anything
 *     looks.
 *
 * A review that is already in the database is never touched. This is a rule
 * about new reviews; retro-applying it would delete things people wrote under
 * the rules that existed when they wrote them.
 */
final class PurchaseOnlyReviews
{
    public static function register(ContainerInterface $container): void
    {
        add_filter('preprocess_comment', static function (array $data) use ($container): array {
            return self::guard($container, $data);
        }, 5);

        add_filter('comments_open', static function (bool $open, $postId) use ($container): bool {
            return self::formOpen($container, $open, (int) $postId);
        }, 10, 2);
    }

    /**
     * The gate. Dies with a sentence rather than returning silently, because a
     * comment form that appears to succeed and stores nothing is the worst of
     * the three possible behaviours.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function guard(ContainerInterface $container, array $data): array
    {
        $postId = (int) ($data['comment_post_ID'] ?? 0);
        if ($postId <= 0 || get_post_type($postId) !== 'product') {
            return $data;
        }
        // Not a marketplace product: whoever owns it makes its rules.
        if ($container->get(ProductRepositoryInterface::class)->findByWcProduct($postId) === null) {
            return $data;
        }
        // A reply, including this plugin's own vendor answers, is not a review
        // and carries no rating. Only the top-level review is gated.
        if ((int) ($data['comment_parent'] ?? 0) > 0) {
            return $data;
        }
        $userId = (int) ($data['user_ID'] ?? 0);
        if ($userId > 0 && $container->get(ProductReviewGatewayInterface::class)->canReview($userId, $postId)) {
            return $data;
        }
        wp_die(
            esc_html__('نظر دربارهٔ این کالا فقط از کسی پذیرفته می‌شود که همان کالا را خریده باشد. اگر با حساب دیگری خرید کرده‌اید، با همان حساب وارد شوید.', 'tecteb-marketplace-core'),
            esc_html__('ثبت نظر انجام نشد', 'tecteb-marketplace-core'),
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * Whether to offer the form at all.
     *
     * Only ever narrows: a product whose comments the shop has already closed
     * stays closed. Nothing here can open a form WooCommerce meant to be shut.
     */
    private static function formOpen(ContainerInterface $container, bool $open, int $postId): bool
    {
        if (!$open || $postId <= 0 || !is_singular('product')) {
            return $open;
        }
        if ($container->get(ProductRepositoryInterface::class)->findByWcProduct($postId) === null) {
            return $open;
        }
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            // A signed-out visitor cannot be proved to have bought anything.
            // The form is hidden; WooCommerce's own «برای ثبت نظر وارد شوید»
            // is what remains, which is the right next step.
            return false;
        }
        return $container->get(ProductReviewGatewayInterface::class)->canReview($userId, $postId);
    }
}
