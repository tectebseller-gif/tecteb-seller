<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * `wp_postmeta` as a handful of rows, so the port's ROW read can be asked a
 * question the object cache cannot answer for it.
 *
 * The point of `WpShopQuantityPort::readRows()` is that it does not go through
 * `get_post_meta()`, because `get_metadata_raw()` always answers from
 * `wp_cache_get($id, 'post_meta')`. Showing that needs the two to be able to
 * DISAGREE: the stub meta store holds one value and these rows hold another,
 * and the test asserts which one each method reports. With one store behind
 * both there is nothing to measure.
 *
 * It also makes two states reachable that the real table permits and the stub
 * meta API does not: two rows for one `(post_id, meta_key)` — `wp_postmeta`
 * has no unique index on the pair, measured on WordPress 7.1 — and a read
 * that FAILS, which must not be mistaken for a read that found nothing.
 *
 * It is a double and is never reported as a database. What the real statement
 * does on real MariaDB, inside the lock, is measured by the probe.
 */
final class CannedPostMetaDatabase implements DatabaseInterface
{
    /** @var list<array{meta_id:int, post_id:int, meta_key:string, meta_value:string}> */
    public array $rows = [];

    /** Post id => post type, for `exists()`. */
    public array $posts = [];

    /** When true every read answers «the statement failed». */
    public bool $failReads = false;

    private int $nextId = 1;

    public function addRow(int $postId, string $key, string $value): int
    {
        $id = $this->nextId++;
        $this->rows[] = ['meta_id' => $id, 'post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value];
        return $id;
    }

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
        return $this->failReads ? null : 0;
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        if ($this->failReads) {
            return null;
        }
        if (str_contains($sql, 'post_type FROM')) {
            return $this->posts[(int) ($params[0] ?? 0)] ?? null;
        }
        if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'postmeta')) {
            return (string) count($this->matching($params));
        }
        return null;
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return null;
    }

    public function getResults(string $sql, array $params = []): array
    {
        if ($this->failReads || !str_contains($sql, 'postmeta')) {
            return [];
        }
        $rows = $this->matching($params);
        usort($rows, static fn (array $a, array $b): int => $a['meta_id'] <=> $b['meta_id']);
        return $rows;
    }

    /** @return list<array{meta_id:int, post_id:int, meta_key:string, meta_value:string}> */
    private function matching(array $params): array
    {
        $postId = (int) ($params[0] ?? 0);
        $keys = array_map('strval', array_slice($params, 1));
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['post_id'] === $postId && in_array($row['meta_key'], $keys, true)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function begin(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function lastError(): string
    {
        return $this->failReads ? 'simulated read failure' : '';
    }
}
