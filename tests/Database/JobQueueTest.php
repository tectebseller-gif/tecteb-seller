<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\Jobs\DbJobRepository;
use Tecteb\Marketplace\Infrastructure\Jobs\DbOutboxRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;

/**
 * The rules `tmc_jobs` and `tmc_event_outbox` put in the SCHEMA, measured on a
 * real MariaDB — because each of them is a rule two concurrent workers could
 * otherwise both pass.
 *
 * A unit test with an in-memory repository cannot show any of this. «Two
 * workers claim, one wins» is a property of `UPDATE … WHERE`, and the only way
 * to know that the WHERE is right is to run it.
 */
final class JobQueueTest extends DatabaseTestCase
{
    private DbJobRepository $jobs;

    private DbOutboxRepository $outbox;

    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        foreach (M0013JobsAndOutbox::TABLES as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        (new M0013JobsAndOutbox())->up($this->db);
        $this->jobs = new DbJobRepository($this->db, new SystemClock());
        $this->outbox = new DbOutboxRepository($this->db, new SystemClock());
    }

    public function testTheMigrationBuildsBothTablesWithTheIndexesTheRulesNeed(): void
    {
        self::assertTrue((new M0013JobsAndOutbox())->verify($this->db));
        self::assertContains('tmc_job_live', $this->indexNames($this->wpdb->prefix . M0013JobsAndOutbox::JOBS));
        self::assertContains('tmc_outbox_event', $this->indexNames($this->wpdb->prefix . M0013JobsAndOutbox::OUTBOX));
    }

    public function testOneLiveJobPerKeyNoMatterHowManyTimesTheButtonIsPressed(): void
    {
        $first = $this->jobs->enqueue('dokan_import', ['run_id' => 'a'], 'dokan_import', 1);
        $second = $this->jobs->enqueue('dokan_import', ['run_id' => 'b'], 'dokan_import', 2);

        self::assertTrue($first['created']);
        self::assertFalse($second['created'], 'the unique index decides, not a check two requests can both pass');
        self::assertSame($first['id'], $second['id'], 'and the caller is told WHICH job is already running');
    }

    public function testATerminalJobReleasesItsKeySoTheSameWorkCanRunAgain(): void
    {
        $first = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        $job = $this->jobs->claim(['dokan_import'], 'token-a', 300);
        self::assertNotNull($job);
        self::assertTrue($this->jobs->finish($job->id, 'token-a', JobStatus::Done, ''));

        $second = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        self::assertTrue($second['created'], 'next month\'s import must not be blocked by last month\'s');
        self::assertNotSame($first['id'], $second['id']);
    }

    public function testTwoWorkersClaimAndExactlyOneWins(): void
    {
        $this->jobs->enqueue('walk', [], 'walk', 1);

        $a = $this->jobs->claim(['walk'], 'token-a', 300);
        $b = $this->jobs->claim(['walk'], 'token-b', 300);

        self::assertNotNull($a);
        self::assertNull($b, 'the loser gets nothing rather than the same job');
        self::assertSame('token-a', $a->lockToken, 'the job returned is provably the one THIS call claimed');
    }

    public function testAWorkerThatLostItsLeaseCannotWriteOverTheNewOwner(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'stale-token', 300);

        // The lease runs out and somebody else takes the job. Expiry is forced
        // by the column rather than by waiting five minutes for a clock.
        $this->expireLease($queued['id']);
        $fresh = $this->jobs->claim(['walk'], 'fresh-token', 300);
        self::assertNotNull($fresh, 'an expired lease makes a running job claimable again');

        self::assertTrue($this->jobs->checkpoint($queued['id'], 'fresh-token', ['at' => 9], 1, 0, 0, 300));
        self::assertFalse(
            $this->jobs->checkpoint($queued['id'], 'stale-token', ['at' => 1], 1, 0, 0, 300),
            'the worker that lost the race writes nothing'
        );
        self::assertFalse($this->jobs->finish($queued['id'], 'stale-token', JobStatus::Done, ''));
        self::assertFalse($this->jobs->release($queued['id'], 'stale-token'));

