<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
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
 *  2. Re-confirms ownership immediately before each step and again before
 *     each version write. A run whose lock expired and was taken over stops
 *     at the next checkpoint and writes NOTHING — not the version, not the
 *     last-error option — so it cannot overwrite the newer run's state.
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
            return MigrationResult::upToDate($from);
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
                return MigrationResult::upToDate($current);
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
                // Ownership again immediately before recording state: this is
                // the write that must never land on top of a newer run.
                if (!$this->lock->refresh()) {
                    return MigrationResult::lockLost($from, $current, $applied, $migration->id());
                }
                try {
                    $persisted = $this->options->set(SchemaVersion::OPTION, $migration->version());
                } catch (\Throwable $e) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'version_persist_threw: ' . TextSanitizer::exceptionSummary($e));
                }
                if (!$persisted) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'version_persist_failed');
                }
                $current = $migration->version();
                $applied[] = $migration->id();
            }
            $this->options->delete(SchemaVersion::LAST_ERROR_OPTION);
            return MigrationResult::applied($from, $current, $applied);
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

    /** @param list<string> $applied */
    private function fail(int $from, int $reached, array $applied, string $step, string $message): MigrationResult
    {
        $this->options->set(SchemaVersion::LAST_ERROR_OPTION, [
            'step' => $step,
            'message' => TextSanitizer::singleLine($message, 200),
            'at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);
        return MigrationResult::failed($from, $reached, $applied, $step, $message);
    }
}
