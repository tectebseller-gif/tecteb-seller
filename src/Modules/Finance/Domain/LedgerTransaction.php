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

    /**
     * What produced these lines, as JSON, for the `snapshot` column.
     *
     * The column has existed since migration 4 and `CommissionSnapshot`
     * has said «for storage beside the ledger row» just as long — and until
     * `alpha.39` the repository wrote an empty string into it. The cost showed
     * up in retries: a line whose ledger write had succeeded could not be
     * rebuilt, because the figures were on disk and the rate behind them was
     * not. Empty stays legal; it means «recorded before this was written».
     */
    private string $snapshotJson = '';

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
    /** @param array<string,mixed> $snapshot */
    public function snapshot(array $snapshot): self
    {
        $this->snapshotJson = $snapshot === []
            ? ''
            : (string) (json_encode($snapshot, JSON_UNESCAPED_UNICODE) ?: '');
        return $this;
    }

    public function snapshotJson(): string
    {
        return $this->snapshotJson;
    }

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
