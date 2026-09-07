<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;

final class SpyModule implements ModuleInterface
{
    public int $registerCalls = 0;
    public int $bootCalls = 0;

    /** @param list<string> $dependencies */
    public function __construct(
        private string $id,
        private array $dependencies,
        private CallLog $log,
        private ?\Throwable $registerThrows = null,
        private ?\Throwable $bootThrows = null,
        private bool $requiresWooCommerce = false,
        private ModuleKind $kind = ModuleKind::Operational
    ) {
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest($this->id, '0.0.1', 'spy ' . $this->id, $this->kind, $this->dependencies, $this->requiresWooCommerce);
    }

    public function register(ContainerInterface $container): void
    {
        $this->registerCalls++;
        $this->log->add('register:' . $this->id);
        if ($this->registerThrows !== null) {
            throw $this->registerThrows;
        }
    }

    public function boot(ContainerInterface $container): void
    {
        $this->bootCalls++;
        $this->log->add('boot:' . $this->id);
        if ($this->bootThrows !== null) {
            throw $this->bootThrows;
        }
    }
}
