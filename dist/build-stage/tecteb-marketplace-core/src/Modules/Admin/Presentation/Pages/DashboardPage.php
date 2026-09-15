<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\OutboundPolicy;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\Navigation;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;

final class DashboardPage extends AbstractPage
{
    public const SLUG = 'tmc-dashboard';
    public const CAPABILITY = Capabilities::VIEW_DASHBOARD;

    public static function menuLabel(): string
    {
        return __('پیشخوان', 'tecteb-marketplace-core');
    }

    public static function pageTitle(): string
    {
        return __('پیشخوان بازارگاه تک‌طب', 'tecteb-marketplace-core');
    }

    protected function renderAuthorized(): void
    {
        /** @var DependencyProbeInterface $deps */
        $deps = $this->container->get(DependencyProbeInterface::class);
        /** @var EnvironmentResolver $resolver */
        $resolver = $this->container->get(EnvironmentResolver::class);
        /** @var MigrationRunner $migrations */
        $migrations = $this->container->get(MigrationRunner::class);
        /** @var ModuleLoader $loader */
        $loader = $this->container->get(ModuleLoader::class);
        $report = $loader->report();
        $env = $resolver->resolve();
        /** @var \ArrayObject $version */
        $version = $this->container->get('tmc.version');

        View::render('dashboard', [
            'version' => (string) $version['version'],
            'environment' => $env,
            'outbound_tmc' => OutboundPolicy::tmcStatus(),
            'outbound_other' => OutboundPolicy::otherPluginsStatus(),
            'woocommerce_available' => $deps->woocommerceAvailable(),
            'plugins_url' => admin_url('plugins.php'),
            'schema_stored' => $migrations->currentVersion(),
            'schema_target' => $migrations->targetVersion(),
            'migration_error' => $migrations->lastError(),
            'modules_problems' => $report !== null && $report->hasProblems(),
            'links' => Navigation::items(),
            'health_url' => admin_url('admin.php?page=' . HealthPage::SLUG),
            'settings_url' => admin_url('admin.php?page=' . SettingsPage::SLUG),
        ]);
    }
}
