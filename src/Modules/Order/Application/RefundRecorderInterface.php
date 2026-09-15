<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * The WooCommerce refund RECORD — the third of the four things
 * `RefundScope` names, kept apart from the fourth.
 *
 * It is its own interface rather than a method on the return service because
 * the two halves of «بازپرداخت» have different dependencies, and an interface
 * that could do both would invite a caller to assume it did.
 *
 *  - **Recording** needs the order, an amount within what the order still
 *    owes, and a decision. No gateway.
 *  - **Moving the money** needs a gateway that supports refunds and a stored
 *    transaction id. This build has no gateway adapter, so it is never done —
 *    and `moneyBlockers()` says which pieces are missing rather than leaving
 *    a screen to say «درگاه نیست» and hope that covers it.
 *
 * Every implementation must leave stock alone: the marketplace restocks when
 * the goods are recorded RECEIVED, and a second restock here would count the
 * same units twice.
 */
interface RefundRecorderInterface
{
    /** Whether WooCommerce is present to record against at all. */
    public function isAvailable(): bool;

    /** Whether the money itself could be sent. False in every build so far. */
    public function canTransferMoney(int $wcOrderId): bool;

    /**
     * What is missing before money could move, as keys a screen can translate.
     *
     * @return list<string>
     */
    public function moneyBlockers(int $wcOrderId): array;

    /**
     * Writes the refund record against ONE order line.
     *
     * @return array{ok:bool, reason:string, refund_id:int, money_moved?:bool,
     *               remaining?:float, message?:string}
     */
    public function record(int $wcOrderId, int $wcOrderItemId, float $amount, int $quantity, string $reason): array;
}