        $job = $this->jobs->find($queued['id']);
        self::assertNotNull($job);
        self::assertSame(['at' => 9], $job->cursor, 'the new owner\'s checkpoint survived');
    }

    public function testTheCheckpointIsWhatARestartedWorkerReads(): void
    {
        $queued = $this->jobs->enqueue('walk', ['n' => 100], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        $this->jobs->checkpoint($queued['id'], 'token-a', ['phase' => 'products', 'after' => 412], 50, 2, 100, 300);
        $this->jobs->release($queued['id'], 'token-a');

        // A whole new process, with nothing in memory.
        $resumed = (new DbJobRepository($this->db, new SystemClock()))->claim(['walk'], 'token-b', 300);
        self::assertNotNull($resumed);
        self::assertSame(['phase' => 'products', 'after' => 412], $resumed->cursor);
        self::assertSame(50, $resumed->done);
        self::assertSame(2, $resumed->failed);
        self::assertSame(2, $resumed->attempts, 'both claims are counted');
    }

    public function testCountsAccumulateAcrossBatchesRatherThanBeingOverwritten(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        $this->jobs->checkpoint($queued['id'], 'token-a', ['at' => 1], 10, 1, 40, 300);
        $this->jobs->checkpoint($queued['id'], 'token-a', ['at' => 2], 10, 0, 0, 300);

        $job = $this->jobs->find($queued['id']);
        self::assertNotNull($job);
        self::assertSame(20, $job->done);
        self::assertSame(1, $job->failed);
        self::assertSame(40, $job->total, 'a later batch that declares no total leaves the known one alone');
        self::assertSame(52, $job->progress());
    }

    /**
     * A batch that changes nothing is still OUR batch.
     *
     * MySQL reports rows changed, not rows matched, and wpdb does not ask for
     * `CLIENT_FOUND_ROWS`. So writing the same cursor with no new counts in the
     * same second touches no column — and a bare `affected > 0` reads that as
     * «somebody else owns this job». The delivery job hit exactly this on its
     * final, entirely correct batch and reported `lease_lost`.
     */
    public function testACheckpointThatChangesNothingIsStillOurs(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);

        self::assertTrue($this->jobs->checkpoint($queued['id'], 'token-a', ['after' => 7], 3, 0, 10, 300));
        // Byte-for-byte identical, no new counts, no new total.
        self::assertTrue(
            $this->jobs->checkpoint($queued['id'], 'token-a', ['after' => 7], 0, 0, 0, 300),
            'an idempotent batch must not be mistaken for a lost lease'
        );
        // And the ambiguity is not resolved by saying yes to everybody: a token
        // that never owned this job still gets false.
        self::assertFalse($this->jobs->checkpoint($queued['id'], 'never-owned-it', ['after' => 7], 0, 0, 0, 300));
    }

    public function testACrashedWorkerIsCountedAsStalledAndNotAsBusy(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        self::assertSame(0, $this->jobs->census()['stalled'], 'a worker actually holding the job is not stalled');

        $this->expireLease($queued['id']);
        $census = $this->jobs->census();
        self::assertSame(1, $census[JobStatus::Running->value]);
        self::assertSame(1, $census['stalled'], 'the row still says running; only the lease tells the truth');
    }

    /**
     * THE STATE STAGING WAS IN. A job held by a worker that no longer exists
     * is invisible to `claim()` for as long as its lease says somebody is
     * working on it — and that is right, because `claim()` cannot tell a live
     * worker from a dead one. `handOver()` is how a person says which it is.
     *
     * ⛔ And the person has to say it about THIS lease, having been told how
     * long the row has been silent. `alpha.72` asked neither question: it
     * expired whatever lease was there, so a job somebody claimed between the
     * page being drawn and the button being pressed was taken off them.
     */
    public function testAJobHeldByALeaseNobodyIsUsingCanBeHandedOver(): void
    {
        $queued = $this->jobs->enqueue('walk', ['run' => 1], 'walk', 1);
        $claimed = $this->jobs->claim(['walk'], 'token-dead', 300);
        self::assertNotNull($claimed);
        $this->jobs->checkpoint($queued['id'], 'token-dead', ['at' => 7], 7, 1, 20, 300);

        self::assertNull($this->jobs->claim(['walk'], 'token-b', 300), 'the live lease hides it');
        self::assertSame(0, $this->jobs->census()['stalled'], 'and the report calls it busy, not stalled');

        // It wrote a moment ago, so «nothing has been written for a while» is
        // false and the hand-over says so rather than guessing.
        $held = $this->jobs->find($queued['id']);
        $seen = $this->jobs->lockFingerprint($held->lockToken, $held->lockedUntil);
        self::assertSame('worker_still_writing', $this->jobs->handOver($queued['id'], $seen));
        self::assertNull($this->jobs->claim(['walk'], 'token-b', 300), 'and the job is still held');

        // The manager, who knows the process is gone, says so.
        self::assertSame('', $this->jobs->handOver($queued['id'], $seen, true));
        $next = $this->jobs->claim(['walk'], 'token-b', 300);
        self::assertNotNull($next, 'the next worker may now take it');
        // The point of handing over rather than cancelling: the position and
        // the counts are the whole reason not to start again from nothing.
        self::assertSame(['at' => 7], $next->cursor);
        self::assertSame(7, $next->done);
        self::assertSame(1, $next->failed);
        self::assertSame(20, $next->total);
    }

    /**
     * A lease claimed between the page and the form is not handed over.
     *
     * The fingerprint is of the lock that was SHOWN, so a second worker taking
     * the job in that window keeps it — and the manager is told to look again
     * rather than finding they have taken a job off somebody who had just
     * started it.
     */
    public function testALeaseThatChangedSinceThePageWasDrawnIsNotHandedOver(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        $shown = $this->jobs->find($queued['id']);
        $seen = $this->jobs->lockFingerprint($shown->lockToken, $shown->lockedUntil);

        // Whatever happens next, it is not the lease the manager looked at.
        $this->jobs->handOver($queued['id'], $seen, true);
        self::assertNotNull($this->jobs->claim(['walk'], 'token-b', 300));

        self::assertSame(
            'lock_changed',
            $this->jobs->handOver($queued['id'], $seen, true),
            'the stale form must not end the new owner\'s lease'
        );
        // And the new owner is still working, untouched.
        self::assertTrue($this->jobs->checkpoint($queued['id'], 'token-b', ['at' => 1], 1, 0, 0, 300));
    }

    /** And the worker that was handed over writes nothing afterwards. */
    public function testAHandedOverWorkerCannotWriteOverTheNewOwner(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-dead', 300);
        self::assertSame('', $this->jobs->handOver($queued['id'], '', true));
        $this->jobs->claim(['walk'], 'token-b', 300);

        self::assertFalse(
            $this->jobs->checkpoint($queued['id'], 'token-dead', ['at' => 99], 99, 0, 0, 300),
            'the old token is refused by the token match that was already there'
        );
        self::assertSame([], $this->jobs->find($queued['id'])->cursor);
    }

    /** Only a running job has a lease to end, and each answer has a name. */
    public function testThereIsNothingToHandOverOnAJobNobodyHolds(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        self::assertSame('not_running', $this->jobs->handOver($queued['id'], '', true), 'a pending job is already claimable');
        $this->jobs->claim(['walk'], 'token-a', 300);
        $this->jobs->finish($queued['id'], 'token-a', JobStatus::Done, '');
        self::assertSame('not_running', $this->jobs->handOver($queued['id'], '', true), 'and a finished one is not coming back');
    }

    /**
     * The hand-over does not stamp the row it is reporting on.
     *
     * `secondsSinceWrite()` is the only evidence of when the WORKER last
     * wrote, and it is read straight from `updated_at`. A hand-over that
     * touched that column would make the row look freshly worked on and refuse
     * the next hand-over for a quarter of a minute — a guard refusing its own
     * previous answer.
     */
    public function testHandingOverDoesNotLookLikeWork(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        $before = $this->jobs->find($queued['id'])->updatedAt;
        self::assertSame('', $this->jobs->handOver($queued['id'], '', true));
        self::assertSame($before, $this->jobs->find($queued['id'])->updatedAt);
        self::assertIsInt($this->jobs->secondsSinceWrite($queued['id']));
    }

    /**
     * The queue page needs the unfinished jobs as ROWS, because what it has to
     * say — «three attempts, same position» — is in each job's own cursor.
     */
    public function testLiveListsOnlyTheJobsThatAreNotFinished(): void
    {
        $a = $this->jobs->enqueue('walk', [], 'a', 1);
        $b = $this->jobs->enqueue('walk', [], 'b', 1);
        $this->jobs->claim(['walk'], 'token-a', 300);
        $this->jobs->finish($a['id'], 'token-a', JobStatus::Done, '');

        $live = $this->jobs->live(50);
        self::assertCount(1, $live);
        self::assertSame($b['id'], $live[0]->id);
    }

    public function testCancellingAJobFreesItsKeyAndStopsItBeingClaimed(): void
    {
        $queued = $this->jobs->enqueue('walk', [], 'walk', 1);
        self::assertTrue($this->jobs->cancel($queued['id']));
        self::assertNull($this->jobs->claim(['walk'], 'token-a', 300));
        self::assertFalse($this->jobs->cancel($queued['id']), 'a terminal job cannot be cancelled twice');
    }

    // ------------------------------------------------------------- outbox

    public function testTheSameEventIsRecordedOnceHoweverOftenItIsOffered(): void
    {
        $payload = ['event' => 'vendor.approved'];
        $first = $this->outbox->record('vendor.approved', 'evt-1', 7, 'vendor', '7', $payload, 'v1=abc');
        $second = $this->outbox->record('vendor.approved', 'evt-1', 7, 'vendor', '7', $payload, 'v1=abc');

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second, 'the unique event id is the receiver\'s only defence against a retry');
        self::assertSame(1, $this->outbox->count());
    }

    public function testMarkingDeliveryMovesTheStateAndLeavesThePayloadAndSignatureAlone(): void
    {
        $id = $this->outbox->record('vendor.approved', 'evt-2', 7, 'vendor', '7', ['a' => 1], 'v1=sig');
        self::assertTrue($this->outbox->markDelivery($id, 'blocked', 'outbound_blocked'));

        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->wpdb->prefix . M0013JobsAndOutbox::OUTBOX . '` WHERE id = %d',
            [$id]
        );
        self::assertNotNull($row);
        self::assertSame('blocked', $row['delivery_state']);
        self::assertSame('outbound_blocked', $row['delivery_reason']);
        self::assertSame(1, (int) $row['attempts']);
        // The signature was taken over these bytes. A row whose payload moved
        // would carry a signature that verifies nothing.
        self::assertSame('v1=sig', $row['signature']);
        self::assertSame('{"a":1}', $row['payload']);
    }

    public function testPendingSkipsWhatHasAlreadyBeenDecidedAndPagesByKey(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->outbox->record('vendor.approved', 'evt-p' . $n, 7, 'vendor', (string) $n, ['n' => $n], 'v1=' . $n);
        }
        $this->outbox->markDelivery($ids[0], 'blocked', 'outbound_blocked');

        $first = $this->outbox->pending(2);
        self::assertCount(2, $first);
        self::assertSame($ids[1], (int) $first[0]['id'], 'the decided row is gone from the queue');

        $next = $this->outbox->pending(2, (int) $first[1]['id']);
        self::assertSame($ids[3], (int) $next[0]['id']);
    }

    // ===================================================== the unit-of-work gate
    //
    // ⛔ WHY THIS IS NOT A CHECKPOINT. `alpha.73` asked the lease with
    // `stillOurs()` — a guarded `UPDATE` that returned — and then wrote on the
    // following lines. Two round trips sit between the answer and the write,
    // and the owner read the source and walked straight through them: a worker
    // parked in that gap had already replaced a picture a second worker
    // attached, and its own later refusal took nothing back. These four tests
    // are about the thing that replaced it: the question and the writes it
    // governs are ONE transaction, opened by a locking read of this job's row.

    public function testAHeldWriteRunsAndIsCommitted(): void
    {
        $queued = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        $job = $this->jobs->claim(['dokan_import'], 'tok-a', 300);
        self::assertNotNull($job);

        $ran = 0;
        $answer = $this->jobs->writingUnderLease($job->id, 'tok-a', function () use (&$ran): bool {
            $ran++;
            return true;
        });

        self::assertSame('', $answer, 'held');
        self::assertSame(1, $ran);
        self::assertSame($queued['id'], $job->id);
    }

    public function testAStaleTokenNeverRunsTheWrite(): void
    {
        $queued = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        $first = $this->jobs->claim(['dokan_import'], 'tok-a', 300);
        self::assertNotNull($first);

        // A second worker takes the job the documented way: the lease expires
        // — or a person hands it over — and the next claim writes ITS token.
        $this->expireLease($queued['id']);
        $second = $this->jobs->claim(['dokan_import'], 'tok-b', 300);
        self::assertNotNull($second, 'the job is claimable again');

        $ran = 0;
        $answer = $this->jobs->writingUnderLease($queued['id'], 'tok-a', function () use (&$ran): bool {
            $ran++;
            return true;
        });

        self::assertSame('lease_lost', $answer);
        self::assertSame(0, $ran, 'NOTHING ran — not a refusal reported after the write');
        // And the worker that does own it still can.
        self::assertSame('', $this->jobs->writingUnderLease($queued['id'], 'tok-b', static fn (): bool => true));
    }

    public function testWorkThatFailsIsRolledBackAndTheLeaseIsLeftAlone(): void
    {
        $queued = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        $job = $this->jobs->claim(['dokan_import'], 'tok-a', 300);
        self::assertNotNull($job);
        $table = $this->wpdb->prefix . M0013JobsAndOutbox::OUTBOX;

        $answer = $this->jobs->writingUnderLease($job->id, 'tok-a', function () use ($table): bool {
            $this->outbox->record('vendor.approved', 'evt-rolled-back', 7, 'vendor', '1', ['a' => 1], 'v1=x');
            return false;                       // the work decided it had failed
        });

        self::assertSame('not_written', $answer);
        // ⛔ Counted through `getRow()` rather than `get_var()`: a count that
        // comes back as `null` casts to the same zero a real count of nought
        // gives, so «the read failed» would read as «the rollback worked»
        // (`alpha.8`).
        $left = $this->db->getRow(
            'SELECT COUNT(*) AS n FROM `' . $table . '` WHERE event_id = %s',
            ['evt-rolled-back']
        );
        self::assertNotNull($left, 'the count was read');
        self::assertSame(0, (int) $left['n'], 'the row written inside went with the rollback');
        // The lease never moved, so this worker carries on.
        self::assertSame('', $this->jobs->writingUnderLease($job->id, 'tok-a', static fn (): bool => true));
    }

    /**
     * A caller already inside a transaction is REFUSED rather than nested.
     *
     * ⛔ This gateway counts nesting and `rollback()` at any depth abandons the
     * whole unit of work — its own documented contract. A nested hold would
     * mean a unit that could not write took its caller's work down with it, so
     * the answer is «unknown», which is not «yes» (`alpha.54`).
     */
    public function testAHoldIsRefusedInsideSomebodyElsesTransaction(): void
    {
        $queued = $this->jobs->enqueue('dokan_import', [], 'dokan_import', 1);
        $job = $this->jobs->claim(['dokan_import'], 'tok-a', 300);
        self::assertNotNull($job);

        self::assertTrue($this->db->begin());
        $ran = 0;
        $answer = $this->jobs->writingUnderLease($job->id, 'tok-a', function () use (&$ran): bool {
            $ran++;
            return true;
        });
        self::assertTrue($this->db->rollback());

        self::assertSame('unreadable', $answer);
        self::assertSame(0, $ran);
    }

    public function testAJobThatIsGoneIsNotAnUnknown(): void
    {
        $ran = 0;
        $answer = $this->jobs->writingUnderLease(999999, 'tok-a', function () use (&$ran): bool {
            $ran++;
            return true;
        });
        self::assertSame('lease_lost', $answer, 'no such job: the lease cannot be ours');
        self::assertSame(0, $ran);
    }

    /** Forces a lease into the past, so expiry is measured and not waited for. */
    private function expireLease(int $jobId): void
    {
        $this->db->execute(
            'UPDATE `' . $this->wpdb->prefix . M0013JobsAndOutbox::JOBS . '`
             SET locked_until = %s WHERE id = %d',
            [gmdate('Y-m-d H:i:s', time() - 3600), $jobId]
        );
    }
}
