<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobStatus;

/**
 * Storage for the job queue.
 *
 * Two of these methods carry a rule that cannot live in the caller:
 *
 *  - **`claim()` must be one atomic write.** It returns the job only to the
 *    single caller whose UPDATE actually changed the row; every other caller
 *    gets null. Implementing it as a read followed by a write re-opens exactly
 *    the race it exists to close.
 *  - **`checkpoint()` and `release()` must match on the lock token.** A worker
 *    whose lease expired while it was working has lost the job; its write must
 *    hit no rows rather than overwrite the checkpoint of the worker that took
 *    over.
 */
interface JobRepositoryInterface
{
    /**
     * Queue a job, or return the live one that already carries this key.
     *
     * @param array<string,mixed> $payload
     * @return array{id:int, created:bool} `created:false` means an existing live job was returned
     */
    public function enqueue(string $type, array $payload, string $liveKey, ?int $actorId): array;

    /**
     * Take ownership of one runnable job for `$leaseSeconds`.
     *
     * Runnable means: pending, or running with an expired lease. Returns null
     * when another worker won the race or nothing is due.
     *
     * @param list<string> $types limit to these handlers; empty means any
     */
    public function claim(array $types, string $lockToken, int $leaseSeconds): ?Job;

    /**
     * Save a batch's progress and extend the lease. Fails (false) when the
     * token no longer owns the job.
     *
     * @param array<string,mixed> $cursor
     */
    public function checkpoint(int $jobId, string $lockToken, array $cursor, int $doneDelta, int $failedDelta, int $total, int $leaseSeconds): bool;

    /** Move a job to a terminal state and drop its dedupe key. */
    public function finish(int $jobId, string $lockToken, JobStatus $status, string $error): bool;

    /** Put a still-unfinished job back for the next worker, clearing the lease. */
    public function release(int $jobId, string $lockToken): bool;

    public function find(int $jobId): ?Job;

    /**
     * @param list<string> $statuses empty means every state
     * @return list<Job>
     */
    public function recent(array $statuses, int $limit, int $offset = 0): array;

    /** @return array<string,int> status => count */
    public function census(): array;

    /** A manager stopping a job they queued; a running job stops after its batch. */
    public function cancel(int $jobId): bool;

    public function lastError(): string;
}
