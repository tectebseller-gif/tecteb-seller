<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
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
     * that the guard is supposed to close.
     *
     * @var null|callable():void
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

    public function setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): bool
    {
        if ($this->onGuardedWrite !== null) {
            ($this->onGuardedWrite)();
        }
        if (($this->rows[$guardKey] ?? null) !== $guardValue) {
            return false;
        }
        if ($this->optionStore !== null) {
            $existing = array_key_exists($key, $this->optionStore->data) ? $this->optionStore->data[$key] : null;
            $this->optionStore->data[$key] = $value;
            return $existing !== $value;
        }
        $existing = array_key_exists($key, $this->guarded) ? $this->guarded[$key] : null;
        $this->guarded[$key] = $value;
        return $existing !== $value;
    }

    public function deleteGuarded(string $key, string $guardKey, string $guardValue): bool
    {
        if ($this->onGuardedWrite !== null) {
            ($this->onGuardedWrite)();
        }
        if (($this->rows[$guardKey] ?? null) !== $guardValue) {
            return false;
        }
        if ($this->optionStore !== null) {
            if (!array_key_exists($key, $this->optionStore->data)) {
                return false;
            }
            unset($this->optionStore->data[$key]);
            return true;
        }
        if (!array_key_exists($key, $this->guarded)) {
            return false;
        }
        unset($this->guarded[$key]);
        return true;
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
