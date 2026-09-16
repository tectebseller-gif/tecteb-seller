<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Order\Application\BuyerVerifierInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;

/**
 * «آیا این شخص این را خریده؟», asked of the WooCommerce order.
 *
 * `boughtLine()` walks marketplace line → WooCommerce order → customer id, and
 * compares. The one subtlety is the guest order: `get_customer_id()` is 0 for
 * one, and 0 is not a user, so the comparison must never be allowed to succeed
 * by both sides being zero. The guard below is explicit about that, because
 * «$a === $b» with two zeroes is exactly the kind of true that looks right in
 * review.
 */
final class WcBuyerVerifier implements BuyerVerifierInterface
{
    public function __construct(private readonly OrderItemRepositoryInterface $items)
    {
    }

    public function isAvailable(): bool
    {
        return function_exists('wc_get_order');
    }

    public function boughtLine(int $orderItemId, int $userId): bool
    {
        if (!$this->isAvailable() || $orderItemId <= 0 || $userId <= 0) {
            return false;
        }
        $item = $this->items->find($orderItemId);
        if ($item === null) {
            return false;
        }
        $order = \wc_get_order($item->orderId);
        if (!$order) {
            return false;
        }
        $customerId = (int) $order->get_customer_id();
        // A guest order has customer id 0. Returning `0 === $userId` would be
        // false anyway because $userId is guarded above — but it is written out
        // rather than relied upon, since the guard above is the kind of line a
        // later refactor moves.
        return $customerId > 0 && $customerId === $userId;
    }

    public function boughtProduct(int $userId, int $wcProductId): bool
    {
        if (!function_exists('wc_customer_bought_product') || $userId <= 0 || $wcProductId <= 0) {
            return false;
        }
        $user = get_userdata($userId);
        return $user !== false && \wc_customer_bought_product((string) $user->user_email, $userId, $wcProductId);
    }
}
