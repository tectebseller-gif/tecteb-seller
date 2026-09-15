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
    /**
     * @var array<string, list<string>>
     *
     * `partially_shipped` is reachable only from a state where nothing has
     * shipped yet, and leads to `shipped` when the last parcel goes out. It is
     * never SET by a person: ShipItems derives it from the parcel rows and
     * moves the line here, which is why it is in the table at all.
     *
     * Note what `partially_shipped` cannot do: it cannot be cancelled. Half a
     * line is in the world already, and a "cancelled" line with a parcel on a
     * courier's van is a record that contradicts a delivery.
     */
    private const ALLOWED = [
        'placed' => ['preparing', 'partially_shipped', 'shipped', 'cancelled'],
        'preparing' => ['partially_shipped', 'shipped', 'cancelled'],
        'partially_shipped' => ['partially_shipped', 'shipped', 'delivered'],
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
        return $to === OrderItemStatus::Shipped || $to === OrderItemStatus::PartiallyShipped;
    }

    /**
     * The moves a PERSON may choose, which is not the same list.
     *
     * A vendor moves a line to «preparing», «delivered» or «cancelled» by
     * pressing a button. They reach «partially_shipped» and «shipped» by
     * recording a parcel, because both of those are statements about
     * quantities that have to add up.
     *
     * @return list<OrderItemStatus>
     */
    public function manualMovesFrom(OrderItemStatus $from): array
    {
        return array_values(array_filter(
            $this->nextFrom($from),
            static fn (OrderItemStatus $to): bool => $to !== OrderItemStatus::Shipped
                && $to !== OrderItemStatus::PartiallyShipped
        ));
    }
}
