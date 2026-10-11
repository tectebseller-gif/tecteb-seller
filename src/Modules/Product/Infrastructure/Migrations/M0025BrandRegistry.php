<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One table and one column: the brand requests, and which approved brand a
 * product's brand IS.
 *
 * **Why this round has a migration.** §4 asks for a vendor to propose a brand
 * that is not in the list, for only the manager to approve and add it, and for
 * «ثبت برند و اعمال آن روی محصول» to be tellable apart and retryable. A
 * request has a name, a vendor, a product, a status, a decision and — once
 * registered — a term id; and «the same name must not be open twice» has to be
 * the database's answer, not a read followed by a write (`alpha.39`). There is
 * nowhere existing to put any of that.
 *
 * Three alternatives were weighed:
 *
 *  - **a field on `tmc_product_revisions`.** A revision is one product's
 *    proposed values and is superseded whole by the next one; a brand request
 *    outlives that and belongs to the CATALOGUE, not to one snapshot. It would
 *    also make «the name was registered, applying it did not finish» an
 *    unsayable state.
 *  - **an option holding the open requests.** Read-modify-write on shared
 *    state, which is the `alpha.20` bug: two managers, two shops, and the
 *    second write silently removes the first.
 *  - **the `product_brand` taxonomy itself, with unapproved terms marked by
 *    meta.** An unapproved brand would then be IN WooCommerce's own list,
 *    visible in its screens and assignable — which is precisely the thing the
 *    approval exists to prevent.
 *
 * **The column is `brand_term_id INT NULL`, with no backfill, and the NULL
 * means something.** It says «this row's brand predates the registry», which
 * is the honest answer for every product that has one today: the names were
 * free text and matching them to terms would be a guess. The owner asked for
 * exactly that restraint — «برندهای قدیمی یا ویرایش‌های مدیر را حذف، تغییر نام
 * یا بدون شاهد تأییدشده تلقی نکن». So a legacy brand keeps working, is shown as
 * it is, and is only ever asked to come from the list when it is CHANGED.
 *
 * `open_key` is what makes «one open request per name» a key rather than a
 * check. While a request is open it holds the normalised name; once decided it
 * holds `#<id>`, which is unique by construction — so a rejected name can be
 * proposed again, and MySQL's unique index still refuses two open ones. A
 * partial index would have said this more directly and MySQL has none.
 *
 * **Rolling back to `alpha.47` needs no database work.** Both additions are
 * additive and nothing older names either; `alpha.47` has no brand registry, so
 * the cost of going back is that brand edits stop being checked against the
 * list — stated in `docs/upgrade-and-rollback.md` §31 — not data loss.
 */
final class M0025BrandRegistry implements MigrationInterface
{
    public const PROPOSALS = 'tmc_brand_proposals';
    public const PRODUCTS = 'tmc_products';
    public const BRAND_TERM = 'brand_term_id';

    /** @var list<string> tables this migration owns, for the uninstaller */
    public const TABLES = [self::PROPOSALS];

    public function version(): int
    {
        return 25;
    }

    public function id(): string
    {
        return '0025_brand_registry';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $proposals = self::table($db, self::PROPOSALS);
        if ($db->execute(
            "CREATE TABLE IF NOT EXISTS `{$proposals}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `vendor_user_id` BIGINT UNSIGNED NOT NULL,
                `name` VARCHAR(120) NOT NULL,
                `normalised` VARCHAR(190) NOT NULL,
                `open_key` VARCHAR(190) NOT NULL DEFAULT '',
                `status` VARCHAR(20) NOT NULL,
                `term_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `decided_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `note` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                `decided_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `open_name` (`open_key`),
                KEY `product` (`product_id`),
                KEY `waiting` (`status`, `created_at`),
                KEY `vendor` (`vendor_user_id`)
            ) " . $db->charsetCollate()
        ) === null) {
            throw new MigrationException('migration ' . $this->id() . ' could not create `' . self::PROPOSALS . '`: ' . $db->lastError());
        }

        $products = self::table($db, self::PRODUCTS);
        // Asked in THREE states — `alpha.38`'s rule. A failed read answers
        // null, which casts to nought, which reads as «the column is not
        // there» and sends an `ALTER` that then fails on the resume.
        $has = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$products, self::BRAND_TERM]
        );
        if ($has === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . self::BRAND_TERM . '`: ' . $db->lastError()
            );
        }
        if ((int) $has === 1) {
            return;                     // a resumed run; already done
        }
        if ($db->execute(
            "ALTER TABLE `{$products}` ADD COLUMN `" . self::BRAND_TERM . '` BIGINT UNSIGNED NULL AFTER `brand`'
        ) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }

    /**
     * The SHAPE, not the existence — `alpha.37`'s rule, and `alpha.47`'s open
     * finding is the reason it is checked this closely.
     *
     * A same-named table with one column satisfies `CREATE TABLE IF NOT
     * EXISTS` and accepts no write; a `brand_term_id` added NOT NULL DEFAULT 0
     * satisfies «a column of that name exists» and then says every legacy row's
     * brand is term 0. So the unique key is asked for by name, and the column
     * is asked to be nullable with no default.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $columns = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (%s, %s, %s, %s, %s, %s)',
            [
                self::table($db, self::PROPOSALS),
                'id', 'product_id', 'name', 'normalised', 'open_key', 'status',
            ]
        );
        if ($columns === null || (int) $columns !== 6) {
            return false;
        }
        // `COUNT(*)` on STATISTICS counts COLUMNS, not indexes (`alpha.41`),
        // so a one-column unique key is one row — which is what this is.
        $unique = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND INDEX_NAME = %s AND NON_UNIQUE = 0',
            [self::table($db, self::PROPOSALS), 'open_name']
        );
        if ($unique === null || (int) $unique !== 1) {
            return false;
        }
        $brand = $db->getRow(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [self::table($db, self::PRODUCTS), self::BRAND_TERM]
        );
        if ($brand === null) {
            return false;
        }
        // TWO SHAPES FOR ONE ANSWER, and the measurement is why this is
        // written out. MariaDB 10.11 reports a nullable column's absent
        // default as the four-character STRING `NULL`, not as SQL NULL — so
        // `=== null` alone said the column was wrong on a database where it
        // was perfectly right, and the migration recorded itself failed with
        // nothing in `lastError()`. Both are accepted, and nothing else is: a
        // `DEFAULT 0` would arrive as `'0'` and must still be refused, because
        // that column would tell every legacy row its brand is term zero.
        $default = $brand['col_default'] ?? null;
        $defaultIsNull = $default === null || strtoupper((string) $default) === 'NULL';
        return strtoupper((string) ($brand['nullable'] ?? 'NO')) === 'YES' && $defaultIsNull;
    }
}
