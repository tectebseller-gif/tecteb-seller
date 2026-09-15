<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Partial shipment and the return/refund infrastructure.
 *
 * Both are recorded as ROWS rather than as columns on the order line, and that
 * is the whole design decision:
 *
 *  - A line shipped in two parcels has two carriers and two tracking codes. A
 *    `carrier` column can hold one of them, so the second parcel would either
 *    overwrite the first or go untracked. `tmc_shipments` holds one row per
 *    parcel, and «how much of this line has shipped» is a SUM over them — one
 *    source of truth that cannot drift from a denormalised counter.
 *  - A return is a small workflow with a decision in the middle, not a
 *    boolean. `tmc_returns` holds one row per request, with the quantity it
 *    covers and the state it is in.
 *
 * Two unique indexes carry the "never refund twice" rule into the database,
 * where a race cannot get past it:
 *
 *  - `tmc_return_reversal` on `reversal_event_key`, the ledger event that
 *    reversed this return. It is NULL until the refund is recorded, and
 *    repeated NULLs are allowed in a unique index in both MySQL and MariaDB,
 *    so this reads as "each return reverses the ledger at most once".
 *  - `tmc_return_wc_refund` on `wc_refund_id`, so one WooCommerce refund
 *    cannot be recorded against two returns.
 *
 * Neither table adds a column to `tmc_order_items`: shipments and returns join
 * on its primary key, and the shipped and returned totals are SUMs over these
 * rows rather than counters beside them, so there is nothing on the order line
 * that can fall out of step with what the rows say.
 *
 * Nothing here encodes a commercial term. There is no return window, no
 * restocking fee and no shipping-refund column, because DEC-03 has not decided
 * any of them and a column with a default would be this plugin deciding by
 * implication. What the schema holds is the quantity, the decision, the money
 * that was actually reversed, and who did it.
 */
final class M0008ShipmentsAndReturns implements MigrationInterface
{
    public const SHIPMENTS = 'tmc_shipments';
    public const RETURNS = 'tmc_returns';

    /** @var list<string> */
    public const TABLES = [self::SHIPMENTS, self::RETURNS];

    public function version(): int
    {
        return 8;
    }

    public function id(): string
    {
        return '0008_shipments_and_returns';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $cc = $db->charsetCollate();

        $shipments = self::table($db, self::SHIPMENTS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$shipments}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `carrier` VARCHAR(96) NOT NULL DEFAULT '',
            `tracking_code` VARCHAR(96) NOT NULL DEFAULT '',
            `tracking_url` VARCHAR(255) NOT NULL DEFAULT '',
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `shipped_at` DATETIME NOT NULL,
            `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `tmc_shipment_item` (`order_item_id`),
            KEY `tmc_shipment_vendor` (`vendor_user_id`, `shipped_at`)
        ) {$cc}");

        $returns = self::table($db, self::RETURNS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$returns}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_item_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'requested',
            `reason` VARCHAR(190) NOT NULL DEFAULT '',
            `note` TEXT NULL,
            `refund_minor` BIGINT NULL,
            `tax_refund_minor` BIGINT NULL,
            `commission_reversed_minor` BIGINT NULL,
            `vendor_share_reversed_minor` BIGINT NULL,
            `restocked_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `reversal_event_key` VARCHAR(190) NULL,
            `wc_refund_id` BIGINT UNSIGNED NULL,
            `requested_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `requested_at` DATETIME NOT NULL,
            `decided_by` BIGINT UNSIGNED NULL,
            `decided_at` DATETIME NULL,
            `received_at` DATETIME NULL,
            `refunded_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_return_reversal` (`reversal_event_key`),
            UNIQUE KEY `tmc_return_wc_refund` (`wc_refund_id`),
            KEY `tmc_return_item` (`order_item_id`),
            KEY `tmc_return_vendor` (`vendor_user_id`, `status`)
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

    /**
     * `execute()` answers NULL on failure and an affected-row count otherwise,
     * and DDL affects zero rows — so the check is against null. `!$result`
     * would read every successful CREATE TABLE as a failed one.
     */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('shipments/returns migration failed: ' . $db->lastError());
        }
    }
}
