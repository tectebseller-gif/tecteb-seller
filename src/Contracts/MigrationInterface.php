<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * One resumable schema step. up() MUST be idempotent: DDL in MySQL has no
 * transactional rollback, so a step may be re-run after a crash.
 */
interface MigrationInterface
{
    /** Schema version this step establishes (1, 2, 3, ...). */
    public function version(): int;

    /** Stable identifier, e.g. "0001_create_audit_table". */
    public function id(): string;

    /** @throws \Tecteb\Marketplace\Core\Exceptions\MigrationException */
    public function up(DatabaseInterface $db): void;

    /** True when the structure this step creates is present. */
    public function verify(DatabaseInterface $db): bool;
}
