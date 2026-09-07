<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Audit;

use Tecteb\Marketplace\Core\Config\SettingsSchema;

/**
 * Allowlist of event types and, per type, the payload keys that may be
 * persisted (CORE-08). Anything else is dropped before insert.
 */
final class AuditEventCatalog
{
    public const SETTINGS_UPDATED = 'settings.updated';
    public const PLUGIN_ACTIVATED = 'plugin.activated';
    public const PLUGIN_DEACTIVATED = 'plugin.deactivated';
    public const MIGRATION_APPLIED = 'migration.applied';
    public const MIGRATION_FAILED = 'migration.failed';

    /** @return array<string, list<string>> event type => allowed top-level payload keys */
    public static function allowlist(): array
    {
        return [
            self::SETTINGS_UPDATED => ['changed', 'old', 'new'],
            self::PLUGIN_ACTIVATED => ['plugin_version', 'schema_version', 'migration_status'],
            self::PLUGIN_DEACTIVATED => ['plugin_version'],
            self::MIGRATION_APPLIED => ['from', 'to', 'steps'],
            self::MIGRATION_FAILED => ['step', 'message'],
        ];
    }

    /** Nested maps under old/new/changed may only carry these keys. */
    public static function nestedKeyAllowlist(string $eventType): array
    {
        if ($eventType === self::SETTINGS_UPDATED) {
            return SettingsSchema::storedKeys();
        }
        return [];
    }

    public static function isKnown(string $eventType): bool
    {
        return isset(self::allowlist()[$eventType]);
    }
}
