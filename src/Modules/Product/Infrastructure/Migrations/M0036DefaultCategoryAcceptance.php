<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One table for one decision: «the manager accepted that WooCommerce's own
 * default term is this product's category».
 *
 * **Why this round has a migration, declared before a line of it was
 * written.** The owner's ruling is option ج of
 * `docs/default-category-investigation-alpha88-fa.md` §5 — «پذیرش مستقل،
 * صریح، ممیزی‌پذیر و قابل لغو» — with two constraints that decide the shape
 * on their own:
 *
 *  - «`category_key` و مجموعه پیوندهای دسته صرفاً برای بستن دروازه دست‌کاری
 *    نشوند.» Both of those columns already mean something: the single key is
 *    what the medical spec template is looked up by
 *    (`SpecTemplateRepositoryInterface::findByCategory()`), and a row in
 *    `tmc_product_categories` is a `product_cat` relationship this
 *    marketplace claims — which the projector writes back
 *    (`WooCommerceProjector::categoryIds()`, the `$imported` branch). Writing
 *    either one to satisfy a counter is the shape `alpha.60` named worse than
 *    the defect, and for the link table it would ALSO add a term to
 *    WooCommerce the moment ownership moved.
 *  - «اگر ترم محصول یا تنظیم پیش‌فرض تغییر کرد، تأیید قدیمی نباید بی‌صدا
 *    معتبر بماند.» So the row has to record WHICH term was accepted, and the
 *    predicate has to compare that against what is true now. A boolean flag
 *    could not: it would be «whatever is in that relationship next week»,
 *    the exact mistake `alpha.47` paid for with an enforcement grant.
 *
 * **Purely additive, and nothing is backfilled.** No column is dropped,
 * narrowed or retyped; no existing row is read or rewritten; and not one
 * acceptance is created by this step. «هیچ تأییدی خودکار صادر نشود» is a
 * property of `up()` here — the only `INSERT` in this module's acceptance
 * path is in `AcceptDefaultCategory::accept()`, behind a capability, a nonce
 * and the term the page actually printed.
 *
 * **The revocable decision is a live key, not a deleted row** — the
 * `tmc_jobs` idiom migration 28 moved Min Max's hand-over onto
 * (`M0028MinMaxHandoverLiveKey`). `live_product_id` is NULL on every revoked
 * row and `UNIQUE (live_product_id)` therefore allows any number of historical
 * decisions beside at most ONE live acceptance per product. That is what makes
 * «قابل لغو» and «ممیزی‌پذیر» the same table rather than two: revoking keeps
 * the row that says who accepted and when, and a second acceptance after a
 * revocation loses at the index rather than at a check between a read and a
 * write (`alpha.54`).
 *
 * **Rolling back to `alpha.88` needs no database work.** This table is read
 * by exactly one clause, and that clause is left OFF whenever the table is
 * absent or unreadable — so the older package, which does not know the name,
 * behaves exactly as it shipped: every accepted product counts as waiting
 * again and the gate stays open. Open is the recoverable direction
 * (`alpha.67`); nothing is lost either way, because the acceptance is a
 * decision ABOUT WooCommerce data and never a copy of it.
 * `docs/upgrade-and-rollback.md` §69.
 */
final class M0036DefaultCategoryAcceptance implements MigrationInterface
{
    public const TABLE = 'tmc_product_default_category';

    public function version(): int
    {
        return 36;
    }

    public function id(): string
    {
        return '0036_default_category_acceptance';
    }

    public static function table(DatabaseInterface $db): string
    {
        return $db->prefix() . self::TABLE;
    }

