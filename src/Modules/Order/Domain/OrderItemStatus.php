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
    /**
     * Some of the line is on its way and some is not.
     *
     * Derived from the shipment rows, never set by hand: a line of three with
     * two parcels totalling two units is here, and marking the whole line
     * «ارسال شد» on the first parcel is a lie the customer acts on.
     */
    case PartiallyShipped = 'partially_shipped';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public static function all(): array
    {
        return [
            self::Placed, self::Preparing, self::PartiallyShipped,
            self::Shipped, self::Delivered, self::Cancelled,
        ];
    }

    public function isOpen(): bool
    {
        return $this === self::Placed || $this === self::Preparing
            || $this === self::PartiallyShipped;
    }

    /** Whether any of this line's goods have left the vendor. */
    public function hasShipped(): bool
    {
        return $this === self::PartiallyShipped || $this === self::Shipped
            || $this === self::Delivered;
    }

    public function isFinished(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }
}
