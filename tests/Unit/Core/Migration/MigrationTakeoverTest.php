<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\FakeDatabase;
use Tecteb\Marketplace\Tests\Support\InMemoryLockStore;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/**
 * Regression cover for lock expiry DURING a migration and take-over by a
 * second run — at the RUNNER level, not just on MigrationLock.
 *
 * On the previous implementation these fail: the runner refreshed the lock
 * only BETWEEN steps and, crucially, never re-read the stored version after
 * acquiring, so a superseded run could write a stale version over the newer
 * run's state.
 */
final class MigrationTakeoverTest extends TestCase
{
    private InMemoryOptionStore $options;
    private InMemoryLockStore $locks;
    private FakeDatabase $db;

    protected function setUp(): void
    {
        $this->options = new InMemoryOptionStore();
        $this->locks = new InMemoryLockStore();
        // guarded writes must land where the runner reads
        $this->locks->optionStore = $this->options;
        $this->db = new FakeDatabase();
    }

    /** @return list<MigrationInterface> two trivial steps that record their execution */
    private function steps(array &$executed): array
    {
        $make = static function (int $version) use (&$executed): MigrationInterface {
            return new class($version, $executed) implements MigrationInterface {
                public function __construct(private int $v, private array &$executed)
                {
                }
                public function version(): int { return $this->v; }
                public function id(): string { return sprintf('%04d_step', $this->v); }
                public function up(DatabaseInterface $db): void { $this->executed[] = $this->id(); }
                public function verify(DatabaseInterface $db): bool { return true; }
            };
        };
        return [$make(1), $make(2)];
    }

