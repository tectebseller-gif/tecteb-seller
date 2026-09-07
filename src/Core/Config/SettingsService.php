<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Config\Sanitizer\BoundedInteger;
use Tecteb\Marketplace\Core\Config\Sanitizer\EnvironmentOverride;
use Tecteb\Marketplace\Core\Config\Sanitizer\PercentToBasisPoints;

/**
 * Loads, validates and stores settings (CORE-07).
 *
 *  - invalid input for a field is rejected and THAT field keeps its previous value
 *  - the commission input is a percent string; the stored value is basis points
 *  - reactivation never resets user values (ensureStored merges, never overwrites)
 */
final class SettingsService
{
    public function __construct(private OptionStoreInterface $options)
    {
    }

    public function load(): Settings
    {
        return Settings::fromStored($this->options->get(SettingsSchema::OPTION_KEY, null));
    }

    public function save(Settings $settings): bool
    {
        return $this->options->set(SettingsSchema::OPTION_KEY, $settings->toStored());
    }

    /**
     * Activation helper: stores defaults only when nothing is stored; when a
     * stored array exists, it is re-canonicalised (missing keys → defaults,
     * existing values untouched).
     */
    public function ensureStored(): bool
    {
        $stored = $this->options->get(SettingsSchema::OPTION_KEY, null);
        $settings = Settings::fromStored($stored);
        if (is_array($stored) && $stored === $settings->toStored()) {
            return true;
        }
        return $this->save($settings);
    }

    /**
     * @param array<string,mixed> $raw submitted form values (input keys)
     */
    public function sanitizeSubmission(array $raw, Settings $current): SanitizeOutcome
    {
        $errors = [];
        $next = $current;

        // Only the INPUT key is read; a submitted stored key is ignored on purpose.
        if (array_key_exists(SettingsSchema::INPUT_COMMISSION_PERCENT, $raw)) {
            $r = PercentToBasisPoints::parse($raw[SettingsSchema::INPUT_COMMISSION_PERCENT]);
            if ($r->ok) {
                $next = $next->withCommissionRateBp($r->value);
            } else {
                $errors[SettingsSchema::INPUT_COMMISSION_PERCENT] = $r->errorCode;
            }
        }

        if (array_key_exists(SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS, $raw)) {
            $r = BoundedInteger::parse(
                $raw[SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS],
                SettingsSchema::SETTLEMENT_DELAY_MIN,
                SettingsSchema::SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX
            );
            if ($r->ok) {
                $next = $next->withSettlementDelayDays($r->value);
            } else {
                $errors[SettingsSchema::INPUT_SETTLEMENT_DELAY_DAYS] = $r->errorCode;
            }
        }

        if (array_key_exists(SettingsSchema::INPUT_MAX_STAFF, $raw)) {
            $r = BoundedInteger::parse(
                $raw[SettingsSchema::INPUT_MAX_STAFF],
                SettingsSchema::MAX_STAFF_MIN,
                SettingsSchema::MAX_STAFF_PROPOSED_TECHNICAL_MAX
            );
            if ($r->ok) {
                $next = $next->withMaxStaff($r->value);
            } else {
                $errors[SettingsSchema::INPUT_MAX_STAFF] = $r->errorCode;
            }
        }

        if (array_key_exists(SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE, $raw)) {
            $r = EnvironmentOverride::parse($raw[SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE]);
            if ($r->ok) {
                $next = $next->withEnvironmentOverride($r->value);
            } else {
                $errors[SettingsSchema::INPUT_ENVIRONMENT_OVERRIDE] = $r->errorCode;
            }
        }

        $changes = [];
        foreach ($current->values() as $key => $old) {
            $new = $next->values()[$key];
            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }
        return new SanitizeOutcome($next, $errors, $changes);
    }
}
