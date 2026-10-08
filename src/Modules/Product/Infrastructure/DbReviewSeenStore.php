<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0021ReviewSeen;

/**
 * One row per (manager, product), and a count that never leaves the database.
 *
 * **Why this replaced a user meta value.** `alpha.36` kept every mark a manager
 * had in one serialised array with a five-hundred-entry cap. The cap failed
 * towards «unseen», which was documented and which is the safe direction — and
 * the owner found the case where safe is still broken: with more than five
 * hundred products waiting, reading all of them cannot take the badge to nought,
 * because recording the five-hundred-and-first drops the first. The red number
 * then comes back for submissions nobody resubmitted.
 *
 * Three properties the row-per-mark shape has and the blob could not:
 *
 *  - **The count is a `COUNT(*)`.** Nothing is loaded into PHP, so the cost does
 *    not grow with what the manager has read. The join is on the primary key.
 *  - **Two tabs cannot destroy each other's marks.** A blob is a
 *    read-modify-write over everything; a row is a write to one key.
 *  - **A late write cannot pull a mark backwards.** The guard is not a clock —
 *    see `markSeen()`. A mark is written only while the identity it carries is
 *    still the product's current one, asked in the same statement that writes,
 *    so «which request finished last» stops being part of the answer.
 *
 * **The token is stored as two integers, not as the string.** `s<n>.r<n>` is
 * built for comparison by the caller; splitting it here is what lets the SQL
 * compare without string concatenation on the indexed side, and what makes the
 * two halves readable in a report.
 */
