<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * Where the marketplace listens to WooCommerce.
 *
 * Registered ONLY when the gate said the module may operate, so there is no
 * request in which these run while money cannot be recorded.
 *
 * Three hooks, because a WooCommerce order can be born in three ways and the
 * marketplace has to record it exactly once whichever it was:
 *   - the classic checkout,
 *   - the Store API (block checkout),
 *   - anything programmatic (WP-CLI, an importer, a test).
 * The capture is idempotent on the order-item id, so overlapping hooks are
 * harmless by construction rather than by ordering luck.
 */
final class OrderHooks
{
    public static function register(ContainerInterface $container): void
    {
        $capture = static function (mixed $orderOrId) use ($container): void {
            self::capture($container, $orderOrId);
        };
        add_action('woocommerce_checkout_order_processed', $capture, 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', $capture, 20, 1);
        add_action('woocommerce_new_order', $capture, 20, 1);

        // Stock moves LATER than the order is created: WooCommerce reduces it
        // when the order reaches a paid status, and restores it on a cancel.
        // Without these the marketplace's mirror would keep showing the
        // pre-sale number — measured on a real site, not assumed.
        $refresh = static function (mixed $orderOrId) use ($container): void {
            self::refreshStock($container, $orderOrId);
        };
        add_action('woocommerce_reduce_order_stock', $refresh, 20, 1);
        add_action('woocommerce_restore_order_stock', $refresh, 20, 1);
        add_action('woocommerce_order_status_changed', $refresh, 20, 1);

        $area = new OrderArea($container);
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($area): array {
            $views[] = [
                'slug' => OrderArea::SLUG,
                'label' => __('سفارش‌ها', 'tecteb-marketplace-core'),
                'title' => __('سفارش‌های فروشگاه', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $area->render($view),
                'actions' => OrderArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $area->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $area->ordersUrl(),
                'nav' => true,
            ];
            return $views;
        });
    }

    private static function refreshStock(ContainerInterface $container, mixed $orderOrId): void
    {
        $order = self::resolveOrder($orderOrId);
        if ($order === null) {
            return;
        }
        try {
            $container->get(CaptureOrder::class)->refreshStock(
                $container->get(WcOrderReader::class)->lines($order)
            );
        } catch (\Throwable) {
            // A mirror that failed to refresh is a stale number on one
            // dashboard, not a reason to break a customer's order.
        }
    }

    private static function resolveOrder(mixed $orderOrId): mixed
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }
        $order = is_object($orderOrId) ? $orderOrId : wc_get_order((int) $orderOrId);
        return $order && method_exists($order, 'get_id') ? $order : null;
    }

    private static function capture(ContainerInterface $container, mixed $orderOrId): void
    {
        $order = self::resolveOrder($orderOrId);
        if ($order === null) {
            return;
        }
        try {
            $reader = $container->get(WcOrderReader::class);
            $container->get(CaptureOrder::class)->capture(
                (int) $order->get_id(),
                $reader->lines($order)
            );
        } catch (\Throwable) {
            // A capture that throws must not break the customer's checkout.
            // The order exists in WooCommerce either way; the marketplace's
            // own record is rebuilt by the next hook that fires for it, and
            // the audit log shows what was and was not recorded.
        }
    }
}
