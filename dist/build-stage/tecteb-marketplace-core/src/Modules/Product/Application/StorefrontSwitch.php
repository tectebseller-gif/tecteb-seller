<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * One flag: may the marketplace sell through the storefront at all?
 *
 * Separate from the thing that acts on it (StorefrontStop) because the
 * purchase path asks this question on every product of every shop page, and
 * it must be one option read — not a service that can reach a projector, a
 * repository and an audit log.
 *
 * The flag is deliberately NOT derived. Taking products out of the shop and
 * remembering that they were taken out are two different facts: a withdrawal
 * that half succeeded must still leave the marketplace refusing to sell, and
 * a product somebody re-published by hand in wp-admin must not silently put
 * the marketplace back in business.
 */
final class StorefrontSwitch
{
    public const OPTION = 'tmc_storefront_stop';

    /**
     * What the last stop could NOT close.
     *
     * Two options rather than part of the flag above, because they answer a
     * different question and have a different lifetime: the flag says the
     * marketplace is closed, these say the closing did not finish. They are
     * options and not transients on purpose — an object cache may drop a
     * transient, and this is the note that has to still be there when somebody
     * comes back to ask why a product is on sale in a shop that is shut.
     */
    public const STUCK_OPTION = 'tmc_storefront_stuck';

    public const STUCK_ORDERS_OPTION = 'tmc_storefront_stuck_orders';

    /** Reasons the marketplace itself records. Anything else is a free note. */
    public const REASON_DEACTIVATED = 'plugin_deactivated';
    public const REASON_MANAGER = 'manager_stopped';

    public function __construct(
        private readonly OptionStoreInterface $options,
        private readonly ClockInterface $clock
    ) {
    }

    public function isStopped(): bool
    {
        return $this->state() !== [];
    }

    public function reason(): string
    {
        return (string) ($this->state()['reason'] ?? '');
    }

    public function stoppedAt(): string
    {
        return (string) ($this->state()['at'] ?? '');
    }

    /** @return array{reason?:string, at?:string, actor?:int, withdrawn?:int} */
    public function state(): array
    {
        $stored = $this->options->get(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    public function markStopped(string $reason, int $actorId = 0, int $withdrawn = 0): bool
    {
        return $this->options->set(self::OPTION, [
            'reason' => $reason,
            'at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'actor' => $actorId,
            'withdrawn' => $withdrawn,
        ]);
    }

    public function markResumed(): bool
    {
        $this->markStuck([], []);
        return $this->options->delete(self::OPTION);
    }

    /**
     * Records what the stop left open — or clears the record when nothing is.
     *
     * @param list<int> $products marketplace products still on sale
     * @param list<int> $orders   unpaid orders whose pay link still works
     */
    public function markStuck(array $products, array $orders): void
    {
        foreach ([self::STUCK_OPTION => $products, self::STUCK_ORDERS_OPTION => $orders] as $key => $ids) {
            if ($ids === []) {
                $this->options->delete($key);
                continue;
            }
            $this->options->set($key, array_values($ids));
        }
    }

    /** @return array{products:list<int>, orders:list<int>} */
    public function stuck(): array
    {
        $read = function (string $key): array {
            $stored = $this->options->get($key, []);
            return is_array($stored) ? array_values(array_map('intval', $stored)) : [];
        };
        return [
            'products' => $read(self::STUCK_OPTION),
            'orders' => $read(self::STUCK_ORDERS_OPTION),
        ];
    }
}
