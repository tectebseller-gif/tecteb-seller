<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Marketplace\Application\ProductReviewGatewayInterface;

/**
 * No WooCommerce, so no products and no reviews of them.
 *
 * Every answer here is «there is nothing to read», never «there is nothing».
 * The distinction is the whole reason this class exists rather than a null
 * check scattered through the callers: `isAvailable()` returning false is what
 * a screen shows as «بخش نظرات محصول اجرا نمی‌شود», and the empty lists below
 * are never allowed to be presented as «this shop has no reviews».
 *
 * `canReview()` is false for the same reason it is false on a foreign product:
 * not «this person may not», but «this is not ours to answer».
 */
final class NullProductReviewGateway implements ProductReviewGatewayInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function canReview(int $userId, int $wcProductId): bool
    {
        return false;
    }

    public function forVendor(int $vendorUserId, ?bool $approved = null, int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function queue(?bool $approved = null, int $limit = 100, int $offset = 0): array
    {
        return [];
    }

    public function find(int $reviewId): ?\Tecteb\Marketplace\Modules\Marketplace\Domain\ProductReview
    {
        return null;
    }

    public function reply(int $reviewId, int $vendorUserId, int $authorUserId, string $body): int
    {
        return 0;
    }

    public function moderate(int $reviewId, bool $approved): bool
    {
        return false;
    }

    public function summaryFor(int $vendorUserId): array
    {
        return ['count' => 0, 'average_hundredths' => 0];
    }
}
