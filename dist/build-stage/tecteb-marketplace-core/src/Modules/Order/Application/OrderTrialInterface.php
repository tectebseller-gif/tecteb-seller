<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

/**
 * The trial waiver, as the gate sees it.
 *
 * An interface rather than the class itself, so the gate can be exercised
 * with the waiver on and off without an options table or a resolved
 * environment — and so that no production code path can construct a waiver
 * that skips TrialUnlock's environment check.
 */
interface OrderTrialInterface
{
    /** Requested AND permitted here. */
    public function isActive(): bool;

    /** '', or why a requested waiver is not being honoured. */
    public function refusal(): string;

    public function environmentName(): string;
}
