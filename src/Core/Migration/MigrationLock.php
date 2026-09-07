<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\LockStoreInterface;

/**
 * Owner-scoped, expiring migration lock (owner correction 1).
 *
 * Guarantees (tests/Unit/Core/Migration/MigrationLockTest.php and the
 * MariaDB suite):
 *  - acquire() succeeds for exactly one owner while the lock is live
 *  - an expired (or corrupt) lock may be taken over, but the takeover is an
 *    atomic compare-and-swap conditioned on the exact stale value observed
 *  - release()/refresh() only touch the lock while it still carries THIS
 *    owner's exact value: an old run can never delete or extend a lock that a
 *    newer run took over
 */
final class MigrationLock
{
    public const KEY = 'tmc_migration_lock';

    private ?string $heldValue = null;

    public function __construct(
        private LockStoreInterface $store,
        private ClockInterface $clock,
        private string $ownerToken,
        private int $ttlSeconds = 300
    ) {
        if (strlen($ownerToken) < 8) {
            throw new \InvalidArgumentException('Owner token must be at least 8 characters.');
        }
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('TTL must be positive.');
        }
    }

    public static function generateOwnerToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function ownerToken(): string
    {
        return $this->ownerToken;
    }

    public function isHeld(): bool
    {
        return $this->heldValue !== null;
    }

    public function acquire(): bool
    {
        if ($this->heldValue !== null) {
            return true;
        }
        $value = $this->encode();
        if ($this->store->insert(self::KEY, $value)) {
            $this->heldValue = $value;
            return true;
        }
        $current = $this->store->read(self::KEY);
        if ($current === null) {
            // Released between our insert and read: one more insert attempt.
            if ($this->store->insert(self::KEY, $value)) {
                $this->heldValue = $value;
                return true;
            }
            return false;
        }
        if ($this->isExpiredOrCorrupt($current)) {
            // Takeover is conditioned on the exact stale value we observed.
            if ($this->store->compareAndSwap(self::KEY, $current, $value)) {
                $this->heldValue = $value;
                return true;
            }
        }
        return false;
    }

    /** Extends the expiry; fails (and drops ownership) if the lock changed hands. */
    public function refresh(): bool
    {
        if ($this->heldValue === null) {
            return false;
        }
        $new = $this->encode();
        if ($new === $this->heldValue) {
            // Same second, same payload: MySQL reports 0 affected rows for a
            // no-change UPDATE, which would look like a lost lock. Nothing to do.
            return true;
        }
        if ($this->store->compareAndSwap(self::KEY, $this->heldValue, $new)) {
            $this->heldValue = $new;
            return true;
        }
        $this->heldValue = null;
        return false;
    }

    /** Deletes the lock only if it still carries this owner's exact value. */
    public function release(): bool
    {
        if ($this->heldValue === null) {
            return false;
        }
        $ok = $this->store->compareAndDelete(self::KEY, $this->heldValue);
        $this->heldValue = null;
        return $ok;
    }

    /** @return array{owner:string, acquired_at:int, expires_at:int}|null */
    public static function decode(string $value): ?array
    {
        $data = json_decode($value, true);
        if (!is_array($data)
            || !isset($data['owner'], $data['acquired_at'], $data['expires_at'])
            || !is_string($data['owner'])
            || !is_int($data['acquired_at'])
            || !is_int($data['expires_at'])) {
            return null;
        }
        return ['owner' => $data['owner'], 'acquired_at' => $data['acquired_at'], 'expires_at' => $data['expires_at']];
    }

    private function isExpiredOrCorrupt(string $current): bool
    {
        $decoded = self::decode($current);
        if ($decoded === null) {
            return true;
        }
        return $decoded['expires_at'] <= $this->clock->now()->getTimestamp();
    }

    private function encode(): string
    {
        $now = $this->clock->now()->getTimestamp();
        return json_encode(
            ['owner' => $this->ownerToken, 'acquired_at' => $now, 'expires_at' => $now + $this->ttlSeconds],
            JSON_THROW_ON_ERROR
        );
    }
}
