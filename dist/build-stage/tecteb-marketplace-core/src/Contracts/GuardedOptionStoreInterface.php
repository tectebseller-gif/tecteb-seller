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
 *
 * Both methods report WHAT HAPPENED (GuardedWriteOutcome), not merely whether
 * something happened. "The guard did not match", "there was nothing to do" and
 * "the database refused" are three different facts and the caller needs all
 * three: the first means another run is in charge, the second is a success,
 * and only the third is this run's problem.
 */
interface GuardedOptionStoreInterface
{
    /**
     * Writes $key = $value ONLY IF $guardKey currently holds exactly
     * $guardValue. Creates $key when it is absent.
     *
     * NoChangeNeeded means the guard matched and the stored value was already
     * identical — the desired state was reached, so callers must treat it as
     * success, not as a refused write.
     */
    public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): GuardedWriteOutcome;

    /**
     * Deletes $key ONLY IF $guardKey currently holds exactly $guardValue.
     *
     * NoChangeNeeded means the guard matched and there was no row to remove.
     * Absence is the desired state, so it is success, not failure.
     */
    public function deleteGuarded(string $key, string $guardKey, string $guardValue): GuardedWriteOutcome;
}
