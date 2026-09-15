<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;

/**
 * No WooCommerce, so no orders and no pay links to retire.
 *
 * Reports zero examined rather than zero stuck-with-a-shrug: the caller's
 * "nothing was left payable" is then true because there was nothing, which is
 * a different statement from "we looked and could not".
 */
final class NullUnpaidOrderGuard implements UnpaidOrderGuardInterface
{
    public function hold(string $reason): array
    {
        return ['held' => 0, 'examined' => 0, 'stuck' => []];
    }

    public function release(): array
    {
        return ['released' => 0, 'stuck' => []];
    }

    public function stillPayable(): array
    {
        return [];
    }
}
