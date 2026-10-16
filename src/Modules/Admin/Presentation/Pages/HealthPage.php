<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Infrastructure\Rest\HealthController;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink;

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

    /**
     * Purchase limits that have silently stopped applying.
     *
     * A Min Max grant is the fingerprint of the three values it was given
     * for, so a rule edited afterwards leaves the grant alive and dead and
     * the product allows any quantity. The exit page says so above its own
     * table; this is the same sentence for somebody who has not opened that
     * page — «هیچ حد خریدی بی هشدار از بین نرود».
     *
     * Wrapped: the Migration module may not be loaded at all, and a health
     * screen must not go blank because a module it is reporting on is
     * absent. An unavailable answer is `null`, which the view says in words
     * rather than drawing as zero.
     *
     * @return ?list<int>
     */
    private function staleGrants(): ?array
    {
        if (!$this->container->has(MigrateMinMax::class)) {
            return null;
        }
        try {
            return $this->container->get(MigrateMinMax::class)->staleGrants();
        } catch (\Throwable) {
            return null;
        }
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
                'account_hooks' => VendorAccountLink::lastSeen(),
                'stale_grants' => $this->staleGrants(),
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
            // What the SITE measured about its own theme. Read here rather
            // than claimed anywhere: this plugin cannot see the owner's
            // account page, and «the hooks never ran» and «they ran and we
            // correctly drew nothing» are opposite problems.
            'account_hooks' => VendorAccountLink::lastSeen(),
            'stale_grants' => $this->staleGrants(),
        ]);
    }
}
