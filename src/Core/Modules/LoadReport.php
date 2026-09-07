<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Modules;

use Tecteb\Marketplace\Contracts\ModuleStatus;

final class LoadReport
{
    /**
     * @param array<string, ModuleLoadState> $states in registration order
     * @param list<array{id:string, reason:string}> $registryErrors duplicate ids etc.
     */
    public function __construct(
        private array $states,
        private array $registryErrors,
        public readonly bool $wooCommerceAvailable
    ) {
    }

    /** @return array<string, ModuleLoadState> */
    public function states(): array
    {
        return $this->states;
    }

    public function state(string $id): ?ModuleLoadState
    {
        return $this->states[$id] ?? null;
    }

    public function status(string $id): ?ModuleStatus
    {
        return isset($this->states[$id]) ? $this->states[$id]->status : null;
    }

    /** @return list<array{id:string, reason:string}> */
    public function registryErrors(): array
    {
        return $this->registryErrors;
    }

    /** Exactly the shape required by the health contract: [{id, status}]. */
    public function forHealth(): array
    {
        $out = [];
        foreach ($this->states as $id => $state) {
            $out[] = ['id' => $id, 'status' => $state->status->value];
        }
        return $out;
    }

    public function hasProblems(): bool
    {
        if ($this->registryErrors !== []) {
            return true;
        }
        foreach ($this->states as $state) {
            if ($state->status === ModuleStatus::Degraded || $state->status === ModuleStatus::Blocked) {
                return true;
            }
        }
        return false;
    }
}
