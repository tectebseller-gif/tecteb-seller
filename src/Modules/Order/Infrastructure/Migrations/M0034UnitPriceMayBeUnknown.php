<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

/**
 * THE PER-UNIT PRICE MAY BE UNKNOWN, because sometimes it is.
 *
 * **Why this round needs a migration, stated rather than implied.** The
 * owner's §3 says «قطع اعشار» is rounding, and it is right about this column
 * too. `unit_price_minor` was derived as the line's total divided by its
 * quantity, and until `alpha.67` that division used `round()` on a float;
 * `alpha.67` replaced it with `intdiv()`, which is flooring and is the same
 * objection one step quieter. Measured on `alpha.67`'s own bytes with the
 * owner's example — a line whose total is ۱۰۱ تومان for three units —
 * `item_unit_price_minor=336666` where the exact third is ۳۳۶۶۶۶٫۶۶۶…, and
 * `unit_price_times_quantity=1009998` against a `base_minor` of `1010000`: a
 * per-unit figure that does not reconstruct the line it came from.
 *
 * **No exponent fixes it.** One third has no terminating decimal, so there is
 * no number of places at which `1010000 / 3` is an integer. `alpha.67` bought
 * four extra places because the rate's denominator is `10000`, which makes
 * every COMMISSION exact; a quantity is a different denominator and extra
 * scale answers nothing.
 *
 * **So the column learns to say «no whole per-unit price».** `NULL` is that
 * sentence. The column was `BIGINT NOT NULL DEFAULT 0`, so `0` was the only
 * value available for «unknown» — and `0` is a price, not an absence
 * (`alpha.49`'s rule, in the one place it had not reached). Widening
 * `NOT NULL` to `NULL` adds a state and removes none.
 *
 * **NOTHING HISTORICAL IS REWRITTEN, and there is NO BACKFILL.** Every row
 * written before this release keeps the integer it holds, truncated or not.
 * Recomputing them would mean deciding retrospectively what those lines meant,
 * which is the «بازتفسیر» the owner ruled out — and the authoritative figure
 * beside them, `base_minor`, was always exact.
 *
 * **What reads this column:** nothing, today. It exists so a list can show
 * «قیمت واحد» without a second query, and no screen in this plugin does yet.
 * That is stated here rather than discovered later: the value of this step is
 * that the column stops asserting a price nobody computed.
 *
 * **Rolling back to `alpha.67` needs no database work** — a nullable column
 * accepts everything the previous release wrote. A row this release left
 * `NULL` would be read by `alpha.67` as `0` through its `(int)` cast, which
 * is «۰ تومان per unit» rather than «unknown». That is a display-only
 * difference on a column no screen prints, and it is written down in
 * `docs/upgrade-and-rollback.md` §48 beside the one that is not.
 */
final class M0034UnitPriceMayBeUnknown implements MigrationInterface
{
    public const COLUMN = 'unit_price_minor';

    /** The shape this step needs: nullable, and no default. */
    public const DEFINITION = 'BIGINT NULL DEFAULT NULL';

    public function version(): int
    {
        return 34;
    }

    public function id(): string
    {
        return '0034_unit_price_may_be_unknown';
    }

    private function table(DatabaseInterface $db): string
    {
        return M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
    }

    public function up(DatabaseInterface $db): void
    {
        $table = $this->table($db);
        // THREE STATES (`alpha.38`): a failed read answers `null`, which
        // casts to nought, which reads as «not nullable yet» and sends an
        // `ALTER` whose failure is then reported as this step's.
        $row = $db->getRow(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, self::COLUMN]
        );
        if ($row === null) {
            // ⛔ A COLUMN THIS STEP CANNOT SEE IS A FAILURE, not «nothing to
            // do». `getRow()` answers `null` for «no such column» AND for «the
            // read failed», and both mean this step must not report success:
            // a renamed or missing column would otherwise leave the structure
            // marked as moved with nothing done (`alpha.38`).
            throw new MigrationException(
                'migration ' . $this->id() . ' could not read `' . $table . '`.`' . self::COLUMN
                . '`: ' . $db->lastError()
            );
        }
        if (self::isShaped($row)) {
            return;             // a resumed run; already done
        }
        if ($db->execute(
            'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . self::COLUMN . '` ' . self::DEFINITION
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed widening `' . $table . '`.`' . self::COLUMN
                . '`: ' . $db->lastError()
            );
        }
    }

    /**
     * The SHAPE this step needs — nullable, no default — not the column set.
     *
     * Matching the exact set of columns would FREEZE the table and make the
     * next step lock the chain on 34 (`alpha.51`). What matters here is that
     * absence stays expressible, and stays the answer an omitted insert gets.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $row = $db->getRow(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$this->table($db), self::COLUMN]
        );
        return $row !== null && self::isShaped($row);
    }

    /**
     * NULLABLE, AND WITH NO DEFAULT — both halves.
     *
     * A nullable column carrying `DEFAULT 0` would satisfy «it can hold
     * NULL» and then quietly put a PRICE in every row an insert omits it
     * from, which is the whole thing this step removes.
     *
     * «No default» has two spellings and this was MEASURED, not read. On
     * this environment's MariaDB 10.11.14 a `DEFAULT NULL` column reports
     * `COLUMN_DEFAULT` as the four-character STRING `NULL` —
     * `COLUMN_DEFAULT IS NULL` answers `0` — while MySQL 8 reports SQL NULL
     * there. Both mean «no default»; `''` and `'0'` do not and are refused.
     *
     * @param array<string,mixed> $row
     */
    private static function isShaped(array $row): bool
    {
        if (strtoupper((string) ($row['nullable'] ?? 'NO')) !== 'YES') {
            return false;
        }
        $default = $row['col_default'] ?? null;
        return $default === null || strtoupper(trim((string) $default)) === 'NULL';
    }
}
