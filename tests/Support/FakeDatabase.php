<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * Records statements; simulates the audit table's presence for verify().
 * Real DDL runs in tests/Database against MariaDB.
 */
final class FakeDatabase implements DatabaseInterface
{
    /** @var list<array{sql:string, params:array}> */
    public array $executed = [];
    public bool $failExecute = false;
    public bool $tableExists = false;
    public bool $createMarksTable = true;
    private string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function charsetCollate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function execute(string $sql, array $params = []): ?int
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];
        if ($this->failExecute) {
            $this->error = 'simulated database failure';
            return null;
        }
        if ($this->createMarksTable && preg_match('/^\s*CREATE TABLE/i', $sql) === 1) {
            $this->tableExists = true;
        }
        return 0;
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return $this->tableExists ? 8 : 0;
        }
        return null;
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function getResults(string $sql, array $params = []): array
    {
        return [];
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function countExecuted(string $pattern): int
    {
        $n = 0;
        foreach ($this->executed as $e) {
            if (preg_match($pattern, $e['sql']) === 1) {
                $n++;
            }
        }
        return $n;
    }
}
