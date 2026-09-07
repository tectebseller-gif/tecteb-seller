<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Limited, capability-gated dependency detection (CORE-06).
 * null means "unknown" and MUST NOT be coerced to false.
 */
interface DependencyProbeInterface
{
    public function woocommerceAvailable(): bool;

    public function woocommerceVersion(): ?string;

    /** HPOS enabled flag. null when WooCommerce or its utility class is absent. */
    public function hposEnabled(): ?bool;

    public function phpVersion(): string;

    public function platformVersion(): ?string;
}
