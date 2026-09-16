<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;

/**
 * Unpaid orders without WooCommerce.
 *
 * Holds a list of order ids whose pay link "works", and a list it is unable to
 * retire — the second one is the whole point, because a stop that could not
 * close a pay link has to read as a failure exactly like a product that would
 * not leave the shop.
 */
final class FakeUnpaidOrderGuard implements UnpaidOrderGuardInterface
{
    /** @var list<int> orders whose mailed link currently takes money */
    public array $payable = [];

    /** @var list<int> orders this guard will refuse to retire */
    public array $refuses = [];

    /** @var list<int> orders currently retired, so release() can hand them back */
    public array $retired = [];

    public function hold(string $reason): array
    {
        $examined = count($this->payable);
        $stuck = [];
        foreach ($this->payable as $orderId) {
            if (in_array($orderId, $this->refuses, true)) {
                $stuck[] = $orderId;
                continue;
            }
            $this->retired[] = $orderId;
        }
        $this->payable = $stuck;
        return ['held' => $examined - count($stuck), 'examined' => $examined, 'stuck' => $stuck];
    }

    public function release(): array
    {
        $released = count($this->retired);
        $this->payable = array_values(array_merge($this->payable, $this->retired));
        $this->retired = [];
        return ['released' => $released, 'stuck' => [], 'moved_on' => [], 'reconcile' => []];
    }

    public function stillPayable(): array
    {
        return $this->payable;
    }

    public function needsReconciliation(): array
    {
        // This fake moves no stock, so it can never leave any to reconcile.
        // Returning [] is the truth here, not a stub.
        return [];
    }

    /** @var list<int> orders a manager has recorded a decision about */
    public array $resolved = [];

    public function resolveReconciliation(int $orderId, int $actorId, string $note): bool
    {
        if (trim($note) === '') {
            return false;
        }
        $this->resolved[] = $orderId;
        return true;
    }

    public function stockTrail(int $orderId): array
    {
        // Nulls rather than falses, for the same reason NullUnpaidOrderGuard
        // uses them: «no stock was moved» and «nothing here moves stock» are
        // different answers, and a test that confused them would pass wrongly.
        return [
            'held' => in_array($orderId, $this->retired, true),
            'was_reduced' => null,
            'moved' => null,
            'reconcile' => '',
        ];
    }
}
