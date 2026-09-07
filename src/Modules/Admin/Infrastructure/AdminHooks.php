<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Infrastructure;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Admin\Application\SettingsSubmission;
use Tecteb\Marketplace\Modules\Admin\Presentation\AssetLoader;
use Tecteb\Marketplace\Modules\Admin\Presentation\MenuRegistrar;

/** All admin-side WordPress hooks of the module, attached once in boot(). */
final class AdminHooks
{
    public static function register(ContainerInterface $container): void
    {
        $menu = new MenuRegistrar($container);
        $container->instance(MenuRegistrar::class, $menu);
        add_action('admin_menu', [$menu, 'register']);

        $registrar = new SettingsRegistrar($container->get(SettingsSubmission::class), $container->get(SettingsService::class));
        $container->instance(SettingsRegistrar::class, $registrar);
        add_action('admin_init', [$registrar, 'register']);

        $version = class_exists(Bootstrap::class, false) ? Bootstrap::pluginVersion() : '0.0.0';
        $baseUrl = class_exists(Bootstrap::class, false) && Bootstrap::mainFile() !== '' ? Bootstrap::pluginDirUrl() : '';
        $assets = new AssetLoader($menu, $baseUrl, $version);
        add_action('admin_enqueue_scripts', [$assets, 'enqueue']);
    }
}
