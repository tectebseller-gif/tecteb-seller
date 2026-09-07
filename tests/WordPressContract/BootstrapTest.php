<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use TmcWpStubs\State;

/** CORE-01/02/04/06 + owner correction 6 (WooCommerce absent: explicit, testable, no fatal). */
final class BootstrapTest extends ContractTestCase
{
    public function testWithoutWooCommerceLimitedModeBootsWithoutFatalAndReportsIt(): void
    {
        $report = $this->bootPlugin(false);

        foreach (['core', 'environment-guard', 'admin', 'health'] as $id) {
            self::assertSame(ModuleStatus::Active, $report->status($id), $id . ' stays available without WooCommerce');
        }
        foreach (['vendor', 'product', 'order', 'commission', 'settlement', 'migration'] as $id) {
            self::assertSame(ModuleStatus::Planned, $report->status($id));
        }
        self::assertFalse($report->wooCommerceAvailable);

        // Persian notice for admins only.
        State::loginAs(9, ['read']);
        self::assertSame('', $this->capture(static fn () => do_action('admin_notices')), 'no notice for users who cannot manage plugins');
        $this->loginAdmin();
        $out = $this->capture(static fn () => do_action('admin_notices'));
        self::assertStringContainsString('WooCommerce فعال نیست', $out);
        self::assertStringContainsString('notice-warning', $out);

        // Limited, allowed dependency detection stays available.
        $health = Bootstrap::container()->get(HealthReportBuilder::class)->build()->toContractArray();
        self::assertFalse($health['dependencies']['woocommerce']['available']);
        self::assertNull($health['dependencies']['woocommerce']['version']);
        self::assertNull($health['dependencies']['hpos']['enabled'], 'unknown must stay null, never false');
        self::assertSame('blocked', $health['outbound']['tmc']);
    }

    public function testWithWooCommerceNoNoticeAndDependencyFactsReported(): void
    {
        $this->bootPlugin(true, '11.0.1', true);
        $this->loginAdmin();
        $out = $this->capture(static fn () => do_action('admin_notices'));
        self::assertStringNotContainsString('WooCommerce فعال نیست', $out);
        $health = Bootstrap::container()->get(HealthReportBuilder::class)->build()->toContractArray();
        self::assertTrue($health['dependencies']['woocommerce']['available']);
        self::assertSame('11.0.1', $health['dependencies']['woocommerce']['version']);
        self::assertTrue($health['dependencies']['hpos']['enabled']);
    }

    public function testPluginsLoadedTwiceNeverDuplicatesHooks(): void
    {
        $this->bootPlugin(false);
        $menuHooks = count(State::$hooks['admin_menu'][10] ?? []);
        $initHooks = count(State::$hooks['admin_init'][10] ?? []);
        do_action('plugins_loaded');
        self::assertSame(1, $menuHooks);
        self::assertSame($menuHooks, count(State::$hooks['admin_menu'][10]));
        self::assertSame($initHooks, count(State::$hooks['admin_init'][10]));
    }

    public function testHposDeclarationIsGuardedAndUsesFeaturesUtilWhenPresent(): void
    {
        $this->bootPlugin(false);
        do_action('before_woocommerce_init'); // no FeaturesUtil class: must be a silent no-op
        self::assertTrue(true);

        if (!class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false)) {
            eval('namespace Automattic\\WooCommerce\\Utilities; class FeaturesUtil { public static array $calls = []; public static function declare_compatibility(string $feature, string $file, bool $compatible = true): bool { self::$calls[] = [$feature, $file, $compatible]; return true; } }');
        }
        do_action('before_woocommerce_init');
        self::assertSame([['custom_order_tables', self::MAIN_FILE, true]], \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls);
    }

    public function testEnvironmentOptionNeverUnlocksOutbound(): void
    {
        State::$options['tmc_settings'] = ['schema_version' => 1, 'values' => ['environment_override' => 'production']];
        State::$environmentType = 'staging';
        $this->bootPlugin(false);
        $health = Bootstrap::container()->get(HealthReportBuilder::class)->build()->toContractArray();
        self::assertSame(['resolved' => 'production', 'source' => 'option'], $health['environment']);
        self::assertSame(['tmc' => 'blocked', 'other_plugins' => 'unknown'], $health['outbound']);
    }
}
