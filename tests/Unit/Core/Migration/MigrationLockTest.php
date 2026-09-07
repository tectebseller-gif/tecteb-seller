<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Migration;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Tests\Support\InMemoryLockStore;

/** CORE-01 lock semantics + owner correction 1. The same scenarios run on MariaDB in tests/Database. */
final class MigrationLockTest extends TestCase
{
    private InMemoryLockStore $store;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryLockStore();
        $this->clock = new FixedClock();
    }

    private function lock(string $owner, int $ttl = 300): MigrationLock
    {
        return new MigrationLock($this->store, $this->clock, $owner, $ttl);
    }

    public function testAcquireStoresOwnerAndExpiry(): void
    {
        $a = $this->lock('owner-aaaaaaaa');
        self::assertTrue($a->acquire());
        self::assertTrue($a->isHeld());
        $row = MigrationLock::decode($this->store->rows[MigrationLock::KEY]);
        self::assertSame('owner-aaaaaaaa', $row['owner']);
        self::assertSame($this->clock->now()->getTimestamp() + 300, $row['expires_at']);
        self::assertTrue($a->acquire(), 'idempotent for the holder');
        self::assertSame(1, $this->store->inserts);
    }

    public function testSecondOwnerCannotAcquireLiveLock(): void
    {
        $this->lock('owner-aaaaaaaa')->acquire();
        $b = $this->lock('owner-bbbbbbbb');
        $this->clock->advance(299);
        self::assertFalse($b->acquire());
        self::assertFalse($b->isHeld());
        self::assertSame('owner-aaaaaaaa', MigrationLock::decode($this->store->rows[MigrationLock::KEY])['owner']);
    }

    public function testExpiredLockIsTakenOverAtomically(): void
    {
        $this->lock('owner-aaaaaaaa')->acquire();
        $this->clock->advance(301);
        $b = $this->lock('owner-bbbbbbbb');
        self::assertTrue($b->acquire());
        self::assertSame('owner-bbbbbbbb', MigrationLock::decode($this->store->rows[MigrationLock::KEY])['owner']);
        self::assertSame(1, $this->store->swaps, 'takeover is a compare-and-swap, not a blind write');
    }

    public function testOldRunCannotReleaseOrRefreshLockTakenOverByNewRun(): void
    {
        $old = $this->lock('owner-aaaaaaaa');
        self::assertTrue($old->acquire());
        $this->clock->advance(301);
        $new = $this->lock('owner-bbbbbbbb');
        self::assertTrue($new->acquire());

        self::assertFalse($old->release(), 'stale owner must not delete the new lock');
        self::assertArrayHasKey(MigrationLock::KEY, $this->store->rows);
        self::assertSame('owner-bbbbbbbb', MigrationLock::decode($this->store->rows[MigrationLock::KEY])['owner']);

        self::assertFalse($old->refresh(), 'stale owner must not extend the new lock');
        self::assertFalse($old->isHeld());
        self::assertSame('owner-bbbbbbbb', MigrationLock::decode($this->store->rows[MigrationLock::KEY])['owner']);

        self::assertTrue($new->release());
        self::assertArrayNotHasKey(MigrationLock::KEY, $this->store->rows);
    }

    public function testReleaseOnlyDeletesOwnValue(): void
    {
        $a = $this->lock('owner-aaaaaaaa');
        $a->acquire();
        self::assertTrue($a->release());
        self::assertSame([], $this->store->rows);
        self::assertFalse($a->release(), 'second release is a no-op');
    }

    public function testCorruptValueCanBeTakenOver(): void
    {
        $this->store->rows[MigrationLock::KEY] = '{not json';
        $b = $this->lock('owner-bbbbbbbb');
        self::assertTrue($b->acquire());
        self::assertSame('owner-bbbbbbbb', MigrationLock::decode($this->store->rows[MigrationLock::KEY])['owner']);
    }

    public function testRefreshExtendsExpiryAndIsNoOpWithinSameSecond(): void
    {
        $a = $this->lock('owner-aaaaaaaa');
        $a->acquire();
        $before = MigrationLock::decode($this->store->rows[MigrationLock::KEY])['expires_at'];
        self::assertTrue($a->refresh(), 'same-second refresh is a no-op success');
        self::assertSame(0, $this->store->swaps);
        $this->clock->advance(10);
        self::assertTrue($a->refresh());
        self::assertSame($before + 10, MigrationLock::decode($this->store->rows[MigrationLock::KEY])['expires_at']);
        self::assertTrue($a->isHeld());
    }

    public function testInsertRaceFallsBackToReadThenRetry(): void
    {
        // Lock deleted between failed insert and read: acquire retries the insert once.
        $store = new class extends InMemoryLockStore {
            public int $calls = 0;
            public function insert(string $key, string $value): bool
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return false; // simulate a concurrent holder that releases immediately
                }
                return parent::insert($key, $value);
            }
        };
        $lock = new MigrationLock($store, $this->clock, 'owner-aaaaaaaa');
        self::assertTrue($lock->acquire());
        self::assertSame(2, $store->calls);
    }

    public function testTokenAndTtlValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lock('short');
    }

    public function testGeneratedTokenIsRandomHex(): void
    {
        $t1 = MigrationLock::generateOwnerToken();
        $t2 = MigrationLock::generateOwnerToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $t1);
        self::assertNotSame($t1, $t2);
    }
}
