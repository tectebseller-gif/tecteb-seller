<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\Admin\ApplicationsPage;
use Tecteb\Marketplace\Modules\Vendor\Presentation\Admin\DocumentTypesPage;

/** Everything the vendor module hangs on WordPress, in one readable place. */
final class VendorHooks
{
    public static function register(ContainerInterface $container): void
    {
        VendorCapabilityPolicy::register();

        $baseUrl = Bootstrap::mainFile() !== '' ? Bootstrap::pluginDirUrl() : '';
        $version = Bootstrap::pluginVersion();
        VendorRoutes::register($container, $baseUrl, $version);

        // The manager's two screens live under the existing «بازارگاه تک‌طب»
        // menu and register through the admin module's own registrar, so the
        // stylesheet rule (plugin screens only) still holds.
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($container): array {
            $applications = new ApplicationsPage($container);
            $documents = new DocumentTypesPage($container);
            $pages[] = [
                'slug' => ApplicationsPage::SLUG,
                'page_title' => ApplicationsPage::menuLabel(),
                'menu_label' => ApplicationsPage::menuLabel(),
                'capability' => ApplicationsPage::CAPABILITY,
                'render' => [$applications, 'render'],
                'nav' => true,
            ];
            $pages[] = [
                'slug' => DocumentTypesPage::SLUG,
                'page_title' => DocumentTypesPage::menuLabel(),
                'menu_label' => DocumentTypesPage::menuLabel(),
                'capability' => DocumentTypesPage::CAPABILITY,
                'render' => [$documents, 'render'],
                'nav' => true,
            ];
            return $pages;
        });
    }
}
