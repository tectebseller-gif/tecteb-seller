<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Modules\LoadReport;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Modules\Order\Application\UnrecordableOrder;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;

/**
 * Marks the order that was created while nothing could record it.
 *
 * ⛔ **Registered OUTSIDE the order module, and that is the whole point.**
 * `ModuleLoader` asks a self-gated module `blockedReason()` BEFORE
 * `register()`, so when the order module is blocked neither `register()` nor
 * `boot()` runs — `OrderServices` never binds and `OrderHooks` never attaches.
 * A guard for that state therefore cannot live inside the module it is
 * guarding against. It is wired from `Bootstrap`, next to the notices, for
 * the same reason `CartGuard` is wired from the product module: «the
 * marketplace may not sell» has to be enforceable exactly when the order
 * module is not there to enforce it.
 *
 * **What it hooks and why all three.** `OrderHooks` captures on the same
 * three, which is the list of ways an order comes into existence:
 * `woocommerce_checkout_order_processed` (classic),
 * `woocommerce_store_api_checkout_order_processed` (blocks) and
 * `woocommerce_new_order` (everything else — wp-admin, WP-CLI, another
 * plugin's `wc_create_order()`). The first two are also guarded earlier, at
 * the cart, by `CartGuard`; the third is the one with no earlier door, and it
 * is the reason this class exists.
 *
 * **It never throws and never changes the order.** Not the status, not the
 * stock, not an item. Every hook here fires after the order row exists, so a
 * refusal at this point would abandon an order a shopper may have paid for —
 * and `ReturnStatus`/`CaptureOrder` have both paid for the lesson that a
 * half-written financial state is worse than a recorded problem. The refusal
 * belongs at the cart, where it already is.
 *
 * **Scoped, in the only way that holds.** `PurchasePolicy::decide()` answers
 * `NOT_OURS` for anything with no `tmc_products` row, and this class acts only
 * on orders where at least one line answered something else. An order of the
 * host shop's own goods, or of Dokan's, gets no mark, no option entry and no
 * audit row — tested by a control, because a guard that commented on every
 * order would be a worse defect than the gap it closes (`alpha.60`).
 */
final class UnrecordableOrderGuard
{
    /** The three ways an order comes into existence, same list as OrderHooks. */
    private const HOOKS = [
        'woocommerce_checkout_order_processed',
        'woocommerce_store_api_checkout_order_processed',
        'woocommerce_new_order',
    ];

    public static function register(ContainerInterface $container): void
    {
        $mark = static function (mixed $orderId) use ($container): void {
            self::mark($container, $orderId);
        };
        foreach (self::HOOKS as $hook) {
            // Priority 10, below OrderHooks' 20. They are mutually exclusive —
            // one runs only while the other is absent — but the lower number
            // keeps the reading order «mark, then capture» rather than leaving
            // it to chance if that ever stops being true.
            add_action($hook, $mark, 10, 1);
        }
    }

    /**
     * One order, asked and answered. Swallows everything: a marketplace defect
     * must never be the reason a customer's order fails to save.
     */
    private static function mark(ContainerInterface $container, mixed $orderId): void
    {
        try {
            $orderId = (int) (is_object($orderId) && method_exists($orderId, 'get_id')
                ? $orderId->get_id()
                : $orderId);
            if ($orderId <= 0 || !function_exists('wc_get_order')) {
                return;
            }
            $report = self::report();
            $status = $report?->status('order');
            // ⛔ Unknown is read as ACTIVE, and this is the one guard in the
            // plugin whose conservative direction points that way. A report
            // nobody could read does not say the module is blocked, and
            // marking every order on a working site «unrecordable» would be a
            // louder and costlier error than missing the mark on one — the
            // `alpha.60` rule, where a guard wider than the defect is the
            // bigger defect. The measurement that does NOT depend on this
            // question is `ReconcileOrders` class one, which reads the orders
            // themselves; that is what makes erring this way affordable.
            //
            // Planned counts as inactive: a module whose manifest was
            // reserved and never added has no hooks either.
            if (!in_array($status, [
                ModuleStatus::Blocked,
                ModuleStatus::Degraded,
                ModuleStatus::Planned,
            ], true)) {
                return;
            }
            $order = wc_get_order($orderId);
            if (!is_object($order) || !method_exists($order, 'get_items')) {
                return;
            }
            $policy = $container->get(PurchasePolicy::class);
            $lines = 0;
            $vendors = [];
            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                    continue;
                }
                // The PARENT id, because that is the id `tmc_products` holds
                // and the id `WcOrderReader` records as `wc_product_id`; a
                // variation is carried beside it, never instead of it.
                $decision = $policy->decide((int) $item->get_product_id());
                if ($decision['decision'] === PurchasePolicy::NOT_OURS) {
                    continue;
                }
                $lines++;
                $product = $decision['product'];
                if ($product !== null) {
                    $vendors[] = $product->vendorUserId;
                }
            }
            $verdict = UnrecordableOrder::judge(
                false,
                (string) ($report?->state('order')?->reasonDetail
                    ?? $report?->state('order')?->reasonCode
                    ?? ''),
                $lines,
                $vendors
            );
            if ($verdict['verdict'] !== UnrecordableOrder::UNRECORDABLE) {
                return;
            }
            self::writeMark($order, $orderId, $verdict);
            self::writeAudit($container, $orderId, $verdict);
        } catch (\Throwable) {
            // Deliberate and narrow: see the class docblock. The order-side
            // scan in `ReconcileOrders` is the reader that does not depend on
            // this method having worked.
        }
    }

    /**
     * @param array{verdict:string, reason:string, lines:int, vendors:list<int>} $verdict
     */
    private static function writeMark(object $order, int $orderId, array $verdict): void
    {
        if (method_exists($order, 'update_meta_data') && method_exists($order, 'save')) {
            $order->update_meta_data(UnrecordableOrder::META_KEY, [
                'reason' => $verdict['reason'],
                'lines' => $verdict['lines'],
                'vendors' => $verdict['vendors'],
            ]);
            // Saves through the order's own data store, so this works the same
            // under HPOS and the legacy post table — the plugin declares HPOS
            // compatible and must not reach past the CRUD layer to prove it.
            $order->save();
        }
        if (function_exists('get_option') && function_exists('update_option')) {
            $stored = get_option(UnrecordableOrder::REGISTER_OPTION, []);
            update_option(
                UnrecordableOrder::REGISTER_OPTION,
                UnrecordableOrder::register(is_array($stored) ? array_values($stored) : [], $orderId),
                false
            );
        }
    }

    /**
     * @param array{verdict:string, reason:string, lines:int, vendors:list<int>} $verdict
     */
    private static function writeAudit(ContainerInterface $container, int $orderId, array $verdict): void
    {
        $container->get(AuditLogger::class)->log(
            AuditEventCatalog::ORDER_UNRECORDABLE,
            0,
            'order',
            (string) $orderId,
            [
                'wc_order_id' => $orderId,
                'reason' => $verdict['reason'],
                'lines' => $verdict['lines'],
                'vendors' => implode(',', $verdict['vendors']),
            ]
        );
    }

    private static function report(): ?LoadReport
    {
        if (!class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)) {
            return null;
        }
        return \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::report();
    }
}
