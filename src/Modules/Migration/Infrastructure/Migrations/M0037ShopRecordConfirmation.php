<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One table for one decision: «the manager confirmed this shop's Dokan records
 * were fully brought over, while that was still checkable».
 *
 * ### Why this round has a migration, and why it is a table of its own
 *
 * The owner closed `docs/open-decisions-alpha79.md` §1 with option ب, worded
 * stronger than the document had it: «تأیید صریح مدیر، فقط پس از تطبیق کامل
 * سوابق، همراه با تاریخچه ممیزی و امکان ابطال. این تصمیم نباید خودکار ثبت شود
 * و نباید مالکیت، اجازه فروش یا داده‌های مالی را تغییر دهد.»
 *
 * ⛔ **That document's own closing note said this step needs no migration,
 * and that is wrong.** It read: «ردیف تصمیم‌های انتقال (`tmc_dokan_handover`)
 * … جای چنین ثبتی هست». Measured from source: that table
 * (`M0018HandoverRowsAndRecordVersion::up()`) has ten columns and every one of
 * them is financial — `decision` drawn from `open/accepted/stays_outside`,
 * `closing_at_decision`, `pending_requests_untouched`, `figures_token`. There
 * is no spare column, so recording anything new there is an `ALTER TABLE`,
 * which is a migration either way. The note was about that table being the
 * right HOME, not about there being no DDL.
 *
 * And it is not the right home either, for three reasons each read off the
 * code rather than argued:
 *
 *  - **The instruction says this decision must not change financial data**,
 *    and `tmc_dokan_handover` IS the financial decision's row — the one
 *    `ReconcileDokanFinance::decide()` takes under a write lock and
 *    `FinanceHandoverRepositoryInterface` describes as «who is responsible
 *    for each shop's imported balance».
 *  - **One row, two writers.** `DbFinanceHandoverRepository::record()` is one
 *    `INSERT … ON DUPLICATE KEY UPDATE` over every column it names. A column
 *    outside that list survives an UPDATE and takes its DEFAULT on an INSERT,
 *    so whether a confirmation survives would depend on which decision a
 *    manager happened to take first. That is the shape `alpha.41` paid for:
 *    one row, two owners, and the one that writes the whole row destroys the
 *    other's half.
 *  - **`PRIMARY KEY (vendor_user_id)` has no history.** The owner asked for
 *    «تاریخچه ممیزی و امکان ابطال» in one breath. A single rewritable row
 *    performs revocation BY OVERWRITING, leaving nothing of the earlier
 *    confirmation but rows in a shared audit table — and building a guarantee
 *    on a table another consumer owns is not a guarantee.
 *
 * ### The shape, and why it is migration 36's shape
 *
 * `live_vendor_user_id` is NULL on every revoked row and `UNIQUE` is declared
 * over it, so «at most one live confirmation per shop, unbounded history» is a
 * property of the INDEX rather than of a check between a read and a write
 * (`alpha.54`). Revoking keeps the row that says who confirmed and when, and a
 * second confirmation after a revocation loses at the index. That is what
 * makes «قابل لغو» and «ممیزی‌پذیر» one table instead of two
 * (`M0036DefaultCategoryAcceptance`, three rounds old, same idiom).
 *
 * `records_version` is what stops an old confirmation standing silently. It is
 * not a new mechanism: `tmc_dokan_shop_version` has existed since migration 18
 * and every write to a shop's imported rows bumps it inside that write's own
 * transaction. So a re-import, a rollback or an extension moves the counter,
 * the stored number no longer matches, and the gate reports the confirmation
 * STALE by name rather than reading as settled. Compare
 * `M0036DefaultCategoryAcceptance`, which stores the accepted TERM for the
 * same reason — a flag would mean «whatever is there next week», the mistake
 * `alpha.47` paid for with an enforcement grant.
 *
 * `reconciliation` holds what was reconciled, as the three verdicts and the
 * three held counts in one short string. It is read by a person, never
 * compared: the comparison is the version counter's job, and a fingerprint of
 * verdicts would collide the moment a rollback restored exactly what was
 * there before.
 *
 * ### Purely additive, and nothing is backfilled
 *
 * No column is dropped, narrowed or retyped; no existing row is read or
 * rewritten; and not one confirmation is created by this step. «این تصمیم
 * نباید خودکار ثبت شود» is a property of `up()` here — the only `INSERT` in
 * this path is in `ConfirmShopRecords::confirm()`, behind a capability, a
 * nonce, and a reconciliation that must be complete at that moment.
 *
 * ### Rolling back to `alpha.90` needs no database work
 *
 * This table is read by exactly one branch of `MigrationCompleteness::
 * recordsState()`, and that branch only fires where Dokan is absent. The older
 * package does not know the name, so it behaves exactly as it shipped: with
 * Dokan gone the record gate stays open for those shops. Open is the
 * recoverable direction (`alpha.67`), and nothing is lost — the confirmation is
 * a decision ABOUT the import, never a copy of it.
 * `docs/upgrade-and-rollback.md` §71.
 */
