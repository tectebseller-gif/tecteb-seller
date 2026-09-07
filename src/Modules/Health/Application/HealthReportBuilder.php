<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\ModuleStatus;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Environment\EnvironmentType;
use Tecteb\Marketplace\Core\Environment\OutboundPolicy;
use Tecteb\Marketplace\Core\Lifecycle\PhpRequirement;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Modules\LoadReport;

/**
 * Pure application service: composes the health picture from probes,
 * the environment resolver, the module load report and the migration state.
 * No WordPress call here (owner correction 3); adapters feed it.
 */
final class HealthReportBuilder
{
    /** @param callable(): ?LoadReport $reportProvider */
    public function __construct(
        private DependencyProbeInterface $deps,
        private EnvironmentResolver $environment,
        private ClockInterface $clock,
        private string $pluginVersion,
        private $reportProvider,
        private MigrationRunner $migrations,
        private SettingsService $settings
    ) {
    }

    public function build(): HealthReport
    {
        $env = $this->environment->resolve();
        $wc = $this->deps->woocommerceAvailable();
        $wcVersion = $wc ? $this->deps->woocommerceVersion() : null;
        $hpos = $wc ? $this->deps->hposEnabled() : null;
        /** @var ?LoadReport $report */
        $report = ($this->reportProvider)();
        $modules = $report ? $report->forHealth() : [];
        $stored = $this->migrations->currentVersion();
        $target = $this->migrations->targetVersion();
        $lastError = $this->migrations->lastError();
        $settings = $this->settings->load();

        $checks = [];
        $php = $this->deps->phpVersion();
        $checks[] = new HealthCheck('php_version', PhpRequirement::isSatisfied($php) ? HealthStatus::Healthy : HealthStatus::ActionRequired, [
            'version' => $php,
            'minimum' => PhpRequirement::MINIMUM,
        ]);
        $checks[] = new HealthCheck('platform_version', $this->deps->platformVersion() === null ? HealthStatus::Unknown : HealthStatus::Healthy, [
            'version' => $this->deps->platformVersion(),
            'tested' => false,
        ]);
        $checks[] = new HealthCheck('woocommerce', $wc ? HealthStatus::Healthy : HealthStatus::ActionRequired, [
            'available' => $wc,
            'version' => $wcVersion,
            'tested' => false,
        ]);
        $checks[] = new HealthCheck('hpos_enabled', $hpos === null ? HealthStatus::Unknown : HealthStatus::Healthy, [
            'enabled' => $hpos,
        ]);
        // Separate field on purpose (UX-01): "enabled" is not "tested".
        $checks[] = new HealthCheck('hpos_tested', HealthStatus::Unknown, ['tested' => false]);
        $checks[] = new HealthCheck('schema', $this->schemaStatus($stored, $target, $lastError), [
            'stored' => $stored,
            'target' => $target,
            'last_error_step' => $lastError['step'] ?? null,
            'last_error_message' => $lastError['message'] ?? null,
            'last_error_at' => $lastError['at'] ?? null,
        ]);
        $checks[] = new HealthCheck('environment', $env->type === EnvironmentType::Unknown ? HealthStatus::Unknown : HealthStatus::Healthy, [
            'resolved' => $env->type->value,
            'source' => $env->source,
            'warnings' => implode(',', $env->warnings),
            'hostname_hint' => $env->hostnameHint,
        ]);
        $checks[] = new HealthCheck('outbound_tmc', HealthStatus::Healthy, ['status' => OutboundPolicy::tmcStatus()]);
        $checks[] = new HealthCheck('outbound_other_plugins', HealthStatus::Unknown, ['status' => OutboundPolicy::otherPluginsStatus()]);
        $checks[] = new HealthCheck('modules', $this->modulesStatus($report), [
            'active' => $report ? $this->count($report, ModuleStatus::Active) : 0,
            'degraded' => $report ? $this->count($report, ModuleStatus::Degraded) : 0,
            'blocked' => $report ? $this->count($report, ModuleStatus::Blocked) : 0,
            'planned' => $report ? $this->count($report, ModuleStatus::Planned) : 0,
            'registry_errors' => $report ? count($report->registryErrors()) : 0,
            'loaded' => $report !== null,
        ]);
        $checks[] = new HealthCheck('commission_rate', $settings->commissionRateBp === null ? HealthStatus::ActionRequired : HealthStatus::Healthy, [
            'configured' => $settings->commissionRateBp !== null,
            'blocking' => false, // an unset rate is a message only; it never blocks the phase-1 skeleton (CORE-07)
        ]);

        return new HealthReport(
            $this->pluginVersion,
            $env,
            $wc,
            $wcVersion,
            $hpos,
            $modules,
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
            $checks,
            $report ? $report->registryErrors() : [],
            $stored,
            $target,
            $lastError
        );
    }

    private function schemaStatus(int $stored, int $target, ?array $lastError): HealthStatus
    {
        if ($lastError !== null) {
            return HealthStatus::ActionRequired;
        }
        return $stored >= $target ? HealthStatus::Healthy : HealthStatus::ActionRequired;
    }

    private function modulesStatus(?LoadReport $report): HealthStatus
    {
        if ($report === null) {
            return HealthStatus::Unknown;
        }
        return $report->hasProblems() ? HealthStatus::ActionRequired : HealthStatus::Healthy;
    }

    private function count(LoadReport $report, ModuleStatus $status): int
    {
        $n = 0;
        foreach ($report->states() as $state) {
            if ($state->status === $status) {
                $n++;
            }
        }
        return $n;
    }
}