    /**
     * Whether the table is there, with THREE answers.
     *
     * Same shape and same reason as `M0035ProductCategorySet::carriedBy()`:
     * `false` is only conservative when the table really is absent, and on an
     * upgraded database it would make every acceptance silently stop counting
     * — which is safe for the gate but would ALSO make the screen say «no
     * decision recorded» about a decision a manager took. So a failed
     * catalogue read asks again a different way, and `SHOW COLUMNS` cannot be
     * ambiguous: a table that exists always answers its own columns, so an
     * empty answer means the read failed.
     *
     * @return bool|null true present, false absent, null the read failed
     */
    public static function carriedBy(DatabaseInterface $db): ?bool
    {
        $table = self::table($db);
        // Interpolated into `SHOW COLUMNS` below, which takes no placeholder
        // for a name, so the name is validated rather than trusted
        // (`alpha.60`).
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return null;
        }
        // ⛔ **THE COLUMNS, NOT THE NAME.** A table of this name with the
        // wrong shape answers «yes» to «is there a table?», and the gate
        // predicate then writes a clause naming `live_product_id` and
        // `term_id` — a statement that fails, so the count degrades to `-1`
        // and the screen says «could not count» about a question it could
        // have answered. Asking for the two columns the clause names gives
        // `false` instead, the clause is omitted, and every imported row
        // keeps counting. That direction is conservative because omitting
        // the clause can only make MORE rows wait, never fewer — and it is
        // derived from the real state of the database rather than from the
        // shape of a return value (`alpha.68`).
        //
        // The mis-shaped table is not swallowed either: `verify()` refuses
        // it, the structure stays on 35 and the reason is recorded, which is
        // where an operator is told.
        $answer = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (%s, %s)',
            [$table, 'live_product_id', 'term_id']
        );
        if ($answer !== null) {
            return (int) $answer === 2;
        }
        // THE SECOND READ, and it is a different question of a different part
        // of the server (`alpha.68`). An EMPTY answer is «could not read»,
        // because a table that exists always answers with its columns — and
        // a table that does not exist makes this statement fail rather than
        // return nothing.
        $columns = $db->getResults('SHOW COLUMNS FROM `' . $table . '`');
        if ($columns === null || $columns === []) {
            return null;
        }
        $names = [];
        foreach ($columns as $row) {
            $names[strtolower(trim((string) ($row['Field'] ?? '')))] = true;
        }
        return isset($names['live_product_id'], $names['term_id']);
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db);
        if ($db->execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BIGINT UNSIGNED NOT NULL,
                `live_product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `term_id` BIGINT UNSIGNED NOT NULL,
                `decided_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `decided_at` DATETIME(6) NOT NULL,
                `revoked_by` BIGINT UNSIGNED NULL DEFAULT NULL,
                `revoked_at` DATETIME(6) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tmc_pdc_live` (`live_product_id`),
                KEY `tmc_pdc_product` (`product_id`)
            ) " . $db->charsetCollate()
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not create `' . self::TABLE . '`: ' . $db->lastError()
            );
        }
        // ⛔ AND THERE IS NO BACKFILL, on purpose and not by omission.
        // Every row this table could be seeded from is a product carrying
        // only `default_product_cat`, and the whole point of the owner's
        // ruling is that a human decides those one at a time. A seeding
        // statement here would be exactly the automatic acceptance the
        // instruction forbids, and it would close the gate on four products
        // nobody had looked at.
    }

    /**
     * The SHAPE this step needs, never the exact column set — `alpha.51`.
     *
     * Matching the column set froze the chain once already and cost a real
     * site its next upgrade. What this step needs is the four value columns
     * and a uniqueness guarantee on `live_product_id`, because that key is
     * what makes «at most one live acceptance per product» a property of the
     * database rather than of a check. The guarantee is asked for by what it
     * DOES rather than by name (`alpha.54`).
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = self::table($db);
        $needed = ['product_id', 'live_product_id', 'term_id', 'decided_by', 'decided_at'];
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
        // One single-column unique index over `live_product_id`, and NOT by
        // name: a site whose index carries another name still has the
        // guarantee, and a same-named index over the wrong column does not.
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
            $byIndex[(string) $row['idx']][] = (string) $row['col'];
        }
        foreach ($byIndex as $columnsOfIndex) {
            if ($columnsOfIndex === ['live_product_id']) {
                return true;
            }
        }
        return false;
    }
}
