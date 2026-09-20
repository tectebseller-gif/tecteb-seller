<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\TransactionInterface;

/**
 * A transaction that keeps no data — it only remembers what it was asked.
 *
 * Unit tests use this to assert the SHAPE of the unit of work: that a refused
 * decision rolls back rather than committing, that a decision never commits
 * twice. It deliberately cannot hold a row still, because nothing in one PHP
 * process can; the tests that prove figures are actually held still run
 * against real MariaDB with two connections.
 */
final class CountingTransaction implements TransactionInterface
{
    /** @var list<string> the verbs in the order they were called */
    public array $calls = [];
    public bool $failBegin = false;
    private int $depth = 0;

    public function begin(): bool
    {
        if ($this->failBegin) {
            return false;
        }
        $this->calls[] = 'begin';
        $this->depth++;
        return true;
    }

    public function commit(): bool
    {
        if ($this->depth === 0) {
            return false;
        }
        $this->depth--;
        $this->calls[] = 'commit';
        return true;
    }

    public function rollback(): bool
    {
        if ($this->depth === 0) {
            return false;
        }
        $this->depth = 0;
        $this->calls[] = 'rollback';
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}
