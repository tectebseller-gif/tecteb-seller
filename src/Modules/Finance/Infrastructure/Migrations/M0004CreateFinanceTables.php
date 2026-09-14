<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Commission rules and the ledger.
 *
 * Two constraints carry the financial contract into the schema, where no
 * caller can forget them:
 *
 *  - `rate_bp` is NULLABLE. "No rule" and "a rule of zero" are different rows
 *    (FIN-02), and a NOT NULL DEFAULT 0 would erase that difference the day
 *    somebody inserts without naming the column.
 *  - `tmc_ledger_event` is UNIQUE on (event_key, account). That is what makes
 *    a repeated payment callback harmless (FIN-03): the second writer is
 *    rejected by the database, not by a check that two workers could both
 *    pass.
 */
final class M0004CreateFinanceTables implements MigrationInterface
{
    public const RULES = 'tmc_commission_rules';
    public const LEDGER = 'tmc_ledger_entries';

    /** @var list<string> */
    public const TABLES = [self::RULES, self::LEDGER];

    public function version(): int
    {
        return 4;
    }

    public function id(): string
    {
        return '0004_create_finance_tables';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $rules = self::table($db, self::RULES);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$rules}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scope` VARCHAR(16) NOT NULL,
            `reference` VARCHAR(190) NOT NULL,
            `rate_bp` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_rule_scope` (`scope`, `reference`)
        ) {$cc}");

        $ledger = self::table($db, self::LEDGER);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$ledger}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_key` VARCHAR(190) NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `account` VARCHAR(32) NOT NULL,
            `amount_minor` BIGINT NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `order_ref` VARCHAR(64) NOT NULL DEFAULT '',
            `item_ref` VARCHAR(64) NOT NULL DEFAULT '',
            `reason` VARCHAR(64) NOT NULL DEFAULT '',
            `reverses_entry_id` BIGINT UNSIGNED NULL,
            `snapshot` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_ledger_event` (`event_key`, `account`),
            KEY `tmc_ledger_vendor` (`vendor_user_id`, `account`),
            KEY `tmc_ledger_order` (`order_ref`, `item_ref`)
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
            throw new MigrationException('finance migration failed: ' . $db->lastError());
        }
    }
}
