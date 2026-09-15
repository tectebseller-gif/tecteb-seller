<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\ResolvedEnvironment;

/** Resolves the environment once per request and exposes it; never unlocks outbound. */
final class EnvironmentGuardModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('environment-guard', Bootstrap::pluginVersion(), 'نگهبان محیط', ModuleKind::Infrastructure, ['core'], false, 'تشخیص محیط و قفل خروجی');
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind(ResolvedEnvironment::class, static fn (ContainerInterface $c) => $c->get(EnvironmentResolver::class)->resolve());
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(ResolvedEnvironment::class);
    }
}
