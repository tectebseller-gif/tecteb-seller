<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\SyncOrderLifecycle;
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

        // ⛔ AND THE ORDER'S OWN STATUS, which until `alpha.99` nothing read.
        //
        // The hook above was already here and all it did was refresh the
        // stock mirror, so a cancelled order left the marketplace reporting
        // the share as recorded, the same share as pending, and the line
        // still awaiting shipment — measured by the owner on staging, order
        // 12300. The stock half is kept exactly as it was; this is the other
        // half of the same signal.
        //
        // Two hooks, because WooCommerce fires them for different things and
        // the marketplace has to react exactly once either way:
        //   - `woocommerce_order_status_changed` for every ordinary move;
        //   - `woocommerce_trash_order` for the one transition that is
        //     WordPress's rather than WooCommerce's, and which no status
        //     change announces.
        // The sync is idempotent — the reversing document collides on the
        // ledger's unique index and the status change is a no-op on a line
        // already cancelled — so overlapping hooks are harmless by
        // construction rather than by ordering luck, the same property the
        // capture has.
        add_action('woocommerce_order_status_changed', static function (mixed $orderId, mixed $from, mixed $to) use ($container): void {
            self::syncLifecycle($container, $orderId, is_string($to) ? $to : '');
        }, 20, 3);
        add_action('woocommerce_trash_order', static function (mixed $orderId) use ($container): void {
            self::syncLifecycle($container, $orderId, 'trash');
        }, 20, 1);

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

    /**
     * Reads the order's status as a meaning and acts on the one that is
     * unambiguous.
     *
     * The status is taken from the HOOK rather than from the order object: on
     * `woocommerce_order_status_changed` the object may not have been saved
     * with the new value yet, and reading it back would answer about the old
     * one. `trash` has no status argument at all, which is why its caller
     * passes the word.
     */
    private static function syncLifecycle(ContainerInterface $container, mixed $orderId, string $status): void
    {
        $id = is_object($orderId) && method_exists($orderId, 'get_id')
            ? (int) $orderId->get_id()
            : (int) $orderId;
        if ($id <= 0) {
            return;
        }
        try {
            $resolved = $status !== '' ? $status : self::statusOf($orderId);
            if ($resolved === '') {
                return;
            }
            $container->get(SyncOrderLifecycle::class)->sync($id, $resolved);
        } catch (\Throwable) {
            // The same posture as the capture's: a sync that throws must not
            // break a manager's click in wp-admin. The audit row says what was
            // and was not done, and the next status change on this order runs
            // the whole thing again from the rows as they stand.
        }
    }

    private static function statusOf(mixed $orderOrId): string
    {
        $order = self::resolveOrder($orderOrId);
        return $order !== null && method_exists($order, 'get_status') ? (string) $order->get_status() : '';
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
            // The unit travels with the order. Until `alpha.39` this call
            // passed only the lines, so `CaptureOrder` fell back to its
            // `'IRR', 0` default and an order placed in any other currency was
            // recorded under a currency nobody had read. The reader puts the
            // same pair on every line as well, and `CaptureOrder` refuses a
            // line whose unit it could not read rather than storing a guess.
            $unit = $reader->unit($order);
            $container->get(CaptureOrder::class)->capture(
                (int) $order->get_id(),
                $reader->lines($order),
                $unit['currency'],
                max(0, $unit['exponent'])
            );
        } catch (\Throwable) {
            // A capture that throws must not break the customer's checkout.
            // The order exists in WooCommerce either way; the marketplace's
            // own record is rebuilt by the next hook that fires for it, and
            // the audit log shows what was and was not recorded.
        }
    }
}
