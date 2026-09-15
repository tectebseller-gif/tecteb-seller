<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

/**
 * Canonical, already-validated settings. Immutable.
 * commissionRateBp is an integer in basis points or null ("not set yet").
 */
final class Settings
{
    public function __construct(
        public readonly ?int $commissionRateBp,
        public readonly int $settlementDelayDays,
        public readonly int $maxStaff,
        public readonly string $environmentOverride
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            SettingsSchema::DEFAULT_COMMISSION_BP,
            SettingsSchema::DEFAULT_SETTLEMENT_DELAY_DAYS,
            SettingsSchema::DEFAULT_MAX_STAFF,
            SettingsSchema::DEFAULT_ENVIRONMENT_OVERRIDE
        );
    }

    /**
     * Tolerant reader for the stored array. Any key that is missing or not in
     * canonical form falls back to its default; no re-conversion of any kind
     * happens here (the stored form is final).
     *
     * @param mixed $stored
     */
    public static function fromStored(mixed $stored): self
    {
        $d = self::defaults();
        if (!is_array($stored)) {
            return $d;
        }
        $values = isset($stored['values']) && is_array($stored['values']) ? $stored['values'] : $stored;

        $bp = $values[SettingsSchema::KEY_COMMISSION_BP] ?? $d->commissionRateBp;
        if ($bp !== null && (!is_int($bp) || $bp < SettingsSchema::COMMISSION_BP_MIN || $bp > SettingsSchema::COMMISSION_BP_MAX)) {
            $bp = $d->commissionRateBp;
        }
        $delay = $values[SettingsSchema::KEY_SETTLEMENT_DELAY_DAYS] ?? $d->settlementDelayDays;
        if (!is_int($delay) || $delay < SettingsSchema::SETTLEMENT_DELAY_MIN || $delay > SettingsSchema::SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX) {
            $delay = $d->settlementDelayDays;
        }
        $staff = $values[SettingsSchema::KEY_MAX_STAFF] ?? $d->maxStaff;
        if (!is_int($staff) || $staff < SettingsSchema::MAX_STAFF_MIN || $staff > SettingsSchema::MAX_STAFF_PROPOSED_TECHNICAL_MAX) {
            $staff = $d->maxStaff;
        }
        $env = $values[SettingsSchema::KEY_ENVIRONMENT_OVERRIDE] ?? $d->environmentOverride;
        if (!is_string($env) || !in_array($env, SettingsSchema::ENVIRONMENT_OVERRIDE_VALUES, true)) {
            $env = $d->environmentOverride;
        }
        return new self($bp, $delay, $staff, $env);
    }

    /** Stored shape: ['schema_version' => 1, 'values' => [...canonical...]] */
    public function toStored(): array
    {
        return [
            'schema_version' => SettingsSchema::SCHEMA_VERSION,
            'values' => [
                SettingsSchema::KEY_COMMISSION_BP => $this->commissionRateBp,
                SettingsSchema::KEY_SETTLEMENT_DELAY_DAYS => $this->settlementDelayDays,
                SettingsSchema::KEY_MAX_STAFF => $this->maxStaff,
                SettingsSchema::KEY_ENVIRONMENT_OVERRIDE => $this->environmentOverride,
            ],
        ];
    }

    /** @return array<string, int|string|null> canonical values keyed by stored key */
    public function values(): array
    {
        return $this->toStored()['values'];
    }

    public function withCommissionRateBp(?int $bp): self
    {
        return new self($bp, $this->settlementDelayDays, $this->maxStaff, $this->environmentOverride);
    }

    public function withSettlementDelayDays(int $days): self
    {
        return new self($this->commissionRateBp, $days, $this->maxStaff, $this->environmentOverride);
    }

    public function withMaxStaff(int $max): self
    {
        return new self($this->commissionRateBp, $this->settlementDelayDays, $max, $this->environmentOverride);
    }

    public function withEnvironmentOverride(string $env): self
    {
        return new self($this->commissionRateBp, $this->settlementDelayDays, $this->maxStaff, $env);
    }
}
