<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Application;

use Tecteb\Marketplace\Core\Environment\OutboundPolicy;
use Tecteb\Marketplace\Core\Environment\ResolvedEnvironment;

final class HealthReport
{
    /**
     * @param list<array{id:string,status:string}> $modules
     * @param list<HealthCheck> $checks
     * @param list<array{id:string,reason:string}> $registryErrors
     */
    public function __construct(
        public readonly string $pluginVersion,
        public readonly ResolvedEnvironment $environment,
        public readonly bool $woocommerceAvailable,
        public readonly ?string $woocommerceVersion,
        public readonly ?bool $hposEnabled,
        public readonly array $modules,
        public readonly \DateTimeImmutable $checkedAtUtc,
        public readonly array $checks,
        public readonly array $registryErrors,
        public readonly int $schemaVersionStored,
        public readonly int $schemaVersionTarget,
        public readonly ?array $migrationLastError
    ) {
    }

    /**
     * EXACTLY the public health contract (CORE-06). No extra keys, no server
     * paths, no PHP/WP versions, no users, no exceptions. null stays null.
     */
    public function toContractArray(): array
    {
        return [
            'schema_version' => '1',
            'plugin' => ['version' => $this->pluginVersion],
            'environment' => $this->environment->toArray(),
            'dependencies' => [
                'woocommerce' => ['available' => $this->woocommerceAvailable, 'version' => $this->woocommerceVersion],
                'hpos' => ['enabled' => $this->hposEnabled],
            ],
            'outbound' => OutboundPolicy::toArray(),
            'modules' => $this->modules,
            'checked_at' => $this->checkedAtUtc->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function overallStatus(): HealthStatus
    {
        $unknown = false;
        foreach ($this->checks as $check) {
            if ($check->status === HealthStatus::ActionRequired) {
                return HealthStatus::ActionRequired;
            }
            if ($check->status === HealthStatus::Unknown) {
                $unknown = true;
            }
        }
        return $unknown ? HealthStatus::Unknown : HealthStatus::Healthy;
    }
}
