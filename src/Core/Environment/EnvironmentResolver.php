<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Environment;

use Tecteb\Marketplace\Contracts\EnvironmentProbeInterface;

/**
 * CORE-04 resolution order: valid constant → valid option → platform.
 * Unknown/invalid values never resolve to a "safe-looking" environment; the
 * hostname is only ever an auxiliary hint. Whatever the result, outbound
 * traffic stays blocked (OutboundPolicy) — resolution has no unlock power.
 */
final class EnvironmentResolver
{
    public function __construct(private EnvironmentProbeInterface $probe)
    {
    }

    public function resolve(): ResolvedEnvironment
    {
        $warnings = [];
        $hint = $this->hostnameHint();

        $constant = $this->probe->constantValue();
        if ($constant !== null) {
            $type = EnvironmentType::fromInput($constant);
            if ($type !== null) {
                return new ResolvedEnvironment($type, ResolvedEnvironment::SOURCE_CONSTANT, $warnings, $hint);
            }
            $warnings[] = 'constant_invalid';
        }

        $option = $this->probe->optionValue();
        if ($option !== null && strtolower(trim($option)) !== 'auto') {
            $type = EnvironmentType::fromInput($option);
            if ($type === EnvironmentType::Staging || $type === EnvironmentType::Production) {
                return new ResolvedEnvironment($type, ResolvedEnvironment::SOURCE_OPTION, $warnings, $hint);
            }
            $warnings[] = 'option_invalid';
        }

        $platform = $this->probe->platformEnvironment();
        if ($platform !== null) {
            $type = EnvironmentType::fromInput($platform);
            if ($type === null) {
                $warnings[] = 'platform_unknown';
                $type = EnvironmentType::Unknown;
            }
            return new ResolvedEnvironment($type, ResolvedEnvironment::SOURCE_PLATFORM, $warnings, $hint);
        }

        return new ResolvedEnvironment(EnvironmentType::Unknown, ResolvedEnvironment::SOURCE_NONE, $warnings, $hint);
    }

    private function hostnameHint(): ?string
    {
        $host = $this->probe->hostname();
        if ($host === null || $host === '') {
            return null;
        }
        $lower = strtolower($host);
        foreach (['staging', 'stage', 'stg', 'dev', 'test', 'local'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'looks_non_production';
            }
        }
        return 'no_signal';
    }
}
