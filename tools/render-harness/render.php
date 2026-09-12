<?php
/**
 * Render harness — DEVELOPMENT ONLY, NOT part of the plugin or the ZIP.
 *
 * Renders the four admin views outside WordPress, through the same stub layer
 * the contract suite uses, so the markup can be measured in a real browser
 * (viewports, reflow, contrast, keyboard order, axe).
 *
 * WHAT THE OUTPUT IS: a sample of the interface («نمونه رابط»).
 * WHAT IT IS NOT: evidence that the plugin works inside wp-admin. Real
 * wp-admin rendering stays Not Run — see docs/compatibility-matrix.md.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/src/Core/Autoloader.php';
Tecteb\Marketplace\Core\Autoloader::register($root . '/src');
require $root . '/tools/wp-stubs/load.php';

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Infrastructure\SettingsRegistrar;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\DashboardPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\HealthPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\ModulesPage;
use Tecteb\Marketplace\Modules\Admin\Presentation\Pages\SettingsPage;
use Tecteb\Marketplace\Tests\Support\FakeDependencyProbe;
use Tecteb\Marketplace\Tests\Support\RecordingAuditRepository;
use TmcWpStubs\State;

require $root . '/tests/Support/FakeDependencyProbe.php';
require $root . '/tests/Support/RecordingAuditRepository.php';

const MAIN_FILE = '/srv/www/wp-content/plugins/tecteb-marketplace-core/tecteb-marketplace-core.php';
const VERSION = '0.1.0-alpha.3';

$outDir = $root . '/docs/evidence/harness';
if (!is_dir($outDir)) {
    mkdir($outDir, 0o755, true);
}

/** Boots the plugin under stubs and returns the container. */
function boot(bool $wc, ?string $wcVersion = null, ?bool $hpos = null): ContainerInterface
{
    State::reset();
    Bootstrap::reset();
    State::loginAs(1, ['manage_options', 'activate_plugins', 'tmc_view_dashboard', 'tmc_view_health', 'tmc_manage_settings', 'tmc_view_modules']);
    Bootstrap::init(MAIN_FILE, VERSION);
    $c = Bootstrap::container();
    $probe = new FakeDependencyProbe($wc, $wcVersion, $hpos, '8.1.34', '6.7.1');
    $audit = new RecordingAuditRepository();
    $c->bind(DependencyProbeInterface::class, static fn () => $probe);
    $c->bind(AuditRepositoryInterface::class, static fn () => $audit);
    do_action('plugins_loaded');
    do_action('admin_menu');
    do_action('admin_init');
    return $c;
}

function capture(callable $fn): string
{
    ob_start();
    try {
        $fn();
    } finally {
        $out = (string) ob_get_clean();
    }
    return $out;
}

function document(string $title, string $body): string
{
    $css = 'file://' . dirname(__DIR__, 2) . '/assets/admin/tmc-admin.css';
    $js = 'file://' . dirname(__DIR__, 2) . '/assets/admin/tmc-admin.js';
    return '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' — نمونه رابط (خارج WordPress)</title>'
        . '<link rel="stylesheet" href="' . $css . '">'
        // Minimal stand-in for the wp-admin frame: only a page background and a
        // readable base font. The plugin's own styles are the real CSS file.
        . '<style>body{margin:0;padding:20px;background:#f0f0f1;'
        . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Naskh Arabic","Tahoma",sans-serif;}'
        . '.harness-banner{background:#143C4D;color:#fff;padding:8px 12px;border-radius:8px;margin-bottom:16px;font-size:14px}</style>'
        . '</head><body>'
        . '<p class="harness-banner">نمونه رابط (خارج WordPress) — این تصویر اثبات کارکرد افزونه نیست.</p>'
        . $body
        . '<script src="' . $js . '"></script></body></html>';
}

$scenarios = [];

// 1. Dashboard, WooCommerce absent (limited mode).
$c = boot(false);
$scenarios['dashboard-no-wc'] = ['عنوان' => 'پیشخوان — بدون WooCommerce', 'html' => capture(static fn () => (new DashboardPage($c))->render())];

// 2. Dashboard with WooCommerce present.
$c = boot(true, '11.0.1', true);
State::$options['tmc_schema_version'] = 1;
$scenarios['dashboard-with-wc'] = ['عنوان' => 'پیشخوان — با WooCommerce', 'html' => capture(static fn () => (new DashboardPage($c))->render())];

// 3. Health, WooCommerce absent: action-required and unknown rows both visible.
$c = boot(false);
$scenarios['health-no-wc'] = ['عنوان' => 'سلامت — بدون WooCommerce', 'html' => capture(static fn () => (new HealthPage($c))->render())];

