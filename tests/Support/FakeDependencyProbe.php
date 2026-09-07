<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DependencyProbeInterface;

final class FakeDependencyProbe implements DependencyProbeInterface
{
    public function __construct(
        public bool $wc = false,
        public ?string $wcVersion = null,
        public ?bool $hpos = null,
        public string $php = '8.1.34',
        public ?string $platform = null
    ) {
    }

    public function woocommerceAvailable(): bool
    {
        return $this->wc;
    }

    public function woocommerceVersion(): ?string
    {
        return $this->wcVersion;
    }

    public function hposEnabled(): ?bool
    {
        return $this->hpos;
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function platformVersion(): ?string
    {
        return $this->platform;
    }
}
