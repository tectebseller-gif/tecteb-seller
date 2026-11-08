<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * What a WooCommerce order's own status means for the marketplace's record of
 * it.
 *
 * Two statuses, not one. WooCommerce owns the ORDER's status — paid, cancelled,
 * refunded — and `OrderItemStatus` owns one vendor's part of it: placed,
 * preparing, shipped. Neither can be derived from the other, which is why this
 * enum exists rather than a boolean: a basket can hold three shops' goods, two
 * of them already on a courier's van, and the order being cancelled says
 * nothing about where those parcels are.
 *
 * ⛔ **Measured on staging, and the reason this class was written.** Order
 * 12300 was captured, the vendor's share was recorded, and the owner then
 * cancelled it in WooCommerce. WooCommerce said `Cancelled`; the marketplace
 * went on reporting the share as recorded, the same share as pending, and two
 * items awaiting shipment. Nothing was wrong with the reports: every total
 * already excludes a line whose own status is `cancelled`
 * (`DbOrderItemRepository::settlementView()` and `totalsForVendor()` both carry
 * `status <> 'cancelled'`). What was missing was anything that ever SET it.
 *
 * **Five answers, and four of them write nothing.** The one that writes is the
 * unambiguous case; everything else is named and left to a person, because the
 * alternative is a plugin that decides what happened to somebody's money from
 * a status flip:
 *
 *   - `AwaitingPayment` — the order exists and nothing has been paid. The
 *     accrual STANDS: the line was captured at checkout by design (FIN-03
 *     wants one capture, and the only moment every checkout path passes
 *     through is before payment), and `VendorBalance` already reports it as
 *     `pending`/`awaiting_completion` because no manager has recorded
 *     completion. So there is no settleable income and no valid debt, which is
 *     what §3-4 asks for — by the design that was already there, not by a new
 *     rule.
 *   - `PaymentFailed` — the same, deliberately. A failed payment is retried
 *     far more often than it is abandoned, and reversing here would mean
 *     re-accruing on the retry: either a second document under a second key,
 *     or a key the unique index refuses. A status that moves back and forth
 *     must not move money back and forth.
 *   - `Paid` — nothing to do. The vendor's own item status is theirs to move.
 *   - `Void` — the sale did not happen. The accrual is reversed with its own
 *     document and the line is cancelled, which takes it out of every total.
 *   - `Refunded` — NOT handled automatically, and this is the owner's rule
 *     verbatim: «ثبت لغو را با استرداد واقعی یکی نگیر؛ تعیین تکلیف پول باید
 *     بر اساس جریان ووکامرس و تصمیم مدیر مجاز باشد». Money has moved somewhere
 *     this plugin cannot see. Its instrument for that is `ManageReturns`, which
 *     a named manager drives.
 *   - `Unknown` — a status this release does not know. Writes nothing, says so.
 *     Plugins add order statuses freely, and the safe answer to «what does
 *     `wc-awaiting-shipment` mean» is not to guess.
 */
enum WcOrderDisposition: string
{
    case AwaitingPayment = 'awaiting_payment';
    case PaymentFailed = 'payment_failed';
    case Paid = 'paid';
    case Void = 'void';
    case Refunded = 'refunded';
    case Unknown = 'unknown';

    /**
     * The one disposition that reverses an accrual on its own.
     *
     * Deliberately narrow. Everything that is not this is either «nothing
     * happened to the money» or «a person decides», and a reversal written
     * from any other answer would be this plugin deciding.
     */
    public function voidsTheAccrual(): bool
    {
        return $this === self::Void;
    }

    /** Whether WooCommerce says this order has been paid for. */
    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    /**
     * Whether a person has to look at this one.
     *
     * `Refunded` because money moved outside this plugin, and `Unknown`
     * because a status nobody has mapped is not a status to act on. Both are
     * reported; neither is acted on.
     */
    public function needsADecision(): bool
    {
        return $this === self::Refunded || $this === self::Unknown;
    }
}
