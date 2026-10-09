<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One row per vendor saying which money their books are kept in.
 *
 * **Why a table was unavoidable, and it is the only reason.** `alpha.39` asked
 * the ledger — `SELECT DISTINCT currency, exponent … WHERE vendor_user_id = %d`
 * — and treated «no rows» as «the first sale sets the unit». Two first
 * captures running at the same moment both see no rows and both write, each in
 * its own unit, and the vendor's books are mixed from that point on. Nothing in
 * the ledger can prevent it: the table holds many rows per vendor on purpose,
 * so there is no index a second writer can collide with.
 *
 * A guard against that needs a UNIQUELY KEYED row, which is what this is. The
 * primary key is the vendor, so the first `INSERT … ON DUPLICATE KEY` fixes the
 * unit and every later one reads back what was fixed — including the one that
 * ran a microsecond behind. The alternatives were weighed:
 *
 *  - a `NOT EXISTS` subquery inside the accrual's own `INSERT` — depends on gap
 *    locking and the isolation level, which is a guarantee this project would
 *    be asserting about a database setting it does not control;
 *  - a site-wide currency SETTING — a business decision nobody has made, and
 *    one that would let a form change the unit of money already recorded;
 *  - doing nothing and documenting the race — which is what `alpha.39` did.
 *
 * **It records, it does not decide.** The row is written by the first accrual
 * and never by a form; there is no screen that edits it and no setting that
 * overrides it. «قواعد تجاری باز را خودسرانه تغییر نده» — this adds no business
 * rule, it makes the rule that already existed («a marketplace records in one
 * unit») enforceable.
 *
 * **The backfill is deliberately partial.** Vendors whose existing ledger holds
 * exactly one unit get that unit, because it is a fact already on disk. Vendors
 * whose ledger is already MIXED get no row at all: writing one would pick a
 * winner between two kinds of money, and «دادهٔ ناسازگار موجود بدون تبدیل حدسی
 * گزارش شود» forbids that. They are reported instead
 * (`LedgerRepositoryInterface::vendorsWithMixedUnits()`) and their new accruals
 * are refused until a person decides.
 *
 * **Rolling back to `alpha.39` or earlier needs no database work.** The table
 * is additive and nothing older reads it; it simply sits there. The cost is
 * stated in `docs/upgrade-and-rollback.md` §23 and it is the race coming back,
 * not data loss.
 */
final class M0022VendorMoneyUnit implements MigrationInterface
{
    public const UNITS = 'tmc_vendor_money_unit';

    public function version(): int
    {
        return 22;
    }

    public function id(): string
    {
        return '0022_vendor_money_unit';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db, self::UNITS);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$table}` (
            `vendor_user_id` BIGINT UNSIGNED NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `exponent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `source_event` VARCHAR(190) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`vendor_user_id`)
        ) " . $db->charsetCollate());

        $this->seedFromLedger($db, $table);
    }

    /**
     * The unit each vendor's existing books already prove, where they prove
     * exactly one.
     *
     * `HAVING COUNT(DISTINCT …) = 1` is the whole rule: a vendor with one unit
     * on disk has a fact to record, and a vendor with two has a decision that
     * is not this migration's to make. `INSERT IGNORE` keeps a row the plugin
     * has written SINCE — on a re-run that is the newer truth and must win by
     * being left alone.
     *
     * One statement, so there is no cursor to advance and no batch to resume:
     * the source is `GROUP BY vendor_user_id` over a table that already has an
     * index on it, and the result is at most one row per vendor.
     */
    private function seedFromLedger(DatabaseInterface $db, string $table): void
    {
        $ledger = M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER);
        // Asked, not assumed, and read in THREE states (`alpha.38`'s rule): a
        // failed read answers `null`, which casts to nought, which would read
        // as «there is no ledger, nothing to seed» — and the schema would
        // reach 22 with not one unit recorded while `verify()` inspected only
        // the destination table and reported success.
        $hasLedger = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$ledger]
        );
        if ($hasLedger === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . $ledger . '`: ' . $db->lastError()
            );
        }
        // Nought is a VALID answer: a fresh install has no ledger yet, there is
        // nothing to seed, and that is not a failure.
        if ((int) $hasLedger === 0) {
            return;
        }
        if ($db->execute(
            "INSERT IGNORE INTO `{$table}`
             (`vendor_user_id`, `currency`, `exponent`, `source_event`, `created_at`)
             SELECT `vendor_user_id`, MIN(`currency`), MIN(`exponent`), %s, %s
               FROM `{$ledger}`
              WHERE `vendor_user_id` > 0
              GROUP BY `vendor_user_id`
             HAVING COUNT(DISTINCT CONCAT(`currency`, '/', `exponent`)) = 1",
            ['migration:0022', '2026-10-09 00:00:00']
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed seeding units from the ledger: ' . $db->lastError()
            );
        }
    }

    /**
     * The table, and the SHAPE of it — `alpha.37`'s rule.
     *
     * «آیا جدولی به این نام هست» is not the question: `CREATE TABLE IF NOT
     * EXISTS` is satisfied by any table of that name, and a step that reports
     * «complete» over a one-column impostor moves the schema onto something
     * nothing can be written to. So the five columns are named and the primary
     * key is checked. Not the types, for the reason migration 21 gives: a
     * wider column still holds what this writes, and a verify that insisted on
     * `tinyint(3) unsigned` would start failing on the first MariaDB that
     * reports it differently.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = self::table($db, self::UNITS);
        $columns = $db->getVar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($columns !== 'created_at,currency,exponent,source_event,vendor_user_id') {
            return false;
        }
        return $db->getVar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [$table, 'PRIMARY']
        ) === 'vendor_user_id';
    }

    /** `execute()` answers with a row count, and a successful DDL affects none (`alpha.8`). */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed: ' . $db->lastError() . ' — ' . $sql
            );
        }
    }
}
