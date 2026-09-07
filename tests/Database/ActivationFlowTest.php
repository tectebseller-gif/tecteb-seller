<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use TmcWpStubs\State;

/** CORE-01/03/07/08 end to end under stubs + MariaDB: activation, reactivation, deactivation retention. */
final class ActivationFlowTest extends DatabaseTestCase
{
    /** One activation = one request: fresh hooks, persistent options/roles/tables. */
    private function activate(): void
    {
        State::newRequest();
        Bootstrap::reset();
        Bootstrap::init(self::MAIN_FILE, self::VERSION);
        $wpdb = $this->wpdb;
        Bootstrap::container()->bind(AuditRepositoryInterface::class, static fn () => new WpAuditRepository($wpdb));
        do_action('activate_tecteb-marketplace-core/tecteb-marketplace-core.php', false);
    }

    /** The page load after activation, where the result notice is shown. */
    private function nextRequest(): void
    {
        State::newRequest();
        Bootstrap::reset();
        Bootstrap::init(self::MAIN_FILE, self::VERSION);
        $wpdb = $this->wpdb;
        Bootstrap::container()->bind(AuditRepositoryInterface::class, static fn () => new WpAuditRepository($wpdb));
        do_action('plugins_loaded');
    }

    public function testActivationInstallsCapabilitiesDefaultsSchemaAndAuditRow(): void
    {
        $this->loginAdmin();
        $this->activate();

        foreach (['tmc_view_dashboard', 'tmc_view_health', 'tmc_manage_settings', 'tmc_view_modules'] as $cap) {
            self::assertTrue(State::$roles['administrator'][$cap], $cap);
        }
        self::assertArrayNotHasKey('subscriber', State::$roles);
        self::assertSame(['schema_version' => 1, 'values' => [
            'default_commission_rate_bp' => null, 'settlement_delay_days' => 4, 'max_staff' => 10, 'environment_override' => 'auto',
        ]], get_option('tmc_settings'));
        self::assertSame(1, $this->storedSchemaVersion());
        self::assertTrue($this->tableExists($this->auditTable()));
        self::assertSame('applied', State::$transients[Activator::NOTICE_TRANSIENT]['migration']);
        $rows = $this->wpdb->get_results("SELECT event_type, actor_id, payload FROM `{$this->auditTable()}`", ARRAY_A);
        self::assertCount(1, $rows);
        self::assertSame('plugin.activated', $rows[0]['event_type']);
        self::assertSame('1', (string) $rows[0]['actor_id']);
        self::assertSame(['plugin_version' => self::VERSION, 'schema_version' => 1, 'migration_status' => 'applied'], json_decode($rows[0]['payload'], true));
        self::assertNull($this->lockRow());

        $this->nextRequest();
        $notice = $this->capture(static fn () => do_action('admin_notices'));
        self::assertStringContainsString('فعال شد', $notice);
        self::assertStringContainsString('notice-success', $notice);
        self::assertArrayNotHasKey(Activator::NOTICE_TRANSIENT, State::$transients, 'the notice is shown once, then cleared');
    }

    public function testReactivationIsIdempotentAndKeepsUserValues(): void
    {
        $this->loginAdmin();
        $this->activate();
        // The values a site owner had already saved, as they sit in wp_options.
        $saved = get_option('tmc_settings');
        $saved['values']['default_commission_rate_bp'] = 250;
        $saved['values']['max_staff'] = 33;
        tmc_stub_option_put('tmc_settings', $saved);
        $before = count($this->wpdb->queries);

        $this->activate();
        $during = array_slice($this->wpdb->queries, $before);

        $after = get_option('tmc_settings');
        self::assertSame(250, $after['values']['default_commission_rate_bp'], 'user value not reset');
        self::assertSame(33, $after['values']['max_staff']);
        self::assertSame(1, $this->storedSchemaVersion());
        self::assertSame('up_to_date', State::$transients[Activator::NOTICE_TRANSIENT]['migration']);
        self::assertSame(2, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->auditTable()}`"), 'second activation is audited too');
        self::assertSame(['PRIMARY', 'tmc_actor_created', 'tmc_evt_created', 'tmc_object'], $this->indexNames($this->auditTable()));
        // Reactivation must do no structural work: the schema is already at
        // the target, so the runner reads the version and stops — no DDL, and
        // no migration lock is taken. Counting queries would only measure how
        // many options are read, so assert on the statements themselves.
        foreach ($during as $q) {
            self::assertDoesNotMatchRegularExpression('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $q, 'no DDL on reactivation: ' . $q);
            self::assertStringNotContainsString('tmc_migration_lock', $q, 'an up-to-date run takes no lock: ' . $q);
        }
        $writes = array_values(array_filter($during, static fn (string $q): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $q) === 1));
        self::assertCount(1, $writes, 'the only write is the activation audit row: ' . implode(' | ', $writes));
        self::assertStringContainsString($this->auditTable(), $writes[0]);
    }

    public function testDeactivationLeavesTableOptionsAndCapabilitiesInPlace(): void
    {
        $this->loginAdmin();
        $this->activate();
        $before = count($this->wpdb->queries);
        do_action('deactivate_tecteb-marketplace-core/tecteb-marketplace-core.php');
        $during = array_slice($this->wpdb->queries, $before);

        self::assertTrue($this->tableExists($this->auditTable()));
        self::assertSame(2, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->auditTable()}`"), 'activation + deactivation rows preserved');
        self::assertIsArray(get_option('tmc_settings'), 'settings survive deactivation');
        self::assertSame(1, $this->storedSchemaVersion());
        self::assertTrue(State::$roles['administrator']['tmc_manage_settings']);
        self::assertSame([], State::$clearedScheduledHooks, 'phase 1 owns no cron hooks');

        foreach ($during as $q) {
            self::assertDoesNotMatchRegularExpression('/^\s*(DROP|TRUNCATE|DELETE|ALTER)\b/i', $q, 'no destructive SQL during deactivation: ' . $q);
        }
        // Across the WHOLE flow, nothing may drop/truncate/delete business data.
        // The one DELETE the plugin ever issues is the release of its own
        // migration lock row, which is transient bookkeeping, not data.
        foreach ($this->wpdb->queries as $q) {
            self::assertDoesNotMatchRegularExpression('/^\s*(DROP|TRUNCATE)\b/i', $q, $q);
            if (preg_match('/^\s*DELETE\b/i', $q) === 1) {
                self::assertStringContainsString("option_name = 'tmc_migration_lock'", $q, 'the only DELETE is the plugin releasing its own lock: ' . $q);
            }
            self::assertStringNotContainsString('DELETE FROM `' . $this->auditTable() . '`', $q);
        }
    }
}
