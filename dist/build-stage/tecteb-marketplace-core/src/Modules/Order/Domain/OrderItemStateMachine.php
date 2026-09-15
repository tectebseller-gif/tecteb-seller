<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * Which moves a vendor may make on their own part of an order.
 *
 * Forward only, and cancellation only while nothing has shipped. A shipped
 * item that could be "cancelled" by the shop would leave a package in the
 * world with no record that it was sent — and the money for it already in the
 * ledger.
 */
final class OrderItemStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'placed' => ['preparing', 'cancelled'],
        'preparing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function canMove(OrderItemStatus $from, OrderItemStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<OrderItemStatus> the moves a vendor may offer right now */
    public function nextFrom(OrderItemStatus $from): array
    {
        $next = [];
        foreach (self::ALLOWED[$from->value] ?? [] as $value) {
            $status = OrderItemStatus::tryFrom($value);
            if ($status !== null) {
                $next[] = $status;
            }
        }
        return $next;
    }

    /** Shipping needs somewhere to ship from and something to track by. */
    public function requiresCarrier(OrderItemStatus $to): bool
    {
        return $to === OrderItemStatus::Shipped;
    }
}