final class DbReviewSeenStore implements ReviewSeenStoreInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * «چند مورد را ندیده‌ام» — one statement, one index lookup per waiting row.
     *
     * The derived table is the review queue with its submission identity
     * attached, exactly as `DbProductRepository` builds it, and the `LEFT JOIN`
     * is on `(user_id, product_id)`, which is the primary key of the marks
     * table. A product that has LEFT the queue is in neither side, so a mark
     * left behind for it subtracts nothing and cannot suppress its next
     * submission.
     */
    public function countUnseenFor(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        [$columns, $columnParams] = ReviewQueueSql::submissionColumns($this->db);
        [$waiting, $waitingParams] = ReviewQueueSql::waiting($this->db);
        $count = $this->db->getVar(
            'SELECT COUNT(*) FROM ('
                . ' SELECT p.id AS id, ' . $columns
                . ' FROM `' . $this->products() . '` p WHERE ' . $waiting
                . ') q'
                . ' LEFT JOIN `' . $this->seen() . '` s'
                . '   ON s.user_id = %d AND s.product_id = q.id'
                . ' WHERE s.user_id IS NULL'
                . '    OR s.submission_id <> COALESCE(q.tmc_submission_id, 0)'
                . '    OR s.revision_id <> COALESCE(q.tmc_revision_id, 0)',
            array_merge($columnParams, $waitingParams, [$userId])
        );
        // A `COUNT(*)` always has a row, so `null` is a FAILED read — and
        // casting it would answer «nothing unseen», which is the one wrong
        // answer this whole round is about: «اگر ثبت مشاهده شکست بخورد، رابط
        // نباید عدد را کاهش‌یافته نشان دهد» cuts the same way for the count.
        // Thrown, so the caller decides; `MenuRegistrar` leaves the number it
        // already had, and the page says in words what it could not read.
        if ($count === null) {
            throw new \RuntimeException('tmc_review_seen count failed: ' . $this->db->lastError());
        }
        return (int) $count;
    }

    /**
     * One guarded upsert per product: «آنچه نشان دادم همان است که الان هست».
     *
     * **Why the guard is not a timestamp.** `alpha.37` wrote `seen_at` from the
     * clock AT WRITE TIME and let the three assignments compare against it, so
     * the order it enforced was the order in which requests FINISHED. A request
     * that had rendered the previous version and then took a long time wrote
     * last, carried the newer timestamp, and put the older token over the newer
     * one — and the red number came back for a version the manager had already
     * seen. Moving the clock read to the start of the request would not fix it:
     * the order in which two requests START is not the order in which they read
     * their snapshots.
     *
     * **Why the guard is not a comparison of the two halves either.** The pair
     * is not monotonic. `approveRevision()` and `rejectRevision()` decide a
     * proposal without appending to the decision trail, so `r` falls back to
     * nought and the pair for «proposal 12 is open» and «proposal 12 has been
     * decided» is the same pair — no ordering of numbers can tell those two
     * states apart.
     *
     * **So the guard is equality with the identity that is current NOW**,
     * evaluated inside the one statement that writes. The justification is the
     * definition of «seen» itself: `countUnseenFor()` asks `<>` against these
     * same two columns, so a mark holding any other identity is «دیده‌نشده»
     * already. Writing a stale identity cannot make anything seen; the only
     * thing it can do is destroy a newer mark. A submission that lands after
     * the snapshot moves the identity, so this write finds no row to insert
     * from and the new, undisplayed version stays unseen — which is the answer
     * asked for, not a side effect.
     *
     * Nothing else is touched: no status, no decision, no proposal, no
     * baseline, no product column. The statement reads the product row only to
     * ask the question.
     *
     * `seen_at` is still recorded, and still with `GREATEST`, but it now
     * decides nothing — it is the «when», kept monotone so a report cannot read
     * a view as having happened before an earlier one.
     */
    public function markSeen(int $userId, array $seen): bool
    {
        if ($userId <= 0 || $seen === []) {
            return false;
        }
        $at = $this->clock->now()->format('Y-m-d H:i:s.u');
        $written = false;
        foreach ($seen as $productId => $token) {
            $productId = (int) $productId;
            if ($productId <= 0 || !is_string($token)) {
                continue;
            }
            [$submission, $revision] = ReviewQueueSql::split($token);
            if ($submission === null) {
                continue;
            }
            [$identity, $identityParams] = ReviewQueueSql::identityIs(
                $this->db,
                $submission,
                $revision
            );
            $rows = $this->db->execute(
                'INSERT INTO `' . $this->seen() . '`'
                    . ' (`user_id`, `product_id`, `submission_id`, `revision_id`, `seen_at`)'
                    . ' SELECT %d, p.id, %d, %d, %s FROM `' . $this->products() . '` p'
                    . ' WHERE p.id = %d AND ' . $identity
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' `submission_id` = %d,'
                    . ' `revision_id` = %d,'
                    . ' `seen_at` = GREATEST(`seen_at`, %s)',
                array_merge(
                    [$userId, $submission, $revision, $at, $productId],
                    $identityParams,
                    [$submission, $revision, $at]
                )
            );
            // Three answers, and only one of them is a failure. `null` is the
            // database refusing the statement. Nought rows is either the guard
            // declining — the identity moved, so this view is not the current
            // one and must NOT be recorded — or the same view recorded twice,
            // which changes no column (`alpha.8`). Neither is an error, and
            // neither may be reported to the manager as one; what the screen
            // then shows is the count re-read from the database, never
            // arithmetic on this answer.
            $written = $written || $rows !== null;
        }
        return $written;
    }

    public function marksFor(int $userId, array $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
        if ($userId <= 0 || $ids === []) {
            return [];
        }
        $rows = $this->db->getResults(
            'SELECT `product_id`, `submission_id`, `revision_id` FROM `' . $this->seen() . '`'
                . ' WHERE `user_id` = %d AND `product_id` IN (' . implode(',', array_map('intval', $ids)) . ')',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['product_id']] = 's' . (int) $row['submission_id'] . '.r' . (int) $row['revision_id'];
        }
        return $out;
    }

    public function forget(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return $this->db->execute(
            'DELETE FROM `' . $this->seen() . '` WHERE `user_id` = %d',
            [$userId]
        ) !== null;
    }

    private function products(): string
    {
        return T::table($this->db, T::PRODUCTS);
    }


    private function seen(): string
    {
        return M0021ReviewSeen::table($this->db, M0021ReviewSeen::SEEN);
    }
}
