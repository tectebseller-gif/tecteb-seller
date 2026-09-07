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
 *  - runs only when stored version < target; a plugin release without a
 *    structural change performs no work and takes no lock
 *  - each step: up() (idempotent) → verify() → persist version; so a crash
 *    between DDL and the version write is repaired by re-running
 *  - the schema version is written only after the step verified
 *  - failure records a sanitised last-error option and stops; later steps
 *    are not attempted; the lock is always released by this owner only
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
        if ($from >= $this->targetVersion) {
            return MigrationResult::upToDate($from);
        }
        if (!$this->lock->acquire()) {
            return MigrationResult::locked($from);
        }
        $current = $from;
        $applied = [];
        try {
            foreach ($this->migrations as $migration) {
                if ($migration->version() <= $current) {
                    continue;
                }
                try {
                    $migration->up($this->db);
                } catch (\Throwable $e) {
                    return $this->fail($from, $current, $applied, $migration->id(), TextSanitizer::exceptionSummary($e));
                }
                if (!$migration->verify($this->db)) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'verify_failed');
                }
                if (!$this->options->set(SchemaVersion::OPTION, $migration->version())) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'version_persist_failed');
                }
                $current = $migration->version();
                $applied[] = $migration->id();
                if ($current < $this->targetVersion && !$this->lock->refresh()) {
                    return $this->fail($from, $current, $applied, $migration->id(), 'lock_lost');
                }
            }
            $this->options->delete(SchemaVersion::LAST_ERROR_OPTION);
            return MigrationResult::applied($from, $current, $applied);
        } finally {
            $this->lock->release();
        }
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
