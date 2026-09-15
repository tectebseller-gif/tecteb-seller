<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * The status of ONE vendor's part of an order (UX §7.1).
 *
 * Deliberately per item rather than per order: a basket can hold three
 * shops' goods, each shipping on its own day, and a single order status
 * would either lie about two of them or force them to move together.
 *
 * The payment status is NOT here. That belongs to WooCommerce and the vendor
 * sees it read-only (UX §7.2) — a vendor who could mark an order paid would
 * be a vendor who could be paid twice.
 */
enum OrderItemStatus: string
{
    case Placed = 'placed';
    case Preparing = 'preparing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Placed, self::Preparing, self::Shipped, self::Delivered, self::Cancelled];
    }

    public function isOpen(): bool
    {
        return $this === self::Placed || $this === self::Preparing;
    }

    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }
}
