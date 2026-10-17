<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * WHAT TECTEB AGREED TO PAY A SUPPLIER FOR ONE ORDER ITEM — and how much of
 * it has actually been paid.
 *
 * The owner's §3 is one sentence long and it is the whole reason this class
 * exists: «مبلغ توافق‌شدهٔ خرید با تأمین‌کننده، سهم محاسبه‌شدهٔ کمیسیون نیست و
 * نباید برابر فرض شود». Until now the only number a payment could be weighed
 * against was `vendorShareMinor`, which the commission model DERIVES from the
 * sale. An agreed purchase price is not derived from anything; two people
 * agreed it, and if nobody has written it down the honest answer is «نامعلوم»
 * rather than a figure computed from the other model.
 *
 * So `agreed` is NULLABLE, all the way down to the column, and the three
 * questions a caller can ask are kept apart:
 *
 *   `isAgreed()`     has an amount been recorded at all?
 *   `paidMinor`      what has been allocated to it by payments that stand
 *   `balanceMinor()` what is left — and only meaningful once agreed
 *
 * A payment smaller than the balance is a PARTIAL PAYMENT and not a problem;
 * a payment larger than it is refused by name, because «پرداخت بیش از مانده
 * نباید خودکار بدهکاری یا پیش‌پرداخت شود» and neither of those two readings
 * has been chosen.
 */
final class SupplierObligation
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderItemId,
        public readonly int $supplierUserId,
        /** The agreed purchase price, or null when nobody has recorded one. */
        public readonly ?Money $agreed,
        /** The sum of the allocations of payments that have not been voided. */
        public readonly int $paidMinor,
        public readonly string $currency,
        public readonly int $exponent,
        public readonly int $agreedBy = 0,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = ''
    ) {
    }

    /**
     * Whether an amount was ever agreed.
     *
     * Zero is agreed — a supplier who is owed nothing for a line is a real
     * answer and a different one from «we have not settled on a price»
     * (`alpha.28`, on a different field, for the same reason).
     */
    public function isAgreed(): bool
    {
        return $this->agreed !== null;
    }

    /** What is still owed, or null when there is no agreed amount to subtract from. */
    public function balanceMinor(): ?int
    {
        return $this->agreed === null ? null : $this->agreed->minor - $this->paidMinor;
    }

    /** Fully covered — asked only when an amount was agreed. */
    public function isSettled(): bool
    {
        return $this->agreed !== null && $this->balanceMinor() === 0;
    }

    /** Some money against it, and still a balance. */
    public function isPartiallyPaid(): bool
    {
        return $this->agreed !== null && $this->paidMinor > 0 && ($this->balanceMinor() ?? 0) > 0;
    }
}
