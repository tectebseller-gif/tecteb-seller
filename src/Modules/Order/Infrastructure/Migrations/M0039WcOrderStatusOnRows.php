<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

/**
 * THE WOOCOMMERCE ORDER STATUS, ON THE ROW — so a detailed report is a query.
 *
 * **Why this round needs a migration, stated rather than implied.** The owner's
 * §7 asks for a per-line report filterable by order status and payment status,
 * «بدون بارگذاری همه سفارش‌ها» and with «ایندکس‌های مناسب». The order's status
 * is WooCommerce's fact and lives in WooCommerce's own table, so there were
 * exactly two ways to put it in a filterable list:
 *
 *   - load the order per row. Fifty rows is fifty `wc_get_order()` calls, each
 *     a full product-and-meta load — the shape `alpha.96` measured and removed
 *     from the migration page for being «غیرقابل‌تحمل», one module along;
 *   - or read WooCommerce's own tables directly, which means knowing whether
 *     this site is on HPOS or on `wp_posts` and writing both. A plugin that
 *     reaches into another's schema to build a report is a plugin that breaks
 *     on their next release.
 *
 * So the status the plugin was TOLD is recorded on the row it is about. One
 * nullable column, no backfill, and the filter becomes an indexed `WHERE`.
 *
 * **`NULL` IS «NOBODY HAS TOLD US», AND IT IS NOT A STATUS.** Every row
 * written before this release has it, and so does a row whose order has never
 * changed status since. The report prints «نامعلوم» for those and does not
 * infer: a column that defaulted to `pending` would assert a payment state
 * nobody observed, which is the `alpha.49` rule — a figure that cannot be
 * known says so rather than taking the commonest value.
 *
 * **NOTHING HISTORICAL IS REWRITTEN AND THERE IS NO BACKFILL.** A backfill
 * would have to read every order, which is the cost this column exists to
 * avoid; and it would record today's status against a line captured months
 * ago as though it had been observed then. `SyncOrderLifecycle` fills a row in
 * the moment WooCommerce announces a change, which on a real order is the
 * first transition out of `pending` — so the column populates itself as the
 * site is used, and says «نامعلوم» honestly until it does.
 *
 * **Two indexes, each for one thing the report does.** `(vendor_user_id,
 * wc_order_status)` for the payment filter, which is always scoped to a shop;
 * `(vendor_user_id, created_at)` for the date range AND for the default
 * newest-first ordering, which a vendor's list does on every page. Neither is
 * speculative: the report issues exactly those two shapes.
 *
 * **Rolling back to `alpha.98` needs no database work.** A nullable column
 * accepts everything the previous release wrote, and `alpha.98` never selects
 * it by name — it reads the row through `DbOrderItemRepository`, which names
 * its columns. The cost is the report, not the data, and it is written down in
 * `docs/upgrade-and-rollback.md`.
 */
final class M0039WcOrderStatusOnRows implements MigrationInterface
{
    public const COLUMN = 'wc_order_status';

    /** Nullable, no default: absence has to stay expressible. */
    public const DEFINITION = 'VARCHAR(32) NULL DEFAULT NULL';

    public const STATUS_INDEX = 'tmc_order_wc_status';
    public const CREATED_INDEX = 'tmc_order_created';

    public function version(): int
    {
        return 39;
    }

    public function id(): string
    {
        return '0039_wc_order_status_on_rows';
    }

    private function table(DatabaseInterface $db): string
    {
        return M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
    }

    public function up(DatabaseInterface $db): void
    {
        $table = $this->table($db);
        // THREE STATES, not two (`alpha.38`): a failed catalogue read answers
        // `null` for «no such column» as well, and treating that as «absent»
        // sends an `ALTER` whose failure is then reported as this step's.
        $state = $this->columnState($db, $table);
        if ($state === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not read the columns of `' . $table . '`: ' . $db->lastError()
            );
        }
        if ($state === false && $db->execute(
            'ALTER TABLE `' . $table . '` ADD COLUMN `' . self::COLUMN . '` ' . self::DEFINITION
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed adding `' . $table . '`.`' . self::COLUMN
                . '`: ' . $db->lastError()
            );
        }
        foreach ([
            self::STATUS_INDEX => '`vendor_user_id`, `' . self::COLUMN . '`',
            self::CREATED_INDEX => '`vendor_user_id`, `created_at`',
        ] as $name => $columns) {
            $present = $this->indexState($db, $table, $name);
            if ($present === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not read the indexes of `' . $table . '`: ' . $db->lastError()
                );
            }
            if ($present) {
                continue;       // a resumed run
            }
            if ($db->execute(
                'ALTER TABLE `' . $table . '` ADD KEY `' . $name . '` (' . $columns . ')'
            ) === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' failed adding index `' . $name . '` to `' . $table
                    . '`: ' . $db->lastError()
                );
            }
        }
    }

    /**
     * The SHAPE this step needs, not the column set.
     *
     * Matching the exact set of columns would FREEZE the table and make the
     * next step lock the chain on 39 — `alpha.51`'s trap, which cost a release
     * on a real site. What matters is that the column exists, can hold
     * «nobody has told us», and that both indexes the report relies on are
     * there.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = $this->table($db);
        if ($this->columnState($db, $table) !== true) {
            return false;
        }
        foreach ([self::STATUS_INDEX, self::CREATED_INDEX] as $name) {
            if ($this->indexState($db, $table, $name) !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * `true` shaped, `false` absent, `null` «I could not find out».
     *
     * Asked of `information_schema` for the column AND its nullability in one
     * read, because a column of the right name that is `NOT NULL` cannot hold
     * the one value this step is for.
     */
    private function columnState(DatabaseInterface $db, string $table): ?bool
    {
        $rows = $db->getResults(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($rows === []) {
            // An order-items table with no columns does not exist: this is a
            // read that failed, not a table that is empty.
            return null;
        }
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') !== self::COLUMN) {
                continue;
            }
            return strtoupper((string) ($row['nullable'] ?? 'NO')) === 'YES';
        }
        return false;
    }

    /** `true` present, `false` absent, `null` «I could not find out». */
    private function indexState(DatabaseInterface $db, string $table, string $index): ?bool
    {
        // Counted on the index NAME, and `SEQ_IN_INDEX = 1` so a two-column
        // key answers one rather than two — `alpha.41`'s measurement, where a
        // bare `COUNT(*)` over `STATISTICS` counted columns and read a single
        // two-column index as two indexes.
        $count = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND INDEX_NAME = %s AND SEQ_IN_INDEX = 1',
            [$table, $index]
        );
        return $count === null ? null : ((int) $count) > 0;
    }
}
