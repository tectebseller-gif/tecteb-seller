<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Infrastructure\Rest\HealthController;

final class HealthPage extends AbstractPage
{
    public const SLUG = 'tmc-health';
    public const CAPABILITY = Capabilities::VIEW_HEALTH;

    public static function menuLabel(): string
    {
        return __('سلامت', 'tecteb-marketplace-core');
    }

    public static function pageTitle(): string
    {
        return __('سلامت بازارگاه', 'tecteb-marketplace-core');
    }

    protected function renderAuthorized(): void
    {
        /** @var ModuleLoader $loader */
        $loader = $this->container->get(ModuleLoader::class);
        $loadReport = $loader->report();
        $healthState = $loadReport?->state('health');

        if (!$this->container->has(HealthReportBuilder::class)) {
            View::render('health', [
                'available' => false,
                'module_state' => $healthState,
                'load_report' => $loadReport,
                'rest_path' => '/wp-json/' . HealthController::NAMESPACE . HealthController::ROUTE,
            ]);
            return;
        }
        /** @var HealthReportBuilder $builder */
        $builder = $this->container->get(HealthReportBuilder::class);
        $report = $builder->build();
        View::render('health', [
            'available' => true,
            'report' => $report,
            'module_state' => $healthState,
            'load_report' => $loadReport,
            'rest_path' => '/wp-json/' . HealthController::NAMESPACE . HealthController::ROUTE,
            'modules_url' => admin_url('admin.php?page=' . ModulesPage::SLUG),
        ]);
    }
}
