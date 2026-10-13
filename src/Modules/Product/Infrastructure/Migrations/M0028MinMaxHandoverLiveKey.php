<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One nullable column, and the index it carries: «one LIVE decision per
 * product» instead of «one decision per product, ever».
 *
 * **Why this round needs a structural change at all.** `alpha.53` keyed the
 * handover ledger `UNIQUE (post_id)`, which bought repeatability — a second
 * batch over the same page is refused by the database rather than by a read
 * followed by a write (`alpha.39`) — and paid for it with two things that were
 * measured on its own bytes:
 *
 *  - **a product that is rolled back can never be migrated again.** Its row
 *    stays, `find()` returns it, and `decide()` answers `already_decided`
 *    about a product whose destination is empty. Measured:
 *    `second_migrated=0 second_already_decided=1
 *    rows_after_second=__absent__/__absent__/__absent__`. The only way out
 *    with that key is to DELETE the history, and «سوابق را کورکورانه حذف
 *    نکن» forbids exactly that — a rolled-back product's record of what its
 *    destination held before is the one thing nobody can reconstruct.
 *  - **the key arbitrated the race too late.** The ledger row was written
 *    AFTER the destination, so two runs on one product both wrote the three
 *    meta rows and the loser's insert was refused afterwards — by which time
 *    the second write had already replaced the first one's values, and the
 *    surviving row's `dest_*_before` described a destination that no longer
 *    existed. «UNIQUE KEY پس از نوشتن مقصد به‌تنهایی کافی نیست» is precisely
 *    that, and the fix is to CLAIM FIRST, which needs a key the claim can
 *    take and release.
 *
 * So `live_key` holds the post id while the decision is live and `NULL` once
 * it is rolled back or released for a retry, and the unique key moves onto it.
 * MySQL does not consider two `NULL`s equal, so any number of historical rows
 * may sit beside the one live row — the `live_key` idiom this plugin already
 * uses for `tmc_jobs` (migration 13, «one live job per kind»), reused rather
 * than reinvented.
 *
 * **Additive in the only sense that matters: no row is touched and no fact is
 * dropped.** The backfill sets `live_key = post_id` for every row whose status
 * is not `rolled_back`, so every existing decision stays live and every
 * existing rolled-back row becomes re-migratable — which is the behaviour the
 * instruction asks for, applied to the rows already on the owner's site. The
 * old index is dropped because its guarantee is strictly weaker than the new
 * one, not because it was wrong.
 *
 * **Going back to `alpha.53` needs no database work and loses nothing.** The
 * column stays, unread; the old code's `UNIQUE (post_id)` is gone, so its
 * repeat-protection falls back to the read in `migrateBatch()` — which is what
 * `alpha.53` did in practice anyway, since its `find()` ran first. The cost is
 * named in `docs/upgrade-and-rollback.md` §37 rather than discovered.
 */
final class M0028MinMaxHandoverLiveKey implements MigrationInterface
{
    public function version(): int
    {
        return 28;
    }

    public function id(): string
    {
        return '0028_minmax_handover_live_key';
    }

    public function up(DatabaseInterface $db): void
    {
        $table = M0027MinMaxHandover::table($db);

        if (!$this->hasColumn($db, 'live_key')) {
            if ($db->execute(
                "ALTER TABLE `{$table}` ADD COLUMN `live_key` BIGINT UNSIGNED NULL DEFAULT NULL"
            ) === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not add `live_key`: ' . $db->lastError()
                );
            }
        }

        // The backfill IS the upgrade: every decision that was not rolled back
        // is live, and `UPDATE` touches only rows whose `live_key` is still
        // NULL, so running this step twice writes nothing the second time.
        if ($db->execute(
            "UPDATE `{$table}` SET `live_key` = `post_id`
              WHERE `live_key` IS NULL AND `status` <> %s",
            ['rolled_back']
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not backfill `live_key`: ' . $db->lastError()
            );
        }

        if (!$this->hasIndex($db, 'one_live_per_post')) {
            if ($db->execute(
                "ALTER TABLE `{$table}` ADD UNIQUE KEY `one_live_per_post` (`live_key`)"
            ) === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not add `one_live_per_post`: ' . $db->lastError()
                );
            }
        }

        // Dropped LAST, so a failure anywhere above leaves the old guarantee
        // standing rather than no guarantee at all. A step that cannot finish
        // must not pass through a state where two runs are both allowed.
        if ($this->hasIndex($db, 'one_per_post')) {
            if ($db->execute("ALTER TABLE `{$table}` DROP INDEX `one_per_post`") === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not drop `one_per_post`: ' . $db->lastError()
                );
            }
        }

        if (!$this->hasIndex($db, 'by_post')) {
            // The history read («every decision ever made about this product»)
            // lost its index when the unique key moved off `post_id`. Without
            // this, the product page's history list is a table scan.
            if ($db->execute("ALTER TABLE `{$table}` ADD KEY `by_post` (`post_id`)") === null) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' could not add `by_post`: ' . $db->lastError()
                );
            }
        }
    }

    /**
     * The shape THIS step needs: the column, its nullability, and the unique
     * key on it. `alpha.51`'s rule — and this step is itself the reason that
     * rule exists in `M0027`, which asserted `one_per_post` by name and would
     * have frozen the chain here.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $nullable = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME = %s AND IS_NULLABLE = %s',
            [M0027MinMaxHandover::table($db), 'live_key', 'YES']
        );
        if ($nullable === null || (int) $nullable !== 1) {
            return false;
        }
        // `COUNT(*)` on STATISTICS counts COLUMNS, not indexes (`alpha.41`),
        // so this one-column unique key is exactly one row.
        $unique = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND INDEX_NAME = %s AND NON_UNIQUE = 0 AND COLUMN_NAME = %s',
            [M0027MinMaxHandover::table($db), 'one_live_per_post', 'live_key']
        );
        if ($unique === null || (int) $unique !== 1) {
            return false;
        }
        // And the key this step replaces must be GONE, because leaving it
        // would keep a rolled-back product unmigratable while every page
        // claimed otherwise.
        $old = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [M0027MinMaxHandover::table($db), 'one_per_post']
        );
        return $old !== null && (int) $old === 0;
    }

    private function hasColumn(DatabaseInterface $db, string $column): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [M0027MinMaxHandover::table($db), $column]
        );
        return $found !== null && (int) $found > 0;
    }

    private function hasIndex(DatabaseInterface $db, string $index): bool
    {
        $found = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [M0027MinMaxHandover::table($db), $index]
        );
        return $found !== null && (int) $found > 0;
    }
}
