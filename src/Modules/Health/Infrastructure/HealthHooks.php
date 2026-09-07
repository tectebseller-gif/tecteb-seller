<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Infrastructure;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Health\Application\HealthReportBuilder;
use Tecteb\Marketplace\Modules\Health\Infrastructure\Rest\HealthController;

final class HealthHooks
{
    public static function register(ContainerInterface $container): void
    {
        add_action('rest_api_init', static function () use ($container): void {
            $controller = new HealthController(
                $container->get(HealthReportBuilder::class),
                $container->get(CapabilityCheckerInterface::class)
            );
            $controller->registerRoute();
        });
    }
}