final class M0037ShopRecordConfirmation implements MigrationInterface
{
    public const TABLE = 'tmc_dokan_record_confirmation';

    public function version(): int
    {
        return 37;
    }

    public function id(): string
    {
        return '0037_shop_record_confirmation';
    }

    public static function table(DatabaseInterface $db): string
    {
        return $db->prefix() . self::TABLE;
    }

    /**
     * Whether the table is there, with THREE answers.
     *
     * Same shape and same reason as `M0036DefaultCategoryAcceptance::
     * carriedBy()`: `false` is only conservative when the table really is
     * absent, and on an upgraded database it would make a confirmation a
     * manager took stop counting while the screen said nobody had confirmed.
     * So a failed catalogue read asks again a different way, and `SHOW
     * COLUMNS` cannot be ambiguous — a table that exists always answers its
     * own columns, so an empty answer means the read failed.
     *
     * ⛔ **The COLUMNS, not the name.** A table of this name with the wrong
     * shape answers «yes» to «is there a table?», and the reader then writes a
     * statement naming `live_vendor_user_id` and `records_version` which
     * fails — so the gate would learn nothing from a question it could have
     * answered. Asking for the two columns the reader names gives `false`
     * instead, the confirmation is treated as absent, and the gate stays OPEN.
     * That direction is conservative because it can only leave MORE work
     * waiting, never less (`alpha.68`).
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
        $answer = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (%s, %s)',
            [$table, 'live_vendor_user_id', 'records_version']
        );
        if ($answer !== null) {
            return (int) $answer === 2;
        }
        $columns = $db->getResults('SHOW COLUMNS FROM `' . $table . '`');
        if ($columns === null || $columns === []) {
            return null;
        }
        $names = [];
        foreach ($columns as $row) {
            $names[strtolower(trim((string) ($row['Field'] ?? '')))] = true;
        }
        return isset($names['live_vendor_user_id'], $names['records_version']);
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db);
        if ($db->execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `vendor_user_id` BIGINT UNSIGNED NOT NULL,
                `live_vendor_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `records_version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `reconciliation` VARCHAR(190) NOT NULL DEFAULT '',
                `confirmed_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `confirmed_at` DATETIME(6) NOT NULL,
                `revoked_by` BIGINT UNSIGNED NULL DEFAULT NULL,
                `revoked_at` DATETIME(6) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tmc_drc_live` (`live_vendor_user_id`),
                KEY `tmc_drc_shop` (`vendor_user_id`)
            ) " . $db->charsetCollate()
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not create `' . self::TABLE . '`: ' . $db->lastError()
            );
        }
        // ⛔ AND THERE IS NO BACKFILL. Every shop this table could be seeded
        // from is a shop whose records nobody has looked at, and the whole
        // point of the owner's ruling is that a human confirms them one at a
        // time after reconciling. A seeding statement here would be exactly
        // the automatic confirmation the instruction forbids.
    }

    /**
     * The SHAPE this step needs, never the exact column set — `alpha.51`.
     *
     * Matching the column set froze the chain once already and cost a real
     * site its next upgrade. What this step needs is the five value columns
     * and a uniqueness guarantee on `live_vendor_user_id`, because that key is
     * what makes «at most one live confirmation per shop» a property of the
     * database rather than of a check. The guarantee is asked for by what it
     * DOES rather than by name (`alpha.54`).
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = self::table($db);
        $needed = ['vendor_user_id', 'live_vendor_user_id', 'records_version', 'confirmed_by', 'confirmed_at'];
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
            if ($columnsOfIndex === ['live_vendor_user_id']) {
                return true;
            }
        }
        return false;
    }
}
