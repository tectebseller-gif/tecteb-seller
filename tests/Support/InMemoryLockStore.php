<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\LockStoreInterface;

/**
 * Single-process model of the atomic primitives. The MariaDB suite tests the
 * real ones. Not final: one test subclasses it to simulate an insert race.
 */
class InMemoryLockStore implements LockStoreInterface
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
