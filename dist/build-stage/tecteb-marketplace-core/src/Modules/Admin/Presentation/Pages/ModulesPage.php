<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Presentation\Pages;

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\View;

final class ModulesPage extends AbstractPage
{
    public const SLUG = 'tmc-modules';
    public const CAPABILITY = Capabilities::VIEW_MODULES;

    public static function menuLabel(): string
    {
        return __('ماژول‌ها', 'tecteb-marketplace-core');
    }

    public static function pageTitle(): string
    {
        return __('ماژول‌های بازارگاه', 'tecteb-marketplace-core');
    }

    protected function renderAuthorized(): void
    {
        /** @var ModuleLoader $loader */
        $loader = $this->container->get(ModuleLoader::class);
        $report = $loader->report();
        View::render('modules', [
            'report' => $report,
            'health_url' => admin_url('admin.php?page=' . HealthPage::SLUG),
        ]);
    }
}
