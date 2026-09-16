<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * Everything that can change what a shop's public page says, wired to forget
 * that shop's cache.
 *
 * It lives in Infrastructure and not beside the services that do the changing,
 * because those are Application classes and Application may not call
 * WordPress — `get_transient` and friends are exactly the calls the
 * architecture test forbids there. So the services stay pure and the
 * listening happens here, on hooks WordPress already fires.
 *
 * **A cache nobody clears is not a cache, it is a bug with a TTL.** The list
 * below is the honest one: a product saved, published, withdrawn or deleted,
 * and the shop's own settings. It is deliberately generous — forgetting too
 * often costs one render, forgetting too rarely shows a withdrawn product to
 * a customer.
 */
final class StoreCacheInvalidation
{
    public static function register(ContainerInterface $container): void
    {
        // Every path that writes a WooCommerce product ends in one of these,
        // whether it came from this plugin's projector, from wp-admin, or from
        // another plugin entirely. Hooking WooCommerce's own events rather
        // than our service methods is what makes that true.
        foreach (['woocommerce_update_product', 'woocommerce_new_product'] as $hook) {
            add_action($hook, static function ($wcProductId) use ($container): void {
                self::forgetOwnerOf($container, (int) $wcProductId);
            }, 20);
        }
        add_action('deleted_post', static function ($postId, $post = null) use ($container): void {
            if ($post === null || (string) $post->post_type === 'product') {
                self::forgetOwnerOf($container, (int) $postId);
            }
        }, 20, 2);
        // A trashed product still has its row, and its page must stop listing
        // it before the trash is emptied.
        add_action('transition_post_status', static function ($new, $old, $post) use ($container): void {
            if ($post !== null && (string) $post->post_type === 'product' && $new !== $old) {
                self::forgetOwnerOf($container, (int) $post->ID);
            }
        }, 20, 3);
        // The shop's own settings — name, intro, carriers, and the temporary
        // closure. Fired by the repository, so it does not matter who asked
        // for the write: the vendor's own form, a manager, or WP-CLI.
        add_action('tmc_store_settings_saved', static function ($vendorUserId): void {
            StorePageCache::forget((int) $vendorUserId);
        }, 20);
    }

    private static function forgetOwnerOf(ContainerInterface $container, int $wcProductId): void
    {
        if ($wcProductId <= 0) {
            return;
        }
        $product = $container->get(ProductRepositoryInterface::class)->findByWcProduct($wcProductId);
        if ($product !== null) {
            StorePageCache::forget($product->vendorUserId);
        }
    }
}
