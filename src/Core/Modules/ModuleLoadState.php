<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Modules;

use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\ModuleStatus;

/**
 * Per-module outcome of a load. `reason` is a machine code plus a short,
 * sanitised detail (never a stack trace); the presentation layer maps
 * codes to Persian text.
 */
final class ModuleLoadState
{
    public function __construct(
        public readonly ModuleManifest $manifest,
        public readonly ModuleStatus $status,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reasonDetail = null,
        public readonly ?string $phase = null
    ) {
    }

    public function id(): string
    {
        return $this->manifest->id;
    }

    public function kind(): ModuleKind
    {
        return $this->manifest->kind;
    }

    public function isActive(): bool
    {
        return $this->status === ModuleStatus::Active;
    }

    public function with(ModuleStatus $status, ?string $code = null, ?string $detail = null, ?string $phase = null): self
    {
        return new self($this->manifest, $status, $code, $detail, $phase);
    }
}
