<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\MigrationInterface;
use Tecteb\Marketplace\Core\Exceptions\MigrationException;

/**
 * A table for «این مدیر کدام ارسال را دیده»، because a capped blob forgets.
 *
 * **Why the structure had to change.** `alpha.36` kept the marks in one user
 * meta value with a 500-entry cap, and the cap was documented as failing in the
 * safe direction. It does — and the owner found the case where safe is still
 * wrong: with more than five hundred products waiting, reading ALL of them
 * cannot take the badge to nought, because recording the five-hundred-and-first
 * drops the first. The red count then comes back for submissions nobody
 * resubmitted, and no amount of work by the manager clears it.
 *
 * Three ways out were weighed and two refused by the owner in advance:
 *
 *  - a bigger number — the same defect one catalogue later;
 *  - no cap at all — one meta value holding a row per product, read and written
 *    whole on every wp-admin request, which trades a wrong number for a slow
 *    site;
 *  - a row per (manager, product), which is this.
 *
 * **The primary key IS the lookup.** `(user_id, product_id)` is what the count
 * joins on and what an upsert collides on, so the count reads one index entry
 * per waiting product and never reads a mark for a product that is not waiting.
 * Nothing is loaded into PHP: the count is a `COUNT(*)`.
 *
 * **Two ids, not one string.** The submission token is a pair, and storing it
 * as two integers is what lets a late write be refused: a tab that recorded an
 * older view must not move a newer one backwards. `seen_at` is `DATETIME(6)` —
 * second precision ties two tabs in the same second, and the tie is exactly the
 * case the guard is for.
 *
 * **The `alpha.36` marks are carried over, and no further.** Whatever is in the
 * meta value becomes rows; whatever the cap already dropped is simply not there,
 * and reads as unseen. That is the rule the owner stated: a mark with no
 * evidence behind it is not assumed. The meta value is left in place, so a
 * rollback to `alpha.36` finds exactly what it wrote.
 *
 * **Rolling back to `alpha.36` or `alpha.35` needs no database work.** The table
 * is additive and nothing older reads it. `alpha.36` goes back to its meta value
 * — with the marks it had at upgrade time, not the ones recorded since — and
 * `alpha.35` and earlier never had a mark at all and show the size of the queue.
 */
final class M0021ReviewSeen implements MigrationInterface
{
    public const SEEN = 'tmc_review_seen';

    /** The `alpha.36` meta key this migration reads once and never writes. */
    public const LEGACY_META_KEY = 'tmc_review_seen';

    /** Users per pass, so a site with many managers resumes rather than stalls. */
    private const BATCH = 200;

    public function version(): int
    {
        return 21;
    }

    public function id(): string
    {
        return '0021_review_seen';
    }

    public static function table(DatabaseInterface $db, string $suffix): string
    {
        return $db->prefix() . $suffix;
    }

    public function up(DatabaseInterface $db): void
    {
        $table = self::table($db, self::SEEN);
        $this->run($db, "CREATE TABLE IF NOT EXISTS `{$table}` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `product_id` BIGINT UNSIGNED NOT NULL,
            `submission_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `revision_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `seen_at` DATETIME(6) NOT NULL,
            PRIMARY KEY (`user_id`, `product_id`),
            KEY `tmc_seen_product` (`product_id`)
        ) " . $db->charsetCollate());

        $this->carryOverLegacyMarks($db, $table);
    }

