<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

/**
 * THE UNIT, ON THE FOUR TABLES THAT CARRY MONEY WITHOUT IT.
 *
 * **Why this round needs a migration, stated rather than implied.** The owner
 * has decided that no rounding is permitted anywhere, with a worked example
 * exact to a thousandth of a تومان: a line of ۱۰۱ at ۱۲٫۵٪ owes ۱۲٫۶۲۵ and
 * leaves ۸۸٫۳۷۵. A rate is an integer number of basis points over a
 * denominator of `10000`, so an exact commission is an integer only at FOUR
 * more decimal places than the order's own unit — `CommissionPrecision`
 * derives that from the denominator rather than choosing it. Three stores
 * already carry `currency` and `exponent` per row and can hold such a figure
 * today:
 *
 *   * `tmc_ledger_entries` (migration 4) — the authoritative record, and the
 *     one `RecordCommission::recover()` reads figures back off;
 *   * `tmc_supplier_payments` / `tmc_supplier_obligations` and friends
 *     (migrations 29 and 31).
 *
 * Four do not, and they are the ones this step changes:
 *
 *   * `tmc_order_items` — `base_minor`, `tax_minor`, `commission_minor`,
 *     `vendor_share_minor` and `unit_price_minor` are bare `BIGINT`s whose
 *     unit was only ever implied, by `tmc_vendor_money_unit`'s guarantee that
 *     a vendor's books hold ONE unit (`alpha.40`);
 *   * `tmc_withdrawals` and `tmc_withdrawal_lines` — an amount summed from
 *     those shares, with the same implication;
 *   * `tmc_returns` — `refund_minor` and its three companions, which
 *     `ManageReturns::refund()` writes in the unit it reads off the sale's
 *     own financial event (`alpha.40`) and then stored WITHOUT it, so the
 *     two screens that print them had to assume one.
 *
 * With two exponents in one vendor's books that implication is no longer
 * true, so the unit has to be a fact on the row — `alpha.39`'s rule, applied
 * where it had not reached.
 *
 * **Nothing historical is rewritten, and there is NO BACKFILL.** All six
 * columns are nullable with no default, and `NULL` means «this row's unit was
 * not recorded» — which is the truth about every row that exists today, and
 * which every reader resolves exactly as it does now: from the vendor's
 * single money unit. Writing a guessed `exponent = 0` into them would be
 * exactly the «بازتفسیر» the owner ruled out, and it would be wrong on any
 * site whose orders were captured in a two-decimal currency.
 *
 * **Rolling back to `alpha.66` needs no database work** and the cost is
 * stated in `docs/upgrade-and-rollback.md` §47: the columns go unread, and a
 * commission recorded at the finer unit by this release would be read by
 * `alpha.66` as a number 10,000 times too large. That is the one rollback in
 * this plugin's history with a data-interpretation cost, and it is why the
 * document says to stop sales and settle open requests BEFORE going back —
 * not «just swap the ZIP».
 */
final class M0033MoneyUnitOnRows implements MigrationInterface
{
    public const WITHDRAWALS = 'tmc_withdrawals';
    public const WITHDRAWAL_LINES = 'tmc_withdrawal_lines';
    public const RETURNS = 'tmc_returns';

    /**
     * The two columns, and the tables that get them.
     *
     * Nullable with no default in every case. A `NOT NULL DEFAULT 0` exponent
     * would claim every legacy row was captured in a currency with no minor
     * unit, which is a guess dressed as a fact.
     */
    public const CURRENCY = 'tmc_currency';
    public const EXPONENT = 'tmc_exponent';

    public const COLUMNS = [
        self::CURRENCY => 'CHAR(3) NULL DEFAULT NULL',
        self::EXPONENT => 'TINYINT UNSIGNED NULL DEFAULT NULL',
    ];

