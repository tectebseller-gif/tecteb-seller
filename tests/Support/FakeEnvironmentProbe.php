<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\EnvironmentProbeInterface;

final class FakeEnvironmentProbe implements EnvironmentProbeInterface
{
    public function __construct(
        public ?string $constant = null,
        public ?string $option = null,
        public ?string $platform = null,
        public ?string $host = null
    ) {
    }

    public function constantValue(): ?string
    {
        return $this->constant;
    }

    public function optionValue(): ?string
    {
        return $this->option;
    }

    public function platformEnvironment(): ?string
    {
        return $this->platform;
    }

    public function hostname(): ?string
    {
        return $this->host;
    }
}
