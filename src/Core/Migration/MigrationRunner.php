<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
use Tecteb\Marketplace\Contracts\GuardedWriteOutcome;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Support\TextSanitizer;

/**
 * Stepwise, resumable runner (CORE-01).
 *
 *  - runs only when the stored version < target; a plugin release without a
 *    structural change performs no work and takes no lock
 *  - each step: own the lock → up() (idempotent) → verify() → own the lock
 *    again → persist version. A crash between DDL and the version write is
 *    repaired by re-running, because up() is idempotent
 *  - the schema version is written only after the step verified AND only
 *    while this run still owns the lock
 *  - failure records a sanitised last-error option and stops; later steps are
 *    not attempted; the lock is always released by this owner only
 *  - a stored version ABOVE the target is never migrated down; it is reported
 *    as Ahead so the health page can say the database was written by a newer
 *    build (CORE-06)
 *
 * ## Concurrency guarantee, and its limit
 *
 * The lock is owner-scoped (see MigrationLock): take-over of an expired lock
 * is a compare-and-swap on the exact stale value, and release/refresh are
 * conditional on this run's own value. On top of that this runner:
 *
 *  1. RE-READS the stored version after acquiring the lock. Waiting for the
 *     lock can take arbitrarily long, and the run that held it may have
 *     advanced the schema meanwhile; the version read before acquiring is
 *     therefore stale by construction and must never drive the loop.
 *  2. Performs EVERY state write — the schema version, recording a failure,
 *     and clearing a previous failure — through GuardedOptionStoreInterface,
 *     which evaluates "do I still own the lock?" and the write in a SINGLE
 *     database statement. Checking ownership and then writing would leave a
 *     window: the process can be suspended between the two for longer than
 *     the lock TTL, and a take-over landing in that window would be
 *     overwritten by the superseded run. No arrangement of PHP-side checks
 *     closes that window; only the database can.
 *  3. Also refreshes the lock before each step, which extends the TTL and
 *     fails fast. That is an optimisation, not the safety property: the
 *     guard on each write is what makes a superseded run harmless.
 *
 * What this does NOT prevent: DDL already in flight. MySQL has no
 * transactional rollback for DDL and PHP cannot abort a statement the server
 * is executing, so a superseded run's CREATE/ALTER may still land after the
 * take-over. That is tolerable ONLY because every step is required to be
 * idempotent (MigrationInterface::up), which makes a duplicate execution a
 * no-op rather than corruption. A step that cannot be made idempotent needs a
 * different design and its own ADR (docs/adr/ADR-003).
 */
final class MigrationRunner
{
    /** @var list<MigrationInterface> sorted by version */
    private array $migrations;

    /** @param list<MigrationInterface> $migrations */
    public function __construct(
        private DatabaseInterface $db,
        private OptionStoreInterface $options,
        private GuardedOptionStoreInterface $guarded,
        private MigrationLock $lock,
        array $migrations,
        private ClockInterface $clock,
        private int $targetVersion = SchemaVersion::TARGET
    ) {
        usort($migrations, static fn (MigrationInterface $a, MigrationInterface $b) => $a->version() <=> $b->version());
        $expected = 1;
        foreach ($migrations as $m) {
            if ($m->version() !== $expected) {
                throw new \InvalidArgumentException('Migration versions must be contiguous from 1; got ' . $m->version());
            }
            $expected++;
        }
        if ($expected - 1 !== $targetVersion) {
            throw new \InvalidArgumentException('Migrations do not reach the target schema version.');
        }
        $this->migrations = array_values($migrations);
    }

    public function currentVersion(): int
    {
        $stored = $this->options->get(SchemaVersion::OPTION, 0);
        return is_numeric($stored) ? (int) $stored : 0;
    }

    public function targetVersion(): int
    {
        return $this->targetVersion;
    }

    public function needsMigration(): bool
    {
        return $this->currentVersion() < $this->targetVersion;
    }

