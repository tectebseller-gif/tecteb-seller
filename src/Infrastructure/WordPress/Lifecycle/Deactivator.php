<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpCapabilities;

/**
 * Deactivation stops only this plugin's own scheduled tasks (there are none
 * in phase 1; the list below is the single place to add them). Options,
 * capabilities and the audit table are left untouched (CORE-01, CORE-08).
 */
final class Deactivator
{
    /** @var list<string> own cron hooks to clear on deactivation */
    public const OWN_SCHEDULED_HOOKS = [];

    public static function deactivate(): void
    {
        foreach (self::OWN_SCHEDULED_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        delete_transient(Activator::NOTICE_TRANSIENT);
        try {
            /** @var AuditLogger $audit */
            $audit = Bootstrap::container()->get(AuditLogger::class);
            $audit->log(
                AuditEventCatalog::PLUGIN_DEACTIVATED,
                (new WpCapabilities())->currentUserId(),
                'plugin',
                'tecteb-marketplace-core',
                ['plugin_version' => Bootstrap::pluginVersion()]
            );
        } catch (\Throwable) {
            // Best effort only: deactivation must never fail because of audit.
        }
    
        // Leaves no /vendor/ rules behind once the plugin is off.
        flush_rewrite_rules(false);
    }
}
