<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * Dokan's order history, carried across as a record and nothing more.
 *
 * Master §12 asks for the history to come with the shop. The plan until now
 * COUNTED those orders and skipped them with a named reason
 * (`historic_commission_not_recomputed`), which was right about the money and
 * incomplete about the history: a vendor moving in lost every trace of what
 * they had sold.
 *
 * **This table is not the ledger, and that is the whole design.** Two rules
 * decide its shape:
 *
 *  - **Nothing here is computed.** `total_minor`, `net_minor` and
 *    `commission_minor` are Dokan's own figures, copied. No rate is applied —
 *    not the marketplace's, not a guessed one, not even a rate that happens to
 *    be configured. FIN-02 forbids inventing one, and the review's own rule
 *    says historical data must not be recalculated with the new rate. A column
 *    that held a number this plugin worked out would be a second, contradictory
 *    answer about money that already moved.
 *  - **It writes no ledger line, ever.** Settlement, the balance and every
 *    report read `tmc_ledger`; these rows are invisible to all of them. So «one
 *    financial engine is responsible per order» holds by construction: Dokan
 *    owned these orders, Dokan's numbers describe them, and this marketplace
 *    reports them without claiming them.
 *
 * **`UNIQUE (wc_order_id, vendor_user_id)`** because a Dokan order row is one
 * seller's share of one order, and importing twice — a resumed job re-running
 * its last page — must produce one record. The index decides that, not a check
 * two batches can both pass.
 *
 * **`run_id`** so an import of history is as undoable as an import of products.
 * A rollback that could remove the products but not the history would leave a
 * shop half-migrated with no way back.
 */
final class M0015DokanOrderHistory implements MigrationInterface
{
    public const HISTORY = 'tmc_dokan_order_history';

    /** @var list<string> */
    public const TABLES = [self::HISTORY];

    public function version(): int
    {
        return 15;
    }

    public function id(): string
    {
        return '0015_dokan_order_history';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $history = self::table($db, self::HISTORY);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$history}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` VARCHAR(64) NOT NULL DEFAULT '',
            `wc_order_id` BIGINT UNSIGNED NOT NULL,
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT '',
            `total_minor` BIGINT NOT NULL DEFAULT 0,
            `net_minor` BIGINT NOT NULL DEFAULT 0,
            `commission_minor` BIGINT NOT NULL DEFAULT 0,
            `refunded` TINYINT(1) NOT NULL DEFAULT 0,
            `source` VARCHAR(16) NOT NULL DEFAULT 'dokan',
            `imported_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tmc_history_once` (`wc_order_id`, `vendor_user_id`),
            KEY `tmc_history_vendor` (`vendor_user_id`, `wc_order_id`),
            KEY `tmc_history_run` (`run_id`)
        ) " . $db->charsetCollate());
    }

    public function verify(DatabaseInterface $db): bool
    {
        return (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [self::table($db, self::HISTORY)]
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
