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
 *     **Not created here**, on purpose: creating one can call the payment
 *     gateway's refund API, and this plugin has no mandate to move a
 *     customer's money. A manager who creates it in WooCommerce can record its
 *     id against the return, and the unique index makes that link one-to-one.
 *  4. **THE MONEY** — the actual transfer back to the customer, through the
 *     gateway or the bank. **Not done here and cannot be**: there is no real
 *     gateway adapter in this build (no sender, no gateway, Alpha). It is a
 *     human step, and every screen that reports a refund says so.
 *
 * The four are named rather than implied so that a later build which does gain
 * a gateway changes this class and the sentences that quote it, instead of
 * quietly changing what an old message meant.
 */
final class RefundScope
{
    /** What this plugin actually performs. */
    public const LEDGER_REVERSAL = 'ledger_reversal';
    public const STOCK_CORRECTION = 'stock_correction';

    /** What it deliberately leaves to a person. */
    public const WC_REFUND_RECORD = 'woocommerce_refund_record';
    public const MONEY_TRANSFER = 'money_transfer';

    /** @var list<string> */
    public const PERFORMED = [self::LEDGER_REVERSAL, self::STOCK_CORRECTION];

    /** @var list<string> */
    public const NOT_PERFORMED = [self::WC_REFUND_RECORD, self::MONEY_TRANSFER];

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
