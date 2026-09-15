<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Two tables: what a ticket message carries, and what a panel has to tell
 * somebody.
 *
 * **Attachments are rows, not a column on the message.** One message can carry
 * more than one file, and each file needs its own size, type, stored path and
 * — the point of the whole thing — its own hiding decision. A column would
 * have forced either one file per message or a serialised blob nobody can
 * query.
 *
 * **The stored path is a path into private storage, never a URL.** There is no
 * `url` column here on purpose: `PrivateFileStorageInterface` writes outside
 * every servable directory and the only way back out is a capability-checked
 * read. A URL column would be an invitation to print it.
 *
 * **A notification is addressed to a person, not broadcast.** `user_id` is who
 * sees it; `vendor_user_id` is which shop it is about, so a shop's staff can
 * be shown their own store's notices without the row being duplicated per
 * member. `read_at` being null is the whole unread state — no separate table,
 * no counter to drift.
 *
 * Nothing here is deleted by anything. Hiding an attachment sets a flag and a
 * reason, exactly like hiding a message, because «پیام و فایل ویرایش عادی
 * ندارند» (UX §10.1) and a deletion is not moderation.
 *
 * Retention is still DEC-05's to decide: no expiry column, no cron, nothing
 * that removes a row after a period nobody has approved.
 */
final class M0011AttachmentsAndNotices implements MigrationInterface
{
    public const TICKET_ATTACHMENTS = 'tmc_ticket_attachments';
    public const NOTIFICATIONS = 'tmc_notifications';

    /** @var list<string> */
    public const TABLES = [self::TICKET_ATTACHMENTS, self::NOTIFICATIONS];

    public function version(): int
    {
        return 11;
    }

    public function id(): string
    {
        return '0011_ticket_attachments_and_notifications';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $charset = $db->charsetCollate();

        $attachments = self::table($db, self::TICKET_ATTACHMENTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$attachments}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id` BIGINT UNSIGNED NOT NULL,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `uploaded_by` BIGINT UNSIGNED NOT NULL,
            `original_name` VARCHAR(191) NOT NULL DEFAULT '',
            `stored_path` VARCHAR(255) NOT NULL,
            `mime` VARCHAR(100) NOT NULL DEFAULT '',
            `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `hidden` TINYINT(1) NOT NULL DEFAULT 0,
            `hidden_reason` VARCHAR(255) NOT NULL DEFAULT '',
            `hidden_by` BIGINT UNSIGNED NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_attachment_message` (`message_id`),
            KEY `tmc_attachment_ticket` (`ticket_id`),
            UNIQUE KEY `tmc_attachment_path` (`stored_path`)
        ) {$charset}");

        $notices = self::table($db, self::NOTIFICATIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$notices}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `event` VARCHAR(64) NOT NULL,
            `subject_type` VARCHAR(32) NOT NULL DEFAULT '',
            `subject_id` VARCHAR(64) NOT NULL DEFAULT '',
            `context` LONGTEXT NULL,
            `read_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_notice_inbox` (`user_id`, `read_at`, `id`),
            KEY `tmc_notice_subject` (`subject_type`, `subject_id`),
            UNIQUE KEY `tmc_notice_once` (`user_id`, `event`, `subject_type`, `subject_id`)
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

    /**
     * Runs one statement, and treats only NULL as failure.
     *
     * `execute()` answers with the affected row count, and a successful DDL
     * affects none — so `if (!$db->execute(...))` reads every successful
     * CREATE TABLE as a failure. That mistake took a whole schema down once;
     * the check is `=== null` everywhere because of it.
     */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
