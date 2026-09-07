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
