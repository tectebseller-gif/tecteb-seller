<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One table: what «Min Max Control» held for a product, what this marketplace
 * held BEFORE the migration touched it, and what was decided.
 *
 * **Why this round has a migration.** The instruction asks for a migration
 * that is resumable, repeatable without duplication, and reversible — and
 * «بازگشت باید مقدارهای مقصدِ قبل از انتقال را بشناسد و تغییرات جدیدتر را
 * کورکورانه پاک نکند». Every clause of that is a thing that has to be WRITTEN
 * DOWN per product at the moment of the write:
 *
 *  - «repeatable without duplication» is a UNIQUE KEY the claim takes, so a
 *    second run is refused by the database rather than by a read followed by
 *    a write — the `alpha.39` rule. This step put it on `post_id`; step 28
 *    moved it onto a nullable `live_key` so that a ROLLED-BACK product can be
 *    migrated again without its history being deleted, and so that the claim
 *    can be taken BEFORE the destination write rather than after it;
 *  - «reversible, knowing the destination's earlier values» is three nullable
 *    columns holding what the three meta rows held, with NULL meaning THE ROW
 *    WAS NOT THERE. Absent and empty are different states and `alpha.28`
 *    cost a round for conflating them: restoring `''` where there had been no
 *    row at all would leave a product carrying an empty rule that reads as
 *    «minimum one» for ever, instead of as «never projected»;
 *  - «does not blindly erase newer changes» is the fingerprint of what the
 *    migration itself wrote, compared at rollback time against what is there
 *    now. `alpha.44`'s rule, at a third field: a record saying «I wrote this»
 *    says nothing about whether anybody has touched it since.
 *
 * Three alternatives were weighed and are written down so the next round does
 * not re-decide them:
 *
 *  - **post meta on each product.** It would survive this plugin being
 *    removed, which sounds like a virtue and is not: these rows exist to be
 *    read as a SET — «how many products has the migration moved, how many
 *    refused, where did the last batch stop» — and a set that can only be
 *    counted by scanning `postmeta` is a set no admin page can show.
 *  - **an option holding the whole run.** Read-modify-write on shared state,
 *    which is the `alpha.20` bug: two managers, and the second write silently
 *    removes the first.
 *  - **reusing `tmc_product_revisions`.** A revision is a vendor's proposal
 *    about one product and is superseded whole by the next one. A handover
 *    record is neither a proposal nor superseded, and it has to outlive every
 *    later edit to stay a valid rollback target.
 *
 * **The run cursor is NOT here.** `tmc_jobs` from migration 13 already holds
 * exactly that — a `cursor` column written after each batch, a `live_key` for
 * «one live job per kind», and a claim that is a single `UPDATE` — and
 * «قابلیت موجود را دوباره نساز» applies. This table holds per-product facts;
 * the job holds where the walk is.
 *
 * **Rolling back to `alpha.52` needs no database work.** The table is
 * additive and nothing older names it; the cost of going back is that the
 * handover ledger stops being readable from the admin screens, not data loss
 * — and the three meta rows the migration wrote STAY, because they are
 * ordinary purchase-quantity rows that `alpha.52` already enforces.
 */
final class M0027MinMaxHandover implements MigrationInterface
{
    public const TABLE = 'tmc_minmax_handover';

    /** @var list<string> tables this migration owns, for the uninstaller */
    public const TABLES = [self::TABLE];

    public function version(): int
    {
        return 27;
    }

    public function id(): string
    {
        return '0027_minmax_handover';
    }

    public static function table(DatabaseInterface $db): string
    {
        return $db->prefix() . self::TABLE;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db);
        if ($db->execute(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `post_id` BIGINT UNSIGNED NOT NULL,
                `post_type` VARCHAR(20) NOT NULL,
                `level` VARCHAR(20) NOT NULL,
                `source_min` VARCHAR(32) NOT NULL DEFAULT '',
                `source_max` VARCHAR(32) NOT NULL DEFAULT '',
                `source_step` VARCHAR(32) NOT NULL DEFAULT '',
                `source_default` VARCHAR(32) NOT NULL DEFAULT '',
                `dest_min_before` VARCHAR(32) NULL DEFAULT NULL,
                `dest_max_before` VARCHAR(32) NULL DEFAULT NULL,
                `dest_step_before` VARCHAR(32) NULL DEFAULT NULL,
                `dest_record_before` TEXT NULL,
                `written_min` VARCHAR(32) NULL DEFAULT NULL,
                `written_max` VARCHAR(32) NULL DEFAULT NULL,
                `written_step` VARCHAR(32) NULL DEFAULT NULL,
                `status` VARCHAR(24) NOT NULL,
                `problems` VARCHAR(191) NOT NULL DEFAULT '',
                `run_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `decided_at` DATETIME NOT NULL,
                `rolled_back_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_post` (`post_id`),
                KEY `by_status` (`status`),
                KEY `by_run` (`run_id`)
            ) " . $db->charsetCollate()
        ) === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' could not create `' . self::TABLE . '`: ' . $db->lastError()
            );
        }
    }

    /**
     * The SHAPE, not the existence — `alpha.37`'s rule, and `alpha.51`'s cost
     * is why the set of columns is NOT matched exactly.
     *
     * `M0021ReviewSeen::verify()` asserted the exact column set and so froze
     * the chain the moment a later step added a column, on the real site and
     * not in review. This asks for the columns THIS step needs, the unique key
     * that makes a second run a no-op, and the nullability that makes «the row
     * was not there» sayable — and says nothing about any column a later round
     * may add.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $needed = [
            'id', 'post_id', 'level', 'source_min', 'source_max', 'source_step',
            'dest_min_before', 'dest_record_before', 'status', 'run_id',
        ];
        $placeholders = implode(', ', array_fill(0, count($needed), '%s'));
        $columns = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (' . $placeholders . ')',
            array_merge([self::table($db)], $needed)
        );
        if ($columns === null || (int) $columns !== count($needed)) {
            return false;
        }
        // The GUARANTEE, not the index's name. This step needs «two runs on
        // one product cannot both be allowed», which it bought with
        // `UNIQUE (post_id)`; step 28 buys a strictly stronger one with
        // `UNIQUE (live_key)` and drops the old key. Asserting the old NAME
        // here would freeze the chain at 27 on the owner's real site the
        // moment 28 ran — which is `alpha.51`'s trap, and `M0021ReviewSeen`
        // paid for it once already. So: a unique key, not the PRIMARY, on
        // either column that can carry the claim.
        $unique = $db->getVar(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND NON_UNIQUE = 0 AND INDEX_NAME <> %s
               AND COLUMN_NAME IN (%s, %s)',
            [self::table($db), 'PRIMARY', 'post_id', 'live_key']
        );
        if ($unique === null || (int) $unique < 1) {
            return false;
        }
        // The three «before» columns must be NULLABLE, because a rollback
        // target of «there was no row» is the common case and a NOT NULL
        // column would record it as an empty string — which reads as a rule.
        $nullable = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND COLUMN_NAME IN (%s, %s, %s) AND IS_NULLABLE = %s',
            [self::table($db), 'dest_min_before', 'dest_max_before', 'dest_step_before', 'YES']
        );
        return $nullable !== null && (int) $nullable === 3;
    }
}
