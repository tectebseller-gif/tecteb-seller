<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/** One withdrawal request, with the amount it locked when it was made. */
final class Withdrawal
{
    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly WithdrawalStatus $status,
        public readonly int $amountMinor,
        public readonly int $lineCount,
        public readonly string $iban = '',
        public readonly string $accountHolder = '',
        public readonly string $reference = '',
        public readonly string $note = '',
        public readonly ?int $reviewedBy = null,
        public readonly ?string $reviewedAt = null,
        public readonly ?string $paidAt = null,
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
        /**
         * THE UNIT `$amountMinor` is in.
         *
         * `null` for every request made before `alpha.67`, which means «the
         * vendor's first-recorded unit» — what it was reserved in. A value
         * means the row says its own, which it must from `alpha.67` on
         * because a request's amount is summed from shares that may be at the
         * finer exponent an exact commission needs.
         *
         * The payout reads THIS rather than the registry: the registry's row
         * holds the vendor's FIRST unit and a request reserved in the finer
         * one would otherwise post a ledger line ten thousand times too
         * large.
         */
        public readonly ?string $currency = null,
        public readonly ?int $exponent = null
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * The amount is a SNAPSHOT, not a live sum.
     *
     * FIN-05: a request locks the whole eligible balance at the instant it is
     * made. Later sales belong to the next request, and a later refund is
     * handled by holding or compensating this one — never by quietly changing
     * the number a vendor already saw.
     */
    public function amountMinor(): int
    {
        return $this->amountMinor;
    }
}
