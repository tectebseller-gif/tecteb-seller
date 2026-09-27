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
 * off the other's menu. That is why it is keyed by user and not by product.
 *
 * **What is recorded is the TOKEN, not a flag.** The caller passes the
 * submission identity it actually rendered, and the next request compares that
 * against the identity the product has now. A flag would say «seen» about a
 * product, and a resubmission would then have to remember to clear it —
 * something to forget in every path that writes a submission. A token compares
 * itself: a new submission has a new one, so it is unseen without anybody
 * clearing anything.
 *
 * **An absent mark means unseen, and that is the documented behaviour for data
 * that predates this feature.** Nothing is backfilled and no migration writes
 * marks, so on the first wp-admin request after the upgrade every product
 * already waiting is unseen — the manager sees the true count of what is
 * waiting for them, and it goes down as they work through it.
 */
interface ReviewSeenStoreInterface
{
    /**
     * @return array<int,string> product id => the submission token this user saw
     */
    public function seenBy(int $userId): array;

    /**
     * Record that this user saw exactly these submissions.
     *
     * Merged into what is already there rather than replacing it: opening page
     * two must not un-see page one.
     *
     * @param array<int,string> $seen product id => the token that was rendered
     * @return bool false when nothing could be written; the caller does not
     *         retry, because an unrecorded view leaves the item unseen, which is
     *         the safe direction
     */
    public function markSeen(int $userId, array $seen): bool;

    /** Every mark this user has, gone. Used by the disposable-site tools only. */
    public function forget(int $userId): bool;
}