    /**
     * Whether a table carries these two columns YET — asked of the database,
     * memoised per request.
     *
     * ⛔ **It exists because of the window the upgrade gate leaves open.**
     * Swapping the plugin's files does not run a migration: `UpgradeGate`
     * runs it on the next admin request, and if that step FAILS it stays
     * failed, visible on the health screen, and retried every five minutes
     * (`alpha.46`). In that window this release's code is on disk and these
     * columns are not in the database — and a `SELECT i.tmc_currency` is then
     * a hard SQL error on a path a vendor uses, which measuring the real
     * upgrade is what found.
     *
     * **Two answers, and they are deliberately different:**
     *
     *   * a READ degrades to the legacy reading — a row with no unit is at
     *     the vendor's single money unit, which is exactly what every release
     *     before this one assumed, so nothing is reinterpreted;
     *   * a WRITE of an amount at the finer exponent REFUSES, by name. An
     *     exact commission is ten thousand times the integer the coarse unit
     *     would use, and storing it in a row that cannot say so leaves a
     *     figure that reads correctly today and wrong the moment the column
     *     arrives. «نمی‌دانم» is an answer (`alpha.49`) and a silent
     *     conversion is the one thing the owner ruled out.
     *
     * A failed read answers `false`, which is the conservative direction for
     * both uses: a read falls back to the behaviour that was right for nine
     * releases, and a write stops.
     *
     * **Nothing is memoised here, and the caller memoises per INSTANCE** —
     * the shape `ProductTableShape` uses, with its own `forget()`. A `static`
     * cache inside this method survives the whole process, which is right for
     * one request and wrong for a test that drops the columns and asks again:
     * the first version of this answered `true` from a cache and the
     * degraded read turned into the SQL error it was written to prevent.
     */
    public static function carriedBy(DatabaseInterface $db, string $table): bool
    {
        $answer = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN (%s, %s)',
            [$table, self::CURRENCY, self::EXPONENT]
        );
        return $answer !== null && (int) $answer === 2;
    }

    public function version(): int
    {
        return 33;
    }

    public function id(): string
    {
        return '0033_money_unit_on_rows';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    /** @return list<string> the four tables, in their own prefix */
    private function tables(DatabaseInterface $db): array
    {
        return [
            M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS),
            self::table($db, self::WITHDRAWALS),
            self::table($db, self::WITHDRAWAL_LINES),
            self::table($db, self::RETURNS),
        ];
    }

    public function up(DatabaseInterface $db): void
    {
        foreach ($this->tables($db) as $table) {
            foreach (self::COLUMNS as $column => $definition) {
                // THREE STATES (`alpha.38`): a failed read answers `null`,
                // which casts to nought, which reads as «not there» and sends
                // an `ALTER` that then fails on the resume.
                $has = $db->getVar(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    [$table, $column]
                );
                if ($has === null) {
                    throw new MigrationException(
                        'migration ' . $this->id() . ' failed asking `' . $table . '`.`' . $column
                        . '`: ' . $db->lastError()
                    );
                }
                if ((int) $has === 1) {
                    continue;           // a resumed run; already done
                }
                if ($db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}") === null) {
                    throw new MigrationException(
                        'migration ' . $this->id() . ' failed adding `' . $table . '`.`' . $column
                        . '`: ' . $db->lastError()
                    );
                }
            }
        }
    }

    /**
     * The SHAPE this step needs — nullable, no default — and not the column set.
     *
     * Matching the exact set of columns would FREEZE the table and make the
     * next step lock the chain on 33 (`alpha.51`). What matters here is that
     * absence stays expressible: a column added `NOT NULL DEFAULT 0` would
     * satisfy «it exists» and then assert that every legacy row was captured
     * with no minor unit.
     */
    public function verify(DatabaseInterface $db): bool
    {
        foreach ($this->tables($db) as $table) {
            foreach (array_keys(self::COLUMNS) as $column) {
                $row = $db->getRow(
                    'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default
                       FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    [$table, $column]
                );
                if ($row === null) {
                    return false;
                }
                // «No default» has two spellings and this was MEASURED, not
                // read: MariaDB 10.11 reports `COLUMN_DEFAULT` for a
                // `DEFAULT NULL` column as the four-character STRING `NULL`,
                // while MySQL 8 reports SQL NULL. Both mean the same thing;
                // `''` or `'0'` do not and are refused.
                $default = $row['col_default'];
                $noDefault = $default === null || strtoupper(trim((string) $default)) === 'NULL';
                if (strtoupper((string) ($row['nullable'] ?? 'NO')) !== 'YES' || !$noDefault) {
                    return false;
                }
            }
        }
        return true;
    }
}
