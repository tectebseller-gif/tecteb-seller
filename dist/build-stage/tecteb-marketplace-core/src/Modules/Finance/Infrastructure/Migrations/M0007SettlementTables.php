<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

/**
 * Settlement: the withdrawal request, the lines it locked, and the one column
 * on the order line that says a manager called that sale complete.
 *
 * Three constraints carry FIN-05 into the schema rather than into a comment:
 *
 *  - `tmc_withdrawal_line` is UNIQUE on `order_item_id`. One sale can be
 *    reserved by exactly one withdrawal, ever. That is the database's answer
 *    to two tabs, a double click, or two workers racing — not a check that
 *    both could pass.
 *  - `tmc_withdrawal_open` is UNIQUE on `(vendor_user_id, open_marker)`, where
 *    `open_marker` is 1 while the request is open and NULL once it is closed.
 *    Repeated NULLs are allowed in a unique index in both MySQL and MariaDB,
 *    so this reads as "one OPEN request per vendor" (v1) while leaving any
 *    number of finished ones.
 *  - `settlement_completed_at` is NULLABLE with no default. "Nobody has called
 *    this sale complete" is a different fact from "it completed at the epoch",
 *    and ORDER-01 says only a manager or an approved process may record it.
 */
final class M0007SettlementTables implements MigrationInterface
{
    public const WITHDRAWALS = 'tmc_withdrawals';
    public const WITHDRAWAL_LINES = 'tmc_withdrawal_lines';

    /** @var list<string> */
    public const TABLES = [self::WITHDRAWALS, self::WITHDRAWAL_LINES];

    /** column => definition, added to tmc_order_items when missing */
    private const ORDER_ITEM_COLUMNS = [
        'settlement_completed_at' => 'DATETIME NULL',
        'settlement_completed_by' => 'BIGINT UNSIGNED NULL',
        'withdrawal_id' => 'BIGINT UNSIGNED NULL',
    ];

    public function version(): int
    {
        return 7;
    }

    public function id(): string
    {
        return '0007_settlement_and_withdrawals';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $withdrawals = self::table($db, self::WITHDRAWALS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$withdrawals}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'requested',
            `amount_minor` BIGINT NOT NULL DEFAULT 0,
            `line_count` INT NOT NULL DEFAULT 0,
            `open_marker` TINYINT(1) NULL DEFAULT 1,
            `iban` VARCHAR(34) NOT NULL DEFAULT '',
            `account_holder` VARCHAR(190) NOT NULL DEFAULT '',
            `reference` VARCHAR(96) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `reviewed_by` BIGINT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `paid_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_withdrawal_open` (`vendor_user_id`, `open_marker`),
            KEY `tmc_withdrawal_status` (`status`)
        ) {$cc}");

        $lines = self::table($db, self::WITHDRAWAL_LINES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$lines}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `withdrawal_id` BIGINT UNSIGNED NOT NULL,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `amount_minor` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_withdrawal_line` (`order_item_id`),
            KEY `tmc_withdrawal_lines_of` (`withdrawal_id`)
        ) {$cc}");

        $orderItems = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
        foreach (self::ORDER_ITEM_COLUMNS as $column => $definition) {
            if ($this->columnExists($db, $orderItems, $column)) {
                continue;
            }
            $this->run($db, "ALTER TABLE `{$orderItems}` ADD COLUMN `{$column}` {$definition}");
        }
        if (!$this->indexExists($db, $orderItems, 'tmc_order_settlement')) {
            $this->run($db, "ALTER TABLE `{$orderItems}` ADD KEY `tmc_order_settlement` (`vendor_user_id`, `settlement_completed_at`)");
        }
    }

    public function verify(DatabaseInterface $db): bool
    {
        foreach (self::TABLES as $suffix) {
            $table = self::table($db, $suffix);
            if ((string) $db->getVar('SHOW TABLES LIKE %s', [$table]) !== $table) {
                return false;
            }
        }
        $orderItems = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
        foreach (array_keys(self::ORDER_ITEM_COLUMNS) as $column) {
            if (!$this->columnExists($db, $orderItems, $column)) {
                return false;
            }
        }
        return true;
    }

    private function columnExists(DatabaseInterface $db, string $table, string $column): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, $column]
        ) > 0;
    }

    private function indexExists(DatabaseInterface $db, string $table, string $index): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [$table, $index]
        ) > 0;
    }

    /**
     * `execute()` answers NULL on failure and an affected-row count otherwise,
     * and DDL affects zero rows — so the check is against null. `!$result`
     * would read every successful CREATE TABLE as a failed one.
     */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('settlement migration failed: ' . $db->lastError());
        }
    }
}
