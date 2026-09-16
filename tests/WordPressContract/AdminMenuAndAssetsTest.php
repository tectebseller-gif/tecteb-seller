<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Admin\Presentation\AssetLoader;
use TmcWpStubs\State;

/** CORE-03/05: four capability-gated pages; assets only on the plugin's own screens. */
final class AdminMenuAndAssetsTest extends ContractTestCase
{
    public function testMenuHasExactlyTheCapabilityGatedPagesOfTheLoadedModules(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        $top = array_values(array_filter(State::$menus, static fn ($m) => $m['parent'] === null));
        self::assertCount(1, $top);
        self::assertSame('بازارگاه تک‌طب', $top[0]['menuTitle']);
        self::assertSame('tmc_view_dashboard', $top[0]['capability']);
        self::assertSame('tmc-dashboard', $top[0]['slug']);

        $subs = array_values(array_filter(State::$menus, static fn ($m) => $m['parent'] === 'tmc-dashboard'));
        // Four from the admin module, then vendor, finance and product —
        // every one of them through the same registrar, so they inherit the
        // capability gate and the "styles on plugin screens only" rule.
        $expected = [
            ['tmc-dashboard', 'tmc_view_dashboard', 'پیشخوان'],
            ['tmc-health', 'tmc_view_health', 'سلامت'],
            ['tmc-settings', 'tmc_manage_settings', 'تنظیمات'],
            ['tmc-modules', 'tmc_view_modules', 'ماژول‌ها'],
            // Operations: three of the four pages carry their OWN capability
            // rather than borrowing another's. Reading the audit trail is not
            // implied by being able to approve a vendor, and issuing an API
            // contract is not implied by being able to run a job.
            ['tmc-setup', 'tmc_manage_settings', 'راه‌اندازی'],
            ['tmc-jobs', 'tmc_manage_jobs', 'صف و سلامت اجرا'],
            ['tmc-events', 'tmc_manage_api', 'رویدادها و API'],
            ['tmc-audit', 'tmc_view_audit', 'ممیزی'],
            ['tmc-vendor-applications', 'tmc_review_vendor', 'درخواست‌های فروشندگان'],
            ['tmc-vendor-documents', 'tmc_manage_vendor_documents', 'مدارک فروشندگان'],
            ['tmc-commission-rules', 'tmc_manage_settings', 'قواعد کمیسیون'],
            ['tmc-withdrawals', 'tmc_review_withdrawals', 'تسویه و برداشت'],
            // Registered by the finance module although it is an order screen:
            // OrderModule is self-gated, and an open return has to stay
            // decidable on a day the order gate is shut (F-15).
            ['tmc-returns', 'tmc_review_withdrawals', 'مرجوعی و بازپرداخت'],
            ['tmc-product-review', 'tmc_review_products', 'بررسی محصولات'],
            ['tmc-spec-templates', 'tmc_manage_spec_templates', 'الگوهای مشخصات'],
            ['tmc-storefront', 'tmc_manage_storefront', 'وضعیت فروش بازارگاه'],
            // The phase-7 module: a shop's own codes are not here (they are on
            // the vendor's own page); what a MANAGER decides is.
            ['tmc-tickets', 'tmc_review_vendor', 'تیکت فروشندگان'],
            // «مالی و عملیاتی» (UX §12.3) plus the manager's OWN inbox. There
            // is no everybody's-notifications screen anywhere, on purpose.
            ['tmc-reports', 'tmc_review_vendor', 'گزارش‌ها'],
            // Moderation, with its OWN capability: approving what the public
            // reads about a shop is not the same permission as approving the
            // shop, so it is not `tmc_review_vendor`.
            ['tmc-reviews', 'tmc_moderate_reviews', 'نظرات'],
            ['tmc-wholesale', 'tmc_review_vendor', 'خریداران عمده'],
            // The slug carries no other plugin's name — the assertion below
            // enforces that, so «مهاجرت از دکان» is the label, not the id.
            ['tmc-import', 'tmc_review_vendor', 'مهاجرت از دکان'],
        ];
        self::assertCount(count($expected), $subs);
        foreach ($expected as $i => [$slug, $cap, $label]) {
            self::assertSame($slug, $subs[$i]['slug']);
            self::assertSame($cap, $subs[$i]['capability']);
            self::assertSame($label, $subs[$i]['menuTitle']);
        }
        foreach (State::$menus as $m) {
            self::assertStringNotContainsString('dokan', strtolower($m['slug']));
            self::assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $m['menuTitle'], 'menu labels are Persian');
        }
    }

    public function testAssetsLoadOnlyOnPluginScreensAndNeverFromCdn(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        do_action('admin_menu');

        foreach (['edit.php', 'index.php', 'plugins.php', 'toplevel_page_woocommerce', ''] as $hook) {
            do_action('admin_enqueue_scripts', $hook);
            self::assertSame([], State::$enqueued['style'], "no style on {$hook}");
            self::assertSame([], State::$enqueued['script'], "no script on {$hook}");
        }
        foreach (['toplevel_page_tmc-dashboard', 'tecteb-marketplace_page_tmc-health', 'tecteb-marketplace_page_tmc-settings', 'tecteb-marketplace_page_tmc-modules'] as $hook) {
            State::$enqueued = ['style' => [], 'script' => []];
            do_action('admin_enqueue_scripts', $hook);
            self::assertArrayHasKey(AssetLoader::STYLE_HANDLE, State::$enqueued['style'], $hook);
            self::assertArrayHasKey(AssetLoader::SCRIPT_HANDLE, State::$enqueued['script'], $hook);
            $style = State::$enqueued['style'][AssetLoader::STYLE_HANDLE];
            $script = State::$enqueued['script'][AssetLoader::SCRIPT_HANDLE];
            self::assertStringStartsWith(State::$homeUrl . '/wp-content/plugins/', $style['src']);
            self::assertStringEndsWith('assets/admin/tmc-admin.css', $style['src']);
            self::assertStringEndsWith('assets/admin/tmc-admin.js', $script['src']);
            self::assertSame(self::VERSION, $style['ver']);
            self::assertSame([], $script['deps'], 'no jQuery, no external deps');
            self::assertTrue($script['args']['in_footer']);
        }
    }

    public function testAssetFilesExistAndContainNoRemoteUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root . '/assets/admin/tmc-admin.css');
        $js = file_get_contents($root . '/assets/admin/tmc-admin.js');
        self::assertStringNotContainsString('http://', $css);
        self::assertStringNotContainsString('https://', $css);
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('http', $js);
        self::assertStringContainsString('.tmc-admin', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('--tmc-touch: 44px', $css);
        self::assertStringNotContainsString('window.tmc', $js, 'no globals');
    }
}
