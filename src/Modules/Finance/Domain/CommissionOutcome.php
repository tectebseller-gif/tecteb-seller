<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * What a commission calculation produced — or why it produced nothing.
 *
 * `NeedsConfiguration` is a first-class outcome, not an error to swallow.
 * FIN-02 forbids guessing a zero rate, so a calculation that cannot find a
 * rate must be able to say so in a way the caller is forced to handle, and
 * the order module is expected to refuse to operate rather than sell at a
 * rate nobody chose.
 */
final class CommissionOutcome
{
    public const CALCULATED = 'calculated';
    public const NEEDS_CONFIGURATION = 'needs_configuration';
    /** A calculated outcome whose figures came off an existing ledger event. */
    public const ALREADY_RECORDED = 'already_recorded';

    private function __construct(
        public readonly string $state,
        public readonly ?Money $base,
        public readonly ?Money $commission,
        public readonly ?Money $vendorShare,
        public readonly ?CommissionSnapshot $snapshot,
        public readonly string $reason = ''
    ) {
    }

    public static function calculated(Money $base, Money $commission, Money $vendorShare, CommissionSnapshot $snapshot): self
    {
        return new self(self::CALCULATED, $base, $commission, $vendorShare, $snapshot);
    }

    /**
     * The figures of an event the ledger ALREADY holds, read back off it.
     *
     * **Why this is a third answer.** `alpha.38` turned a refused ledger write
     * into `needsConfiguration('already_recorded')`, which is not calculated —
     * so `CaptureOrder` wrote the order line with a null commission, a null
     * share and an empty ledger event, and then skipped the line for ever
     * because a row existed. The money was in the ledger and the line said it
     * was unknown. Measured on the shipped bytes.
     *
     * Calculated is the right state: these ARE the figures, and they are the
     * ones recorded at the time rather than a rate resolved again today
     * («نرخ تازه نباید تاریخ را بازنویسی کند»). `reason` marks where they came
     * from, and `$snapshot` is null when the event predates the snapshot
     * column being written — figures recovered, rate honestly unknown.
     */
    public static function recovered(
        Money $base,
        Money $commission,
        Money $vendorShare,
        ?CommissionSnapshot $snapshot
    ): self {
        return new self(self::CALCULATED, $base, $commission, $vendorShare, $snapshot, self::ALREADY_RECORDED);
    }

    public static function needsConfiguration(string $reason): self
    {
        return new self(self::NEEDS_CONFIGURATION, null, null, null, null, $reason);
    }

    public function isCalculated(): bool
    {
        return $this->state === self::CALCULATED;
    }

    /** Calculated, but read back off an event that was already recorded. */
    public function isRecovered(): bool
    {
        return $this->state === self::CALCULATED && $this->reason === self::ALREADY_RECORDED;
    }
}
