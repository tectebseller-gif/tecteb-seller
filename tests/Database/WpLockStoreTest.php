<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpLockStore;

/** Correction 1 on real SQL: INSERT IGNORE / conditional UPDATE / conditional DELETE, plus true concurrency via fork. */
final class WpLockStoreTest extends DatabaseTestCase
{
    public function testPrimitivesAreConditionalOnTheExactValue(): void
    {
        $store = new WpLockStore($this->wpdb);
        self::assertTrue($store->insert('k', 'v1'));
        self::assertFalse($store->insert('k', 'v2'), 'INSERT IGNORE: existing key is not overwritten');
        self::assertSame('v1', $store->read('k'));
        self::assertFalse($store->compareAndSwap('k', 'wrong', 'v3'));
        self::assertSame('v1', $store->read('k'));
        self::assertTrue($store->compareAndSwap('k', 'v1', 'v3'));
        self::assertSame('v3', $store->read('k'));
        self::assertFalse($store->compareAndDelete('k', 'v1'));
        self::assertSame('v3', $store->read('k'));
        self::assertTrue($store->compareAndDelete('k', 'v3'));
        self::assertNull($store->read('k'));
        self::assertNull($this->wpdb->last_error === '' ? null : $this->wpdb->last_error);
    }

    public function testOldRunCannotReleaseOrRefreshLockTakenOverOnRealDatabase(): void
    {
        $clock = new FixedClock();
        $store = new WpLockStore($this->wpdb);
        $old = new MigrationLock($store, $clock, 'owner-old-run-xx', 300);
        self::assertTrue($old->acquire());
        $clock->advance(301);
        $new = new MigrationLock($store, $clock, 'owner-new-run-yy', 300);
        self::assertTrue($new->acquire(), 'expired lock taken over atomically');
        self::assertSame('owner-new-run-yy', MigrationLock::decode((string) $this->lockRow())['owner']);

        self::assertFalse($old->release());
        self::assertSame('owner-new-run-yy', MigrationLock::decode((string) $this->lockRow())['owner'], 'stale DELETE affected 0 rows');
        self::assertFalse($old->refresh());
        self::assertSame('owner-new-run-yy', MigrationLock::decode((string) $this->lockRow())['owner'], 'stale UPDATE affected 0 rows');

        $clock->advance(10);
        self::assertTrue($new->refresh());
        self::assertTrue($new->release());
        self::assertNull($this->lockRow());
    }

    public function testExactlyOneOfManyConcurrentProcessesAcquiresTheLock(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::fail('pcntl is required for the concurrency test (Not Run would hide a real gap).');
        }
        // Children inherit and then close the parent's socket, so hand the
        // parent connection back before forking and take a fresh one after.
        $this->wpdb->disconnect();
        $children = [];
        $n = 8;
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                // Child: fresh connection, unique owner, single acquire attempt.
                $wpdb = new \wpdb();
                $lock = new MigrationLock(new WpLockStore($wpdb), new FixedClock(), 'owner-child-' . str_pad((string) $i, 8, '0'), 300);
                $ok = $lock->acquire();
                exit($ok ? 0 : 1);
            }
            $children[] = $pid;
        }
        $acquired = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $acquired++;
            }
        }
        $this->wpdb->reconnect();
        self::assertSame(1, $acquired, "exactly one of {$n} concurrent acquirers must win");
        self::assertNotNull($this->lockRow());
        self::assertStringStartsWith('owner-child-', MigrationLock::decode((string) $this->lockRow())['owner']);
    }
}
