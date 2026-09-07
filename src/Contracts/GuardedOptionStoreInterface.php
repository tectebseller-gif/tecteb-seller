<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Option writes that are conditioned on a guard row IN THE SAME STATEMENT.
 *
 * WHY THIS EXISTS: checking ownership and then writing is two operations.
 * Between them the process can be suspended — a slow query, a stalled
 * request, the OS descheduling the worker — long enough for the migration
 * lock to expire and a newer run to take it over. The write then lands on
 * top of the newer run's state even though the check "passed".
 *
 * These methods close that window by pushing the condition into the database:
 * the guard comparison and the write are one statement, so either both happen
 * or neither does. No amount of extra checking in PHP can provide this.
 */
interface GuardedOptionStoreInterface
{
    /**
     * Writes $key = $value ONLY IF $guardKey currently holds exactly
     * $guardValue. Creates $key when it is absent.
     *
     * @return bool true when the write took effect. False means either the
     *         guard did not match or the stored value was already identical;
     *         callers that need to tell those apart must ask the lock.
     */
    public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): bool;

    /**
     * Deletes $key ONLY IF $guardKey currently holds exactly $guardValue.
     *
     * @return bool true when a row was removed. False also covers "there was
     *         nothing to delete", which is not an error.
     */
    public function deleteGuarded(string $key, string $guardKey, string $guardValue): bool;
}
