<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Raw environment signals. Resolution order lives in Core\Environment\EnvironmentResolver.
 */
interface EnvironmentProbeInterface
{
    /** Value of the TMC_ENVIRONMENT constant, or null if undefined. */
    public function constantValue(): ?string;

    /** Stored environment_override setting, or null if unavailable. */
    public function optionValue(): ?string;

    /** Platform-reported environment (wp_get_environment_type), or null if unavailable. */
    public function platformEnvironment(): ?string;

    /** Auxiliary hint only. Never used for resolution. */
    public function hostname(): ?string;
}
