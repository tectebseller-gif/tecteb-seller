<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Core\Modules\ModuleRegistry;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;
use Tecteb\Marketplace\Tests\Support\CallLog;
use Tecteb\Marketplace\Tests\Support\SpyModule;
use TmcWpStubs\State;
use TmcWpStubs\WpDieException;

/** CORE-03/05: capability gates on render, Persian RTL shell, no fake stats, honest health rows. */
final class PagesTest extends ContractTestCase
{
    /** @return array<string, class-string> */
    private static function pages(): array
    {
        return [
            'tmc_view_dashboard' => DashboardPage::class,
            'tmc_view_health' => HealthPage::class,
            'tmc_manage_settings' => SettingsPage::class,
            'tmc_view_modules' => ModulesPage::class,
        ];
    }

    public function testEveryPageDiesWith403ForGuestSubscriberAndSellerWithoutCapability(): void
    {
        $this->bootPlugin(false);
        $identities = [
            'guest' => static fn () => State::logout(),
            'subscriber' => static fn () => State::loginAs(5, ['read']),
            'seller-like' => static fn () => State::loginAs(6, ['read', 'edit_products', 'dokandar']),
            'admin-without-tmc-caps' => static fn () => State::loginAs(7, ['manage_options']),
        ];
        foreach ($identities as $name => $login) {
            foreach (self::pages() as $cap => $class) {
                $login();
                $page = new $class(Bootstrap::container());
                $output = '';
                try {
                    $output = $this->capture(static fn () => $page->render());
                    self::fail("{$class} rendered for {$name}");
                } catch (WpDieException $e) {
                    self::assertSame(403, $e->args['response']);
                    self::assertStringContainsString('مجوز', $e->getMessage());
                    self::assertStringNotContainsString($cap, $e->getMessage(), 'neutral message: does not reveal the capability');
                }
                self::assertSame('', $output);
            }
        }
    }

    public function testDashboardIsPersianRtlWithRealLinksAndNoFabricatedStats(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        $out = $this->capture(static fn () => (new DashboardPage(Bootstrap::container()))->render());
        self::assertStringContainsString('class="wrap tmc-admin" dir="rtl" lang="fa"', $out);
        self::assertStringContainsString('بازارگاه تک‌طب', $out);
        self::assertStringContainsString('نسخه آزمایشی', $out);
        self::assertStringContainsString('مسدود', $out);
        self::assertStringContainsString('بررسی‌نشده', $out);
        self::assertStringContainsString('WooCommerce فعال نیست', $out);
        self::assertStringContainsString('plugins.php', $out);
        self::assertStringContainsString('آنچه در این نسخه نیست', $out);
        foreach (['tmc-dashboard', 'tmc-health', 'tmc-settings', 'tmc-modules'] as $slug) {
            self::assertStringContainsString('admin.php?page=' . $slug, $out);
        }
        // The page's own "what this release does NOT contain" card legitimately
        // uses words like «درآمد»; only the part above it may present figures.
        $split = strpos($out, 'tmc-card--muted');
        self::assertNotFalse($split);
        $body = substr($out, 0, $split);
        self::assertStringContainsString('هیچ آمار فروش یا درآمدی نمایش داده نمی‌شود', $out, 'the absence of data is stated, not filled with zeros');
        foreach (['درآمد', 'فروش امروز', 'تومان', 'ریال', 'سفارش', 'کمیسیون'] as $fake) {
            self::assertStringNotContainsString($fake, $body, 'no fabricated business statistic above the disclaimer');
        }
        self::assertDoesNotMatchRegularExpression('/<dd>\s*[۰-۹0-9]+\s*<\/dd>/u', $body, 'no bare numeric stat values');
        // The version is direction-isolated. Asserted by intent, not by the
        // exact attribute list: the chip gained a class when the layout was
        // rebuilt, and that must not read as the isolation disappearing.
        self::assertMatchesRegularExpression('/<bdi[^>]*\bdir="ltr"[^>]*>0\.1\.0-alpha\.1<\/bdi>/u', $out);
    }

