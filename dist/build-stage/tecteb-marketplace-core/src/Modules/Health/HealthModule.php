<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Infrastructure\HealthHooks;

/**
 * Health module (CORE-06). Does not require WooCommerce: it is exactly the
 * module that must keep working when WooCommerce is absent (correction 6).
 */
final class HealthModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'health',
            $this->version(),
            'سلامت',
            ModuleKind::Operational,
            ['core', 'environment-guard'],
            false,
            'گزارش سلامت و endpoint خصوصی REST'
        );
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind(HealthReportBuilder::class, static function (ContainerInterface $c): HealthReportBuilder {
            /** @var \ArrayObject $version */
            $version = $c->get('tmc.version');
            /** @var ModuleLoader $loader */
            $loader = $c->get(ModuleLoader::class);
            return new HealthReportBuilder(
                $c->get(DependencyProbeInterface::class),
                $c->get(EnvironmentResolver::class),
                $c->get(ClockInterface::class),
                (string) $version['version'],
                static fn () => $loader->report(),
                $c->get(MigrationRunner::class),
                $c->get(SettingsService::class)
            );
        });
    }

    public function boot(ContainerInterface $container): void
    {
        HealthHooks::register($container);
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
