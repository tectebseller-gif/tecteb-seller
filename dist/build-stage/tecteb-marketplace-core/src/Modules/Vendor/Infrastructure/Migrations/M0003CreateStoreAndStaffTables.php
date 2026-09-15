<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Store settings, staff memberships and the manager's change queue.
 *
 * Three `CREATE TABLE IF NOT EXISTS`, idempotent and resumable like every
 * step before them. Two constraints carry rules rather than convenience:
 *
 *  - `tmc_staff_one_store` is UNIQUE on the staff USER, not on the pair. A
 *    person belongs to one shop (A.4, and plan §9 item 6 confirms it), so the
 *    database refuses a second membership rather than trusting every caller
 *    to check.
 *  - `tmc_staff_username` is unique across shops: the internal username
 *    identifies a person to the whole marketplace, so two shops cannot each
 *    have their own «anbar».
 */
final class M0003CreateStoreAndStaffTables implements MigrationInterface
{
    public const STORES = 'tmc_vendor_stores';
    public const STAFF = 'tmc_vendor_staff';
    public const CHANGE_REQUESTS = 'tmc_vendor_change_requests';

    /** @var list<string> */
    public const TABLES = [self::STORES, self::STAFF, self::CHANGE_REQUESTS];

    public function version(): int
    {
        return 3;
    }

    public function id(): string
    {
        return '0003_create_store_and_staff_tables';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $stores = self::table($db, self::STORES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$stores}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `store_name` VARCHAR(190) NOT NULL DEFAULT '',
            `city` VARCHAR(190) NOT NULL DEFAULT '',
            `intro` TEXT NULL,
            `logo_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `banner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `preparation_days` INT NOT NULL DEFAULT 1,
            `origin_warehouse` VARCHAR(190) NOT NULL DEFAULT '',
            `carriers` TEXT NULL,
            `closed` TINYINT(1) NOT NULL DEFAULT 0,
            `closed_from` DATE NULL,
            `closed_to` DATE NULL,
            `reopen_message` VARCHAR(255) NOT NULL DEFAULT '',
            `social` TEXT NULL,
            `bank_iban` VARCHAR(34) NOT NULL DEFAULT '',
            `bank_holder` VARCHAR(190) NOT NULL DEFAULT '',
            `bank_document_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `bank_status` VARCHAR(32) NOT NULL DEFAULT 'none',
            `settlement_on_hold` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_store_user` (`user_id`)
        ) {$cc}");

        $staff = self::table($db, self::STAFF);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$staff}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `staff_user_id` BIGINT UNSIGNED NOT NULL,
            `display_name` VARCHAR(190) NOT NULL DEFAULT '',
            `username` VARCHAR(60) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `mobile` VARCHAR(32) NOT NULL DEFAULT '',
            `role_preset` VARCHAR(32) NOT NULL,
            `permissions` TEXT NULL,
            `status` VARCHAR(16) NOT NULL,
            `invite_hash` CHAR(64) NOT NULL DEFAULT '',
            `invite_expires_at` DATETIME NULL,
            `invited_at` DATETIME NULL,
            `activated_at` DATETIME NULL,
            `suspended_at` DATETIME NULL,
            `last_seen_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_staff_one_store` (`staff_user_id`),
            UNIQUE KEY `tmc_staff_username` (`username`),
            KEY `tmc_staff_vendor` (`vendor_user_id`, `status`)
        ) {$cc}");

        $changes = self::table($db, self::CHANGE_REQUESTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$changes}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `field` VARCHAR(32) NOT NULL,
            `current_value` TEXT NULL,
            `requested_value` TEXT NULL,
            `status` VARCHAR(16) NOT NULL,
            `note` TEXT NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_change_queue` (`status`, `created_at`),
            KEY `tmc_change_vendor` (`vendor_user_id`, `field`)
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
            throw new MigrationException('store/staff migration failed: ' . $db->lastError());
        }
    }
}
