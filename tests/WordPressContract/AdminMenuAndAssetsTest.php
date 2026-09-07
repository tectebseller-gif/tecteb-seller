<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Admin\Presentation\AssetLoader;
use TmcWpStubs\State;

/** CORE-03/05: four capability-gated pages; assets only on the plugin's own screens. */
final class AdminMenuAndAssetsTest extends ContractTestCase
{
    public function testMenuHasExactlyFourCapabilityGatedPages(): void
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
        $expected = [
            ['tmc-dashboard', 'tmc_view_dashboard', 'پیشخوان'],
            ['tmc-health', 'tmc_view_health', 'سلامت'],
            ['tmc-settings', 'tmc_manage_settings', 'تنظیمات'],
            ['tmc-modules', 'tmc_view_modules', 'ماژول‌ها'],
        ];
        self::assertCount(4, $subs);
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
