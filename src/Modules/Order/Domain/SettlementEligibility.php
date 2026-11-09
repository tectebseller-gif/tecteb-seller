<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * WHETHER A MANAGER MAY DECLARE ONE LINE COMPLETE FOR SETTLEMENT — asked of
 * the order's own payment status, and refusing when it cannot be read.
 *
 * ### The hole this closes, measured
 *
 * `ManageOrderItems::recordSettlementCompletion()` refused exactly one thing:
 * a line whose own status was `cancelled`. Nothing asked whether the buyer's
 * money had ever arrived. So a manager could mark a line of a `pending`, a
 * `failed` or an `on-hold` order complete, the waiting period would elapse,
 * and `VendorBalance` would report the share as `eligible` — money owed on a
 * sale nobody was paid for.
 *
 * `WcOrderDisposition`'s own docblock argued the opposite and the argument
 * was half right: «there is no settleable income … by the design that was
 * already there». What was already there is that nothing SETS completion
 * automatically. That is a true statement about automatic processes and says
 * nothing about the button, and §2-4 is about the button: «جلوگیری از
 * قابل‌تسویه‌شدن مبلغی که وصول آن تأیید نشده است».
 *
 * ### Why this is the same question BACS asks
 *
 * §2-3 asks for the difference between a manager's manual confirmation and
 * real receipt of funds. With a bank transfer WooCommerce leaves the order
 * `on-hold` and a person moves it to `processing` once the transfer lands —
 * so the manager's move IS the receipt, and it is recorded in the one place
 * both systems can see. This rule therefore does not invent a second
 * confirmation: it refuses to treat «the order exists» or «it is on hold» as
 * receipt, and accepts exactly the statuses WooCommerce itself calls paid.
 *
 * ### Reading an absence
 *
 * `null` is «nobody told us», not «not paid» and certainly not «paid». The
 * mirrored column arrived in schema 39 with no backfill on purpose, so a row
 * captured earlier carries nothing; and `CaptureOrder` does not write it, so a
 * freshly captured order that has never changed status carries nothing
 * either. Both refuse here, by name, rather than being inferred from — the
 * inference would even be RIGHT in both cases today, which is exactly what
 * makes it the dangerous kind: a reader that is right by accident goes on
 * being trusted after the accident stops holding (`alpha.76`).
 *
 * ⛔ **And one direction is always allowed: clearing.** Taking a completion
 * back only removes money from `eligible`, so it is refused by nothing here.
 * A completion recorded by mistake on an order that turned out not to be paid
 * has to be undoable without first making the order paid.
 */
final class SettlementEligibility
{
    /** The order was paid; a manager may record completion on its lines. */
    public const OK = 'ok';
    /** The buyer's money has not arrived (pending, on hold, failed, cancelled). */
    public const NOT_RECEIVED = 'order_payment_not_received';
    /** Money came in and went back out; what remains owed is a person's call. */
    public const REFUND_NEEDS_DECISION = 'order_refund_needs_decision';
    /** A status this release has no meaning for — never guessed at. */
    public const STATUS_UNKNOWN = 'order_status_unknown';
    /** Nobody has told us this order's status, which is not «unpaid» either. */
    public const STATUS_UNRECORDED = 'order_status_unrecorded';

    /**
     * @param string|null $wcOrderStatus the order's own status, or null when
     *        it could not be read at all
     * @return array{ok:bool, reason:string, disposition:string}
     */
    public static function forCompletion(?string $wcOrderStatus): array
    {
        if ($wcOrderStatus === null || trim($wcOrderStatus) === '') {
            return [
                'ok' => false,
                'reason' => self::STATUS_UNRECORDED,
                'disposition' => WcOrderDisposition::Unknown->value,
            ];
        }
        $disposition = WcOrderLifecycle::of($wcOrderStatus);
        if ($disposition->isPaid()) {
            return ['ok' => true, 'reason' => self::OK, 'disposition' => $disposition->value];
        }
        $reason = match ($disposition) {
            WcOrderDisposition::Refunded => self::REFUND_NEEDS_DECISION,
            WcOrderDisposition::Unknown => self::STATUS_UNKNOWN,
            default => self::NOT_RECEIVED,
        };
        return ['ok' => false, 'reason' => $reason, 'disposition' => $disposition->value];
    }

    /**
     * Every reason this rule can give for refusing, so a screen can carry a
     * sentence for each without a second list of its own.
     *
     * @return list<string>
     */
    public static function refusals(): array
    {
        return [
            self::NOT_RECEIVED,
            self::REFUND_NEEDS_DECISION,
            self::STATUS_UNKNOWN,
            self::STATUS_UNRECORDED,
        ];
    }
}
