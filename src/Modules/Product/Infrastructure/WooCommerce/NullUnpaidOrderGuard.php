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
        return ['released' => 0, 'stuck' => [], 'moved_on' => [], 'reconcile' => []];
    }

    public function stillPayable(): array
    {
        return [];
    }

    public function needsReconciliation(): array
    {
        return [];
    }

    public function resolveReconciliation(int $orderId, int $actorId, string $note): bool
    {
        // There is no order here to resolve anything about. False rather than
        // true: reporting a resolution that did not happen is the one answer
        // that would let a real mark be cleared by a site without WooCommerce.
        return false;
    }

    public function stockTrail(int $orderId): array
    {
        // Not «no stock was moved» — «there is no WooCommerce here to have
        // moved any». Nulls rather than falses, for the same reason this class
        // reports zero examined rather than zero stuck.
        return ['held' => false, 'was_reduced' => null, 'moved' => null, 'reconcile' => ''];
    }
}
