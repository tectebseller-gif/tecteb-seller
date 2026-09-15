<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Two-phase module lifecycle.
 *
 * Convention enforced by the loader's guarantees:
 *  - register(): container bindings ONLY. No WordPress hooks, no side effects.
 *  - boot():     attach hooks / start behaviour. Runs once per request, in
 *                dependency order, and only if every dependency is Active.
 *
 * Because hooks live in boot(), a module that is Blocked at boot time has no
 * live behaviour even though its bindings were registered.
 */
interface ModuleInterface
{
    public function manifest(): ModuleManifest;

    public function register(ContainerInterface $container): void;

    public function boot(ContainerInterface $container): void;
}
