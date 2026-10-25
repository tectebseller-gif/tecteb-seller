<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as Products;

/**
 * One table, so a product can be in several categories at once.
 *
 * **Why this round has a migration, declared before it was written.** The
 * owner's instruction forbids the workaround that would have kept the schema
 * fixed — «برای ثابت نگه‌داشتن schema راه‌حل موقتی مثل شناسه‌های کامایی نساز»
 * — and requires the whole set to survive: «مجموعهٔ کامل دسته‌ها باید حفظ
 * شود، حتی وقتی والد و فرزند هم‌زمان متصل‌اند. هیچ رابطه‌ای خودکار حذف یا
 * اضافه نشود.»
 *
 * `tmc_products.category_key` is a `VARCHAR(64) NOT NULL DEFAULT ''`
 * (migration 5, line 61): one column, one value. Measured on `alpha.81`'s
 * installed bytes, a product carrying a PARENT and its CHILD stored `244`
 * and nothing anywhere recorded `245`; accepting that key then wrote
 * `set_category_ids([244])`, which REPLACES, and `245` was gone from
 * WooCommerce with the screen reporting success
 * (`docs/evidence/alpha82-audit/before-alpha81.txt`). Three alternatives were
 * weighed and all three rejected, with the reasons in
 * `docs/category-path-audit-alpha82-fa.md` §7-الف:
 *
 *  - **comma-joined ids in the same column.** Forbidden, and independently
 *    broken: `find('207,208,209')` answers `__null__` on the real directory
 *    (`alpha.81`), so such a row reads as «invalid» on every screen; and the
 *    form transport strips commas before anything sees them —
 *    `sanitize_key('207,208,209')` is `207208209`, measured. Sixty-four bytes
 *    would not hold ten six-digit ids either.
 *  - **reading the relationship from WooCommerce every time.** The
 *    relationship IS the reference, but then the manager's agreement is
 *    recorded nowhere, somebody else's change cannot be detected, and nothing
 *    survives WooCommerce going away.
 *  - **storing it in `approved_baseline`.** That column already carries the
 *    whole set (`"category":"244,245"`, measured) — but it answers «what was
 *    agreed», not «what is true now». Using it as the current state is the
 *    «two questions, one answer» mistake `alpha.66` closed.
 *
 * **Purely additive, and `category_key` keeps its meaning.** No column is
 * dropped, narrowed or retyped. The single key stays exactly what it is
 * today: the one key the spec-template lookup
 * (`SpecTemplateRepositoryInterface::findByCategory()`, whose own table has
 * `UNIQUE KEY tmc_template_category`) and the Dokan category mapping read.
 * Which category's template applies to a multi-category product is the
 * owner's decision and it is NOT taken here —
 * `docs/open-decisions-alpha82.md` §1.
 *
 * **The backfill invents nothing.** One row per product whose `category_key`
 * is non-empty, carrying that same id. That restates information already in
 * that row; it is not a guess. A row with an empty key gets NO row, because
 * «we do not know this product's categories» and «this product has none» are
 * different facts (`alpha.8`), and writing a token row to settle a counter is
 * the thing `alpha.60` forbade.
 *
 * ⛔ **And the backfill does NOT read `approved_baseline`,** even though it
 * holds the full set. That set is the state at the moment of agreement; the
 * relationship may have moved since, and recording a possibly-stale
 * relationship as today's truth is worse than recording nothing. Today's set
 * is read from WooCommerce itself, by an explicit, resumable admin action.
 *
 * **Rolling back to `alpha.81` needs no database work.** This table is a
 * mirror, not the reference — `product_cat` is — so leaving it in place loses
 * no relationship and nothing older names it. The cost is written down rather
 * than hidden: `docs/upgrade-and-rollback.md` §62.
 */
final class M0035ProductCategorySet implements MigrationInterface
{
    public const TABLE = 'tmc_product_categories';

    public function version(): int
    {
        return 35;
    }

    public function id(): string
    {
        return '0035_product_category_set';
    }

