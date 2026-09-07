<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Lifecycle;

/**
 * The only capabilities created in phase 1 (CORE-03). Future capabilities are
 * documented in docs/public-contracts.md, not created here.
 */
final class Capabilities
{
    public const VIEW_DASHBOARD = 'tmc_view_dashboard';
    public const VIEW_HEALTH = 'tmc_view_health';
    public const MANAGE_SETTINGS = 'tmc_manage_settings';
    public const VIEW_MODULES = 'tmc_view_modules';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD,
            self::VIEW_HEALTH,
            self::MANAGE_SETTINGS,
            self::VIEW_MODULES,
        ];
    }

    /** Role that receives the capabilities on single-site installs. */
    public const TARGET_ROLE = 'administrator';
}
