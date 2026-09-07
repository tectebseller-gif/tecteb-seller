<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Contracts\GuardedWriteOutcome;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\FakeDatabase;
use Tecteb\Marketplace\Tests\Support\InMemoryLockStore;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/** CORE-01: idempotent, resumable, lock-guarded, version-after-verify. */
final class MigrationRunnerTest extends TestCase
{
    private FakeDatabase $db;
    private InMemoryOptionStore $options;
    private InMemoryLockStore $locks;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->db = new FakeDatabase();
        $this->options = new InMemoryOptionStore();
        $this->locks = new InMemoryLockStore();
        // guarded writes must land where the runner reads
        $this->locks->optionStore = $this->options;
        $this->clock = new FixedClock();
    }

    private function runner(string $owner = 'owner-runner-1'): MigrationRunner
    {
        $lock = new MigrationLock($this->locks, $this->clock, $owner);
        return new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            $lock, [new M0001CreateAuditTable()], $this->clock, 1);
    }

    public function testFreshInstallAppliesStepAndReleasesLock(): void
    {
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(['0001_create_audit_table'], $result->appliedSteps);
        self::assertSame(1, $this->db->countExecuted('/^\s*CREATE TABLE IF NOT EXISTS `wp_tmc_audit_events`/'));
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
        self::assertSame([], $this->locks->rows, 'lock released');
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
    }

    public function testSecondRunIsUpToDateWithoutLockOrDdl(): void
    {
        $this->runner()->run();
        $inserts = $this->locks->inserts;
        $executed = count($this->db->executed);
        $result = $this->runner('owner-runner-2')->run();
        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame($inserts, $this->locks->inserts, 'no lock taken when nothing to do');
        self::assertSame($executed, count($this->db->executed), 'no DDL executed');
    }

    public function testReleaseUpgradeWithoutStructuralChangeRunsNothing(): void
    {
        // F-01: a release bump does not touch the schema version; runner sees stored == target.
        $this->options->set(SchemaVersion::OPTION, '1');
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame(0, $this->locks->inserts);
        self::assertSame([], $this->db->executed);
    }

    public function testResumesWhenTableExistsButVersionWasNeverWritten(): void
    {
        $this->db->tableExists = true; // crash happened after DDL, before the version write
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
    }

    public function testFailureKeepsVersionRecordsSanitisedErrorAndReleasesLock(): void
    {
        $this->db->failExecute = true;
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertSame('0001_create_audit_table', $result->failedStep);
        self::assertNull($this->options->get(SchemaVersion::OPTION), 'version never written on failure');
        $err = $this->options->get(SchemaVersion::LAST_ERROR_OPTION);
        self::assertSame('0001_create_audit_table', $err['step']);
        self::assertStringContainsString('simulated database failure', $err['message']);
        self::assertStringNotContainsString("\n", $err['message']);
        self::assertSame([], $this->locks->rows, 'lock released even on failure');
    }

    public function testVersionIsWrittenOnlyAfterVerify(): void
    {
        $this->db->createMarksTable = false; // DDL "succeeds" but verify sees no table
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertSame('verify_failed', $result->error);
        self::assertNull($this->options->get(SchemaVersion::OPTION));
    }

    public function testLockedByAnotherRunDoesNothing(): void
    {
        $other = new MigrationLock($this->locks, $this->clock, 'owner-other-run');
        self::assertTrue($other->acquire());
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Locked, $result->status);
        self::assertSame([], $this->db->executed, 'no DDL while another run holds the lock');
        self::assertNull($this->options->get(SchemaVersion::OPTION));
        self::assertTrue($other->isHeld());
        self::assertTrue($other->release());
    }

    public function testSuccessClearsPreviousError(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, ['step' => 'x', 'message' => 'y', 'at' => 'z']);
        $this->runner()->run();
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));
    }

    public function testNonContiguousMigrationsAreRejected(): void
    {
        $step3 = new class implements MigrationInterface {
            public function version(): int { return 3; }
            public function id(): string { return 'x'; }
            public function up(DatabaseInterface $db): void {}
            public function verify(DatabaseInterface $db): bool { return true; }
        };
        $this->expectException(\InvalidArgumentException::class);
        new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $this->clock, 'owner-runner-1'), [new M0001CreateAuditTable(), $step3], $this->clock, 3);
    }

    public function testVerifyThrowingBecomesAControlledFailureNotALeakedException(): void
    {
        $throwing = new class implements MigrationInterface {
            public function version(): int { return 1; }
            public function id(): string { return '0001_create_audit_table'; }
            public function up(DatabaseInterface $db): void {}
            public function verify(DatabaseInterface $db): bool
            {
                throw new \RuntimeException('information_schema unavailable at /srv/secret.php:9');
            }
        };
        $lock = new MigrationLock($this->locks, $this->clock, 'owner-verify-x');
        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            $lock, [$throwing], $this->clock, 1);

        $result = $runner->run(); // must NOT throw
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertStringStartsWith('verify_threw:', (string) $result->error);
        self::assertNull($this->options->get(SchemaVersion::OPTION), 'no version recorded for an unverified step');
        $err = $this->options->get(SchemaVersion::LAST_ERROR_OPTION);
        self::assertStringContainsString('RuntimeException', $err['message']);
        self::assertStringNotContainsString("\n", $err['message']);
        self::assertSame([], $this->locks->rows, 'lock released');
    }

    public function testVersionPersistenceThrowingBecomesAControlledFailure(): void
    {
        $options = new InMemoryOptionStore();
        // The version now travels through the GUARDED path, so that is where
        // an exploding store has to be simulated.
        $this->locks = new class extends InMemoryLockStore {
            public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): GuardedWriteOutcome
            {
                if ($key === SchemaVersion::OPTION) {
                    throw new \RuntimeException('option store exploded');
                }
                return parent::setGuarded($key, $value, $guardKey, $guardValue);
            }
        };
        $this->locks->optionStore = $options;
        $lock = new MigrationLock($this->locks, $this->clock, 'owner-persist-x');
        $runner = new MigrationRunner(
            $this->db,
            $options,
            $this->locks,
            $lock, [new M0001CreateAuditTable()], $this->clock, 1);

        $result = $runner->run(); // must NOT throw
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertStringStartsWith('version_persist_threw:', (string) $result->error);
        self::assertNull($options->get(SchemaVersion::OPTION), 'no version recorded');
        self::assertNotNull($options->get(SchemaVersion::LAST_ERROR_OPTION), 'the failure is visible');
        self::assertSame([], $this->locks->rows, 'lock released even when the store throws');
    }

    public function testStoredVersionAboveTargetIsReportedAheadAndNeverTouched(): void
    {
        $this->options->set(SchemaVersion::OPTION, 7);
        $runner = $this->runner();
        $result = $runner->run();
        self::assertSame(MigrationStatus::Ahead, $result->status);
        self::assertTrue($runner->isAhead());
        self::assertSame(7, $this->options->get(SchemaVersion::OPTION));
        self::assertSame([], $this->db->executed);
        self::assertSame(0, $this->locks->inserts);
    }

    public function testAuditTableDdlIsPrefixSafeAndUtc(): void
    {
        $this->runner()->run();
        $sql = $this->db->executed[0]['sql'];
        self::assertStringContainsString('`wp_tmc_audit_events`', $sql);
        self::assertStringContainsString('`actor_id` BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString('`created_at` DATETIME NOT NULL', $sql);
        self::assertStringContainsString('KEY `tmc_evt_created` (`event_type`, `created_at`)', $sql);
        self::assertStringContainsString('DEFAULT CHARACTER SET utf8mb4', $sql);
        self::assertStringNotContainsString('DROP', strtoupper($sql));
    }
}
