<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

use Tecteb\Marketplace\Contracts\JobRepositoryInterface;

/**
 * Claims a job, runs exactly one batch, writes the checkpoint, lets go.
 *
 * The shape is the point. The runner does not loop until the queue is empty,
 * because the process it runs in has a time limit it does not know; it does one
 * batch and returns, and something — cron, a second visitor, a manager pressing
 * «اجرا» — calls it again. Everything about resuming then follows from the fact
 * that there was never a long-running loop to interrupt.
 *
 * Three failure modes and three different answers, which is why this is not a
 * try/catch around a while loop:
 *
 *  - **The handler says the work is wrong** (`JobBatch::failed`). The job stops
 *    in `failed` with the reason. It is not retried: the same instruction would
 *    be wrong the same way.
 *  - **The handler throws.** Same as above, with the exception message as the
 *    reason — a throw is a handler that did not consider this case, and
 *    replaying it silently would hide that.
 *  - **The process dies.** No batch comes back at all, nothing is written, and
 *    the lease simply expires. The next worker claims the job and re-runs the
 *    last cursor. That is why `step()` has to be idempotent against its cursor:
 *    work may have been done between the last checkpoint and the death.
 *
 * The lease is extended by every checkpoint, so a handler that keeps making
 * progress keeps its job, and one that stalls loses it to a worker that can.
 */
final class JobRunner
{
    public const DEFAULT_LEASE = 300;

    /** @var array<string,JobHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private readonly JobRepositoryInterface $jobs,
        private readonly \Closure $tokenFactory,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE
    ) {
    }

    public function register(JobHandlerInterface $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    public function handles(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Run up to `$batches` batches, stopping early when nothing is due.
     *
     * @return list<array{job:int, type:string, outcome:string, done:int, failed:int, error:string}>
     */
    public function run(int $batches = 1): array
    {
        $results = [];
        for ($i = 0; $i < max(1, $batches); $i++) {
            $result = $this->runOne();
            if ($result === null) {
                break;
            }
            $results[] = $result;
        }
        return $results;
    }

    /** @return array{job:int, type:string, outcome:string, done:int, failed:int, error:string}|null */
    public function runOne(): ?array
    {
        if ($this->handlers === []) {
            return null;
        }
        $token = (string) ($this->tokenFactory)();
        $job = $this->jobs->claim($this->types(), $token, $this->leaseSeconds);
        if ($job === null) {
            return null;
        }

        $handler = $this->handlers[$job->type] ?? null;
        if ($handler === null) {
            // Claimed a type we no longer serve — a downgrade, or a renamed
            // handler. Put it back rather than failing somebody else's job.
            $this->jobs->release($job->id, $token);
            return ['job' => $job->id, 'type' => $job->type, 'outcome' => 'no_handler', 'done' => 0, 'failed' => 0, 'error' => ''];
        }

        try {
            $batch = $handler->step($job);
        } catch (\Throwable $e) {
            $this->jobs->finish($job->id, $token, JobStatus::Failed, 'exception: ' . $e->getMessage());
            return ['job' => $job->id, 'type' => $job->type, 'outcome' => 'failed', 'done' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }

        // The checkpoint goes in first in every case, including failure: what a
        // failing batch did before it gave up is still done, and re-doing it
        // would be the duplicate this table exists to prevent.
        $kept = $this->jobs->checkpoint(
            $job->id,
            $token,
            $batch->cursor,
            $batch->done,
            $batch->failed,
            $batch->total,
            $this->leaseSeconds
        );
        if (!$kept) {
            // The lease expired mid-batch and somebody else owns this now. Say
            // so rather than writing over their work.
            return ['job' => $job->id, 'type' => $job->type, 'outcome' => 'lease_lost', 'done' => 0, 'failed' => 0, 'error' => ''];
        }

        if ($batch->isFailure()) {
            $this->jobs->finish($job->id, $token, JobStatus::Failed, $batch->error);
            $outcome = 'failed';
        } elseif ($batch->more) {
            $this->jobs->release($job->id, $token);
            $outcome = 'more';
        } else {
            $this->jobs->finish($job->id, $token, JobStatus::Done, '');
            $outcome = 'done';
        }

        return [
            'job' => $job->id,
            'type' => $job->type,
            'outcome' => $outcome,
            'done' => $batch->done,
            'failed' => $batch->failed,
            'error' => $batch->error,
        ];
    }
}
