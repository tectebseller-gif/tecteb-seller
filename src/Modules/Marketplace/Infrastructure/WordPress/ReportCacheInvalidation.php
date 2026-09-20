<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * Everything that can change a figure, wired to retire that shop's reports.
 *
 * It lives in Infrastructure for the reason `StoreCacheInvalidation` does:
 * the services that do the changing are Application classes, and Application
 * may not call WordPress. So the services stay pure, fire a plain action from
 * their Infrastructure repository, and the listening happens here.
 *
 * **The single hook that matters is `tmc_vendor_figures_changed`.** Every
 * repository that writes a table a report reads fires it with the shop's id.
 * That is one name to grep for rather than a list of hooks maintained in two
 * places — and `ReportCacheContractTest` asserts that each of those
 * repositories still fires it, so a new write path cannot go quiet.
 *
 * Product events come in through WooCommerce's own hooks as well, because a
 * product can be written by wp-admin or by another plugin without passing
 * through any repository of ours — the same reasoning that put
 * `woocommerce_update_product` in the store page's invalidation.
 *
 * Forgetting too often costs one recomputation. Forgetting too rarely shows a
 * vendor a balance that is not theirs any more, which is the whole point.
 */
final class ReportCacheInvalidation
{
    public const HOOK = 'tmc_vendor_figures_changed';

    public static function register(ContainerInterface $container): void
    {
        add_action(self::HOOK, static function ($vendorUserId) use ($container): void {
            self::forget($container, (int) $vendorUserId);
        }, 20);

        foreach (['woocommerce_update_product', 'woocommerce_new_product'] as $hook) {
            add_action($hook, static function ($wcProductId) use ($container): void {
                $product = $container->get(ProductRepositoryInterface::class)
                    ->findByWcProduct((int) $wcProductId);
                if ($product !== null) {
                    self::forget($container, $product->vendorUserId);
                }
            }, 20);
        }

        // An order reaching a terminal state moves money for every shop that
        // had a line in it, and the lines are ours to look up.
        foreach (['woocommerce_order_status_changed', 'woocommerce_order_refunded'] as $hook) {
            add_action($hook, static function ($orderId) use ($container): void {
                self::forgetEveryShopIn($container, (int) $orderId);
            }, 20);
        }
    }

    private static function forget(ContainerInterface $container, int $vendorUserId): void
    {
        if ($vendorUserId <= 0 || !$container->has(Reports::class)) {
            return;
        }
        $reports = $container->get(Reports::class);
        $reports->forgetVendor($vendorUserId);
        // The marketplace-wide pair is a sum over shops, so any shop's change
        // is its change too. One extra option write, and the alternative is a
        // manager's total that lags behind the vendor's own screen.
        $reports->forgetMarketplace();
    }

    private static function forgetEveryShopIn(ContainerInterface $container, int $wcOrderId): void
    {
        if ($wcOrderId <= 0) {
            return;
        }
        $items = \Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class;
        if (!$container->has($items)) {
            return;
        }
        foreach ($container->get($items)->forOrder($wcOrderId) as $line) {
            self::forget($container, (int) $line->vendorUserId);
        }
    }
}
