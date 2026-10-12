<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;

interface ProductRevisionRepositoryInterface
{
    public function find(int $revisionId): ?ProductRevision;

    public function pendingFor(int $productId): ?ProductRevision;

    /** @return list<ProductRevision> */
    public function pending(int $limit = 200): array;

    public function countPending(): int;

    /**
     * Which of THESE products have an unanswered proposal.
     *
     * One query for the page's rows rather than `pendingFor()` twenty times,
     * and a subset rather than a flag per row: the caller marks the ids it
     * gets back and leaves every other row alone. An empty input means no
     * query at all — asking the database about nothing is how an `IN ()`
     * syntax error reaches a page that had nothing to show anyway.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public function pendingProductIds(array $productIds): array;

    /** @param array<string,mixed> $payload @return int revision id, or 0 on failure */
    public function create(int $productId, int $vendorUserId, array $payload): int;

    /**
     * Replace the pending proposal with a new one as ONE unit of work.
     *
     * **The finding this closes.** Until `alpha.49` every caller did it in two
     * statements: supersede the pending row, then insert the new one. A
     * refused insert left the product with the first half done — no pending
     * proposal at all — so the title or the brand the vendor was still waiting
     * on was gone, the product dropped out of the review queue, and the only
     * thing anybody was told was «ذخیره نشد». Measured on the `alpha.48` bytes:
     * `pending_after=0`, `earlier_status=superseded`,
     * `in_needs_review_after=no`.
     *
     * «جایگزینی پیشنهاد باید اتمیک باشد.» So it is one unit of work, and the
     * three ways it can fail each leave the pending proposal exactly where it
     * was:
     *
     *  - `not_atomic` — no transaction could be opened, so NOTHING is
     *    attempted. A build that cannot hold two statements together must not
     *    close a proposal on the hope that the second one lands (`alpha.39`).
     *  - `superseded_elsewhere` — the row we read as pending was decided by
     *    somebody else in between. Zero rows from the guarded `UPDATE` is a
     *    lost race here, not a success (`alpha.8`, and the half of it that
     *    `alpha.39` paid for), and queueing a second pending proposal behind
     *    theirs would break the one-pending-per-product invariant every screen
     *    rests on.
     *  - `storage_failed` — the insert was refused. The supersede goes back.
     *
     * `$supersedeId` of 0 means there is nothing to replace, and the answer is
     * then `created`: a single insert is already atomic and is not wrapped.
     *
     * @param array<string,mixed> $payload
     * @return array{id:int, code:string} `id` is 0 unless `code` is
     *         `replaced` or `created`
     */
    public function replacePending(int $productId, int $vendorUserId, array $payload, int $supersedeId): array;

    public function decide(int $revisionId, string $status, int $reviewerId, string $note): bool;
}