    public function testHealthPageSeparatesEnabledFromTestedAndHidesTraces(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        $out = $this->capture(static fn () => (new HealthPage(Bootstrap::container()))->render());
        self::assertStringContainsString('HPOS فعال است؟', $out);
        self::assertStringContainsString('HPOS آزموده شده؟', $out);
        self::assertStringContainsString('نیازمند اقدام', $out);
        self::assertStringContainsString('نامشخص', $out);
        self::assertStringContainsString('ارسال‌های خود افزونه (TMC)', $out);
        self::assertStringContainsString('ارسال‌های سایر افزونه‌ها', $out);
        self::assertStringContainsString('<time datetime="', $out);
        self::assertStringContainsString('/wp-json/tmc/v1/health', $out);
        self::assertStringNotContainsString('Stack trace', $out);
        self::assertStringNotContainsString(dirname(__DIR__, 2), $out, 'no server path');
        self::assertStringContainsString('<caption class="tmc-sr-only">', $out);
    }

    public function testHealthPageDegradesWhenHealthModuleIsNotActive(): void
    {
        $registry = new ModuleRegistry();
        $log = new CallLog();
        $registry->add(new SpyModule('core', [], $log));
        $registry->add(new SpyModule('health', ['core'], $log, new \RuntimeException('binding failed in /srv/secret/path.php')));
        $container = new Container();
        $loader = new ModuleLoader($registry);
        $container->instance(ModuleLoader::class, $loader);
        $report = $loader->load($container, false);
        self::assertSame(ModuleStatus::Degraded, $report->status('health'));

        $this->loginAdmin();
        $out = $this->capture(static fn () => (new HealthPage($container))->render());
        self::assertStringContainsString('ماژول سلامت فعال نیست', $out);
        self::assertStringContainsString('RuntimeException: binding failed', $out);
        self::assertStringContainsString('مرحله register', $out);
        self::assertStringNotContainsString('#0', $out, 'no stack frames');
    }

    public function testModulesPageShowsReasonsAndNoActivationButtonForPlanned(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        $out = $this->capture(static fn () => (new ModulesPage(Bootstrap::container()))->render());
        self::assertStringContainsString('برنامه‌ریزی‌شده', $out);
        self::assertStringContainsString('فعال‌سازی در این نسخه ممکن نیست', $out);
        self::assertStringContainsString('نقشه راه', $out);
        self::assertStringNotContainsString('<button', $out, 'planned modules never get an activation control');
        self::assertMatchesRegularExpression('/<bdi[^>]*\bdir="ltr"[^>]*>vendor<\/bdi>/u', $out);
        self::assertStringContainsString('نیازمند WooCommerce', $out);
        self::assertSame(10, substr_count($out, 'class="tmc-card tmc-module"'));
    }

    public function testSettingsPageStatesUnsetVersusZeroAndLabelsEveryControl(): void
    {
        $this->bootPlugin(false);
        $this->loginAdmin();
        $out = $this->capture(static fn () => (new SettingsPage(Bootstrap::container()))->render());
        self::assertStringContainsString('هنوز تعیین نشده.', $out);
        self::assertStringContainsString('name="tmc_settings[default_commission_rate]"', $out);
        self::assertStringNotContainsString('name="tmc_settings[default_commission_rate_bp]"', $out, 'stored key is never a form input');
        self::assertStringContainsString('name="_wpnonce"', $out);
        self::assertStringContainsString('هنوز مصرف عملیاتی ندارند', $out);
        self::assertStringContainsString('این محدودیت را دور نمی‌زند', $out);
        self::assertStringContainsString('حد فنی پیشنهادی', $out);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $out);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $controls = $xpath->query('//input[not(@type="hidden")] | //select');
        self::assertGreaterThanOrEqual(4, $controls->length);
        foreach ($controls as $control) {
            $id = $control->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertSame(1, $xpath->query('//label[@for="' . $id . '"]')->length, "label for {$id}");
            self::assertNotSame('', $control->getAttribute('aria-describedby'));
        }

        State::$options['tmc_settings'] = ['schema_version' => 1, 'values' => ['default_commission_rate_bp' => 0]];
        $out = $this->capture(static fn () => (new SettingsPage(Bootstrap::container()))->render());
        self::assertStringContainsString('صفر یعنی بدون کمیسیون.', $out);
        self::assertStringContainsString('value="0"', $out);
        State::$options['tmc_settings'] = ['schema_version' => 1, 'values' => ['default_commission_rate_bp' => 1234]];
        $out = $this->capture(static fn () => (new SettingsPage(Bootstrap::container()))->render());
        self::assertStringContainsString('value="12.34"', $out);
    }
}
