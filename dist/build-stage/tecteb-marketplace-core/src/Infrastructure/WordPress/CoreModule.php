<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;

/** Infrastructure root node of the dependency graph. Bindings live in Bootstrap. */
final class CoreModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('core', Bootstrap::pluginVersion(), 'هسته', ModuleKind::Infrastructure, [], false, 'bootstrap، container، loader، migration، config');
    }

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(ContainerInterface $container): void
    {
    }
}
