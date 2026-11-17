<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * WHAT AN ATTRIBUTE IS FOR — two booleans the table could not hold.
 *
 * **Why this round needs a migration, stated and not implied.** The owner's §3
 * asks a vendor to decide, per attribute, «تعیین کند ویژگی در صفحه محصول دیده
 * شود» and «تعیین کند ویژگی برای ساخت تنوع استفاده شود». Neither decision
 * existed anywhere: `tmc_product_attributes` has `attr_key`, `label`,
 * `options` and `sort_order`, and `WooCommerceProjector::wcAttributes()` wrote
 * `set_visible(true)` and `set_variation(true)` over every row.
 *
 * Two ways not to need a column were considered and both were worse:
 *
 *  - **encode it in the key** (`fact-material`). A prefix is not data, it is a
 *    convention a vendor can type by accident, and `alpha.42` already paid for
 *    reading meaning out of a key WooCommerce discards.
 *  - **keep it in a product option.** A row per product in `wp_options`
 *    holding a map of attribute key to flags — a second place the same fact
 *    lives, which is the shape this repository keeps paying for (`alpha.46`,
 *    `alpha.52`), and it would go stale the moment an attribute is renamed.
 *
 * ⛔ **`DEFAULT 1` IS THE BACKFILL, AND THAT IS MEASURED RATHER THAN HOPED.**
 * Every row on disk today was written by a build whose projector set both
 * flags true unconditionally. Measured on the installed `alpha.108` bytes
 * against a real WooCommerce 11.0.1:
 *
 *     wcattr_%d8%ac%d9%86%d8%b3_visible=yes
 *     wcattr_%d8%ac%d9%86%d8%b3_variation=yes
 *     wc_variation_attribute_count=2
 *
 * So `1`/`1` restates what the existing data already means. **No existing
 * product's axes stop being axes**, nothing is rewritten, and the new
 * behaviour is reached only by a vendor who unticks a box — which is what
 * «هیچ قابلیت سالمی حذف یا غیرفعال نشود» and «داده‌های واقعی محصولات تغییر
 * نکنند» require of a change at this depth. The `alpha.51` argument, one
 * table over: a column default is the honest backfill exactly when the
 * default is the old behaviour.
 *
 * ⛔ **`is_visible` and `is_variation`, not `visible` and `variation`.**
 * `VISIBLE` is a reserved word in MySQL 8.0 (invisible indexes), and a column
 * whose name needs quoting in every statement is a column that will one day
 * be written unquoted.
 *
 * **No index.** Both columns are read only as part of the per-product
 * attribute list, which is already served by the `tmc_attr_key` unique key on
 * `(product_id, attr_key)`; an index on a two-valued column over a handful of
 * rows per product would cost writes and save nothing.
 *
 * **Rolling back to `alpha.108` needs no database work.** The previous release
 * selects this table's columns by name and never these two, so the extra
 * columns are inert to it; and because the defaults equal its behaviour, a
 * product it then re-projects comes out exactly as it did before. The cost is
 * that a vendor's «این ویژگی محور تنوع نیست» stops being honoured — the
 * attribute becomes an axis again on the next projection — and that cost is
 * written down in `docs/upgrade-and-rollback.md` rather than discovered.
 */
final class M0040AttributeRoles implements MigrationInterface
{
    public const VISIBLE = 'is_visible';
    public const VARIATION = 'is_variation';

    /**
     * `NOT NULL DEFAULT 1` on purpose, and the one place the two differ from
     * `alpha.39`'s nullable column: there, absence had to stay expressible
     * because «nobody has told us» was a real third state. Here there is no
     * third state — an attribute either contributes an axis or it does not —
     * and the old behaviour is one of the two, so a default says it exactly.
     */
    public const DEFINITION = 'TINYINT(1) NOT NULL DEFAULT 1';

    public function version(): int
    {
        return 40;
    }

    public function id(): string
    {
        return '0040_attribute_roles';
    }

