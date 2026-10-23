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

    /**
     * The runner hands the handler a checkpoint, and what the handler records
     * with it is not counted a second time.
     *
     * ⛔ The alternative is the bug this replaced from the other side: a
     * handler that records fifty units per unit and then returns fifty in its
     * batch would double every figure on the queue page.
     */
    public function testWhatAHandlerRecordsItselfIsNotCountedAgain(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(3, 'per-unit', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $handler = new PerUnitHandler();
        $result = $this->runner($repo, $handler)->runOne();

        self::assertSame('more', $result['outcome']);
        self::assertSame(4, $result['checkpoints'], 'four units, four records');
        self::assertSame(0, $result['done'], 'and nothing left for the final write to count');
        self::assertSame(['at' => 4], $repo->cursors[3]);
    }

    /**
     * A checkpoint refused in the MIDDLE of a batch means somebody else owns
     * the job now — so the outcome is `lease_lost` even though the last write
     * would have landed.
     */
    public function testALeaseLostMidBatchIsReportedEvenWhenTheFinalWriteWouldLand(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(4, 'per-unit', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $repo->refuseCheckpointAfter = 2;
        $handler = new PerUnitHandler();
        $result = $this->runner($repo, $handler)->runOne();

        self::assertSame('lease_lost', $result['outcome']);
        self::assertSame(2, $handler->recorded, 'the handler stopped when it was told to');
        self::assertSame(JobStatus::Running, $repo->jobs[4]->status, 'and wrote nothing terminal');
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

    public function testAWriteRunsOnlyWhileTheLeaseStillHolds(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(5, 'under-lease', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $handler = new LeaseWritingHandler();
        $runner = $this->runner($repo, $handler);

        $runner->runOne();

        self::assertSame([1, 2, 3], $handler->wrote);
        self::assertSame(['held', 'held', 'held'], $handler->answers);
        self::assertSame(3, $repo->heldWrites);
    }

    public function testOnceAnotherWorkerHasClaimedTheJobTheWriteNeverRuns(): void
    {
        $repo = new FakeJobRepository();
        $repo->queue(new Job(6, 'under-lease', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        // The hand-over lands after the first unit's write is committed.
        $repo->stealLeaseAfter = 1;
        $handler = new LeaseWritingHandler();
        $runner = $this->runner($repo, $handler);

        $result = $runner->runOne();

        self::assertSame([1], $handler->wrote, 'the second unit wrote NOTHING, not even half of itself');
        self::assertSame(['held', 'lease_lost'], $handler->answers);
        self::assertSame('lease_lost', $result['outcome']);
    }

    public function testOnceTheLeaseIsGoneTheGateIsNotAskedAgain(): void
    {
        // The runner remembers, so a handler that keeps going cannot turn one
        // lost lease into a row of hopeful round trips to the database.
        $repo = new FakeJobRepository();
        $repo->queue(new Job(7, 'under-lease', JobStatus::Pending, [], [], 0, 0, 0, 0, '', '', '', null, '', '', ''));
        $repo->stealLeaseAfter = 1;
        $handler = new LeaseWritingHandler();
        $handler->keepGoingAfterARefusal = true;
        $runner = $this->runner($repo, $handler);

        $runner->runOne();

        self::assertSame([1], $handler->wrote, 'still only the one write');
        self::assertSame(['held', 'lease_lost', 'lease_lost'], $handler->answers, 'and it was told, every time');
        // ⛔ The number that matters: the door was knocked on TWICE, not four
        // times. Counting held writes instead would not see this — the fake
        // turns the later calls away without counting them, so the count stays
        // at one whether the runner remembers or not. The first version of
        // this test asserted that, and reverting the memory left it green.
        self::assertSame(2, $repo->gateCalls, 'one that held, one that found the lease gone');
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

    public function step(Job $job, ?\Closure $checkpoint = null, ?\Closure $underLease = null): JobBatch
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

    public function step(Job $job, ?\Closure $checkpoint = null, ?\Closure $underLease = null): JobBatch
    {
        throw new \RuntimeException('nope');
    }
}

/**
 * A handler whose units are expensive enough to record one at a time — the
 * shape `DokanImportJob` has, without any of its dependencies.
 */
final class PerUnitHandler implements JobHandlerInterface
{
    public int $recorded = 0;

    public function type(): string
    {
        return 'per-unit';
    }

    public function batchSize(): int
    {
        return 4;
    }

    public function step(Job $job, ?\Closure $checkpoint = null, ?\Closure $underLease = null): JobBatch
    {
        $at = (int) ($job->cursor['at'] ?? 0);
        for ($i = 1; $i <= $this->batchSize(); $i++) {
            if ($checkpoint !== null && !$checkpoint(['at' => $at + $i], 1, 0, 0)) {
                return JobBatch::progress(['at' => $at + $i - 1], 0, 0, 0);
            }
            $this->recorded++;
        }
        return JobBatch::progress(['at' => $at + $this->batchSize()], 0, 0, 0);
    }
}

/**
 * A handler whose unit has TWO writes that must not be split.
 *
 * ⛔ The point is what it does NOT do: it never writes outside the hold. A
 * handler that wrote first and asked afterwards is exactly the shape
 * `alpha.73` shipped, and the runner cannot tell the two apart from the
 * outside — so the thing under test here is that the gate refuses, not that
 * the handler is polite.
 */
final class LeaseWritingHandler implements JobHandlerInterface
{
    /** @var list<int> */
    public array $wrote = [];

    /** @var list<string> */
    public array $answers = [];

    /**
     * A handler that carries on after a refusal.
     *
     * ⛔ Not a handler anybody should write — and that is the point. The
     * runner's own memory of a lost lease exists for handlers that do not
     * stop, so a test of it needs one that does not.
     */
    public bool $keepGoingAfterARefusal = false;

    public function type(): string
    {
        return 'under-lease';
    }

    public function batchSize(): int
    {
        return 3;
    }

    public function step(Job $job, ?\Closure $checkpoint = null, ?\Closure $underLease = null): JobBatch
    {
        $at = (int) ($job->cursor['at'] ?? 0);
        for ($i = 1; $i <= $this->batchSize(); $i++) {
            $unit = $at + $i;
            if ($underLease === null) {
                $this->answers[] = 'no_gate';
                break;
            }
            $answer = $underLease(function () use ($unit): bool {
                $this->wrote[] = $unit;
                return true;
            });
            $this->answers[] = $answer === '' ? 'held' : $answer;
            if ($answer !== '') {
                if (!$this->keepGoingAfterARefusal) {
                    return JobBatch::progress(['at' => $unit - 1], 0, 0, 0);
                }
                continue;
            }
            if ($checkpoint !== null && !$checkpoint(['at' => $unit], 1, 0, 0)) {
                return JobBatch::progress(['at' => $unit], 0, 0, 0);
            }
        }
        return JobBatch::progress(['at' => $at + $this->batchSize()], 0, 0, 0);
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

    /** Refuse from the nth call onwards; 0 means never. */
    public int $refuseCheckpointAfter = 0;

    private int $checkpoints = 0;

    public bool $claimAnyway = false;

    private string $held = '';

    public int $heldWrites = 0;

    /** Every call at the door, including the ones turned away. */
    public int $gateCalls = 0;

    /** Hand the job to another worker once this many writes have been held. */
    public int $stealLeaseAfter = 0;

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
        $this->checkpoints++;
        if ($this->refuseCheckpoint || $lockToken !== $this->held) {
            return false;
        }
        if ($this->refuseCheckpointAfter > 0 && $this->checkpoints > $this->refuseCheckpointAfter) {
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

    public function live(int $limit = 50): array
    {
        return array_values(array_filter($this->jobs, static fn (Job $j): bool => $j->status->isLive()));
    }

    public function handOver(int $jobId, string $seenLock = '', bool $evenIfWriting = false): string
    {
        return isset($this->jobs[$jobId]) ? '' : 'not_running';
    }

    /**
     * The gate, and it is NOT a pass-through.
     *
     * ⛔ The whole point of the thing is that the closure does not run when
     * the token is stale, so a double that always ran it would let the runner
     * look correct while handing a handler a door with no lock on it — the
     * `alpha.70` trap, and the `alpha.59` one before that.
     */
    public function writingUnderLease(int $jobId, string $lockToken, \Closure $write): string
    {
        $this->gateCalls++;
        if (!isset($this->jobs[$jobId])) {
            return 'lease_lost';
        }
        // A second worker's `claim()` is the only thing that ends a lease, and
        // it ends it by writing ITS OWN token — `handOver()` alone merely
        // expires the lease, which is why the token and not the expiry is the
        // fence. Here that claim is simulated at the gate's door, so the
        // closure has not run yet when it happens.
        if ($this->stealLeaseAfter > 0 && $this->heldWrites >= $this->stealLeaseAfter) {
            $this->held = 'claimed-by-somebody-else';
        }
        if ($this->held === '' || !hash_equals($this->held, $lockToken)) {
            return 'lease_lost';
        }
        $this->heldWrites++;
        return $write() === true ? '' : 'not_written';
    }

    public function lockFingerprint(string $lockToken, string $lockedUntil): string
    {
        return hash('sha256', $lockToken . '|' . $lockedUntil);
    }

    public function secondsSinceWrite(int $jobId): ?int
    {
        return isset($this->jobs[$jobId]) ? 999 : null;
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
