<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\GuardedWriteOutcome;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Migration\CleanupOutcome;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\FakeDatabase;
use Tecteb\Marketplace\Tests\Support\InMemoryLockStore;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/**
 * Clearing the recorded failure at the end of a run has FOUR endings, and the
 * runner used to discard all of them: it called the clear and returned Applied
 * whatever happened.
 *
 *   nothing recorded → not a failure, must not look like one
 *   record cleared   → the ordinary success
 *   not the owner    → another run is authoritative; leave its record alone
 *   storage failed   → a stale record is still there; NOT a clean success
 *
 * Every test here fails on the previous implementation, which returned
 * MigrationResult::applied(...) with no cleanup information at all.
 */
final class MigrationCleanupTest extends TestCase
{
    private InMemoryOptionStore $options;
    private InMemoryLockStore $locks;
    private FakeDatabase $db;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->options = new InMemoryOptionStore();
        $this->locks = new InMemoryLockStore();
        $this->locks->optionStore = $this->options;
        $this->db = new FakeDatabase();
        $this->clock = new FixedClock();
    }

    private const RECORD = ['step' => '0001_step', 'message' => 'boom', 'at' => '2026-09-07T10:00:00+00:00'];

    /** @return list<MigrationInterface> */
    private function steps(int $count = 1): array
    {
        $out = [];
        for ($v = 1; $v <= $count; $v++) {
            $out[] = new class($v) implements MigrationInterface {
                public function __construct(private int $v)
                {
                }
                public function version(): int { return $this->v; }
                public function id(): string { return sprintf('%04d_step', $this->v); }
                public function up(DatabaseInterface $db): void {}
                public function verify(DatabaseInterface $db): bool { return true; }
            };
        }
        return $out;
    }

    private function runner(?InMemoryLockStore $locks = null, string $owner = 'owner-cleanup-1'): MigrationRunner
    {
        $locks ??= $this->locks;
        return new MigrationRunner(
            $this->db,
            $this->options,
            $locks,
            new MigrationLock($locks, $this->clock, $owner, 300),
            $this->steps(),
            $this->clock,
            1
        );
    }

    // ---- 1. nothing recorded ------------------------------------------------

    public function testNothingRecordedIsNotAFailure(): void
    {
        $result = $this->runner()->run();

        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(CleanupOutcome::NotNeeded, $result->cleanup, 'an absent record is not a failed cleanup');
        self::assertNull($result->error);
        self::assertTrue($result->isFullySuccessful());
        self::assertFalse($result->leftStaleErrorRecord());
    }

    // ---- 2. record cleared --------------------------------------------------

    public function testRecordedFailureIsClearedAndReported(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);

        $result = $this->runner()->run();

        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(CleanupOutcome::Cleared, $result->cleanup);
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
        self::assertNull($result->error);
        self::assertTrue($result->isFullySuccessful());
    }

    // ---- 3. ownership lost --------------------------------------------------

    public function testCleanupIsSkippedWhenTheLockWasTakenOverAfterTheVersionWrite(): void
    {
        // Run B's record: written by the run that owns the lock now.
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);

        $clockB = new FixedClock();
        // The take-over lands between the version write and the clear: only
        // the clear's guard sees it.
        $this->locks->onGuardedWrite = function (string $key) use ($clockB): void {
            if ($key !== SchemaVersion::LAST_ERROR_OPTION) {
                return;
            }
            $this->clock->advance(600);
            $clockB->advance(600);
            $b = new MigrationLock($this->locks, $clockB, 'owner-run-b-clean', 300);
            if (!$b->acquire()) {
                throw new \RuntimeException('run B could not take over');
            }
        };

        $result = $this->runner()->run();

        self::assertSame(MigrationStatus::Applied, $result->status, 'the migration itself did happen');
        self::assertSame(CleanupOutcome::SkippedNotOwner, $result->cleanup);
        self::assertSame(self::RECORD, $this->options->get(SchemaVersion::LAST_ERROR_OPTION), "the new owner's record is untouched");
        self::assertTrue($result->isFullySuccessful(), 'deferring to the owner is not this run failing');
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
    }

    // ---- 4. storage failure -------------------------------------------------

    public function testCleanupFailureIsReportedAndNeverReadsAsACleanSuccess(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);
        $this->locks->failGuardedKeys = [SchemaVersion::LAST_ERROR_OPTION];

        $result = $this->runner()->run();

        self::assertSame(MigrationStatus::Applied, $result->status, 'the schema really did reach the target');
        self::assertSame(CleanupOutcome::Failed, $result->cleanup);
        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isFullySuccessful(), 'a failed cleanup must not read as a clean success');
        self::assertTrue($result->leftStaleErrorRecord());
        self::assertNotNull($result->error);
        self::assertStringContainsString('cleanup_failed', (string) $result->error);
        self::assertSame(self::RECORD, $this->options->get(SchemaVersion::LAST_ERROR_OPTION), 'the stale record is still there, and we say so');
        self::assertSame([], $this->locks->rows, 'the lock is still released');
    }

    public function testCleanupThrowingIsReportedAsAFailedCleanupNotAnException(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);
        $locks = new class extends InMemoryLockStore {
            public function deleteGuarded(string $key, string $guardKey, string $guardValue): GuardedWriteOutcome
            {
                throw new \RuntimeException('the option store is down');
            }
        };
        $locks->optionStore = $this->options;

        $result = $this->runner($locks, 'owner-cleanup-2')->run(); // must not throw

        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(CleanupOutcome::Failed, $result->cleanup);
        self::assertFalse($result->isFullySuccessful());
        self::assertSame([], $locks->rows, 'the lock is still released');
    }

    // ---- the recovery path for a record left behind -------------------------

    public function testAnUpToDateRunClearsAStaleRecordLeftByAnEarlierRun(): void
    {
        // Exactly the state a failed cleanup leaves: schema at target, record
        // describing a failure that is over.
        $this->options->set(SchemaVersion::OPTION, 1);
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);

        $result = $this->runner(null, 'owner-recovery-1')->run();

        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame(CleanupOutcome::Cleared, $result->cleanup);
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION), 'reactivation recovers from a failed cleanup');
        self::assertSame([], $this->locks->rows, 'the lock taken for the cleanup is released again');
    }

    public function testAnUpToDateRunWithNothingToCleanTakesNoLockAtAll(): void
    {
        $this->options->set(SchemaVersion::OPTION, 1);

        $result = $this->runner(null, 'owner-recovery-2')->run();

        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame(CleanupOutcome::NotNeeded, $result->cleanup);
        self::assertSame(0, $this->locks->inserts, 'the common path must stay free of lock traffic');
    }

    public function testStaleCleanupDefersToARunThatHoldsTheLock(): void
    {
        $this->options->set(SchemaVersion::OPTION, 1);
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, self::RECORD);
        $other = new MigrationLock($this->locks, $this->clock, 'owner-other-hold', 300);
        self::assertTrue($other->acquire());

        $result = $this->runner(null, 'owner-recovery-3')->run();

        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame(CleanupOutcome::SkippedNotOwner, $result->cleanup);
        self::assertSame(self::RECORD, $this->options->get(SchemaVersion::LAST_ERROR_OPTION));
        self::assertTrue($other->release(), "the other run's lock was never touched");
    }

    // ---- the guarded store's own four answers -------------------------------

    public function testGuardedWriteReportsNoChangeNeededRatherThanRefusal(): void
    {
        $lock = new MigrationLock($this->locks, $this->clock, 'owner-outcomes-1', 300);
        self::assertTrue($lock->acquire());
        $guard = (string) $lock->guardValue();

        self::assertSame(
            GuardedWriteOutcome::Written,
            $this->locks->setGuarded(SchemaVersion::OPTION, 1, $lock->guardKey(), $guard)
        );
        self::assertSame(
            GuardedWriteOutcome::NoChangeNeeded,
            $this->locks->setGuarded(SchemaVersion::OPTION, 1, $lock->guardKey(), $guard),
            'writing the value it already has is not a refused write'
        );
        self::assertSame(
            GuardedWriteOutcome::NoChangeNeeded,
            $this->locks->deleteGuarded('tmc_absent_option', $lock->guardKey(), $guard),
            'deleting what is not there is not a failure'
        );
        self::assertSame(
            GuardedWriteOutcome::NotOwner,
            $this->locks->deleteGuarded(SchemaVersion::OPTION, $lock->guardKey(), 'someone-else'),
            'a guard that does not match is a refusal, not an absence'
        );
    }

    /** An identical version write still counts as persisted, not as a failure. */
    public function testResumingOntoTheVersionItAlreadyHasIsNotAPersistenceFailure(): void
    {
        $lock = new MigrationLock($this->locks, $this->clock, 'owner-outcomes-2', 300);
        self::assertTrue($lock->acquire());
        $this->locks->setGuarded(SchemaVersion::OPTION, 1, $lock->guardKey(), (string) $lock->guardValue());
        self::assertTrue($lock->release());

        // The stored version is 1 while the runner still believes it is 0, so
        // step 1 runs again and rewrites the same value.
        $this->options->data[SchemaVersion::OPTION] = 1;
        $runner = $this->runner(null, 'owner-outcomes-3');
        $this->options->data[SchemaVersion::OPTION] = 0;
        $written = [];
        $this->locks->onGuardedWrite = function (string $key) use (&$written): void {
            if ($key === SchemaVersion::OPTION && !isset($written[$key])) {
                $written[$key] = true;
                $this->options->data[$key] = 1; // another writer got there first
            }
        };

        $result = $runner->run();

        self::assertSame(MigrationStatus::Applied, $result->status, 'an identical value is the desired state, not a failed write');
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
    }
}