// 4. Health with WooCommerce and a recorded migration failure.
$c = boot(true, '11.0.1', false);
State::$options['tmc_schema_version'] = 1;
State::$options['tmc_migration_last_error'] = ['step' => '0002_example', 'message' => 'MigrationException: CREATE TABLE failed', 'at' => '2026-09-06T06:30:00+00:00'];
$scenarios['health-with-error'] = ['عنوان' => 'سلامت — با خطای ثبت‌شده', 'html' => capture(static fn () => (new HealthPage($c))->render())];

// 5. Settings, defaults (commission unset).
$c = boot(false);
$scenarios['settings-default'] = ['عنوان' => 'تنظیمات — پیش‌فرض', 'html' => capture(static fn () => (new SettingsPage($c))->render())];

// 6. Settings after a rejected value: error summary + success/warning notices.
$c = boot(false);
State::$options['tmc_settings'] = ['schema_version' => 1, 'values' => ['default_commission_rate_bp' => 1234, 'settlement_delay_days' => 0, 'max_staff' => 10, 'environment_override' => 'staging']];
add_settings_error(SettingsRegistrar::ERROR_SETTING, 'tmc_field_default_commission_rate', 'درصد کمیسیون عمومی: باید بین ۰ و ۱۰۰ باشد. مقدار قبلی حفظ شد.', 'error');
add_settings_error(SettingsRegistrar::ERROR_SETTING, 'tmc_field_max_staff', 'حداکثر پرسنل هر فروشگاه: باید عدد صحیح بین ۱ و ۱۰۰ باشد. مقدار قبلی حفظ شد.', 'error');
add_settings_error(SettingsRegistrar::ERROR_SETTING, 'tmc_audit_failed', 'تنظیمات ذخیره شد، اما ثبت رویداد ممیزی ناموفق بود. این مورد را در صفحه سلامت بررسی کنید.', 'warning');
$scenarios['settings-errors'] = ['عنوان' => 'تنظیمات — با خطای اعتبارسنجی', 'html' => capture(static fn () => (new SettingsPage($c))->render())];

// 7. Modules, normal.
$c = boot(false);
$scenarios['modules'] = ['عنوان' => 'ماژول‌ها', 'html' => capture(static fn () => (new ModulesPage($c))->render())];

// 8. Modules with a degraded module and its blocked dependents.
$c = boot(false);
(static function (ContainerInterface $c): void {
    $registry = Bootstrap::kernel()->registry();
    $ref = new ReflectionClass($registry);
    // Inject a failing operational module plus a dependent, then reload.
    $failing = new class implements Tecteb\Marketplace\Contracts\ModuleInterface {
        public function manifest(): Tecteb\Marketplace\Contracts\ModuleManifest
        {
            return new Tecteb\Marketplace\Contracts\ModuleManifest('reports', '0.1.0', 'گزارش‌ها', Tecteb\Marketplace\Contracts\ModuleKind::Operational, ['core'], false, 'نمونه ماژول خراب برای نمایش حالت');
        }
        public function register(Tecteb\Marketplace\Contracts\ContainerInterface $container): void
        {
            throw new RuntimeException('binding "reports.repository" is not configured');
        }
        public function boot(Tecteb\Marketplace\Contracts\ContainerInterface $container): void
        {
        }
    };
    $dependent = new class implements Tecteb\Marketplace\Contracts\ModuleInterface {
        public function manifest(): Tecteb\Marketplace\Contracts\ModuleManifest
        {
            return new Tecteb\Marketplace\Contracts\ModuleManifest('exports', '0.1.0', 'خروجی‌ها', Tecteb\Marketplace\Contracts\ModuleKind::Operational, ['reports'], false, 'وابسته به گزارش‌ها');
        }
        public function register(Tecteb\Marketplace\Contracts\ContainerInterface $container): void
        {
        }
        public function boot(Tecteb\Marketplace\Contracts\ContainerInterface $container): void
        {
        }
    };
    $registry->add($failing);
    $registry->add($dependent);
    $loaderProp = new ReflectionProperty(ModuleLoader::class, 'report');
    $loader = $c->get(ModuleLoader::class);
    $loaderProp->setValue($loader, null);
    $loader->load($c, false);
})($c);
$scenarios['modules-degraded'] = ['عنوان' => 'ماژول‌ها — با ماژول خراب و وابسته متوقف', 'html' => capture(static fn () => (new ModulesPage($c))->render())];

$index = [];
foreach ($scenarios as $name => $s) {
    $file = $outDir . '/' . $name . '.html';
    file_put_contents($file, document($s['عنوان'], $s['html']));
    $index[] = ['name' => $name, 'title' => $s['عنوان'], 'file' => $file, 'bytes' => strlen($s['html'])];
    printf("%-22s %6d bytes  %s\n", $name, strlen($s['html']), $file);
}
file_put_contents($outDir . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nrendered " . count($index) . " scenarios\n";
