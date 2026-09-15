<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * Where a return request has got to.
 *
 * The states are about the GOODS and the MONEY, and nothing else. There is no
 * «eligible» or «expired» state, because eligibility depends on a return
 * window that DEC-03 has not set, and a state machine that could expire a
 * request would be this plugin choosing that window by implication.
 */
enum ReturnStatus: string
{
    /** The customer, the vendor or a manager asked. Nobody has decided. */
    case Requested = 'requested';
    /** A manager agreed to take the goods back. No money has moved. */
    case Approved = 'approved';
    /** A manager said no, with a reason. Terminal. */
    case Rejected = 'rejected';
    /** The goods are back. Stock may be corrected from here. */
    case Received = 'received';
    /** The money was reversed in the ledger. Terminal, and reached once. */
    case Refunded = 'refunded';
    /** Withdrawn before a decision, or after one. Terminal, nothing moved. */
    case Cancelled = 'cancelled';
    /**
     * The row says refunded and the ledger does not — or the other way round.
     *
     * Reached only when a refund gets half way: the return row is claimed
     * (which is what stops a second refund) and then the ledger refuses the
     * reversing transaction. Nothing is retried automatically, for the same
     * reason FIN-05 forbids retrying a transfer whose outcome is unknown: the
     * two stores disagree, and only a person can say which is right.
     */
    case ReconciliationRequired = 'reconciliation_required';

    /** @return list<self> */
    public static function all(): array
    {
        return [
            self::Requested, self::Approved, self::Rejected, self::Received,
            self::Refunded, self::Cancelled, self::ReconciliationRequired,
        ];
    }

    /** Still counted against the line's quantity — it may yet come back. */
    public function reservesQuantity(): bool
    {
        return $this === self::Requested || $this === self::Approved
            || $this === self::Received || $this === self::Refunded
            // A half-finished refund still holds its goods against the line:
            // whatever the books say, those units are not for sale again.
            || $this === self::ReconciliationRequired;
    }

    public function isFinished(): bool
    {
        return $this === self::Refunded || $this === self::Rejected || $this === self::Cancelled;
    }

    /** Whether somebody has to look at this by hand. */
    public function needsAPerson(): bool
    {
        return $this === self::ReconciliationRequired;
    }
}
