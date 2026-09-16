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
     * The refund this return already has in WooCommerce, or 0.
     *
     * Exists because of one crash: the refund is created and the process dies
     * before its id reaches the marketplace's own row. A retry must find that
     * orphan, not make a second refund.
     */
    public function findExisting(int $wcOrderId, int $returnId): int;

    /**
     * Writes the refund record against ONE order line — or hands back the one
     * a previous, interrupted attempt already made.
     *
     * `$returnId` is a parameter rather than something read out of `$reason`,
     * because it is the idempotency key: it is stamped on the refund inside
     * the same insert that creates it, and it is what `findExisting()` looks
     * for. A key carried inside a human-readable string is a key somebody will
     * reword.
     *
     * `reason` comes back as `refund_recovered` when an orphan was adopted, so
     * a caller can tell «made one» from «found the one I had already made».
     *
     * It comes back as `refund_reconcile_required` — with `candidates` — when
     * an earlier attempt is known to have run and left refunds this class
     * cannot identify as its own. An implementation must NOT create a refund
     * in that case and must NOT adopt one by resemblance: the whole point is
     * that from here the possibilities are indistinguishable, and the thing
     * being guessed about is money.
     *
     * @return array{ok:bool, reason:string, refund_id:int, money_moved?:bool,
     *               remaining?:float, message?:string, candidates?:list<int>}
     */
    public function record(
        int $wcOrderId,
        int $wcOrderItemId,
        float $amount,
        int $quantity,
        string $reason,
        int $returnId
    ): array;
}
