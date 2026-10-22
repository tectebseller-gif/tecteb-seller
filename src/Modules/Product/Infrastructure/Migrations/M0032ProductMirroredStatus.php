<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One column on `tmc_products`: the WooCommerce status an intake OBSERVED.
 *
 * **Why this round has a migration.** `project()` writes the storefront's
 * `post_status` unconditionally — `publish` for a published row and `draft`
 * for everything else — and `alpha.66` mapped WooCommerce's `pending` onto
 * the marketplace's «پیش‌نویس». Measured on `alpha.66`'s installed bytes, on
 * the exact path the owner walked: intake left the shop at `pending`,
 * accepting management left it at `pending`, an ordinary save left it at
 * `pending`, and the FIRST PROJECTION wrote `draft` — and took the product
 * out of the catalogue and out of search with it. Nobody asked for either.
 *
 * The cause is not the filter's label and it is not the mapping on its own:
 * it is that the marketplace writes a status it never decided. So this column
 * records what the intake SAW, and while it holds a value the projector
 * leaves the storefront's status and catalogue visibility alone — the shape
 * `alpha.66` already used for a price that must not be written
 * (`_tmc_price_not_projected`), and `alpha.44`'s rule one object down:
 * ownership is bound to what WE wrote, and we wrote nothing here.
 *
 * **`VARCHAR(20) NULL DEFAULT NULL`, and no backfill.** `NULL` means «this
 * row's storefront status was never observed», which is true of every row
 * that exists today — rows the marketplace itself created, whose status IS
 * its own decision — so the default is the honest answer and the behaviour
 * for them does not change by one byte. A backfill would have to guess which
 * of today's rows were intaken before this column existed and what the shop
 * held at the time, and the second half of that is not recoverable: the
 * status may have moved since. Guessing it would hold a status the shop does
 * not have, or release one it does.
 *
 * The alternatives were weighed:
 *
 *  - **post meta on the storefront product, written at intake.** The shape
 *    `PRICE_HELD_META` uses — but that one is written BY the projector, which
 *    is already writing. An intake must write nothing to somebody's live
 *    product (`alpha.55`: «خواندن یک عنوان شاهد نوشتنش نیست»), and this value
 *    has to exist BEFORE the first projection or the projection destroys the
 *    status before anything can hold it.
 *  - **deriving it from `link_ownership = observed`.** Wrong twice: the
 *    ownership moves to `marketplace` the moment a manager accepts, which is
 *    before the first projection and would release the hold exactly when it
 *    is needed; and an observed row says nothing about WHICH status the shop
 *    held.
 *  - **a new `ProductStatus` case for «pending».** It would travel into
 *    `countsByStatus()`, the review queue's identity, the vendor's own
 *    filters and six gates, and it would still not answer the general
 *    question, which is «this product's status is not ours to write». The
 *    marketplace lifecycle (§4.2) is a fixed six, and the storefront's status
 *    is a separate fact already shown on its own facet.
 *
 * **Rolling back to `alpha.66` needs no database work.** The column is
 * additive and nothing older names it. The cost is stated in
 * `docs/upgrade-and-rollback.md` §47 and it is the hold going away — the next
 * projection of an intaken `pending` product writes `draft` again — not data
 * loss.
 */
final class M0032ProductMirroredStatus implements MigrationInterface
{
    public const PRODUCTS = 'tmc_products';
    public const MIRRORED = 'mirrored_status';

    public function version(): int
    {
        return 32;
    }

    public function id(): string
    {
        return '0032_product_mirrored_status';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db, self::PRODUCTS);
        // THREE states, not two (`alpha.38`). A failed read answers `null`,
        // which casts to nought, which reads as «the column is not there» and
        // sends an `ALTER` that then fails on the resume.
        $has = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, self::MIRRORED]
        );
        if ($has === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . self::MIRRORED . '`: ' . $db->lastError()
            );
        }
        if ((int) $has === 1) {
            return;                     // a resumed run; already done
        }
        if ($db->execute(
            "ALTER TABLE `{$table}` ADD COLUMN `" . self::MIRRORED . '` VARCHAR(20) NULL DEFAULT NULL'
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed: ' . $db->lastError()
            );
        }
    }

    /**
     * The SHAPE this step needs, and nothing more — `alpha.51`'s trap.
     *
     * Checking the exact column set would FREEZE the table: the next step to
     * add a column would make this one answer `false`, and the chain would
     * lock on 31 on a real site. What this step needs is a nullable text
     * column of at least this length whose default is NULL, because the whole
     * meaning of the column is «absent is an answer» — a column added
     * `NOT NULL DEFAULT ''` would satisfy «a column of that name exists» and
     * then hold the hold open on every row the marketplace created itself.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $row = $db->getRow(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default,
                    DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_len
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [self::table($db, self::PRODUCTS), self::MIRRORED]
        );
        if ($row === null) {
            return false;
        }
        // «NO DEFAULT» HAS TWO SPELLINGS, AND THIS WAS MEASURED, NOT READ.
        //
        // On the MariaDB this runs on (10.11.14), `information_schema` reports
        // `COLUMN_DEFAULT` for a `DEFAULT NULL` column as the four-character
        // STRING `NULL`, not as SQL NULL — checked with
        // `COLUMN_DEFAULT IS NULL`, which answered 0, and `QUOTE()`, which
        // answered `'NULL'`. The same is true of migration 23's
        // `description`, which has been nullable since `alpha.41`. MySQL 8
        // reports SQL NULL for the same column, so both are accepted and
        // nothing else is: a column added `DEFAULT ''` reports `''`, which
        // would hold every marketplace-created row's status open for ever.
        $default = $row['col_default'];
        $noDefault = $default === null || strtoupper(trim((string) $default)) === 'NULL';
        return strtoupper((string) ($row['nullable'] ?? 'NO')) === 'YES'
            && $noDefault
            && in_array(strtolower((string) ($row['data_type'] ?? '')), ['varchar', 'char', 'text'], true)
            && (int) ($row['max_len'] ?? 0) >= 20;
    }
}
