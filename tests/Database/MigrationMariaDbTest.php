<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\UpgradeGate;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpLockStore;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;

/** CORE-01/08 on MariaDB: real DDL, idempotent, resumable, lock-guarded, failure-safe. */
final class MigrationMariaDbTest extends DatabaseTestCase
{
    private function runner(array $migrations = null, int $target = 1, string $owner = 'owner-mig-test1'): MigrationRunner
    {
        return new MigrationRunner(
            new WpDatabase($this->wpdb),
            new WpOptionStore(),
            new MigrationLock(new WpLockStore($this->wpdb), new FixedClock(), $owner),
            $migrations ?? [new M0001CreateAuditTable()],
            new FixedClock(),
            $target
        );
    }

    public function testFreshRunCreatesPrefixSafeTableWithIndexesAndWritesVersion(): void
    {
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Applied, $result->status, (string) $result->error);
        self::assertTrue($this->tableExists($this->auditTable()));
        self::assertStringStartsWith('wptest_', $this->auditTable());
        self::assertSame(['PRIMARY', 'tmc_actor_created', 'tmc_evt_created', 'tmc_object'], $this->indexNames($this->auditTable()));
        $cols = $this->wpdb->get_results('SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . "'" . $this->auditTable() . "'" . ' ORDER BY ORDINAL_POSITION', ARRAY_A);
        self::assertSame(M0001CreateAuditTable::COLUMNS, array_column($cols, 'COLUMN_NAME'));
        self::assertSame('YES', $cols[2]['IS_NULLABLE'], 'actor_id nullable');
        self::assertSame('datetime', $cols[7]['DATA_TYPE']);
        self::assertSame(1, get_option(SchemaVersion::OPTION));
        self::assertNull($this->lockRow(), 'lock released');
    }

    public function testSecondRunIsUpToDateAndIssuesNoSql(): void
    {
        $this->runner()->run();
        $queries = $this->wpdb->num_queries;
        $result = $this->runner(null, 1, 'owner-mig-test2')->run();
        self::assertSame(MigrationStatus::UpToDate, $result->status);
        self::assertSame($queries, $this->wpdb->num_queries);
    }

    public function testResumeAfterCrashBetweenDdlAndVersionWrite(): void
    {
        $this->runner()->run();
        delete_option(SchemaVersion::OPTION); // simulate: table exists, version never written
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(1, get_option(SchemaVersion::OPTION));
        self::assertTrue($this->tableExists($this->auditTable()));
    }

    public function testFailingStepLeavesVersionUnchangedRecordsErrorAndReleasesLock(): void
    {
        $bad = new class implements MigrationInterface {
            public function version(): int { return 2; }
            public function id(): string { return '0002_bad_step'; }
            public function up(DatabaseInterface $db): void
            {
                if ($db->execute('CREATE TABLE `' . $db->prefix() . 'tmc_bad` (`x` NOT_A_TYPE)') === null) {
                    throw new MigrationException('DDL failed: ' . $db->lastError());
                }
            }
            public function verify(DatabaseInterface $db): bool { return false; }
        };
        $result = $this->runner([new M0001CreateAuditTable(), $bad], 2)->run();
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertSame('0002_bad_step', $result->failedStep);
        self::assertSame(['0001_create_audit_table'], $result->appliedSteps, 'first step applied and kept');
        self::assertSame(1, get_option(SchemaVersion::OPTION), 'version reflects the last verified step only');
        $err = get_option(SchemaVersion::LAST_ERROR_OPTION);
        self::assertSame('0002_bad_step', $err['step']);
        self::assertStringContainsString('MigrationException', $err['message']);
        self::assertStringNotContainsString("\n", $err['message']);
        self::assertNull($this->lockRow(), 'lock released after failure');
        self::assertFalse($this->tableExists($this->wpdb->prefix . 'tmc_bad'));

        // Re-running resumes at the failed step, does not redo step 1.
        $again = $this->runner([new M0001CreateAuditTable(), $bad], 2)->run();
        self::assertSame(MigrationStatus::Failed, $again->status);
        self::assertSame([], $again->appliedSteps);
    }

    /**
     * The take-over scenario on a REAL database, driven through the Runner:
     * run A stalls inside a step until its lock expires, run B takes over and
     * completes, and run A must then leave both the schema version and the
     * error option exactly as B left them.
     */
    public function testSupersededRunWritesNothingOverTheNewRunOnRealDatabase(): void
    {
        $clockA = new FixedClock();
        $clockB = new FixedClock();
        $wpdb = $this->wpdb;
        $options = new WpOptionStore();

        $plain = static fn (int $v): MigrationInterface => new class($v) implements MigrationInterface {
            public function __construct(private int $v)
            {
            }
            public function version(): int { return $this->v; }
            public function id(): string { return sprintf('%04d_noop', $this->v); }
            public function up(DatabaseInterface $db): void {}
            public function verify(DatabaseInterface $db): bool { return true; }
        };

        $stalling = new class($clockA, $clockB, $wpdb, $options) implements MigrationInterface {
            public function __construct(
                private FixedClock $clockA,
                private FixedClock $clockB,
                private \wpdb $wpdb,
                private WpOptionStore $options
            ) {
            }
            public function version(): int { return 2; }
            public function id(): string { return '0002_stalling'; }
            public function up(DatabaseInterface $db): void
            {
                $this->clockA->advance(600);
                $this->clockB->advance(600);
                $b = new MigrationLock(new WpLockStore($this->wpdb), $this->clockB, 'owner-real-run-b', 300);
                if (!$b->acquire()) {
                    throw new \RuntimeException('run B could not take over the expired lock');
                }
                $this->options->set(SchemaVersion::OPTION, 3);
                $b->release();
            }
            public function verify(DatabaseInterface $db): bool { return true; }
        };

        $runner = new MigrationRunner(
            new WpDatabase($this->wpdb),
            $options,
            new MigrationLock(new WpLockStore($this->wpdb), $clockA, 'owner-real-run-a', 300),
            [$plain(1), $stalling, $plain(3)],
            $clockA,
            3
        );
        $result = $runner->run();

        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertSame(3, (int) get_option(SchemaVersion::OPTION), "run B's version survives on the real database");
        self::assertFalse(get_option(SchemaVersion::LAST_ERROR_OPTION), 'the superseded run recorded nothing');
        self::assertNull($this->lockRow(), 'no stale lock row left behind');
    }

    public function testRunIsRefusedWhileAnotherOwnerHoldsTheLock(): void
    {
        $other = new MigrationLock(new WpLockStore($this->wpdb), new FixedClock(), 'owner-other-holder', 300);
        self::assertTrue($other->acquire());
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Locked, $result->status);
        self::assertFalse($this->tableExists($this->auditTable()), 'no DDL while locked');
        self::assertFalse(get_option(SchemaVersion::OPTION));
        self::assertTrue($other->release());
        self::assertSame(MigrationStatus::Applied, $this->runner()->run()->status);
    }
}
