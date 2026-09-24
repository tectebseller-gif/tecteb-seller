<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;

/**
 * The trail of what was decided about a product.
 *
 * Append-only by contract: there is no `update()` and no `delete()` here, and
 * that absence is the feature. A history a rejection can erase is not a
 * history — «رد، نیازمند اصلاح و ارسال مجدد نباید سابقه را حذف کنند».
 *
 * Every read is scoped. `forProduct()` takes the product; the caller that
 * serves a vendor passes the vendor id too, so one shop cannot read another
 * shop's correspondence by guessing an id.
 */
interface ProductDecisionRepositoryInterface
{
    public function record(
        int $productId,
        int $vendorUserId,
        int $actorId,
        string $decision,
        string $note
    ): bool;

    /**
     * @param int|null $vendorUserId when given, rows are returned only if the
     *        product belongs to that vendor — an ownership test, not a filter
     * @return list<ProductDecision> newest first
     */
    public function forProduct(int $productId, ?int $vendorUserId = null, int $limit = 50): array;

    /** The newest row the vendor is owed an explanation for, or null. */
    public function latestForVendor(int $productId, int $vendorUserId): ?ProductDecision;
}
