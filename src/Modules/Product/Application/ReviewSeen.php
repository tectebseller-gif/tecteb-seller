<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

/**
 * «چند مورد را ندیده‌ام؟» — for one manager.
 *
 * Until `alpha.35` the red number on the menu was `countAwaitingReview()`: the
 * size of the queue, the same for everybody, and unchanged by anything except a
 * decision. The owner asked for a notification instead — something that appears
 * when a vendor submits and goes away when the manager has LOOKED, per manager,
 * without approving or rejecting anything.
 *
 * **The count is one query, and it is exact.** `alpha.36` computed it here, by
 * subtracting the manager's matching marks from the size of the queue, which
 * meant reading their marks into PHP — and that read is what had to be capped,
 * and the cap is what made a six-hundred-product queue impossible to clear. From
 * `alpha.37` the store answers in SQL and this class asks it.
 *
 *  - the product left the queue → it is not in the count, so a mark left behind
 *    for it subtracts nothing. «مواردی که از صف بررسی خارج شده‌اند نباید در
 *    شمارنده باقی بمانند».
 *  - the vendor submitted again → the identity moved, the mark no longer
 *    matches, and the product is unseen again without any path clearing a flag.
 *  - nothing happened → the mark matches and the product is not counted.
 *
 * **Nothing here decides anything.** `markSeen()` writes a view state; it does
 * not touch the product, its status, its baseline, its proposal or the queue.
 * The queue is emptied by `ReviewProducts`, and by nothing else.
 */
final class ReviewSeen
{
    public function __construct(private readonly ReviewSeenStoreInterface $store)
    {
    }

    /** How many waiting products this manager has not seen the current submission of. */
    public function unseenCount(int $userId): int
    {
        return $userId > 0 ? max(0, $this->store->countUnseenFor($userId)) : 0;
    }

    /**
     * Record the submissions this manager just had on screen.
     *
     * `$seen` is what the page RENDERED, read in the same statement as the rows
     * (`forManagerWithSubmission()`) or as the product (`findWithSubmission()`),
     * and it is written through unchanged. This method must never look the
     * tokens up again: re-reading here is exactly how a submission that arrived
     * after the page was built would be marked as seen by somebody who never
     * saw it.
     *
     * @param array<int,string> $seen product id => the token that was rendered
     */
    public function markSeen(int $userId, array $seen): bool
    {
        if ($userId <= 0 || $seen === []) {
            return false;
        }
        return $this->store->markSeen($userId, $seen);
    }
}
