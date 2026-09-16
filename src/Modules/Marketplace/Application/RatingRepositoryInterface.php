<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Modules\Marketplace\Domain\RatingStatus;
use Tecteb\Marketplace\Modules\Marketplace\Domain\VendorRating;

/**
 * Storage for vendor ratings — the half of «نظرات و امتیاز» WooCommerce has no
 * home for.
 *
 * `add()` returns false rather than throwing when the same purchase is rated
 * twice, because that is the unique index answering and a second rating is an
 * ordinary thing for a double-clicked form to attempt, not an error.
 *
 * There is deliberately no `delete()`. Moderation decides what the public sees;
 * it does not unsay anything.
 */
interface RatingRepositoryInterface
{
    /** @return bool false when this purchase has already been rated */
    public function add(VendorRating $rating): bool;

    public function find(int $ratingId): ?VendorRating;

    public function findByOrderItem(int $orderItemId): ?VendorRating;

    /** @return list<VendorRating> */
    public function forVendor(int $vendorUserId, ?RatingStatus $status = null, int $limit = 50, int $offset = 0): array;

    /** @return list<VendorRating> every shop's, for the manager's queue */
    public function queue(?RatingStatus $status = null, int $limit = 100, int $offset = 0): array;

    /**
     * The shop's one answer. Scoped to the shop in the WHERE clause, so a
     * guessed id cannot be answered by the wrong vendor.
     */
    public function reply(int $ratingId, int $vendorUserId, string $reply, string $at): bool;

    public function moderate(int $ratingId, RatingStatus $status, int $actorId, string $reason, string $at): bool;

    /**
     * The shop's public standing: how many approved ratings, and their average
     * in hundredths of a star.
     *
     * Hundredths rather than a float because this number is shown, compared and
     * put in a report, and «۴٫۳۳» has to mean the same thing in all three.
     *
     * @return array{count:int, average_hundredths:int, distribution:array<int,int>}
     */
    public function summaryFor(int $vendorUserId): array;

    /** @return array<int,array{count:int, average_hundredths:int}> keyed by vendor user id */
    public function summaries(array $vendorUserIds): array;
}
