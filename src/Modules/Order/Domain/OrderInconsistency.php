<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * What can be wrong between a WooCommerce order and this marketplace's books.
 *
 * ⛔ **WHY THIS LIST EXISTS.** The owner's §5: the hooks that record an order
 * swallow exceptions so a customer's checkout does not break, and that is the
 * right trade — but it means a WooCommerce order can exist while the line or
 * the financial document behind it does not, and **nothing would ever say
 * so**. «تعیین کن اگر سفارش ووکامرس ایجاد شد ولی ثبت قلم یا سند مالی بازارگاه
 * شکست خورد، چگونه سیستم آن را کشف می‌کند» — this is the answer: a named
 * class per failure shape, so a manager reads a finding rather than a silence.
 *
 * **Every one of them is a report.** Nothing in this module repairs anything:
 * «هیچ اصلاح مالی خودکاری روی داده واقعی انجام نده». The two states that LOOK
 * like a verdict and are not are the important ones — `UNCHECKED` is «I could
 * not ask», which is never printed as «clean».
 */
enum OrderInconsistency: string
{
    /** A WooCommerce order holding a marketplace product with no line here. */
    case OrderWithoutLine = 'order_without_line';

    /** A line that was recorded while its financial document was not. */
    case LineWithoutAccrual = 'line_without_accrual';

    /** One accrual key on more than one line, or more rows than its shape. */
    case DuplicateAccrual = 'duplicate_accrual';

    /** A cancelled line whose obligation was never reversed. */
    case CancelledWithoutReversal = 'cancelled_without_reversal';

    /** A reversing document beside a line that is not cancelled. */
    case ReversalWithoutCancel = 'reversal_without_cancel';

    /** An event whose rows do not sum to nought, or mix units. */
    case UnbalancedEvent = 'unbalanced_event';

    /** A line whose vendor or status does not agree with its own record. */
    case VendorOrStatusMismatch = 'vendor_or_status_mismatch';

    /**
     * ⛔ NOT A FINDING AND NOT A CLEAN BILL. «نتوانستم بپرسم».
     *
     * A reconciliation that answered «nothing wrong» because it could not
     * look is the one answer this control must never give: it would be read
     * as proof, and `alpha.97` paid for exactly that mistake on a readiness
     * gate — `staff_decided` said «done» off a failed read for nine rounds.
     */
    case Unchecked = 'unchecked';

    /** Whether a finding means money may be wrong, rather than only records. */
    public function touchesMoney(): bool
    {
        return match ($this) {
            self::LineWithoutAccrual, self::DuplicateAccrual,
            self::CancelledWithoutReversal, self::ReversalWithoutCancel,
            self::UnbalancedEvent => true,
            self::OrderWithoutLine, self::VendorOrStatusMismatch, self::Unchecked => false,
        };
    }

    /** @return list<self> every real finding, in the order §5 names them */
    public static function findings(): array
    {
        return [
            self::OrderWithoutLine,
            self::LineWithoutAccrual,
            self::DuplicateAccrual,
            self::CancelledWithoutReversal,
            self::ReversalWithoutCancel,
            self::UnbalancedEvent,
            self::VendorOrStatusMismatch,
        ];
    }
}
