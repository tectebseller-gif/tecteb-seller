<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * The commercial terms of a return — and the honest admission that they are
 * not decided.
 *
 * DEC-03 has not fixed the return window, who pays return postage, or whether
 * a restocking fee applies. The owner's instruction is explicit: «مدت و شرایط
 * تجاری مرجوعیِ تعیین‌نشده را حدس نزن». So this class does not compute them.
 * It exists to be asked, and to answer «تعیین‌نشده» in a way a screen can show
 * and a service can act on.
 *
 * What that means in practice, and why the return module still works:
 *
 *  - **No automatic eligibility.** Nothing expires, nothing auto-approves and
 *    nothing is refused for being «too late». Every request goes to a manager,
 *    who decides with the facts in front of them. A default window would be
 *    this plugin choosing DEC-03 by implication.
 *  - **No fee, no shipping refund.** The amount reversed is arithmetic from
 *    the line's own snapshot — unit price × quantity, plus that quantity's
 *    share of the tax that was collected. Whether the customer also gets the
 *    delivery charge back, and whether anything is deducted, is a term, not a
 *    calculation, and is left at «ثبت‌نشده» rather than at zero.
 *
 * When the owner decides DEC-03, this is the one place that changes, and the
 * services around it already carry the quantity, the money and the history
 * the decision will need.
 */
final class ReturnTerms
{
    public const WINDOW_UNDECIDED = 'return_window_undecided';
    public const SHIPPING_REFUND_UNDECIDED = 'return_shipping_refund_undecided';
    public const FEE_UNDECIDED = 'return_fee_undecided';

    /** @var list<string> every term this module refuses to invent */
    public const OPEN_TERMS = [
        self::WINDOW_UNDECIDED,
        self::SHIPPING_REFUND_UNDECIDED,
        self::FEE_UNDECIDED,
    ];

    /** The approved decision that has to close before any of these is a rule. */
    public const DECISION = 'DEC-03';

    /**
     * Whether a return may be judged automatically. It may not, today.
     *
     * Returned as a method rather than as a constant so that the day DEC-03
     * closes, the callers do not change — only what this answers does.
     */
    public function decidesAutomatically(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function openTerms(): array
    {
        return self::OPEN_TERMS;
    }
}
