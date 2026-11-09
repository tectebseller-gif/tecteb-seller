<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\OrderLineReaderInterface;

/**
 * The seventh reconciliation check's only source — WooCommerce itself.
 *
 * ⛔ **`wc_get_orders()` AND NOT A QUERY.** Orders live in `wp_posts` on one
 * installation and in their own tables on another, and the owner's staging is
 * the second. A hand-written query would be right on one of them, which is
 * exactly the defect `OrderAdminPath` exists to document — so the storage
 * question is left to WooCommerce, every time.
 *
 * **Reads only.** No `save`, no status change, no meta write: this class is
 * two getters, and the reconciliation that uses it has no writer to give the
 * answers to.
 *
 * `null` rather than `[]` on any failure, because «WooCommerce could not tell
 * me» and «there are no orders» are different facts and only one of them lets
 * a reconciliation call itself complete.
 */
final class WcOrderLineReader implements OrderLineReaderInterface
{
    public function recentOrderIds(int $limit): ?array
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        try {
            $ids = wc_get_orders([
                'limit' => max(1, $limit),
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'ids',
                // Every status, deliberately: an order that failed to record
                // is just as likely to be `cancelled` or `failed` as paid, and
                // narrowing here would hide the window the audit is for.
                'status' => 'any',
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($ids)) {
            return null;
        }
        $out = [];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    public function productIdsIn(int $wcOrderId): ?array
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }
        try {
            $order = wc_get_order($wcOrderId);
        } catch (\Throwable) {
            return null;
        }
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return null;
        }
        $out = [];
        try {
            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                    continue;
                }
                $productId = (int) $item->get_product_id();
                if ($productId > 0) {
                    $out[] = $productId;
                }
                // The variation too, because a marketplace product may be
                // linked by either and a line that named only the parent
                // would read as «nothing of ours» on a variable product.
                if (method_exists($item, 'get_variation_id')) {
                    $variationId = (int) $item->get_variation_id();
                    if ($variationId > 0) {
                        $out[] = $variationId;
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }
        return array_values(array_unique($out));
    }
}
