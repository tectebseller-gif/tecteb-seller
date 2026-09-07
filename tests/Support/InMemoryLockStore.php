<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
use Tecteb\Marketplace\Contracts\GuardedWriteOutcome;
use Tecteb\Marketplace\Contracts\LockStoreInterface;

/**
 * Single-process model of the atomic primitives. The MariaDB suite tests the
 * real ones. Not final: one test subclasses it to simulate an insert race.
 */
class InMemoryLockStore implements LockStoreInterface, GuardedOptionStoreInterface
{
    /** @var array<string,string> */
    public array $rows = [];
    public int $inserts = 0;
    public int $swaps = 0;
    public int $deletes = 0;

    public function insert(string $key, string $value): bool
    {
        $this->inserts++;
        if (array_key_exists($key, $this->rows)) {
            return false;
        }
        $this->rows[$key] = $value;
        return true;
    }

    public function read(string $key): ?string
    {
        return $this->rows[$key] ?? null;
    }

    public function compareAndSwap(string $key, string $expected, string $new): bool
    {
        $this->swaps++;
        if (!array_key_exists($key, $this->rows) || $this->rows[$key] !== $expected) {
            return false;
        }
        $this->rows[$key] = $new;
        return true;
    }

    /**
     * Same semantics as the SQL version: the guard is evaluated and the write
     * applied as one indivisible step. A test may set $onGuardedWrite to run
     * immediately BEFORE that step, to model a take-over landing in the gap
     * that the guard is supposed to close. It receives the option key, so a
     * test can target one specific write.
     *
     * @var null|callable(string):void
     */
    public $onGuardedWrite = null;

    /**
     * Guarded writes must land where the runner READS from, otherwise the
     * test proves nothing. Point this at the same InMemoryOptionStore the
     * runner uses.
     */
    public ?InMemoryOptionStore $optionStore = null;

    /** Fallback storage when no option store is attached. */
    public array $guarded = [];

    /**
     * Option keys whose guarded writes report a storage failure, so a test can
     * tell "the database refused" apart from "the guard said no".
     *
     * @var list<string>
     */
    public array $failGuardedKeys = [];

    public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): GuardedWriteOutcome
    {
        if ($this->onGuardedWrite !== null) {
            ($this->onGuardedWrite)($key);
        }
        if (($this->rows[$guardKey] ?? null) !== $guardValue) {
            return GuardedWriteOutcome::NotOwner;
        }
        if (in_array($key, $this->failGuardedKeys, true)) {
            return GuardedWriteOutcome::Failed;
        }
        $store = $this->optionStore !== null ? $this->optionStore->data : $this->guarded;
        $existing = array_key_exists($key, $store) ? $store[$key] : null;
        if ($this->optionStore !== null) {
            $this->optionStore->data[$key] = $value;
        } else {
            $this->guarded[$key] = $value;
        }
        return $existing === $value ? GuardedWriteOutcome::NoChangeNeeded : GuardedWriteOutcome::Written;
    }

    public function deleteGuarded(string $key, string $guardKey, string $guardValue): GuardedWriteOutcome
    {
        if ($this->onGuardedWrite !== null) {
            ($this->onGuardedWrite)($key);
        }
        if (($this->rows[$guardKey] ?? null) !== $guardValue) {
            return GuardedWriteOutcome::NotOwner;
        }
        if (in_array($key, $this->failGuardedKeys, true)) {
            return GuardedWriteOutcome::Failed;
        }
        if ($this->optionStore !== null) {
            if (!array_key_exists($key, $this->optionStore->data)) {
                return GuardedWriteOutcome::NoChangeNeeded;
            }
            unset($this->optionStore->data[$key]);
            return GuardedWriteOutcome::Written;
        }
        if (!array_key_exists($key, $this->guarded)) {
            return GuardedWriteOutcome::NoChangeNeeded;
        }
        unset($this->guarded[$key]);
        return GuardedWriteOutcome::Written;
    }

    public function compareAndDelete(string $key, string $expected): bool
    {
        $this->deletes++;
        if (!array_key_exists($key, $this->rows) || $this->rows[$key] !== $expected) {
            return false;
        }
        unset($this->rows[$key]);
        return true;
    }
}
