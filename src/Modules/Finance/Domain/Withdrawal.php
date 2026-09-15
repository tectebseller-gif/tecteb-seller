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
        public readonly string $updatedAt = ''
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