    public static function table(DatabaseInterface $db): string
    {
        return $db->prefix() . self::TABLE;
    }

    /**
     * Whether the link table is there, with THREE answers.
     *
     * `false` is only conservative when the table really is absent; on an
     * upgraded database it would make a reader fall back to the single key and
     * silently narrow a set that is recorded — `alpha.68`'s finding, one table
     * over. So a failed catalogue read asks again a different way, and
     * `SHOW TABLES` cannot be ambiguous: an empty answer means the read failed
     * or the table is not there, and either way the caller is told «unknown»
     * rather than «absent».
     *
     * @return bool|null true present, false absent, null the read failed
     */
    public static function carriedBy(DatabaseInterface $db): ?bool
    {
        $table = self::table($db);
        $has = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($has !== null) {
            return (int) $has === 1;
        }
        // Second read, a different path in the server. A table that exists
        // always answers its own columns, so an EMPTY answer here means the
        // read failed — not that the table is empty.
        $columns = $db->getResults('SHOW COLUMNS FROM `' . $table . '`');
        if ($columns === null) {
            return null;
        }
        return $columns === [] ? null : true;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db);
        if ($db->execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `product_id` BIGINT UNSIGNED NOT NULL,
                `term_id` BIGINT UNSIGNED NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`product_id`, `term_id`),
                KEY `tmc_pc_term` (`term_id`)
            ) " . $db->charsetCollate()
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not create `' . self::TABLE . '`: ' . $db->lastError()
            );
        }

        // THE BACKFILL, and it is idempotent by the primary key rather than by
        // a check: `INSERT IGNORE` lets a resumed run finish without asking
        // which rows it already wrote (`alpha.54`). `category_key` is a string
        // column, so the cast is explicit and only digits are taken — a legacy
        // slug from before the key became a term id is NOT a term id, and
        // `AliasingSpecTemplateRepository` is the thing that still understands
        // those. Writing one here would record a relationship nobody has.
        $products = $db->prefix() . Products::PRODUCTS;
        if ($db->execute(
            "INSERT IGNORE INTO `{$table}` (`product_id`, `term_id`, `sort_order`)
             SELECT `id`, CAST(`category_key` AS UNSIGNED), 0
               FROM `{$products}`
              WHERE `category_key` <> ''
                AND `category_key` REGEXP '^[0-9]+$'
                AND CAST(`category_key` AS UNSIGNED) > 0"
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not seed `' . self::TABLE . '`: ' . $db->lastError()
            );
        }
    }

    /**
     * The SHAPE this step needs, never the exact column set — `alpha.51`.
     *
     * Matching the column set froze the chain once already and cost a real
     * site its next upgrade. What this step needs is the two id columns and a
     * uniqueness guarantee on the PAIR, because that pair is what makes the
     * backfill and every later write idempotent. The guarantee is asked for by
     * what it DOES rather than by name (`alpha.54`): a key covering both
     * columns, in either order.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = self::table($db);
        $needed = ['product_id', 'term_id', 'sort_order'];
        $placeholders = implode(', ', array_fill(0, count($needed), '%s'));
        $columns = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (' . $placeholders . ')',
            array_merge([$table], $needed)
        );
        if ($columns === null || (int) $columns !== count($needed)) {
            return false;
        }
        // `COUNT(*)` on `STATISTICS` counts COLUMNS, not indexes (`alpha.41`):
        // a two-column key is two rows. So the pair is asked for as two rows
        // of one non-unique-free index name.
        $rows = $db->getResults(
            'SELECT INDEX_NAME AS idx, COLUMN_NAME AS col
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0',
            [$table]
        );
        if ($rows === null) {
            return false;
        }
        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[(string) ($row['idx'] ?? '')][] = (string) ($row['col'] ?? '');
        }
        foreach ($byIndex as $cols) {
            if (in_array('product_id', $cols, true) && in_array('term_id', $cols, true)) {
                return true;
            }
        }
        return false;
    }
}
