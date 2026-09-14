<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * A module that can refuse to run itself.
 *
 * Dependencies and WooCommerce are conditions the LOADER can see. Some
 * conditions only the module can: the order module must not operate until a
 * commission rate resolves and the ledger accepts writes, and no amount of
 * dependency graph expresses that.
 *
 * The alternative — booting anyway and hiding the parts that are not ready —
 * is what this interface exists to avoid. A module that cannot do its job
 * safely says so, is reported as blocked with its own reason, and none of its
 * code runs.
 */
interface SelfGatedModuleInterface
{
    /**
     * Null to proceed, or a short machine-readable reason to stay blocked.
     *
     * Called during registration, before the module's own register() — so it
     * must be cheap and must not assume its own services exist yet.
     */
    public function blockedReason(ContainerInterface $container): ?string;
}
