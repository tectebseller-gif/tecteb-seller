<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Vendor tables (plan §3.2). Four CREATE TABLE IF NOT EXISTS statements, so
 * the step is idempotent and a half-finished run resumes cleanly — the same
 * property the audit-table migration relies on.
 *
 * No seed data. In particular no document types: what a pharmacy marketplace
 * must collect is the owner's decision, and an empty table is the honest
 * starting point (plan §2).
 */
final class M0002CreateVendorTables implements MigrationInterface
{
    public const APPLICATIONS = 'tmc_vendor_applications';
    public const PROFILES = 'tmc_vendor_profiles';
    public const DOCUMENT_TYPES = 'tmc_vendor_document_types';
    public const DOCUMENTS = 'tmc_vendor_documents';

    /** @var list<string> */
    public const TABLES = [self::APPLICATIONS, self::PROFILES, self::DOCUMENT_TYPES, self::DOCUMENTS];

    public function version(): int
    {
        return 2;
    }

    public function id(): string
    {
        return '0002_create_vendor_tables';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $applications = self::table($db, self::APPLICATIONS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$applications}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(32) NOT NULL,
            `store_name` VARCHAR(190) NOT NULL DEFAULT '',
            `legal_name` VARCHAR(190) NOT NULL DEFAULT '',
            `contact_email` VARCHAR(190) NOT NULL DEFAULT '',
            `contact_mobile` VARCHAR(32) NOT NULL DEFAULT '',
            `address` TEXT NULL,
            `terms_accepted` TINYINT(1) NOT NULL DEFAULT 0,
            `review_note` TEXT NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `submitted_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_vendor_app_user` (`user_id`),
            KEY `tmc_vendor_app_status` (`status`, `updated_at`)
        ) {$cc}");

        $profiles = self::table($db, self::PROFILES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$profiles}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `store_name` VARCHAR(190) NOT NULL DEFAULT '',
            `can_sell` TINYINT(1) NOT NULL DEFAULT 0,
            `can_publish_directly` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_vendor_profile_user` (`user_id`)
        ) {$cc}");

        $types = self::table($db, self::DOCUMENT_TYPES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$types}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(64) NOT NULL,
            `label` VARCHAR(190) NOT NULL,
            `required` TINYINT(1) NOT NULL DEFAULT 1,
            `allowed_mime` VARCHAR(255) NOT NULL,
            `max_bytes` BIGINT UNSIGNED NOT NULL,
            `instructions` TEXT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_vendor_type_slug` (`slug`)
        ) {$cc}");

        $documents = self::table($db, self::DOCUMENTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$documents}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `application_id` BIGINT UNSIGNED NOT NULL,
            `type_slug` VARCHAR(64) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `stored_path` VARCHAR(255) NOT NULL,
            `mime` VARCHAR(128) NOT NULL,
            `size_bytes` BIGINT UNSIGNED NOT NULL,
            `uploaded_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_vendor_doc_one_per_type` (`application_id`, `type_slug`),
            KEY `tmc_vendor_doc_app` (`application_id`)
        ) {$cc}");
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            $table = self::table($db, $suffix);
            if ((string) $db->getVar('SHOW TABLES LIKE %s', [$table]) !== $table) {
                return false;
            }
        }
        return true;
    }

    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('vendor migration failed: ' . $db->lastError());
        }
    }
}
