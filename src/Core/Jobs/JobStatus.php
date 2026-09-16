<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * Five states, and the split that matters is «live» versus «terminal»: a live
 * job holds its dedupe key, a terminal one has released it.
 *
 * `Failed` is terminal on purpose. A job that stops because the work itself was
 * wrong should wait for somebody to look at it, not retry until the log fills;
 * a job that stops because the process died is still `Running` with an expired
 * lease, and that one is picked up again by itself.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    public function isTerminal(): bool
    {
        return !$this->isLive();
    }

    public static function fromStorage(string $raw): self
    {
        return self::tryFrom($raw) ?? self::Failed;
    }
}
