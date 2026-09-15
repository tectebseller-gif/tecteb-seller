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

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Requested, self::Approved, self::Rejected, self::Received, self::Refunded, self::Cancelled];
    }

    /** Still counted against the line's quantity — it may yet come back. */
    public function reservesQuantity(): bool
    {
        return $this === self::Requested || $this === self::Approved
            || $this === self::Received || $this === self::Refunded;
    }

    public function isFinished(): bool
    {
        return $this === self::Refunded || $this === self::Rejected || $this === self::Cancelled;
    }
}
