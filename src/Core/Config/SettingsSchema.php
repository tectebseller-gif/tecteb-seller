<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

/**
 * Single source of truth for setting keys, defaults and validation bounds
 * (CORE-07; decision-log F-02/F-03).
 *
 * The *_PROPOSED_TECHNICAL_MAX constants are proposed technical validation
 * bounds that reject implausible input. They are NOT business rules and
 * their existence is NOT evidence of tested capacity. Changing one is a
 * versioned release change (Master Spec §20), not a runtime option.
 */
final class SettingsSchema
{
    public const OPTION_KEY = 'tmc_settings';

    /** Version of the stored settings array shape (not the DB schema, not the plugin release). */
    public const SCHEMA_VERSION = 1;

    // Stored keys (canonical form).
    public const KEY_COMMISSION_BP = 'default_commission_rate_bp';
    public const KEY_SETTLEMENT_DELAY_DAYS = 'settlement_delay_days';
    public const KEY_MAX_STAFF = 'max_staff';
    public const KEY_ENVIRONMENT_OVERRIDE = 'environment_override';

    // Form input keys. The commission INPUT is a percent string and is
    // deliberately named differently from the stored basis-point key so the
    // two representations can never be confused (owner correction 4).
    public const INPUT_COMMISSION_PERCENT = 'default_commission_rate';
    public const INPUT_SETTLEMENT_DELAY_DAYS = self::KEY_SETTLEMENT_DELAY_DAYS;
    public const INPUT_MAX_STAFF = self::KEY_MAX_STAFF;
    public const INPUT_ENVIRONMENT_OVERRIDE = self::KEY_ENVIRONMENT_OVERRIDE;

    // Defaults (approved: Master Spec §8.3, A.2, A.4; FIN-02).
    public const DEFAULT_COMMISSION_BP = null;      // "هنوز تعیین نشده" — distinct from 0
    public const DEFAULT_SETTLEMENT_DELAY_DAYS = 4;
    public const DEFAULT_MAX_STAFF = 10;
    public const DEFAULT_ENVIRONMENT_OVERRIDE = 'auto';

    // Validation bounds.
    public const COMMISSION_BP_MIN = 0;
    public const COMMISSION_BP_MAX = 10000;          // 100.00 % — contract bound (CORE-07), not a proposal

    public const SETTLEMENT_DELAY_MIN = 0;           // 0 = no additional time delay (see docs/decision-log.md F-03)
    /** Proposed technical bound; not a business rule; not tested capacity. */
    public const SETTLEMENT_DELAY_PROPOSED_TECHNICAL_MAX = 365;

    public const MAX_STAFF_MIN = 1;                  // 0 would be a business decision not in the spec
    /** Proposed technical bound; not a business rule; not tested capacity. */
    public const MAX_STAFF_PROPOSED_TECHNICAL_MAX = 100;

    /** @var list<string> */
    public const ENVIRONMENT_OVERRIDE_VALUES = ['auto', 'staging', 'production'];

    /** @return list<string> the keys the settings form actually submits */
    public static function inputKeys(): array
    {
        return [
            self::INPUT_COMMISSION_PERCENT,
            self::INPUT_SETTLEMENT_DELAY_DAYS,
            self::INPUT_MAX_STAFF,
            self::INPUT_ENVIRONMENT_OVERRIDE,
        ];
    }

    /** @return list<string> */
    public static function storedKeys(): array
    {
        return [
            self::KEY_COMMISSION_BP,
            self::KEY_SETTLEMENT_DELAY_DAYS,
            self::KEY_MAX_STAFF,
            self::KEY_ENVIRONMENT_OVERRIDE,
        ];
    }
}
