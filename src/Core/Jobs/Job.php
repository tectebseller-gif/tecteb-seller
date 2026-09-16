<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * One queued unit of work, as the row holds it.
 *
 * `cursor` is the whole point of the table: it is where the last completed
 * batch stopped, written by the worker that finished that batch, read by
 * whichever worker runs next — which may be a different PHP process minutes
 * later, after the first one was killed by a timeout.
 */
final class Job
{
    /**
     * @param array<string,mixed> $payload  what the job was asked to do; never changes
     * @param array<string,mixed> $cursor   where the last completed batch stopped
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly JobStatus $status,
        public readonly array $payload,
        public readonly array $cursor,
        public readonly int $total,
        public readonly int $done,
        public readonly int $failed,
        public readonly int $attempts,
        public readonly string $lockToken,
        public readonly string $lockedUntil,
        public readonly string $lastError,
        public readonly ?int $actorId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $finishedAt
    ) {
    }

    /** Percent complete, or null when the handler never declared a total. */
    public function progress(): ?int
    {
        if ($this->total <= 0) {
            return null;
        }
        return (int) min(100, (int) floor((($this->done + $this->failed) / $this->total) * 100));
    }
}
