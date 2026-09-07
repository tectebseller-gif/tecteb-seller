<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Modules;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Support\TextSanitizer;

/**
 * Two-phase loader (CORE-02) with failure containment (owner correction 2):
 *
 *  - register() for all modules in dependency order, then boot() in the same order
 *  - a module whose register()/boot() throws becomes Degraded
 *  - every module that depends (directly or transitively) on a Degraded,
 *    Blocked or missing module becomes Blocked and is NOT run; the reason is
 *    recorded for the health page
 *  - independent modules keep running
 *  - cycles, missing dependencies and WooCommerce-required-but-absent are
 *    Blocked with distinct reason codes
 *  - Planned manifests are never run and cannot be activated
 *  - load() is idempotent: a second call returns the first report and never
 *    re-runs boot(), so hooks are never attached twice
 */
final class ModuleLoader
{
    public const REASON_CYCLE = 'dependency_cycle';
    public const REASON_MISSING_DEPENDENCY = 'missing_dependency';
    public const REASON_DEPENDENCY_FAILED = 'dependency_failed';
    public const REASON_REQUIRES_WOOCOMMERCE = 'requires_woocommerce';
    public const REASON_EXCEPTION = 'exception';

    private ?LoadReport $report = null;

    public function __construct(private ModuleRegistry $registry)
    {
    }

    public function report(): ?LoadReport
    {
        return $this->report;
    }

    public function load(ContainerInterface $container, bool $wooCommerceAvailable): LoadReport
    {
        if ($this->report !== null) {
            return $this->report;
        }

        $manifests = $this->registry->manifests();
        /** @var array<string, ModuleLoadState> $states */
        $states = [];
        /** @var array<string, bool> $ok "healthy so far" */
        $ok = [];

        // Phase 0: planned, missing dependencies.
        $graphDeps = [];
        foreach ($manifests as $id => $manifest) {
            if ($manifest->kind === ModuleKind::Planned) {
                $states[$id] = new ModuleLoadState($manifest, ModuleStatus::Planned);
                $ok[$id] = false;
                continue;
            }
            $missing = [];
            foreach ($manifest->dependencies as $dep) {
                if (!isset($manifests[$dep]) || $manifests[$dep]->kind === ModuleKind::Planned) {
                    $missing[] = $dep;
                }
            }
            if ($missing !== []) {
                $states[$id] = new ModuleLoadState(
                    $manifest,
                    ModuleStatus::Blocked,
                    self::REASON_MISSING_DEPENDENCY,
                    implode(', ', $missing),
                    'resolve'
                );
                $ok[$id] = false;
                continue;
            }
            $graphDeps[$id] = $manifest->dependencies;
        }

        // Phase 0b: cycles.
        $graph = DependencyGraph::order($graphDeps);
        foreach ($graph['cycles'] as $id => $path) {
            $states[$id] = new ModuleLoadState(
                $manifests[$id],
                ModuleStatus::Blocked,
                self::REASON_CYCLE,
                implode(' → ', $path),
                'resolve'
            );
            $ok[$id] = false;
        }
        $order = $graph['order'];

        // Phase 1: register.
        foreach ($order as $id) {
            $manifest = $manifests[$id];
            $failedDep = $this->firstFailedDependency($manifest->dependencies, $ok, $states);
            if ($failedDep !== null) {
                $states[$id] = new ModuleLoadState(
                    $manifest,
                    ModuleStatus::Blocked,
                    self::REASON_DEPENDENCY_FAILED,
                    $failedDep,
                    'register'
                );
                $ok[$id] = false;
                continue;
            }
            if ($manifest->requiresWooCommerce && !$wooCommerceAvailable) {
                $states[$id] = new ModuleLoadState(
                    $manifest,
                    ModuleStatus::Blocked,
                    self::REASON_REQUIRES_WOOCOMMERCE,
                    null,
                    'register'
                );
                $ok[$id] = false;
                continue;
            }
            $module = $this->registry->module($id);
            try {
                $module->register($container);
                $states[$id] = new ModuleLoadState($manifest, ModuleStatus::Active, null, null, 'register');
                $ok[$id] = true;
            } catch (\Throwable $e) {
                $states[$id] = new ModuleLoadState(
                    $manifest,
                    ModuleStatus::Degraded,
                    self::REASON_EXCEPTION,
                    TextSanitizer::exceptionSummary($e),
                    'register'
                );
                $ok[$id] = false;
            }
        }

        // Phase 2: boot.
        foreach ($order as $id) {
            if (!($ok[$id] ?? false)) {
                continue;
            }
            $manifest = $manifests[$id];
            $failedDep = $this->firstFailedDependency($manifest->dependencies, $ok, $states);
            if ($failedDep !== null) {
                $states[$id] = $states[$id]->with(
                    ModuleStatus::Blocked,
                    self::REASON_DEPENDENCY_FAILED,
                    $failedDep,
                    'boot'
                );
                $ok[$id] = false;
                continue;
            }
            $module = $this->registry->module($id);
            try {
                $module->boot($container);
                $states[$id] = $states[$id]->with(ModuleStatus::Active, null, null, 'boot');
            } catch (\Throwable $e) {
                $states[$id] = $states[$id]->with(
                    ModuleStatus::Degraded,
                    self::REASON_EXCEPTION,
                    TextSanitizer::exceptionSummary($e),
                    'boot'
                );
                $ok[$id] = false;
            }
        }

        // Preserve registration order in the report.
        $ordered = [];
        foreach ($manifests as $id => $_) {
            $ordered[$id] = $states[$id];
        }
        $this->report = new LoadReport($ordered, $this->registry->errors(), $wooCommerceAvailable);
        return $this->report;
    }

    /**
     * @param list<string> $dependencies
     * @param array<string,bool> $ok
     * @param array<string, ModuleLoadState> $states
     * @return string|null detail text naming the failed dependency and its state
     */
    private function firstFailedDependency(array $dependencies, array $ok, array $states): ?string
    {
        foreach ($dependencies as $dep) {
            if (!($ok[$dep] ?? false)) {
                $state = $states[$dep] ?? null;
                $status = $state ? $state->status->value : 'unknown';
                $phase = $state && $state->phase ? ' (' . $state->phase . ')' : '';
                return $dep . ':' . $status . $phase;
            }
        }
        return null;
    }
}
