<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * A set of lines that must be written together and must balance.
 *
 * FIN-06 requires the accounts to sum, so the check lives here rather than in
 * a caller's head: a transaction whose lines do not cancel out is refused
 * before it reaches storage. That is what stops the "bulk transfer of the
 * order amount to the vendor balance" the spec explicitly forbids — such a
 * transfer has no matching commission and tax lines, so it cannot balance.
 */
final class LedgerTransaction
{
    /** @var list<array{account:LedgerAccount, amount:Money, reason:string, reverses:?int}> */
    private array $lines = [];

    public function __construct(
        public readonly string $eventKey,
        public readonly int $vendorUserId,
        public readonly string $orderRef,
        public readonly string $itemRef
    ) {
    }

    /**
     * A line that moves nothing is not recorded. A ledger records movements,
     * and a zero row for tax nobody collected would only make every event
     * look busier than it was — while still balancing, so it proves nothing.
     */
    public function add(LedgerAccount $account, Money $amount, string $reason, ?int $reverses = null): self
    {
        if ($amount->isZero()) {
            return $this;
        }
        $this->lines[] = ['account' => $account, 'amount' => $amount, 'reason' => $reason, 'reverses' => $reverses];
        return $this;
    }

    /** @return list<array{account:LedgerAccount, amount:Money, reason:string, reverses:?int}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Debits and credits cancel. Central payment comes IN as a positive line;
     * commission, tax and the vendor's earning go OUT as negatives, so the
     * total of every event is zero.
     */
    public function balances(): bool
    {
        if ($this->lines === []) {
            return false;
        }
        $total = null;
        foreach ($this->lines as $line) {
            $total = $total === null ? $line['amount'] : $total->add($line['amount']);
        }
        return $total !== null && $total->isZero();
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
