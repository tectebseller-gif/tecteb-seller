<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * What the word «بازپرداخت» covers in this plugin — and, more importantly,
 * what it does not.
 *
 * A refund is four separate things, and this marketplace performs two of them.
 * Saying «بازگشت مالی ثبت شد» without saying which two is how a manager comes
 * to believe a customer has their money back when nobody has sent it. The
 * owner asked for exactly this to be spelled out: «بدون درگاه واقعی، انتقال
 * پول کامل اعلام نشود».
 *
 *  1. **LEDGER** — the marketplace's own books. Reversing entries are written
 *     with their own event key, naming the accrual lines they undo. Nothing is
 *     edited or deleted. **Done here.**
 *  2. **STOCK** — the goods going back on WooCommerce's shelf, when the
 *     manager says they were received. **Done here, on request.**
 *  3. **WOOCOMMERCE REFUND RECORD** — a `WC_Order_Refund` against the order.
 *     **Done here since `alpha.11`, but only when a manager asks**, never as
 *     part of the refund itself. It was withheld entirely until it had been
 *     read out of WooCommerce rather than assumed: `wc_create_refund()` takes
 *     `refund_payment => false` by default and calls no gateway at all, so the
 *     record can be written honestly without moving anybody's money. It is
 *     still a separate, deliberate step, because a record created alongside
 *     the ledger reversal would look like proof of (4).
 *  4. **THE MONEY** — the actual transfer back to the customer, through the
 *     gateway or the bank. **Not done here and cannot be**: there is no real
 *     gateway adapter in this build (no sender, no gateway, Alpha). It is a
 *     human step, and every screen that reports a refund says so.
 *
 * The four are named rather than implied so that a later build which does gain
 * a gateway changes this class and the sentences that quote it, instead of
 * quietly changing what an old message meant.
 *
 * **«Performed» is three-valued, not two.** An earlier version of this class
 * had only PERFORMED and NOT_PERFORMED, and (2) and (3) fit neither: both are
 * real capabilities that happen only when somebody asks for them. Filing them
 * under «not performed» would understate the plugin, and under «performed»
 * would promise they happen by themselves. They have their own list.
 */
final class RefundScope
{
    /** Written by the refund itself, every time. */
    public const LEDGER_REVERSAL = 'ledger_reversal';

    /** Real, and done only when somebody asks for it. */
    public const STOCK_CORRECTION = 'stock_correction';
    public const WC_REFUND_RECORD = 'woocommerce_refund_record';

    /** What no build of this plugin has ever been able to do. */
    public const MONEY_TRANSFER = 'money_transfer';

    /** @var list<string> happens as part of the refund, unasked */
    public const PERFORMED = [self::LEDGER_REVERSAL];

    /** @var list<string> capabilities that wait for an explicit decision */
    public const ON_REQUEST = [self::STOCK_CORRECTION, self::WC_REFUND_RECORD];

    /** @var list<string> */
    public const NOT_PERFORMED = [self::MONEY_TRANSFER];

    /**
     * Whether a real payment gateway exists to move money through.
     *
     * A method rather than a constant so the day an adapter arrives, the
     * callers do not change — only the answer does. Today there is none, and
     * no build of this plugin has ever had one.
     */
    public function canTransferMoney(): bool
    {
        return false;
    }

    /**
     * @return array<string,bool> each part of a refund, and whether this
     *         plugin did it
     */
    public function parts(bool $restocked, bool $wcRefundLinked): array
    {
        return [
            self::LEDGER_REVERSAL => true,
            self::STOCK_CORRECTION => $restocked,
            self::WC_REFUND_RECORD => $wcRefundLinked,
            self::MONEY_TRANSFER => $this->canTransferMoney(),
        ];
    }
}
