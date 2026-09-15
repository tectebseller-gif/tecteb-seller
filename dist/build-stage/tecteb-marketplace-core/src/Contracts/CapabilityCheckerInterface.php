<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

interface CapabilityCheckerInterface
{
    public function can(string $capability): bool;

    /** Pseudonymous actor reference for audit rows; null when unauthenticated. */
    public function currentUserId(): ?int;
}
