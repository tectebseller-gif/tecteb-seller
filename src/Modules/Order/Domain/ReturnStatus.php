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
    /**
     * Somebody asked. Nobody has decided.
     *
     * ⛔ TODAY THAT SOMEBODY IS THE SHOP, and only the shop. The one entry
     * point is `open_return` on the vendor dashboard
     * (`OrderArea::ACTIONS`), behind `StaffArea::Order` + `StaffLevel::Edit`
     * — so a buyer asks the shop out of band, and the shop raises it. The
     * manager's screen decides and refunds; it cannot open.
     *
     * This sentence used to read «The customer, the vendor or a manager
     * asked», which was true of none of them but the vendor. The customer's
     * own path hangs on DEC-03, which `decision-log.md` records as outside
     * phase 1 — so it is a named absence rather than a gap, and UX §10.2 puts
     * the manager in this flow as the one who DECIDES, which is built.
     */
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
