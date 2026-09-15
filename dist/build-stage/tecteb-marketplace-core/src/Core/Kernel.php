<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Modules\LoadReport;
use Tecteb\Marketplace\Core\Modules\ModuleLoader;
use Tecteb\Marketplace\Core\Modules\ModuleRegistry;

/**
 * Pure composition root. WordPress-specific wiring lives in
 * Infrastructure\WordPress\Bootstrap; the kernel never touches WP.
 */
final class Kernel
{
    private ContainerInterface $container;
    private ModuleRegistry $registry;
    private ModuleLoader $loader;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? new Container();
        $this->registry = new ModuleRegistry();
        $this->loader = new ModuleLoader($this->registry);
        $this->container->instance(ModuleRegistry::class, $this->registry);
        $this->container->instance(ModuleLoader::class, $this->loader);
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function registry(): ModuleRegistry
    {
        return $this->registry;
    }

    /** Idempotent: repeated calls return the first report and never re-boot. */
    public function load(bool $wooCommerceAvailable): LoadReport
    {
        return $this->loader->load($this->container, $wooCommerceAvailable);
    }

    public function report(): ?LoadReport
    {
        return $this->loader->report();
    }
}
