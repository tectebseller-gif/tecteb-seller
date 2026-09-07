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
            new WpLockStore($this->wpdb),
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
        self::assertSame(1, $this->storedSchemaVersion());
        self::assertNull($this->lockRow(), 'lock released');
    }

    public function testSecondRunIsUpToDateAndIssuesNoDdlLockOrWrite(): void
    {
        $this->runner()->run();
        $before = count($this->wpdb->queries);
        $result = $this->runner(null, 1, 'owner-mig-test2')->run();
        self::assertSame(MigrationStatus::UpToDate, $result->status);

        // An up-to-date run reads the stored version and stops there: no lock
        // is taken, no DDL is issued, nothing is written.
        $during = array_slice($this->wpdb->queries, $before);
        self::assertCount(1, $during, implode(' | ', $during));
        self::assertMatchesRegularExpression('/^\s*SELECT\b/i', $during[0]);
        self::assertStringNotContainsString('tmc_migration_lock', $during[0], 'no lock row is touched');
    }

    public function testResumeAfterCrashBetweenDdlAndVersionWrite(): void
    {
        $this->runner()->run();
        delete_option(SchemaVersion::OPTION); // simulate: table exists, version never written
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(1, $this->storedSchemaVersion());
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
        self::assertSame(1, $this->storedSchemaVersion(), 'version reflects the last verified step only');
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
            new WpLockStore($this->wpdb),
            new MigrationLock(new WpLockStore($this->wpdb), $clockA, 'owner-real-run-a', 300),
            [$plain(1), $stalling, $plain(3)],
            $clockA,
            3
        );
        $result = $runner->run();

        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertSame(3, $this->storedSchemaVersion(), "run B's version survives on the real database");
        self::assertNull($this->rawOption(SchemaVersion::LAST_ERROR_OPTION), 'the superseded run recorded nothing');
        self::assertNull($this->lockRow(), 'no stale lock row left behind');
    }

    /**
     * The guarded writes themselves, on the real engine. Ownership is part of
     * the SAME statement as the write: there is no window between "am I still
     * the owner?" and "write", because there is no second statement.
     */
    public function testGuardedWriteOnlyHappensWhileTheGuardValueMatches(): void
    {
        $store = new WpLockStore($this->wpdb);
        $store->insert('tmc_migration_lock', 'owner-a:100');

        // Creates the row: the option does not exist yet.
        self::assertTrue($store->setGuarded('tmc_db_version', 1, 'tmc_migration_lock', 'owner-a:100'));
        self::assertSame('1', $this->rawOption('tmc_db_version'));

        // Updates it: same guard, existing row.
        self::assertTrue($store->setGuarded('tmc_db_version', 2, 'tmc_migration_lock', 'owner-a:100'));
        self::assertSame('2', $this->rawOption('tmc_db_version'));

        // Another owner now holds the lock: the write must not happen at all.
        self::assertTrue($store->compareAndSwap('tmc_migration_lock', 'owner-a:100', 'owner-b:700'));
        self::assertFalse($store->setGuarded('tmc_db_version', 99, 'tmc_migration_lock', 'owner-a:100'));
        self::assertSame('2', $this->rawOption('tmc_db_version'), 'the superseded owner changed nothing');

        // No lock row at all is not ownership either.
        self::assertTrue($store->compareAndDelete('tmc_migration_lock', 'owner-b:700'));
        self::assertFalse($store->setGuarded('tmc_db_version', 99, 'tmc_migration_lock', 'owner-a:100'));
        self::assertSame('2', $this->rawOption('tmc_db_version'));
    }

    public function testGuardedDeleteOnlyHappensWhileTheGuardValueMatches(): void
    {
        $store = new WpLockStore($this->wpdb);
        $store->insert('tmc_migration_lock', 'owner-a:100');
        self::assertTrue($store->setGuarded('tmc_db_last_error', ['step' => 's', 'message' => 'm'], 'tmc_migration_lock', 'owner-a:100'));
        self::assertNotNull($this->rawOption('tmc_db_last_error'));

        // A stale owner cannot delete the record the current owner wrote.
        self::assertTrue($store->compareAndSwap('tmc_migration_lock', 'owner-a:100', 'owner-b:700'));
        self::assertFalse($store->deleteGuarded('tmc_db_last_error', 'tmc_migration_lock', 'owner-a:100'));
        self::assertNotNull($this->rawOption('tmc_db_last_error'), "the new owner's record survives");

        // The current owner can.
        self::assertTrue($store->deleteGuarded('tmc_db_last_error', 'tmc_migration_lock', 'owner-b:700'));
        self::assertNull($this->rawOption('tmc_db_last_error'));

        // Deleting what is not there reports "nothing removed", not success.
        self::assertFalse($store->deleteGuarded('tmc_db_last_error', 'tmc_migration_lock', 'owner-b:700'));
    }

    /** Values a guarded write stores are read back identically by get_option(). */
    public function testGuardedWriteRoundTripsThroughTheOptionApi(): void
    {
        $store = new WpLockStore($this->wpdb);
        $store->insert('tmc_migration_lock', 'owner-a:100');
        $payload = ['step' => '0002_bad', 'message' => 'خطای آزمایشی', 'at' => '2026-01-01T00:00:00+00:00'];
        self::assertTrue($store->setGuarded(SchemaVersion::LAST_ERROR_OPTION, $payload, 'tmc_migration_lock', 'owner-a:100'));
        self::assertSame($payload, (new WpOptionStore())->get(SchemaVersion::LAST_ERROR_OPTION));
        self::assertSame($payload, get_option(SchemaVersion::LAST_ERROR_OPTION));
    }

    public function testRunRefusesToWriteTheVersionAfterAnotherOwnerTookTheLock(): void
    {
        $clock = new FixedClock();
        $wpdb = $this->wpdb;
        $thief = new class($wpdb) implements MigrationInterface {
            public function __construct(private \wpdb $wpdb)
            {
            }
            public function version(): int { return 1; }
            public function id(): string { return '0001_thief'; }
            public function up(DatabaseInterface $db): void
            {
                // Someone else now owns the lock; our version write must fail.
                $store = new WpLockStore($this->wpdb);
                $current = (string) $store->read('tmc_migration_lock');
                $store->compareAndSwap('tmc_migration_lock', $current, 'owner-thief:1');
            }
            public function verify(DatabaseInterface $db): bool { return true; }
        };

        $result = new MigrationRunner(
            new WpDatabase($wpdb),
            new WpOptionStore(),
            new WpLockStore($wpdb),
            new MigrationLock(new WpLockStore($wpdb), $clock, 'owner-victim', 300),
            [$thief],
            $clock,
            1
        );
        $result = $result->run();

        self::assertSame(MigrationStatus::LockLost, $result->status);
        self::assertNull($this->rawOption(SchemaVersion::OPTION), 'no version written without ownership');
        self::assertNull($this->rawOption(SchemaVersion::LAST_ERROR_OPTION), 'no error written without ownership');
        self::assertSame('owner-thief:1', $this->lockRow(), "the thief's lock is untouched");
    }

    public function testRunIsRefusedWhileAnotherOwnerHoldsTheLock(): void
    {
        $other = new MigrationLock(new WpLockStore($this->wpdb), new FixedClock(), 'owner-other-holder', 300);
        self::assertTrue($other->acquire());
        $result = $this->runner()->run();
        self::assertSame(MigrationStatus::Locked, $result->status);
        self::assertFalse($this->tableExists($this->auditTable()), 'no DDL while locked');
        self::assertNull($this->storedSchemaVersion());
        self::assertTrue($other->release());
        self::assertSame(MigrationStatus::Applied, $this->runner()->run()->status);
    }
}