    /** @return array{step:string, message:string, at:string}|null */
    public function lastError(): ?array
    {
        $stored = $this->options->get(SchemaVersion::LAST_ERROR_OPTION, null);
        if (!is_array($stored) || !isset($stored['step'], $stored['message'], $stored['at'])) {
            return null;
        }
        return ['step' => (string) $stored['step'], 'message' => (string) $stored['message'], 'at' => (string) $stored['at']];
    }

    public function run(): MigrationResult
    {
        $from = $this->currentVersion();
        if ($from > $this->targetVersion) {
            return MigrationResult::ahead($from, $this->targetVersion);
        }
        if ($from === $this->targetVersion) {
            // Nothing to migrate — but a failure record may still be lying
            // around from a run whose clear did not go through. This is the
            // recovery path for it: reactivating the plugin calls run().
            return MigrationResult::upToDate($from, $this->clearStaleRecord());
        }
        if (!$this->lock->acquire()) {
            return MigrationResult::locked($from);
        }
        $applied = [];
        try {
            // Re-read AFTER acquiring: waiting for the lock may have taken
            // arbitrarily long and the previous holder may have finished.
            $current = $this->currentVersion();
            if ($current > $this->targetVersion) {
                return MigrationResult::ahead($current, $this->targetVersion);
            }
            if ($current >= $this->targetVersion) {
                // Already inside the lock, so clear directly.
                return MigrationResult::upToDate($current, $this->clearRecordedErrorIfOwned());
            }
            $from = $current;

            foreach ($this->migrations as $migration) {
                if ($migration->version() <= $current) {
                    continue;
                }
                // Ownership before doing any work, to narrow the window in
                // which a superseded run can still issue DDL.
                if (!$this->lock->refresh()) {
                    return MigrationResult::lockLost($from, $current, $applied, $migration->id());
                }
                try {
                    $migration->up($this->db);
                } catch (\Throwable $e) {
                    return $this->fail($from, $current, $applied, $migration->id(), TextSanitizer::exceptionSummary($e));
                }
                try {
                    $verified = $migration->verify($this->db);
                } catch (\Throwable $e) {
                    // verify() must never leak: a broken check is a failed step.
                    return $this->fail($from, $current, $applied, $migration->id(), 'verify_threw: ' . TextSanitizer::exceptionSummary($e));
                }
                if (!$verified) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'verify_failed');
                }
                // The version write itself carries the ownership condition,
                // so a take-over that happens right now cannot be overwritten.
                try {
                    $persisted = $this->writeVersionIfOwned($migration->version());
                } catch (\Throwable $e) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'version_persist_threw: ' . TextSanitizer::exceptionSummary($e));
                }
                if (!$persisted) {
                    if (!$this->lock->stillOwned()) {
                        return MigrationResult::lockLost($from, $current, $applied, $migration->id());
                    }
                    return $this->fail($from, $current, $applied, $migration->id(), 'version_persist_failed');
                }
                $current = $migration->version();
                $applied[] = $migration->id();
            }
            // Clearing a previous failure is also state: only the owner may.
            // The outcome is carried in the result — a clear that failed left
            // a stale record behind and must not read as a clean success.
            return MigrationResult::applied($from, $current, $applied, $this->clearRecordedErrorIfOwned());
        } finally {
            // Conditional on this run's own value: a lock taken over by a
            // newer run is left untouched.
            $this->lock->release();
        }
    }

    /** True when the database was written by a build newer than this one. */
    public function isAhead(): bool
    {
        return $this->currentVersion() > $this->targetVersion;
    }

    /**
     * Records a controlled failure. If the guarded write is refused because
     * this run no longer owns the lock, the outcome is LockLost instead: a
     * superseded run has no standing to mark a database failed that the
     * newer run may have migrated successfully.
     *
     * @param list<string> $applied
     */
    private function fail(int $from, int $reached, array $applied, string $step, string $message): MigrationResult
    {
        $recorded = $this->recordErrorIfOwned($step, $message);
        if (!$recorded && !$this->lock->stillOwned()) {
            return MigrationResult::lockLost($from, $reached, $applied, $step);
        }
        return MigrationResult::failed(
            $from,
            $reached,
            $applied,
            $step,
            $recorded ? $message : $message . ' (not recorded)'
        );
    }

    /**
     * Writes the schema version only while this run owns the lock.
     * NoChangeNeeded (the stored value is already this version) counts as
     * persisted: the desired state is what matters, not the row count.
     */
    private function writeVersionIfOwned(int $version): bool
    {
        $guard = $this->lock->guardValue();
        if ($guard === null) {
            return false;
        }
        $outcome = $this->guarded->setGuarded(SchemaVersion::OPTION, $version, $this->lock->guardKey(), $guard);
        return $outcome === GuardedWriteOutcome::Written || $outcome === GuardedWriteOutcome::NoChangeNeeded;
    }

    /**
     * Records the failure, guarded by ownership. Never throws: failing to
     * write a failure must not become a second, uncontrolled failure on top
     * of the first — the caller is already on the error path and the health
     * page reports the migration as incomplete either way.
     */
    private function recordErrorIfOwned(string $step, string $message): bool
    {
        try {
            $guard = $this->lock->guardValue();
            if ($guard === null) {
                return false;
            }
            $outcome = $this->guarded->setGuarded(
                SchemaVersion::LAST_ERROR_OPTION,
                [
                    'step' => $step,
                    'message' => TextSanitizer::singleLine($message, 200),
                    'at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                ],
                $this->lock->guardKey(),
                $guard
            );
            return $outcome === GuardedWriteOutcome::Written || $outcome === GuardedWriteOutcome::NoChangeNeeded;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clears a recorded failure, guarded by ownership. Never throws, and never
     * collapses the four possible endings into one boolean:
     *
     *   NotNeeded        there was no record — an ordinary run, not a failure
     *   Cleared          the record was removed
     *   SkippedNotOwner  another run owns the lock and is authoritative here
     *   Failed           the database refused; a stale record is still there
     */
    private function clearRecordedErrorIfOwned(): CleanupOutcome
    {
        try {
            $guard = $this->lock->guardValue();
            if ($guard === null) {
                return CleanupOutcome::SkippedNotOwner;
            }
            return match ($this->guarded->deleteGuarded(SchemaVersion::LAST_ERROR_OPTION, $this->lock->guardKey(), $guard)) {
                GuardedWriteOutcome::Written => CleanupOutcome::Cleared,
                GuardedWriteOutcome::NoChangeNeeded => CleanupOutcome::NotNeeded,
                GuardedWriteOutcome::NotOwner => CleanupOutcome::SkippedNotOwner,
                GuardedWriteOutcome::Failed => CleanupOutcome::Failed,
            };
        } catch (\Throwable) {
            // Recording or clearing a failure must never become a second,
            // uncontrolled failure on top of the first.
            return CleanupOutcome::Failed;
        }
    }

    /**
     * Removes a failure record left behind by an earlier run when the schema
     * has since reached the target — the record describes a failure that no
     * longer exists.
     *
     * Costs nothing on an ordinary request: run() is reached only on
     * activation or when a migration is actually pending, and the lock is
     * taken only when there really is a record to remove.
     */
    private function clearStaleRecord(): CleanupOutcome
    {
        if ($this->lastError() === null) {
            return CleanupOutcome::NotNeeded;
        }
        if (!$this->lock->acquire()) {
            return CleanupOutcome::SkippedNotOwner; // another run is working; it owns this record
        }
        try {
            if ($this->currentVersion() < $this->targetVersion) {
                // The other run moved the schema in the meantime: the record
                // is not stale after all, and is not ours to remove.
                return CleanupOutcome::SkippedNotOwner;
            }
            return $this->clearRecordedErrorIfOwned();
        } finally {
            $this->lock->release();
        }
    }
}
