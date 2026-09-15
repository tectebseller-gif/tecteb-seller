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
        return $this->options->delete(self::OPTION);
    }
}
