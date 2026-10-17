<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * MONEY A SUPPLIER OWES BACK, after a return of something already paid for.
 *
 * The owner's §2 names three events and insists they stay three:
 *
 *   **ثبت طلب**   — this object: an amount recorded as owed, with the return
 *                   and the item it came from. Recording it moves no money.
 *   **وصول واقعی** — a `collected` settlement: the supplier actually sent the
 *                   money back, and a person wrote down that they did.
 *   **تهاتر**      — an `offset` settlement: the amount is set against what
 *                   Tecteb owes the supplier next. Still not a transfer, and
 *                   still not automatic — «کسر خودکار انجام نشود».
 *
 * One balance holds all three apart: `amount` is what was owed, `settledMinor`
 * is what has been accounted for by either kind, and a settlement bigger than
 * the balance is refused. That is the whole of «یک مبلغ دوبار وصول یا کسر
 * نشود» — not a rule somebody remembers, a subtraction somebody cannot get
 * around.
 *
 * **Which of the two the manager chooses is not decided here.** §2 says the
 * decision is theirs, per return, and this module records whichever they
 * record and neither by default.
 */
final class SupplierReceivable
{
    public function __construct(
        public readonly int $id,
        public readonly int $supplierUserId,
        public readonly int $orderItemId,
        /** The marketplace return this came from, or 0 when recorded by hand. */
        public readonly int $returnId,
        /** How many units came back — «مرجوعی جزئی فقط روی اقلام و تعداد مرتبط». */
        public readonly int $quantity,
        public readonly Money $amount,
        /** Collected plus offset, of the settlements recorded so far. */
        public readonly int $settledMinor,
        public readonly string $reason = '',
        public readonly int $recordedBy = 0,
        public readonly string $eventKey = '',
        public readonly string $createdAt = ''
    ) {
    }

    public function balanceMinor(): int
    {
        return $this->amount->minor - $this->settledMinor;
    }

    public function isOpen(): bool
    {
        return $this->balanceMinor() > 0;
    }
}