    /**
     * The `alpha.36` meta values, turned into rows.
     *
     * Batched by user id and idempotent: `INSERT IGNORE` leaves a row this
     * migration already wrote — or one the plugin has written SINCE, which on a
     * re-run is the newer truth and must win by being left alone.
     *
     * A value that is not an array, a token that is not `s<n>.r<n>`, a product
     * id that is not a positive integer: all skipped without comment. This is a
     * view state, and an unreadable mark is one item shown to somebody twice.
     */
    private function carryOverLegacyMarks(DatabaseInterface $db, string $table): void
    {
        $meta = $db->prefix() . 'usermeta';
        // Asked, not assumed. A read that fails here returns an empty page and
        // the carry-over would end silently — «پرچمی که هرگز روشن نشود». On a
        // real site the table is always there, so «it is not» and «the read
        // broke» are two different answers and only one of them is nothing to
        // do. The other throws, and the upgrade gate retries on the next
        // request: the step is idempotent by `INSERT IGNORE`.
        if ((int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$meta]
        ) === 0) {
            return;
        }
        $after = 0;
        while (true) {
            $rows = $db->getResults(
                "SELECT `user_id`, `meta_value` FROM `{$meta}`
                 WHERE `meta_key` = %s AND `user_id` > %d
                 ORDER BY `user_id` ASC LIMIT " . self::BATCH,
                [self::LEGACY_META_KEY, $after]
            );
            if ($rows === []) {
                // Distinguish «no more rows» from «the read failed», which
                // both arrive as an empty array (`getResults()` has one answer
                // for both). The error is checked rather than the count,
                // because zero rows is the ordinary end of the loop.
                $error = $db->lastError();
                if ($error !== '') {
                    throw new MigrationException(
                        'migration ' . $this->id() . ' failed reading legacy marks: ' . $error
                    );
                }
                return;
            }
            // The cursor is the key the page was selected BY, so a non-empty
            // page always contains a `user_id` greater than it and the loop
            // strictly advances — which is why there is no pass counter here.
            // A counter would have put a silent ceiling on how many managers a
            // site may have, and «گاردی که بی‌صدا بایستد» is the shape this
            // repository has had to unpick twice.
            $highest = $after;
            foreach ($rows as $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                $highest = max($highest, $userId);
                if ($userId <= 0) {
                    continue;
                }
                foreach (self::parseLegacy((string) ($row['meta_value'] ?? '')) as $productId => $mark) {
                    // Checked, not fired and forgotten. An `INSERT` that fails
                    // here carries nothing over while the step still reports
                    // success — the half-applied shape this repository has
                    // unpicked three times. Throwing keeps the site on schema
                    // 20, which is `alpha.36` and works, and the upgrade gate
                    // retries on the next admin request: `INSERT IGNORE` makes
                    // the retry a no-op for the rows that did land.
                    if ($db->execute(
                        "INSERT IGNORE INTO `{$table}`
                         (`user_id`, `product_id`, `submission_id`, `revision_id`, `seen_at`)
                         VALUES (%d, %d, %d, %d, %s)",
                        [$userId, $productId, $mark['submission'], $mark['revision'], $mark['at']]
                    ) === null) {
                        throw new MigrationException(
                            'migration ' . $this->id() . ' failed carrying over user ' . $userId
                                . ' product ' . $productId . ': ' . $db->lastError()
                        );
                    }
                }
            }
            if ($highest <= $after) {
                throw new MigrationException(
                    'migration ' . $this->id() . ' failed: legacy mark cursor did not advance past ' . $after
                );
            }
            $after = $highest;
        }
    }

    /**
     * One `alpha.36` meta value, as rows this table can hold.
     *
     * @return array<int,array{submission:int,revision:int,at:string}>
     */
    private static function parseLegacy(string $serialised): array
    {
        $value = @unserialize($serialised, ['allowed_classes' => false]);
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $productId => $mark) {
            $productId = (int) $productId;
            if ($productId <= 0 || !is_array($mark)) {
                continue;
            }
            $token = is_string($mark['token'] ?? null) ? $mark['token'] : '';
            if (preg_match('/^s(\d+)\.r(\d+)$/', $token, $m) !== 1) {
                continue;
            }
            // Validated, not trusted: `alpha.36` wrote `Y-m-d H:i:s`, and a
            // value that is not that shape would land in a `DATETIME(6)` as a
            // warning on a lenient server and an error on a strict one — which
            // would fail the whole migration over a decoration.
            $at = is_string($mark['at'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mark['at']) === 1
                    ? $mark['at']
                    : '1970-01-01 00:00:00';
            $out[$productId] = [
                'submission' => (int) $m[1],
                'revision' => (int) $m[2],
                // `alpha.36` wrote second precision; the column keeps six
                // places, and a carried-over mark is older than anything this
                // build records, which is the correct order.
                'at' => $at . '.000000',
            ];
        }
        return $out;
    }

    /**
     * The table, and the SHAPE of it.
     *
     * «آیا جدولی به این نام هست» is not the question. `CREATE TABLE IF NOT
     * EXISTS` is satisfied by any table of that name, so a site that already
     * had one — a half-finished attempt, something another tool left — would
     * pass an existence check and then fail every write against it. Measured:
     * with a one-column table of this name in the way, the existence check
     * reported the step complete and the schema moved to 21 over a table
     * nothing could be written to.
     *
     * So the five columns are named and the primary key is checked. Not the
     * types: a column that exists with a wider type still holds what this
     * writes, and a verify that insisted on `bigint(20) unsigned` would start
     * failing on the first MariaDB that reports it differently.
     */
    public function verify(DatabaseInterface $db): bool
    {
        $table = self::table($db, self::SEEN);
        $columns = $db->getVar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($columns !== 'product_id,revision_id,seen_at,submission_id,user_id') {
            return false;
        }
        return $db->getVar(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            [$table, 'PRIMARY']
        ) === 'user_id,product_id';
    }

    /** `execute()` answers with a row count, and a successful DDL affects none (`alpha.8`). */
    private function run(DatabaseInterface $db, string $sql): void
    {
        if ($db->execute($sql) === null) {
            throw new MigrationException('migration ' . $this->id() . ' failed: ' . $db->lastError());
        }
    }
}
