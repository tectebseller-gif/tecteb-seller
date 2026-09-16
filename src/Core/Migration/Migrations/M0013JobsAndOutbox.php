<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Two tables for the two things this release stopped doing inside a request:
 * long work, and telling somebody else about it.
 *
 * **`tmc_jobs` — the checkpoint is a column, not a log line.** The Dokan import
 * used to run inside the admin POST that started it, so a PHP timeout in the
 * middle left no record of how far it had got; the only recovery was to run the
 * whole thing again and hope the duplicate guards held. `cursor` is that
 * missing record. A worker writes it after every batch, and a worker that
 * starts later reads it and carries on from there. It is TEXT because what a
 * cursor means belongs to the handler — a row id for one job, a page number for
 * another — and a column shape would have picked one of those for everybody.
 *
 * **`lock_token` with `locked_until`, and the claim is an UPDATE.** Two cron
 * runs overlapping is normal, not exotic: WP-Cron fires on a visitor request,
 * and two visitors arrive at once. A SELECT-then-UPDATE claim lets both of them
 * pass the SELECT, which is the same mistake `INSERT IGNORE` fixed for ratings.
 * So the claim is one `UPDATE … WHERE id = ? AND (lock_token IS NULL OR
 * locked_until < ?)` and the winner is the one the row count names. The lease
 * expires so a worker killed mid-batch does not hold the job for ever.
 *
 * **`live_key` is the dedupe, and it is nullable on purpose.** «Import Dokan»
 * queued twice while the first is still pending should be one job, not two —
 * but the same job type must be runnable again next month. MySQL lets a UNIQUE
 * index hold any number of NULLs, so the key is set while the job is pending or
 * running and cleared the moment it reaches a terminal state. The uniqueness is
 * therefore «one LIVE job per key», which is the rule we actually wanted, and it
 * is enforced by the index rather than by a check two requests can both pass.
 *
 * **`tmc_event_outbox` records what would be sent, and records it whether or
 * not anything can send.** `OutboundPolicy` blocks every outbound channel in
 * the Alpha and cannot be opened by configuration, so a row here reaching
 * `blocked` is the normal, correct outcome — not a failure. The signature is
 * stored next to the payload so the contract can be verified by a reader today
 * and the delivery attempted unchanged on the day a real endpoint exists.
 * Nothing in this table is ever updated in place except its delivery state: the
 * payload a row was signed over must stay exactly what was signed.
 */
final class M0013JobsAndOutbox implements MigrationInterface
{
    public const JOBS = 'tmc_jobs';

    public const OUTBOX = 'tmc_event_outbox';

    /** @var list<string> */
    public const TABLES = [self::JOBS, self::OUTBOX];

    public function version(): int
    {
        return 13;
    }

    public function id(): string
    {
        return '0013_jobs_and_outbox';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $charset = $db->charsetCollate();
        $jobs = self::table($db, self::JOBS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$jobs}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_type` VARCHAR(64) NOT NULL,
            `live_key` VARCHAR(191) NULL DEFAULT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
            `payload` LONGTEXT NULL,
            `cursor` LONGTEXT NULL,
            `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `done_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `failed_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `lock_token` VARCHAR(64) NULL DEFAULT NULL,
            `locked_until` DATETIME NULL DEFAULT NULL,
            `last_error` TEXT NULL,
            `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_job_live` (`job_type`, `live_key`),
            KEY `tmc_job_due` (`status`, `locked_until`, `id`),
            KEY `tmc_job_type` (`job_type`, `status`, `id`)
        ) {$charset}");

        $outbox = self::table($db, self::OUTBOX);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$outbox}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_type` VARCHAR(64) NOT NULL,
            `event_id` VARCHAR(64) NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
            `object_type` VARCHAR(32) NOT NULL DEFAULT '',
            `object_id` VARCHAR(64) NOT NULL DEFAULT '',
            `payload` LONGTEXT NOT NULL,
            `signature` VARCHAR(191) NOT NULL DEFAULT '',
            `signed_at` DATETIME NULL DEFAULT NULL,
            `delivery_state` VARCHAR(16) NOT NULL DEFAULT 'recorded',
            `delivery_reason` VARCHAR(191) NOT NULL DEFAULT '',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_attempt_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_outbox_event` (`event_id`),
            KEY `tmc_outbox_state` (`delivery_state`, `id`),
            KEY `tmc_outbox_vendor` (`vendor_user_id`, `id`),
            KEY `tmc_outbox_type` (`event_type`, `id`)
        ) {$charset}");
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            if (!$this->tableExists($db, self::table($db, $suffix))) {
                return false;
            }
        }
        return true;
    }

    private function tableExists(DatabaseInterface $db, string $table): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        ) > 0;
    }

    /** `execute()` answers with a row count, and a successful DDL affects none. */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