    private function table(DatabaseInterface $db): string
    {
        return M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ATTRIBUTES);
    }

    public function up(DatabaseInterface $db): void
    {
        $table = $this->table($db);
        foreach ([self::VISIBLE, self::VARIATION] as $column) {
            // THREE STATES, not two (`alpha.38`, `alpha.49`): a failed
            // catalogue read also answers «no such column», and treating that
            // as «absent» sends an `ALTER` whose failure is then reported as
            // this step's rather than as the read's.
            $state = $this->columnState($db, $table, $column);
            if ($state === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not read the columns of `' . $table
                    . '`: ' . $db->lastError()
                );
            }
            if ($state === true) {
                continue;       // a resumed run
            }
            if ($db->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . self::DEFINITION
            ) === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' failed adding `' . $table . '`.`' . $column
                    . '`: ' . $db->lastError()
                );
            }
        }
    }

    /**
     * The SHAPE this step needs — the two columns, each able to hold a one.
     *
     * ⛔ Not the exact column set. Matching that would FREEZE the table and
     * make the NEXT step lock the chain on 40, which is `alpha.51`'s trap and
     * cost a release on a real site.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = $this->table($db);
        foreach ([self::VISIBLE, self::VARIATION] as $column) {
            if ($this->columnState($db, $table, $column) !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the table CARRIES both roles — for the reader, not the step.
     *
     * ⛔ **Three answers from two reads, and the second only when the first
     * cannot answer** — `M0033MoneyUnitOnRows::carriedBy()`'s shape and its
     * reason. Swapping the plugin's files does not run a migration
     * (`UpgradeGate` does it on the next admin request, `alpha.67`), so this
     * release's repository can run for one request against a table that has
     * neither column. A reader told «absent» falls back to `true`/`true`,
     * which is what every existing row means; a reader told «absent» because
     * the CATALOGUE could not be read would do the same thing about a table
     * that holds a vendor's real decision, and that is the `alpha.68` defect
     * — the wrong figure presented as a fact.
     *
     * `SHOW COLUMNS` is the second read because it cannot be ambiguous: a
     * table always has columns, so an empty answer is a failed read and a
     * non-empty one is the list itself.
     *
     * ⛔ **Nothing is memoised here.** A `static` cache survives the whole
     * process, which is right for one request and wrong for a test that
     * changes the table and asks again (`alpha.37`). The caller memoises per
     * INSTANCE and never memoises a `null`.
     */
    public static function carriedBy(DatabaseInterface $db): ?bool
    {
        $table = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ATTRIBUTES);
        $rows = $db->getResults(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($rows === []) {
            $rows = $db->getResults('SHOW COLUMNS FROM `' . $table . '`');
            if ($rows === []) {
                return null;        // the read failed, twice and differently
            }
            $names = [];
            foreach ($rows as $row) {
                // `SHOW COLUMNS` names the column `Field`; the catalogue names
                // it `COLUMN_NAME`. Both are accepted so one helper can read
                // either answer.
                $names[] = (string) ($row['Field'] ?? $row['name'] ?? $row['COLUMN_NAME'] ?? '');
            }
            return in_array(self::VISIBLE, $names, true) && in_array(self::VARIATION, $names, true);
        }
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }
        return in_array(self::VISIBLE, $names, true) && in_array(self::VARIATION, $names, true);
    }

    /**
     * `true` shaped, `false` absent, `null` «I could not find out».
     *
     * The type is read as well as the name, because a column of the right
     * name that cannot hold a boolean — a `VARCHAR` a hand-run `ALTER` left
     * behind — would satisfy a name check and then store «1» as text that
     * every comparison in this plugin reads differently (`alpha.49`, where a
     * `tinyint` narrowed to hold 127 stored 500 in silence).
     */
    private function columnState(DatabaseInterface $db, string $table, string $column): ?bool
    {
        $rows = $db->getResults(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($rows === []) {
            // An attribute table with no columns does not exist: a read that
            // failed, not a table that is empty.
            return null;
        }
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') !== $column) {
                continue;
            }
            return in_array(strtolower((string) ($row['type'] ?? '')), ['tinyint', 'smallint', 'int', 'bigint'], true);
        }
        return false;
    }
}
