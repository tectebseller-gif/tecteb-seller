<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes;
use Tecteb\Marketplace\Infrastructure\WordPress\WpCapabilities;

/**
 * Idempotent activation (CORE-01): guard → capabilities → settings defaults
 * (merge, never reset) → migration (resumable, owner-locked). The outcome is
 * stored in a short-lived transient for one admin notice and is always
 * visible on the health page.
 */
final class Activator
{
    public const NOTICE_TRANSIENT = 'tmc_activation_result';

    public static function activate(bool $networkWide): void
    {
        MultisiteGuard::assertAllowed($networkWide);

        $container = Bootstrap::container();
        $capsOk = CapabilityInstaller::install();
        /** @var SettingsService $settings */
        $settings = $container->get(SettingsService::class);
        $settingsOk = $settings->ensureStored();
        /** @var MigrationRunner $runner */
        $runner = $container->get(MigrationRunner::class);
        $migration = $runner->run();

        // The vendor area answers at /vendor/…, which only works once the
        // rewrite rules are in the database. Registering them here (the init
        // hook has not run yet during activation) and flushing once is the
        // documented way; deactivation flushes again so nothing is left over.
        VendorRoutes::addRewriteRules();
        flush_rewrite_rules(false);

        $result = [
            'capabilities' => $capsOk,
            'settings' => $settingsOk,
            'migration' => $migration->status->value,
            'migration_error' => $migration->error,
            'schema_version' => $runner->currentVersion(),
        ];
        set_transient(self::NOTICE_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS);

        if ($migration->isSuccess()) {
            /** @var AuditLogger $audit */
            $audit = $container->get(AuditLogger::class);
            $audit->log(
                AuditEventCatalog::PLUGIN_ACTIVATED,
                (new WpCapabilities())->currentUserId(),
                'plugin',
                'tecteb-marketplace-core',
                [
                    'plugin_version' => Bootstrap::pluginVersion(),
                    'schema_version' => $runner->currentVersion(),
                    'migration_status' => $migration->status->value,
                ]
            );
            // An audit failure here is visible on the health page; activation itself is not blocked.
        }
    }
}
