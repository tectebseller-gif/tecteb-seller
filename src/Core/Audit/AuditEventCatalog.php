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
    // Vendor phase. Payload keys stay coarse on purpose: an audit row records
    // that a decision happened and by whom, never the applicant's documents,
    // address or phone number.
    public const VENDOR_APPLICATION_SUBMITTED = 'vendor.application_submitted';
    public const VENDOR_APPLICATION_REVIEWED = 'vendor.application_reviewed';
    public const VENDOR_DOCUMENT_UPLOADED = 'vendor.document_uploaded';
    public const VENDOR_DOCUMENT_REJECTED = 'vendor.document_rejected';
    public const VENDOR_DOCUMENT_DOWNLOADED = 'vendor.document_downloaded';
    public const VENDOR_REQUIREMENTS_CHANGED = 'vendor.requirements_changed';

    /** @return array<string, list<string>> event type => allowed top-level payload keys */
    public static function allowlist(): array
    {
        return [
            self::SETTINGS_UPDATED => ['changed', 'old', 'new'],
            self::PLUGIN_ACTIVATED => ['plugin_version', 'schema_version', 'migration_status'],
            self::PLUGIN_DEACTIVATED => ['plugin_version'],
            self::MIGRATION_APPLIED => ['from', 'to', 'steps'],
            self::MIGRATION_FAILED => ['step', 'message'],
            self::VENDOR_APPLICATION_SUBMITTED => ['application_id', 'from', 'to', 'documents'],
            self::VENDOR_APPLICATION_REVIEWED => ['application_id', 'from', 'to', 'decision', 'has_note'],
            self::VENDOR_DOCUMENT_UPLOADED => ['application_id', 'type', 'bytes', 'mime'],
            self::VENDOR_DOCUMENT_REJECTED => ['application_id', 'type', 'reason', 'bytes'],
            self::VENDOR_DOCUMENT_DOWNLOADED => ['application_id', 'document_id', 'type'],
            self::VENDOR_REQUIREMENTS_CHANGED => ['action', 'type', 'mode'],
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
