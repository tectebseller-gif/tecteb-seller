<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Health-visible module states. Values are part of the public health contract.
 */
enum ModuleStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    /** The module's own register()/boot() threw. */
    case Degraded = 'degraded';
    /** Not run because a dependency is missing/degraded/blocked or WooCommerce is required and absent. */
    case Blocked = 'blocked';
}
