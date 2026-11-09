<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use TmcWpStubs\State;

/** CORE-01/02/04/06 + owner correction 6 (WooCommerce absent: explicit, testable, no fatal). */
final class BootstrapTest extends ContractTestCase
{
    public function testWithoutWooCommerceLimitedModeBootsWithoutFatalAndReportsIt(): void
    {
        $report = $this->bootPlugin(false);

        // The vendor module is real now, and it does NOT need WooCommerce:
        // onboarding is identity and paperwork, not products.
        // Finance is real too, and also does not need WooCommerce: commission
        // rules and the ledger are this plugin's own tables, and the thing
        // that WOULD need WooCommerce — reading a paid order — is precisely
        // the part held back for the open financial decisions.
        foreach (['core', 'environment-guard', 'admin', 'health', 'vendor', 'finance'] as $id) {
            self::assertSame(ModuleStatus::Active, $report->status($id), $id . ' stays available without WooCommerce: ' . (($report->state($id)?->reasonCode ?? '?') . ' / ' . ($report->state($id)?->reasonDetail ?? '?') . ' @ ' . ($report->state($id)?->phase ?? '?')));
        }
        self::assertSame(ModuleStatus::Planned, $report->status('settlement'));
        // Migration is a real module now, and it does not need WooCommerce:
        // reading Dokan's users and posts is WordPress, and the dry run writes
        // nothing at all.
        self::assertSame(ModuleStatus::Active, $report->status('migration'));
        // Orders are a real module that refuses to run — here for the plainer
        // reason that WooCommerce is absent, which is checked first.
        self::assertSame(ModuleStatus::Blocked, $report->status('order'));
        self::assertSame('requires_woocommerce', $report->state('order')?->reasonCode);
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

    /**
     * ⛔ REWRITTEN THIS ROUND, not loosened — and what it measures is stronger
     * than what it used to.
     *
     * It used to assert `Blocked` with DEC-02 and DEC-04 in the reason, which
     * was true for twenty releases and is the exact state the owner's answers
     * changed: «ثبت سفارش واقعی مجاز است» once the guards pass, and
     * `OPEN_DECISIONS` is empty (`OwnerDecisions::FIN_04`). Keeping the old
     * assertion would have pinned a decision the owner has made, and deleting
     * the test would have dropped the property that still matters most —
     * that the module gates ITSELF rather than riding on a missing dependency.
     *
     * So it now measures both sides of that gate on one boot: active with
     * WooCommerce present and Dokan absent, and `self_gated` the moment Dokan
     * is there. The second half is the one that has to hold on the owner's
     * site, because 184 transferred products are attributed by `post_author`
     * to a Dokan vendor and by `link_ownership` to the marketplace, so two
     * systems would record one sale.
     */
    public function testWithWooCommerceAndNoDokanTheOrderModuleRuns(): void
    {
        $this->bootPlugin(true);
        $report = Bootstrap::report();

        // The owner answered DEC-02 and DEC-04, so a missing definition is no
        // longer in the way.
        self::assertSame(ModuleStatus::Active, $report?->status('order'));
        self::assertSame(
            [],
            OrderOperationsGate::OPEN_DECISIONS,
            'and the list the old assertion read is empty, which is why'
        );

        // And finance is active beside it, as it always was.
        self::assertSame(ModuleStatus::Active, $report?->status('finance'));

    }

    /**
     * ⛔ AND THE OTHER SIDE OF THE SAME GATE: with Dokan present the order
     * module gates ITSELF, so no capture hook is ever registered.
     *
     * This is the half that has to hold on the owner's site. Shop 102's 184
     * transferred products are attributed by `post_author` to a Dokan vendor
     * — `TransferOwnership` deliberately never changes it — and by
     * `link_ownership` to the marketplace, so both systems would record one
     * sale. Until this release a build could not sell anyway because
     * `OPEN_DECISIONS` had names in it; now that it is empty, this is the only
     * thing in the way, and the trial switch cannot answer it.
     *
     * ⛔ IN ITS OWN PROCESS, because the class has to exist BEFORE the boot.
     * The first version of this test eval'd `WeDevs_Dokan` and booted again in
     * the same method, and the module stayed `Active`: `Bootstrap::init()` is
     * static and the module report is computed once. That is `alpha.37` in
     * miniature — framework sequencing is measured, not remembered — and the
     * fix is a process where the detection is true from the first boot rather
     * than a second boot that was never going to recompute.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithDokanPresentTheOrderModuleGatesItself(): void
    {
        // Before any boot: this is what a site with Dokan installed looks like
        // to `WpDokanPresence`, whose cheapest signal is this class.
        eval('class WeDevs_Dokan {}');

        $this->bootPlugin(true);
        $report = Bootstrap::report();

        self::assertSame(ModuleStatus::Blocked, $report?->status('order'));
        self::assertSame(
            'self_gated',
            $report?->state('order')?->reasonCode,
            'the module gates itself; it is not a missing dependency'
        );
        self::assertStringContainsString(
            OrderOperationsGate::DOKAN_RUNNING,
            (string) $report?->state('order')?->reasonDetail,
            'and the reason names Dokan rather than a decision'
        );

        // Finance is untouched beside it: the Dokan guard is about RECORDING a
        // sale, not about the module that keeps the books.
        self::assertSame(ModuleStatus::Active, $report?->status('finance'));
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
