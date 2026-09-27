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
 * **The count is a subtraction, and it is exact.**
 *
 *     unseen = everything waiting − the waiting things whose submission this
 *              manager has already seen
 *
 * The first term is the existing `COUNT(*)`. The second is read for the products
 * this manager has marks for — bounded by how much they have looked at, not by
 * the size of the queue — and a mark only counts when the token still MATCHES:
 *
 *  - the product left the queue → it is in neither term, so it subtracts
 *    nothing. «مواردی که از صف بررسی خارج شده‌اند نباید در شمارنده باقی بمانند».
 *  - the vendor submitted again → the token moved, the mark no longer matches,
 *    and the product is unseen again without any path having to clear a flag.
 *  - nothing happened → the mark matches and the product is not counted.
 *
 * **Zero queries when there is nothing to ask.** A manager with no marks — every
 * manager, on the first request after the upgrade — costs exactly the one
 * `COUNT(*)` the badge already cost.
 *
 * **Nothing here decides anything.** `markSeen()` writes a view state; it does
 * not touch the product, its status, its baseline, its proposal or the queue.
 * The queue is emptied by `ReviewProducts`, and by nothing else.
 */
final class ReviewSeen
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ReviewSeenStoreInterface $store
    ) {
    }

    /** How many waiting products this manager has not seen the current submission of. */
    public function unseenCount(int $userId): int
    {
        $waiting = $this->products->countAwaitingReview();
        if ($waiting <= 0 || $userId <= 0) {
            return max(0, $waiting);
        }
        $marks = $this->store->seenBy($userId);
        if ($marks === []) {
            return $waiting;
        }
        $current = $this->products->submissionsOf(array_map('intval', array_keys($marks)));
        $seen = 0;
        foreach ($current as $productId => $token) {
            if (($marks[$productId] ?? null) === $token) {
                $seen++;
            }
        }
        // Clamped rather than trusted: the two terms are two reads, and a
        // decision landing between them can only make the subtraction too big.
        // A negative badge is a number nobody can act on.
        return max(0, $waiting - $seen);
    }

    /**
     * Record the submissions this manager just had on screen.
     *
     * `$seen` is what the page RENDERED, read in the same statement as the rows
     * (`forManagerWithSubmission()`), and it is written through unchanged. This
     * method must never look the tokens up again: re-reading here is exactly how
     * a submission that arrived after the page was built would be marked as
     * seen by somebody who never saw it.
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
