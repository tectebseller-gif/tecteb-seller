<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Atomic primitives needed by the migration lock.
 *
 * Every method MUST be atomic at the storage level (single statement),
 * otherwise the ownership guarantees of MigrationLock do not hold.
 */
interface LockStoreInterface
{
    /** Inserts only if $key does not exist. */
    public function insert(string $key, string $value): bool;

    public function read(string $key): ?string;

    /** Replaces the value only if the current value is exactly $expected. */
    public function compareAndSwap(string $key, string $expected, string $new): bool;

    /** Deletes only if the current value is exactly $expected. */
    public function compareAndDelete(string $key, string $expected): bool;
}
