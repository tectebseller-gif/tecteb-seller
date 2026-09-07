<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Modules;

use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;

/**
 * Holds operational modules (with code) and planned manifests (roadmap only).
 * Duplicate ids are rejected and recorded; the first registration wins.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, ModuleManifest> */
    private array $manifests = [];

    /** @var list<string> insertion order */
    private array $order = [];

    /** @var list<array{id:string, reason:string}> */
    private array $errors = [];

    public function add(ModuleInterface $module): bool
    {
        $manifest = $module->manifest();
        if ($manifest->kind === ModuleKind::Planned) {
            $this->errors[] = ['id' => $manifest->id, 'reason' => 'planned_with_code'];
            return false;
        }
        if (!$this->reserve($manifest)) {
            return false;
        }
        $this->modules[$manifest->id] = $module;
        return true;
    }

    /** Roadmap entry: manifest only, never registered or booted. */
    public function addPlanned(ModuleManifest $manifest): bool
    {
        if ($manifest->kind !== ModuleKind::Planned) {
            $this->errors[] = ['id' => $manifest->id, 'reason' => 'not_planned_kind'];
            return false;
        }
        return $this->reserve($manifest);
    }

    private function reserve(ModuleManifest $manifest): bool
    {
        if (isset($this->manifests[$manifest->id])) {
            $this->errors[] = ['id' => $manifest->id, 'reason' => 'duplicate_id'];
            return false;
        }
        $this->manifests[$manifest->id] = $manifest;
        $this->order[] = $manifest->id;
        return true;
    }

    /** @return array<string, ModuleManifest> in insertion order */
    public function manifests(): array
    {
        $out = [];
        foreach ($this->order as $id) {
            $out[$id] = $this->manifests[$id];
        }
        return $out;
    }

    public function module(string $id): ?ModuleInterface
    {
        return $this->modules[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->manifests[$id]);
    }

    /** @return list<array{id:string, reason:string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
