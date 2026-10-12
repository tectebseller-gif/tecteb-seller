<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * One column: which brand request a manager had seen when they looked.
 *
 * **Why this round has a migration, and why it is this one.** `alpha.51` makes
 * typing an unapproved brand into the single field RECORD A REQUEST on the
 * ordinary save. That turns a brand request from something a vendor pressed a
 * separate button for into the everyday way a brand changes — and the red
 * badge could not see it.
 *
 * Measured rather than argued: the badge counts queue rows whose identity
 * differs from the manager's mark, and that identity was
 * `(last submitted decision, open revision)`. A published product that a
 * manager has already reviewed carries a mark with exactly that identity. A
 * brand request writes no decision row and no revision, so the identity does
 * not move, the mark still matches — and the product sits in the review queue,
 * correctly counted as WAITING by `ReviewQueueSql::waiting()`, while the
 * manager's notification stays dark. A product that needs a decision and no
 * screen says so is the one outcome this project treats as worse than a
 * refusal.
 *
 * **`DEFAULT 0` is a real backfill, not a placeholder.** Every existing mark
 * gets nought, which reads as «there was no open brand request when I looked».
 * For the products that have one now, nought differs from the request's id, so
 * they become unseen — which is the correct answer and the whole point. For
 * every other product nought equals nought and the mark stands, so the upgrade
 * does NOT reset the manager's reading of the queue. One column, additive, no
 * data moved.
 *
 * **Three alternatives were weighed:**
 *
 *  - **Reuse `revision_id` for both.** Two tables' auto-increments collide, so
 *    «revision 12» and «brand request 12» would be one identity, and deciding
 *    one would mark the other seen.
 *  - **Store the token as a string instead of its parts.** It would need no
 *    column next time either, and it would also move the comparison off the
 *    indexed integers that make `countUnseenFor()` one index lookup per row —
 *    and rewrite every stored mark to a new format, which is the opposite of
 *    the «no mass reset» property above.
 *  - **Make a brand request write a `submitted` decision row.** The decision
 *    trail is the record of what a MANAGER decided about a product's status; a
 *    vendor's brand request is neither, and a queue filter that reads status
 *    would start seeing published products as submitted.
 *
 * **Rolling back to `alpha.50` needs no database work.** The column is
 * additive and nothing older names it; `alpha.50` reads two columns and
 * ignores the third, so the only cost of going back is that a brand request
 * stops lighting the badge again — which is the behaviour it shipped with.
 */
final class M0026ReviewSeenBrand implements MigrationInterface
{
    public const SEEN = M0021ReviewSeen::SEEN;
    public const BRAND_PROPOSAL = 'brand_proposal_id';

    public function version(): int
    {
        return 26;
    }

    public function id(): string
    {
        return '0026_review_seen_brand';
    }

    public function up(DatabaseInterface $db): void
    {
        $table = M0021ReviewSeen::table($db, self::SEEN);
        // ASKED IN THREE STATES — `alpha.38`'s rule. A failed read answers
        // null, which casts to nought, which reads as «the column is not
        // there» and sends an `ALTER` that then fails on the resume.
        $has = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$table, self::BRAND_PROPOSAL]
        );
        if ($has === null) {
            throw new MigrationException(
                'migration ' . $this->id() . ' failed asking for `' . self::BRAND_PROPOSAL . '`: ' . $db->lastError()
            );
        }
        if ((int) $has === 1) {
            return;                     // a resumed run; already done
        }
        if ($db->execute(
            "ALTER TABLE `{$table}` ADD COLUMN `" . self::BRAND_PROPOSAL
                . '` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `revision_id`'
        ) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }

    /**
     * The SHAPE, not the existence — `alpha.37`'s rule.
     *
     * A column of this name added as NULL-able would make «I had seen no
     * request» indistinguishable from «I do not know», and the comparison in
     * `countUnseenFor()` is `<>`, which is neither true nor false against
     * NULL — so a nullable column would quietly stop counting those rows at
     * all. So the default is asked for by value and the nullability by name.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $column = $db->getRow(
            'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default, DATA_TYPE AS data_type
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [M0021ReviewSeen::table($db, self::SEEN), self::BRAND_PROPOSAL]
        );
        if ($column === null) {
            return false;
        }
        return strtoupper((string) ($column['nullable'] ?? 'YES')) === 'NO'
            && (string) ($column['col_default'] ?? '') === '0'
            && strtolower((string) ($column['data_type'] ?? '')) === 'bigint';
    }
}
