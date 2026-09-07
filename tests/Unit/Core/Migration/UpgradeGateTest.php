<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Migration\UpgradeGate;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\FakeDatabase;
use Tecteb\Marketplace\Tests\Support\InMemoryLockStore;
use Tecteb\Marketplace\Tests\Support\InMemoryOptionStore;

/**
 * The upgrade path that does NOT depend on activation, plus bounded retry of
 * a failed migration. WordPress never re-fires the activation hook when a
 * plugin's files are updated, so without this the new SCHEMA_VERSION would
 * ship and never be applied.
 */
final class UpgradeGateTest extends TestCase
{
    private InMemoryOptionStore $options;
    private InMemoryLockStore $locks;
    private FakeDatabase $db;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->options = new InMemoryOptionStore();
        $this->locks = new InMemoryLockStore();
        // guarded writes must land where the runner reads
        $this->locks->optionStore = $this->options;
        $this->db = new FakeDatabase();
        $this->clock = new FixedClock();
    }

    private function gate(): UpgradeGate
    {
        $runner = new MigrationRunner(
            $this->db,
            $this->options,
            $this->locks,
            new MigrationLock($this->locks, $this->clock, 'owner-upgrade-x'),
            [new M0001CreateAuditTable()],
            $this->clock,
            1
        );
        return new UpgradeGate($runner, $this->options, $this->clock);
    }

    public function testPendingUpgradeRunsWithoutAnyActivation(): void
    {
        // Files were replaced by an update: code targets 1, storage says 0,
        // and no activation hook will ever fire.
        self::assertTrue($this->gate()->shouldRun());
        $result = $this->gate()->runIfNeeded();
        self::assertNotNull($result);
        self::assertSame(MigrationStatus::Applied, $result->status);
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
    }

    public function testUpToDateDoesNothingAndTakesNoLock(): void
    {
        $this->options->set(SchemaVersion::OPTION, 1);
        self::assertFalse($this->gate()->shouldRun());
        self::assertNull($this->gate()->runIfNeeded());
        self::assertSame(0, $this->locks->inserts);
        self::assertSame([], $this->db->executed);
    }

    public function testSchemaAheadIsNeverRun(): void
    {
        $this->options->set(SchemaVersion::OPTION, 5);
        self::assertFalse($this->gate()->shouldRun(), 'a newer schema must not be touched by an older build');
        self::assertNull($this->gate()->runIfNeeded());
        self::assertSame(5, $this->options->get(SchemaVersion::OPTION));
    }

    public function testFailedMigrationIsRetriedButOnlyAfterTheCooldown(): void
    {
        $this->db->failExecute = true;
        $first = $this->gate()->runIfNeeded();
        self::assertSame(MigrationStatus::Failed, $first->status);
        self::assertNotNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION));

        // Immediately afterwards: the failure is recorded, so a retry on the
        // very next request would hammer the database.
        self::assertFalse($this->gate()->shouldRun());
        self::assertNull($this->gate()->runIfNeeded());
        self::assertSame(1, $this->db->countExecuted('/CREATE TABLE/'), 'no second attempt inside the cooldown');
        self::assertGreaterThan(0, $this->gate()->secondsUntilRetry());

        // After the cooldown the plugin recovers on its own.
        $this->clock->advance(UpgradeGate::RETRY_COOLDOWN_SECONDS);
        self::assertTrue($this->gate()->shouldRun());
        $this->db->failExecute = false;
        $second = $this->gate()->runIfNeeded();
        self::assertSame(MigrationStatus::Applied, $second->status);
        self::assertSame(1, $this->options->get(SchemaVersion::OPTION));
        self::assertNull($this->options->get(SchemaVersion::LAST_ERROR_OPTION), 'success clears the recorded failure');
        self::assertNull($this->gate()->secondsUntilRetry());
    }

    public function testCorruptFailureTimestampDoesNotBlockRecoveryForever(): void
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, ['step' => 'x', 'message' => 'y', 'at' => 'not-a-date']);
        self::assertTrue($this->gate()->shouldRun(), 'an unparseable timestamp must not wedge the plugin shut');
    }
}
