<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * The seven states of a withdrawal, exactly as FIN-05 lists them.
 *
 * `ReconciliationRequired` is the one that usually goes missing from designs
 * like this, and it is the one that matters most: a money transfer can answer
 * neither yes nor no. FIN-05 is explicit that such a request must NOT be
 * retried automatically — a retry on an unknown outcome is how a vendor gets
 * paid twice — so it has its own terminal-until-a-human-looks state.
 */
enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Reviewing = 'reviewing';
    case Approved = 'approved';
    case PaymentInProgress = 'payment_in_progress';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case ReconciliationRequired = 'reconciliation_required';

    /** @return list<self> */
    public static function all(): array
    {
        return [
            self::Requested, self::Reviewing, self::Approved, self::PaymentInProgress,
            self::Paid, self::Rejected, self::Cancelled, self::ReconciliationRequired,
        ];
    }

    /**
     * Whether this request still holds its lines.
     *
     * An open request is the one the unique index counts (one per vendor), and
     * its lines may not be reserved by any other request.
     */
    public function isOpen(): bool
    {
        return !in_array($this, [self::Paid, self::Rejected, self::Cancelled], true);
    }

    /**
     * Whether money may still be un-reserved without an accounting entry.
     *
     * Once payment has started, FIN-05 stops treating a cancel as free: the
     * reservation is released atomically only BEFORE that point.
     */
    public function releasableWithoutCompensation(): bool
    {
        return in_array($this, [self::Requested, self::Reviewing, self::Approved], true);
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
