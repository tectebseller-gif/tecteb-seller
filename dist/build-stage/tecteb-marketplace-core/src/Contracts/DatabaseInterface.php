<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Thin database gateway. SQL uses wpdb-style placeholders (%s, %d) so the
 * same statement text runs through $wpdb->prepare() in WordPress and through
 * PDO bindings in the database test-suite.
 *
 * Table names are never parameters; callers build them from prefix().
 */
interface DatabaseInterface
{
    public function prefix(): string;

    /** e.g. "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" */
    public function charsetCollate(): string;

    /** @return int|null affected rows, or null on error (see lastError()) */
    public function execute(string $sql, array $params = []): ?int;

    public function getVar(string $sql, array $params = []): mixed;

    /** @return array<string,mixed>|null */
    public function getRow(string $sql, array $params = []): ?array;

    /** @return list<array<string,mixed>> */
    public function getResults(string $sql, array $params = []): array;

    public function lastError(): string;
}