    private function runner(FixedClock $clock, string $owner, array $steps, int $ttl = 300): MigrationRunner
    {
        return new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $clock, $owner, $ttl),
            $steps,
            $clock,
            2
        );
    }

    public function testSupersededRunMustNotWriteAnOlderVersionOverTheNewRunsSchema(): void
    {
        // Three steps. Run A applies step 1, then stalls inside step 2 long
        // enough for its lock to expire. Run B takes the lock over and
        // finishes the whole migration to version 3.
        //
        // On the previous implementation run A then wrote version 2 on top of
        // B's 3 — the schema went BACKWARDS while B believed it was done —
        // and reported Applied. A superseded run must record nothing.
        $clockA = new FixedClock();
        $clockB = new FixedClock();
        $executed = [];

        $plain = function (int $version) use (&$executed): MigrationInterface {
            return new class($version, $executed) implements MigrationInterface {
                public function __construct(private int $v, private array &$executed)
                {
                }
                public function version(): int { return $this->v; }
                public function id(): string { return sprintf('%04d_step', $this->v); }
                public function up(DatabaseInterface $db): void { $this->executed[] = $this->id(); }
                public function verify(DatabaseInterface $db): bool { return true; }
            };
        };

        $stalling = new class($executed, $clockA, $clockB, $this->locks, $this->options) implements MigrationInterface {
            public function __construct(
                private array &$executed,
                private FixedClock $clockA,
                private FixedClock $clockB,
                private InMemoryLockStore $locks,
                private InMemoryOptionStore $options
            ) {
            }
            public function version(): int { return 2; }
            public function id(): string { return '0002_step'; }
            public function up(DatabaseInterface $db): void
            {
                $this->executed[] = '0002_step';
                // Run A is slow: its lock expires while this statement runs.
                $this->clockA->advance(600);
                $this->clockB->advance(600);
                $b = new MigrationLock($this->locks, $this->clockB, 'owner-run-b-xxxx', 300);
                if (!$b->acquire()) {
                    throw new \RuntimeException('run B could not take over the expired lock');
                }
                $this->options->set(SchemaVersion::OPTION, 3); // B completes everything
                $b->release();
            }
            public function verify(DatabaseInterface $db): bool { return true; }
        };

        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $clockA, 'owner-run-a-xxxx', 300),
            [$plain(1), $stalling, $plain(3)],
            $clockA,
            3
        );
        $result = $runner->run();

        self::assertSame(MigrationStatus::LockLost, $result->status, 'the superseded run must say it lost the lock');
        self::assertSame('0002_step', $result->failedStep);
        self::assertSame(3, $this->options->get(SchemaVersion::OPTION), "run B's version must not be written backwards");
        self::assertNull(
            $this->options->get(SchemaVersion::LAST_ERROR_OPTION),
            'the superseded run is not authoritative and must not record a failure'
        );
        self::assertNotContains('0003_step', $executed, 'the superseded run must stop, not carry on');
        self::assertSame([], $this->locks->rows, 'run B released its own lock; run A deleted nothing');
    }

    public function testVersionIsReReadAfterAcquiringNotBeforeIt(): void
    {
        // The version read before acquiring is stale by construction: another
        // run can finish in the window between that read and the acquire.
        // This store models exactly that interleaving.
        $locks = new class extends InMemoryLockStore {
            public ?InMemoryOptionStore $options = null;
            public function insert(string $key, string $value): bool
            {
                $ok = parent::insert($key, $value);
                if ($ok && $this->options !== null) {
                    // The previous run completed at this instant.
                    $this->options->set(SchemaVersion::OPTION, 2);
                }
                return $ok;
            }
        };
        $locks->options = $this->options;

        $clock = new FixedClock();
        $executed = [];
        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($locks, $clock, 'owner-waiter-xx', 300),
            $this->steps($executed),
            $clock,
            2
        );

        $result = $runner->run();

        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame([], $executed, 'no step may run against an already-migrated schema');
        self::assertSame(2, $this->options->get(SchemaVersion::OPTION), 'the completed version is left alone');
    }

    /**
     * Take-over, then the old run's up() throws. The failure belongs to a run
     * that no longer owns the lock, so it must be reported as LockLost and
     * must NOT write the last-error option over the newer run's state.
     */
    public function testTakeoverThenUpThrowsRecordsNothing(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, ['step' => 'from-run-b', 'message' => 'B', 'at' => '2026-09-07T00:00:00+00:00']);
        $result = $this->runAfterTakeover(
            static function (): void {
                throw new \RuntimeException('DDL blew up in the superseded run');
            },
            static fn (): bool => true
        );

        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertSame(
            ['step' => 'from-run-b', 'message' => 'B', 'at' => '2026-09-07T00:00:00+00:00'],
            $this->options->get(SchemaVersion::LAST_ERROR_OPTION),
            "run B's recorded error must be untouched by the superseded run"
        );
        self::assertSame(3, $this->options->get(SchemaVersion::OPTION));
    }

    /** Take-over, then the old run's verify() returns false. */
    public function testTakeoverThenVerifyReturnsFalseRecordsNothing(): void
    {
        $result = $this->runAfterTakeover(static fn (): null => null, static fn (): bool => false);
        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
        self::assertSame(3, $this->options->get(SchemaVersion::OPTION));
    }

    /** Take-over, then the old run's verify() throws. */
    public function testTakeoverThenVerifyThrowsRecordsNothing(): void
    {
        $result = $this->runAfterTakeover(
            static fn (): null => null,
            static function (): bool {
                throw new \RuntimeException('information_schema unavailable');
            }
        );
        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
        self::assertSame(3, $this->options->get(SchemaVersion::OPTION));
    }

    /**
     * The gap the guarded write exists to close: the take-over lands AFTER
     * ownership was confirmed and BEFORE the version write reaches storage.
     * A PHP-side check cannot see this; only a write whose condition is
     * evaluated in the same statement can refuse it.
     */
    public function testTakeoverBetweenTheOwnershipCheckAndTheVersionWrite(): void
    {
        $clockA = new FixedClock();
        $clockB = new FixedClock();
        $executed = [];

        // Run B takes over exactly once, at the first attempt run A makes to
        // write the version — after ownership was last confirmed and before
        // the value reaches storage. It is registered on BOTH stores so the
        // injection point is the same whether the version is written through
        // the guarded statement (this implementation) or through a plain
        // option write after a PHP-side check (the previous one).
        $fired = false;
        $takeover = function () use ($clockA, $clockB, &$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $clockA->advance(600);
            $clockB->advance(600);
            $b = new MigrationLock($this->locks, $clockB, 'owner-run-b-gap0', 300);
            if (!$b->acquire()) {
                throw new \RuntimeException('run B could not take over in the gap');
            }
            $this->options->set(SchemaVersion::OPTION, 3);
            $b->release();
        };
        $this->locks->onGuardedWrite = $takeover;
        $this->options->onWrite = $takeover;

        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $clockA, 'owner-run-a-gap0', 300),
            $this->steps($executed),
            $clockA,
            2
        );
        $result = $runner->run();

        self::assertSame(MigrationStatus::LockLost, $result->status, 'the guard must refuse a write made after take-over');
        self::assertSame(3, $this->options->get(SchemaVersion::OPTION), 'version 1 must not land on top of 3');
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
    }

    /**
     * The success path also clears the last-error option. That clear is state
     * too: a superseded run must not delete a failure the newer run recorded.
     */
    public function testSupersededRunCannotDeleteTheNewRunsRecordedError(): void
    {
        $clockA = new FixedClock();
        $clockB = new FixedClock();

        // Run A owns the lock and is about to finish its last step.
        $lockA = new MigrationLock($this->locks, $clockA, 'owner-run-a-clr0', 300);
        self::assertTrue($lockA->acquire());

        // While A is mid-flight, its lock expires, B takes over, records a
        // failure of its own, and leaves.
        $clockA->advance(600);
        $clockB->advance(600);
        $lockB = new MigrationLock($this->locks, $clockB, 'owner-run-b-clr0', 300);
        self::assertTrue($lockB->acquire());
        $bError = ['step' => '0002_step', 'message' => 'B failed here', 'at' => '2026-09-07T10:00:00+00:00'];
        self::assertTrue($this->locks->setGuarded(SchemaVersion::LAST_ERROR_OPTION, $bError, $lockB->guardKey(), (string) $lockB->guardValue()));

        // A now tries to clear the error as part of "finishing successfully".
        self::assertFalse(
            $this->locks->deleteGuarded(SchemaVersion::LAST_ERROR_OPTION, $lockA->guardKey(), (string) $lockA->guardValue()),
            'the guard must refuse a delete from a run that no longer owns the lock'
        );
        self::assertSame($bError, $this->options->get(SchemaVersion::LAST_ERROR_OPTION), "run B's failure survives");

        // The owner may clear it.
        self::assertTrue($this->locks->deleteGuarded(SchemaVersion::LAST_ERROR_OPTION, $lockB->guardKey(), (string) $lockB->guardValue()));
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
    }

    /** Recording a failure must never turn into a second, uncontrolled failure. */
    public function testFailureToRecordTheErrorDoesNotThrow(): void
    {
        // The storage layer is down for the error option, on BOTH the guarded
        // path and the plain one, so the test does not depend on which of them
        // the runner uses to record a failure.
        $options = new class extends InMemoryOptionStore {
            public function set(string $key, mixed $value): bool
            {
                if ($key === SchemaVersion::LAST_ERROR_OPTION) {
                    throw new \RuntimeException('the option store is down too');
                }
                return parent::set($key, $value);
            }
        };
        $this->options = $options;
        $locks = new class extends InMemoryLockStore {
            public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): bool
            {
                if ($key === SchemaVersion::LAST_ERROR_OPTION) {
                    throw new \RuntimeException('the option store is down too');
                }
                return parent::setGuarded($key, $value, $guardKey, $guardValue);
            }
        };
        $locks->optionStore = $options;
        $clock = new FixedClock();
        $failing = new class implements MigrationInterface {
            public function version(): int { return 1; }
            public function id(): string { return '0001_step'; }
            public function up(DatabaseInterface $db): void
            {
                throw new \RuntimeException('step failed');
            }
            public function verify(DatabaseInterface $db): bool { return true; }
        };
        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $locks,
            new MigrationLock($locks, $clock, 'owner-record-x1', 300),
            [$failing],
            $clock,
            1
        );

        $result = $runner->run(); // must not throw
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertStringContainsString('(not recorded)', (string) $result->error, 'the report says the failure could not be stored');
        self::assertNull($this->options->get(SchemaVersion::OPTION));
        self::assertSame([], $locks->rows, 'lock still released');
    }

    /**
     * Drives a run whose lock is taken over during step 2, then lets the
     * caller decide how that step fails afterwards.
     *
     * @param callable():mixed $upAfterTakeover
     * @param callable():bool  $verify
     */
    private function runAfterTakeover(callable $upAfterTakeover, callable $verify)
    {
        $clockA = new FixedClock();
        $clockB = new FixedClock();
        $locks = $this->locks;
        $options = $this->options;

        $step2 = new class($clockA, $clockB, $locks, $options, $upAfterTakeover, $verify) implements MigrationInterface {
            public function __construct(
                private FixedClock $clockA,
                private FixedClock $clockB,
                private InMemoryLockStore $locks,
                private InMemoryOptionStore $options,
                private $upAfter,
                private $verifyWith
            ) {
            }
            public function version(): int { return 2; }
            public function id(): string { return '0002_step'; }
            public function up(DatabaseInterface $db): void
            {
                $this->clockA->advance(600);
                $this->clockB->advance(600);
                $b = new MigrationLock($this->locks, $this->clockB, 'owner-run-b-xxxx', 300);
                if (!$b->acquire()) {
                    throw new \RuntimeException('run B could not take over');
                }
                $this->options->set(SchemaVersion::OPTION, 3);
                $b->release();
                ($this->upAfter)();
            }
            public function verify(DatabaseInterface $db): bool
            {
                return (bool) ($this->verifyWith)();
            }
        };

        $plain = static fn (int $v): MigrationInterface => new class($v) implements MigrationInterface {
            public function __construct(private int $v)
            {
            }
            public function version(): int { return $this->v; }
            public function id(): string { return sprintf('%04d_step', $this->v); }
            public function up(DatabaseInterface $db): void {}
            public function verify(DatabaseInterface $db): bool { return true; }
        };

        return (new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $clockA, 'owner-run-a-xxxx', 300),
            [$plain(1), $step2, $plain(3)],
            $clockA,
            3
        ))->run();
    }

    public function testOwnershipIsProvenBeforeEachStepNotAssumed(): void
    {
        $clock = new FixedClock();
        $executed = [];
        $runner = $this->runner($clock, 'owner-run-a-xxxx', $this->steps($executed));

        // Someone else's value sits in the lock row from the very start: the
        // runner acquires nothing and must not execute any step.
        $this->locks->rows[MigrationLock::KEY] = json_encode(
            ['owner' => 'owner-other-run', 'acquired_at' => $clock->now()->getTimestamp(), 'expires_at' => $clock->now()->getTimestamp() + 300]
        );
        self::assertSame(MigrationStatus::Locked, $runner->run()->status);
        self::assertSame([], $executed);
    }

    public function testSchemaAheadOfThisBuildIsNeverMigratedDown(): void
    {
        $this->options->set(SchemaVersion::OPTION, 9);
        $clock = new FixedClock();
        $executed = [];
        $runner = $this->runner($clock, 'owner-run-a-xxxx', $this->steps($executed));

        $result = $runner->run();
        self::assertSame(MigrationStatus::Ahead, $result->status);
        self::assertTrue($runner->isAhead());
        self::assertSame(9, $this->options->get(SchemaVersion::OPTION), 'never written down');
        self::assertSame([], $executed);
        self::assertSame(0, $this->locks->inserts, 'no lock taken for a schema we must not touch');
    }
}
