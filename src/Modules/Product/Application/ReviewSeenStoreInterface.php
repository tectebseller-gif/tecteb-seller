<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * Which submission each manager has already looked at.
 *
 * **This is a VIEW state and nothing more.** It records that a person had a
 * submission on their screen. It is not a decision, it is not a permission, and
 * nothing anywhere reads it to decide what may happen to a product: a product
 * stays in the review queue until somebody approves or rejects it, whether or
 * not it has been looked at. Reading is the one thing it means.
 *
 * **It belongs to one person.** Two managers reviewing the same queue each have
 * their own marks, so one of them opening the list must not take the red count
 * off the other's menu.
 *
 * **What is recorded is the SUBMISSION, not a flag.** The caller passes the
 * identity it actually rendered, and the next request compares that against the
 * identity the product has now. A flag would say «seen» about a product, and a
 * resubmission would then have to remember to clear it — something to forget in
 * every path that writes a submission. A pair of ids compares itself: a new
 * submission has a new one, so it is unseen without anybody clearing anything.
 *
 * **An absent mark means unseen, and that is the documented behaviour for data
 * that predates this feature.** Nothing is backfilled from product rows, so on
 * the first wp-admin request after the upgrade every product already waiting is
 * unseen — the manager sees the true count of what is waiting for them, and it
 * goes down as they work through it.
 *
 * **Nothing here is capped, and from `alpha.37` nothing is read into PHP to
 * count.** `alpha.36` kept all of a manager's marks in one value with a
 * five-hundred-entry cap, and with a larger queue reading every product could
 * not take the badge to nought: recording the five-hundred-and-first dropped the
 * first. `countUnseenFor()` is one query against an index, and `markSeen()`
 * writes one row per product — so neither the count nor the write grows a
 * structure that has to be read whole.
 */
interface ReviewSeenStoreInterface
{
    /**
     * How many products are waiting that this manager has not seen the current
     * submission of.
     *
     * Answered in SQL, not in PHP: the store knows which marks it has and the
     * caller must not have to fetch them to find out. A manager with no marks
     * costs the same query as one with ten thousand.
     */
    public function countUnseenFor(int $userId): int;

    /**
     * Record that this user saw exactly these submissions.
     *
     * One row per product, written independently, so two tabs recording at the
     * same time cannot destroy each other's marks — and a row that is already
     * there is only moved forward: a late write carrying an OLDER view must not
     * pull a newer one backwards.
     *
     * @param array<int,string> $seen product id => the token that was rendered
     * @return bool false when nothing could be written; the caller does not
     *         retry, because an unrecorded view leaves the item unseen, which is
     *         the safe direction
     */
    public function markSeen(int $userId, array $seen): bool;

    /**
     * What this user has recorded for these products — for tests and tools.
     *
     * Takes the ids rather than returning everything: a method that answered
     * «all of them» is the shape `alpha.36` had to cap.
     *
     * @param list<int> $productIds
     * @return array<int,string> product id => token
     */
    public function marksFor(int $userId, array $productIds): array;

    /** Every mark this user has, gone. Used by the disposable-site tools only. */
    public function forget(int $userId): bool;
}
