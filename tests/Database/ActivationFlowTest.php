<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Modules\Product\Application\DeactivationStopPolicy;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Core\Support\SystemClock;
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

        foreach (['tmc_view_dashboard', 'tmc_view_health', 'tmc_manage_settings', 'tmc_view_modules', 'tmc_review_vendor', 'tmc_manage_vendor_documents'] as $cap) {
            self::assertTrue(State::$roles['administrator'][$cap], $cap);
        }
        self::assertArrayNotHasKey('subscriber', State::$roles);
        self::assertSame(['schema_version' => 1, 'values' => [
            'default_commission_rate_bp' => null, 'settlement_delay_days' => 4, 'max_staff' => 10, 'environment_override' => 'auto',
        ]], get_option('tmc_settings'));
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion());
        self::assertTrue($this->tableExists($this->auditTable()));
        self::assertSame('applied', State::$transients[Activator::NOTICE_TRANSIENT]['migration']);
        $rows = $this->wpdb->get_results("SELECT event_type, actor_id, payload FROM `{$this->auditTable()}`", ARRAY_A);
        self::assertCount(1, $rows);
        self::assertSame('plugin.activated', $rows[0]['event_type']);
        self::assertSame('1', (string) $rows[0]['actor_id']);
        self::assertSame(['plugin_version' => self::VERSION, 'schema_version' => SchemaVersion::TARGET, 'migration_status' => 'applied'], json_decode($rows[0]['payload'], true));
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
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion());
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
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion());
        self::assertTrue(State::$roles['administrator']['tmc_manage_settings']);
        // Ours and only ours: the queue tick, whose callback goes away with
        // the plugin. Nothing else on the site is unscheduled — and the jobs
        // table below survives, because a pending import is data.
        self::assertSame(['tmc_run_jobs'], State::$clearedScheduledHooks);

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

    /**
     * ⛔ §6: «خاموش‌کردن یا ارتقای عادی افزونه نباید بدون تصمیم مستقل مالک
     * وضعیت منتشرشده محصولات را تغییر دهد.»
     *
     * Up to `alpha.101` `deactivate()` called the storefront stop in its
     * straight-line body — no option, no capability, no filter — so every
     * projected product went to `draft` and `hidden`, every unpaid marketplace
     * order went to `on-hold` (which moves stock), and reactivation put none of
     * it back.
     *
     * The measurement is the stop FLAG, because only `StorefrontStop::stop()`
     * and the last-resort `markStoppedAnyway()` ever write it. No flag means
     * the stop never ran, which means nothing was drafted and no order moved.
     */
    public function testDeactivationLeavesTheShelfAloneUnlessItWasToldTo(): void
    {
        $this->loginAdmin();
        $this->activate();
        delete_option(StorefrontSwitch::OPTION);
        delete_option(DeactivationStopPolicy::OPTION);
        // ⛔ THE SWITCH MUST BE REACHABLE, or this test measures nothing.
        //
        // Without the binding, the stop path cannot write its flag even when
        // it runs — so «no flag» would be true of the gated build AND of the
        // unconditional one, and the first run of this assertion passed on a
        // mutant that put the old behaviour back (`alpha.82`). Bound, «no
        // flag» means the stop genuinely did not run.
        Bootstrap::container()->bind(
            StorefrontSwitch::class,
            static fn (): StorefrontSwitch => new StorefrontSwitch(new WpOptionStore(), new SystemClock())
        );

        do_action('deactivate_tecteb-marketplace-core/tecteb-marketplace-core.php');

        self::assertFalse(
            (bool) get_option(StorefrontSwitch::OPTION, false),
            'a plain deactivation must not stop selling'
        );
        self::assertSame(
            0,
            (int) get_option(DeactivationStopPolicy::LEFT_ON_SALE_OPTION, 0),
            'and with no projected product there is nothing left on sale to report'
        );
    }

    /** And the control: told to, it does — the old behaviour, now chosen. */
    public function testDeactivationStopsSellingWhenTheOwnerAskedForThat(): void
    {
        $this->loginAdmin();
        $this->activate();
        delete_option(StorefrontSwitch::OPTION);
        update_option(DeactivationStopPolicy::OPTION, true, false);
        // The product module is not registered in this minimal container, so
        // the full stop cannot run — but the switch can, and it is the last
        // resort `Deactivator` falls back to. What is measured is therefore
        // the BRANCH: the opt-in reached the stop path at all. The stop's own
        // behaviour on real rows is `CatalogProjectionTest`.
        Bootstrap::container()->bind(
            StorefrontSwitch::class,
            static fn (): StorefrontSwitch => new StorefrontSwitch(new WpOptionStore(), new SystemClock())
        );

        do_action('deactivate_tecteb-marketplace-core/tecteb-marketplace-core.php');

        $flag = get_option(StorefrontSwitch::OPTION, false);
        self::assertNotFalse($flag, 'the opt-in must still reach the stop');
        self::assertSame(
            StorefrontSwitch::REASON_DEACTIVATED,
            is_array($flag) ? ($flag['reason'] ?? '') : '',
            'and record why the shelf came down'
        );
        delete_option(DeactivationStopPolicy::OPTION);
    }

    /**
     * The price of the default is written down rather than left implicit: the
     * audit row says which way it went on BOTH paths, so a reader does not
     * have to know what the option said that day.
     */
    public function testTheAuditRowSaysWhetherDeactivationStoppedSelling(): void
    {
        $this->loginAdmin();
        $this->activate();
        delete_option(DeactivationStopPolicy::OPTION);

        do_action('deactivate_tecteb-marketplace-core/tecteb-marketplace-core.php');

        $payload = (string) $this->wpdb->get_var(
            "SELECT payload FROM `{$this->auditTable()}` WHERE event_type = 'plugin.deactivated' ORDER BY id DESC LIMIT 1"
        );
        self::assertStringContainsString('stopped_selling', $payload, $payload);
        self::assertStringContainsString('"no"', $payload, $payload);
        self::assertStringContainsString('left_on_sale', $payload, $payload);
    }

}
