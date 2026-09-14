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

    public static function needsConfiguration(string $reason): self
    {
        return new self(self::NEEDS_CONFIGURATION, null, null, null, null, $reason);
    }

    public function isCalculated(): bool
    {
        return $this->state === self::CALCULATED;
    }
}
