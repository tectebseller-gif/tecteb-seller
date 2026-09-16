<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Jobs;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobBatch;
use Tecteb\Marketplace\Core\Jobs\JobHandlerInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Core\Jobs\JobStatus;

/**
 * WHAT THIS PROVES: a job that is interrupted resumes from what it wrote, and
 * the four ways a batch can end are four different outcomes rather than one.
 *
 * The tests that matter most are the two about losing: a handler that throws
 * must not be retried for ever, and a worker whose lease expired must not
 * overwrite the checkpoint of the worker that took over.
 */
final class JobRunnerTest extends TestCase
{
    public function testOneBatchAtATimeWithTheCheckpointWrittenBetweenThem(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(1, 'walk', JobStatus::Pending, ['n' => 3], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $runner = $this->runner($repo, new CountingHandler());

        $first = $runner->runOne();
        self::assertSame('more', $first['outcome']);
        self::assertSame(['at' => 1], $repo->cursors[1], 'the checkpoint is written before the runner lets go');

        $runner->runOne();
        $third = $runner->runOne();
        self::assertSame('done', $third['outcome']);
        self::assertSame(JobStatus::Done, $repo->jobs[1]->status);
    }

    public function testAResumedJobCarriesOnFromTheStoredCursorAndNotFromZero(): void
    {
        $repo = new FakeJobRepository();
        // A job that ALREADY has a cursor: this is the shape a worker killed by
        // a timeout leaves behind, and the next worker must honour it.
        $repo->queue(new Job(7, 'walk', JobStatus::Running, ['n' => 3], ['at' => 2], 0, 2, 0, 1, '', '', '', null, '', '', ''));
        $handler = new CountingHandler();
        $this->runner($repo, $handler)->runOne();

        self::assertSame([2], $handler->startedFrom, 'the batch began where the last checkpoint said');
        self::assertSame(JobStatus::Done, $repo->jobs[7]->status);
    }

    public function testAHandlerThatThrowsFailsTheJobOnceInsteadOfRetryingForEver(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(2, 'boom', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $runner = $this->runner($repo, new ThrowingHandler());

        $result = $runner->runOne();
        self::assertSame('failed', $result['outcome']);
        self::assertSame(JobStatus::Failed, $repo->jobs[2]->status);
        self::assertStringContainsString('exception: nope', $repo->jobs[2]->lastError);

        // Terminal: a second run finds nothing, because a wrong instruction
        // repeated is the same wrong instruction.
        self::assertNull($runner->runOne());
    }

    public function testAWorkerThatLostItsLeaseWritesNothing(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(3, 'walk', JobStatus::Pending, ['n' => 3], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $repo->refuseCheckpoint = true;           // the lease expired mid-batch
        $runner = $this->runner($repo, new CountingHandler());

        $result = $runner->runOne();
        self::assertSame('lease_lost', $result['outcome']);
        self::assertSame([], $repo->cursors, 'nothing is written over the new owner');
        self::assertSame(JobStatus::Running, $repo->jobs[3]->status, 'and the job is not finished either');
    }

    public function testAClaimedJobWithNoHandlerIsPutBackRatherThanFailed(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(4, 'walk', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        // The runner serves 'walk', so it claims the job — then the handler is
        // gone, which is what a downgrade looks like.
        $runner = new JobRunner($repo, static fn (): string => 'token');
        $runner->register(new CountingHandler());
        $repo->jobs[4] = new Job(4, 'renamed', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', '');
        $repo->claimAnyway = true;

        $result = $runner->runOne();
        self::assertSame('no_handler', $result['outcome']);
        self::assertSame(JobStatus::Pending, $repo->jobs[4]->status, 'somebody else may still serve it');
    }

    private function runner(FakeJobRepository $repo, JobHandlerInterface $handler): JobRunner
    {
        $runner = new JobRunner($repo, static fn (): string => 'token-' . random_int(1, 1000000));
        $runner->register($handler);
        return $runner;
    }
}

/** Walks to `payload['n']`, one step per batch. */
final class CountingHandler implements JobHandlerInterface
{
    /** @var list<int> */
    public array $startedFrom = [];

    public function type(): string
    {
        return 'walk';
    }

    public function batchSize(): int
    {
        return 1;
    }

    public function step(Job $job): JobBatch
    {
        $at = (int) ($job->cursor['at'] ?? 0);
        $this->startedFrom[] = $at;
        $at++;
        return $at >= (int) ($job->payload['n'] ?? 1)
            ? JobBatch::finished(['at' => $at], 1)
            : JobBatch::progress(['at' => $at], 1);
    }
}

final class ThrowingHandler implements JobHandlerInterface
{
    public function type(): string
    {
        return 'boom';
    }

    public function batchSize(): int
    {
        return 1;
    }

    public function step(Job $job): JobBatch
    {
        throw new \RuntimeException('nope');
    }
}

/** In-memory queue with the two failure modes the runner has to survive. */
final class FakeJobRepository implements JobRepositoryInterface
{
    /** @var array<int,Job> */
    public array $jobs = [];

    /** @var array<int,array<string,mixed>> */
    public array $cursors = [];

    public bool $refuseCheckpoint = false;

    public bool $claimAnyway = false;

    private string $held = '';

    public function queue(Job $job): void
    {
        $this->jobs[$job->id] = $job;
    }

    public function enqueue(string $type, array $payload, string $liveKey, ?int $actorId): array
    {
        $id = count($this->jobs) + 1;
        $this->jobs[$id] = new Job($id, $type, JobStatus::Pending, $payload, [], 0, 0, 0, 0, '', '', '', $actorId, '', '', '');
        return ['id' => $id, 'created' => true];
    }

    public function claim(array $types, string $lockToken, int $leaseSeconds): ?Job
    {
        foreach ($this->jobs as $job) {
            if (!$job->status->isLive()) {
                continue;
            }
            if (!$this->claimAnyway && !in_array($job->type, $types, true)) {
                continue;
            }
            $this->held = $lockToken;
            $this->jobs[$job->id] = $this->with($job, JobStatus::Running);
            return $this->jobs[$job->id];
        }
        return null;
    }

    public function checkpoint(int $jobId, string $lockToken, array $cursor, int $doneDelta, int $failedDelta, int $total, int $leaseSeconds): bool
    {
        if ($this->refuseCheckpoint || $lockToken !== $this->held) {
            return false;
        }
        $this->cursors[$jobId] = $cursor;
        return true;
    }

    public function finish(int $jobId, string $lockToken, JobStatus $status, string $error): bool
    {
        if ($lockToken !== $this->held) {
            return false;
        }
        $this->jobs[$jobId] = $this->with($this->jobs[$jobId], $status, $error);
        return true;
    }

    public function release(int $jobId, string $lockToken): bool
    {
        if ($lockToken !== $this->held) {
            return false;
        }
        $this->jobs[$jobId] = $this->with($this->jobs[$jobId], JobStatus::Pending);
        return true;
    }

    public function find(int $jobId): ?Job
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function recent(array $statuses, int $limit, int $offset = 0): array
    {
        return array_values($this->jobs);
    }

    public function census(): array
    {
        return [];
    }

    public function cancel(int $jobId): bool
    {
        $this->jobs[$jobId] = $this->with($this->jobs[$jobId], JobStatus::Cancelled);
        return true;
    }

    public function lastError(): string
    {
        return '';
    }

    private function with(Job $job, JobStatus $status, string $error = ''): Job
    {
        return new Job(
            $job->id,
            $job->type,
            $status,
            $job->payload,
            $this->cursors[$job->id] ?? $job->cursor,
            $job->total,
            $job->done,
            $job->failed,
            $job->attempts,
            $job->lockToken,
            $job->lockedUntil,
            $error !== '' ? $error : $job->lastError,
            $job->actorId,
            $job->createdAt,
            $job->updatedAt,
            $job->finishedAt
        );
    }
}
