<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Modules\Marketplace\Domain\ProductReview;

/**
 * The way in to reviews that are WooCommerce's, not ours.
 *
 * It exists because of the architecture rule this codebase enforces with a
 * test: the Application layer may not call WordPress — not `get_comments()`,
 * not `wp_insert_comment()`, not even `__()`. A product review is a WordPress
 * comment, so every question about one is asked through here, and the only
 * implementation that knows what a comment is lives in Infrastructure.
 *
 * Two rules the implementation must keep, both of them «not ours» rules:
 *
 *  - Every method is scoped to marketplace products. A review on the shop's
 *    own product or a Dokan vendor's is not returned, not replied to, not
 *    moderated and not counted. Those belong to whoever owns the product.
 *  - `canReview()` answers «has this person actually bought this product»,
 *    which is Master A.5's «نظر فقط برای خریدار واقعی است». It is asked before
 *    a review is accepted, not after, and it is asked of WooCommerce's own
 *    purchase history rather than of anything this plugin stores.
 */
interface ProductReviewGatewayInterface
{
    /** Whether there is a WooCommerce here to read reviews out of at all. */
    public function isAvailable(): bool;

    /**
     * Whether this person has bought this product and may therefore review it.
     *
     * False for a product this marketplace does not own, too — not because
     * they may not review it, but because it is not this plugin's to rule on.
     */
    public function canReview(int $userId, int $wcProductId): bool;

    /** @return list<ProductReview> reviews on ONE shop's products */
    public function forVendor(int $vendorUserId, ?bool $approved = null, int $limit = 50, int $offset = 0): array;

    /** @return list<ProductReview> every marketplace shop's, for the manager */
    public function queue(?bool $approved = null, int $limit = 100, int $offset = 0): array;

    public function find(int $reviewId): ?ProductReview;

    /**
     * The shop's answer, written as a child comment so the storefront shows it
     * under the review it answers.
     *
     * @return int the reply's id, or 0
     */
    public function reply(int $reviewId, int $vendorUserId, int $authorUserId, string $body): int;

    public function moderate(int $reviewId, bool $approved): bool;

    /**
     * One shop's products' reviews, summarised.
     *
     * @return array{count:int, average_hundredths:int}
     */
    public function summaryFor(int $vendorUserId): array;
}
