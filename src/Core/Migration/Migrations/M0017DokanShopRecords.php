<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * The rest of a Dokan shop, as records: its staff, its balance ledger, and its
 * withdrawal requests.
 *
 * Same shape and same reasoning as the order history in migration 15, and for
 * the same rule (FIN-02): **one financial engine per order, and for a shop's
 * past it is Dokan's.** Nothing here is recomputed. The money columns are
 * `DECIMAL(19,4)` — byte for byte the type Dokan itself uses — so an imported
 * balance is not even converted, let alone re-derived from a rate this
 * marketplace happens to charge today. A shop that earned under a 5% rate and
 * is now on 8% must still see the number Dokan recorded, or the migration has
 * quietly restated its history.
 *
 * Every table carries `run_id` for the same reason the product rows do: the
 * row that exists is the record that an import created it, so a rollback works
 * from the rows and not from a manifest a crash can lose.
 *
 * Each has a UNIQUE key over its natural identity, so a resumed job writes one
 * row rather than a second copy — the index answers «did we already import
 * this?», which is a question two concurrent batches can both answer no.
 */
final class M0017DokanShopRecords implements MigrationInterface
{
    public const STAFF = 'tmc_dokan_staff_history';
    public const BALANCE = 'tmc_dokan_balance_history';
    public const WITHDRAW = 'tmc_dokan_withdraw_history';

    public const TABLES = [self::STAFF, self::BALANCE, self::WITHDRAW];

    public function version(): int
    {
        return 17;
    }

    public function id(): string
    {
        return '0017_dokan_shop_records';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $collate = $db->charsetCollate();

        $staff = self::table($db, self::STAFF);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$staff}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` VARCHAR(64) NOT NULL DEFAULT '',
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `staff_user_id` BIGINT UNSIGNED NOT NULL,
            `display_name` VARCHAR(191) NOT NULL DEFAULT '',
            `user_email` VARCHAR(191) NOT NULL DEFAULT '',
            `dokan_role` VARCHAR(64) NOT NULL DEFAULT '',
            `source` VARCHAR(32) NOT NULL DEFAULT 'dokan',
            `imported_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_staff_once` (`staff_user_id`, `vendor_user_id`),
            KEY `tmc_staff_vendor` (`vendor_user_id`),
            KEY `tmc_staff_run` (`run_id`)
        ) {$collate}");

        // `trn_id` is Dokan's own transaction id and `trn_type` says what kind
        // of thing it points at, so identity is the pair plus the shop —
        // exactly the key Dokan's own reads use.
        $balance = self::table($db, self::BALANCE);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$balance}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` VARCHAR(64) NOT NULL DEFAULT '',
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `trn_id` BIGINT UNSIGNED NOT NULL,
            `trn_type` VARCHAR(30) NOT NULL DEFAULT '',
            `particulars` TEXT NULL,
            `debit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `credit` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `status` VARCHAR(30) NOT NULL DEFAULT '',
            `trn_date` DATETIME NULL,
            `source` VARCHAR(32) NOT NULL DEFAULT 'dokan',
            `imported_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_balance_once` (`vendor_user_id`, `trn_id`, `trn_type`),
            KEY `tmc_balance_vendor` (`vendor_user_id`),
            KEY `tmc_balance_run` (`run_id`)
        ) {$collate}");

        $withdraw = self::table($db, self::WITHDRAW);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$withdraw}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` VARCHAR(64) NOT NULL DEFAULT '',
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `dokan_withdraw_id` BIGINT UNSIGNED NOT NULL,
            `amount` DECIMAL(19,4) NOT NULL DEFAULT 0,
            `status` VARCHAR(30) NOT NULL DEFAULT '',
            `method` VARCHAR(64) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `requested_at` DATETIME NULL,
            `source` VARCHAR(32) NOT NULL DEFAULT 'dokan',
            `imported_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_withdraw_once` (`dokan_withdraw_id`),
            KEY `tmc_withdraw_vendor` (`vendor_user_id`),
            KEY `tmc_withdraw_run` (`run_id`)
        ) {$collate}");
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

    /** `execute()` answers with a row count, and a successful DDL affects none. */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
