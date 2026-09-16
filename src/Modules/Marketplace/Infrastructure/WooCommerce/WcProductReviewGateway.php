<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Marketplace\Application\ProductReviewGatewayInterface;
use Tecteb\Marketplace\Modules\Marketplace\Domain\ProductReview;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * Product reviews, read out of WooCommerce instead of stored again.
 *
 * A WooCommerce product review is a WordPress comment on the product post,
 * with a `rating` meta and a `verified` meta. The storefront renders those,
 * the star average on the product page is computed from those, and wp-admin's
 * comment screen moderates those. This class asks them questions and writes
 * exactly one kind of answer — the shop's reply — and owns none of it.
 *
 * **Everything is scoped to marketplace products.** Every method resolves the
 * product to a marketplace product first; a comment on the shop's own product
 * or a Dokan vendor's resolves to nothing and is returned by no method, so
 * there is no id a vendor or a manager can pass through here to reach somebody
 * else's reviews. That is the same `not_ours` rule the catalogue and the
 * purchase guards keep, and it is why this class never queries comments
 * without a `post__in`.
 *
 * **The shop's reply is a child comment, not a field.** Written that way so it
 * appears under the review on the storefront — which is where the shopper who
 * wrote the review will look — instead of only inside a panel they cannot see.
 * It carries `_tmc_vendor_reply` so this plugin can tell its own replies from
 * anything else that has answered a review.
 */
final class WcProductReviewGateway implements ProductReviewGatewayInterface
{
    /** Marks a comment as a marketplace shop's answer, and says whose. */
    public const REPLY_META = '_tmc_vendor_reply';

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    public function isAvailable(): bool
    {
        return function_exists('wc_get_product') && function_exists('get_comments');
    }

    public function canReview(int $userId, int $wcProductId): bool
    {
        if (!$this->isAvailable() || $userId <= 0 || $wcProductId <= 0) {
            return false;
        }
        // Not ours to rule on. A shopper may well be allowed to review the
        // shop's own product — by whatever rules the shop has — and this
        // plugin does not answer for those.
        if ($this->vendorOf($wcProductId) === 0) {
            return false;
        }
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }
        // WooCommerce's own purchase history, asked by e-mail and id the way
        // `wc_customer_bought_product()` expects. Not this plugin's captured
        // lines: a product bought before the marketplace started capturing was
        // still bought.
        return function_exists('wc_customer_bought_product')
            && \wc_customer_bought_product((string) $user->user_email, $userId, $wcProductId);
    }

    public function forVendor(int $vendorUserId, ?bool $approved = null, int $limit = 50, int $offset = 0): array
    {
        $productIds = $this->productIdsOf($vendorUserId);
        return $productIds === []
            ? []
            : $this->query($productIds, $approved, $limit, $offset, $vendorUserId);
    }

    public function queue(?bool $approved = null, int $limit = 100, int $offset = 0): array
    {
        $map = $this->ownershipMap();
        return $map === [] ? [] : $this->query(array_keys($map), $approved, $limit, $offset, null, $map);
    }

    public function find(int $reviewId): ?ProductReview
    {
        if (!$this->isAvailable() || $reviewId <= 0) {
            return null;
        }
        $comment = get_comment($reviewId);
        if (!$comment) {
            return null;
        }
        $vendorUserId = $this->vendorOf((int) $comment->comment_post_ID);
        // Not a marketplace product — so not ours to show, answer or moderate.
        return $vendorUserId === 0 ? null : $this->hydrate($comment, $vendorUserId);
    }

    public function reply(int $reviewId, int $vendorUserId, int $authorUserId, string $body): int
    {
        $review = $this->find($reviewId);
        if ($review === null || $review->vendorUserId !== $vendorUserId) {
            return 0;
        }
        $author = get_userdata($authorUserId);
        $replyId = (int) wp_insert_comment([
            'comment_post_ID' => $review->wcProductId,
            'comment_parent' => $reviewId,
            'comment_content' => $body,
            'comment_author' => $author ? (string) $author->display_name : '',
            'comment_author_email' => $author ? (string) $author->user_email : '',
            'user_id' => $authorUserId,
            // Approved on write, unlike the review itself. The shop answering
            // in its own panel IS the decision — a reply that sat in a queue
            // would let a review stand unanswered for as long as nobody looked,
            // which is the situation the reply exists to prevent.
            'comment_approved' => 1,
            // Deliberately NOT comment_type 'review': a reply has no rating of
            // its own, and typing it as a review would put it in the star
            // average as a zero.
            'comment_type' => 'comment',
        ]);
        if ($replyId > 0) {
            update_comment_meta($replyId, self::REPLY_META, (string) $vendorUserId);
        }
        return $replyId;
    }

    public function moderate(int $reviewId, bool $approved): bool
    {
        return $this->find($reviewId) !== null
            && (bool) wp_set_comment_status($reviewId, $approved ? 'approve' : 'hold');
    }

    public function summaryFor(int $vendorUserId): array
    {
        $productIds = $this->productIdsOf($vendorUserId);
        if ($productIds === []) {
            return ['count' => 0, 'average_hundredths' => 0];
        }
        $total = 0;
        $count = 0;
        foreach ($this->query($productIds, true, 500, 0, $vendorUserId) as $review) {
            if ($review->stars > 0) {
                $total += $review->stars;
                $count++;
            }
        }
        return [
            'count' => $count,
            // Hundredths, like the vendor rating's, so the two can sit beside
            // each other on a screen without one of them being rounded twice.
            'average_hundredths' => $count === 0 ? 0 : (int) round(($total / $count) * 100),
        ];
    }

    // --- the WordPress side --------------------------------------------------

    /**
     * @param list<int> $productIds
     * @param array<int,int>|null $ownership wc product id => vendor user id
     * @return list<ProductReview>
     */
    private function query(
        array $productIds,
        ?bool $approved,
        int $limit,
        int $offset,
        ?int $vendorUserId = null,
        ?array $ownership = null
    ): array {
        $args = [
            'post__in' => $productIds,
            'type' => 'review',
            'number' => max(1, min(500, $limit)),
            'offset' => max(0, $offset),
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'status' => $approved === null ? 'all' : ($approved ? 'approve' : 'hold'),
        ];
        $out = [];
        foreach (get_comments($args) as $comment) {
            $owner = $vendorUserId ?? (int) ($ownership[(int) $comment->comment_post_ID] ?? 0);
            if ($owner === 0) {
                continue;
            }
            $out[] = $this->hydrate($comment, $owner);
        }
        return $out;
    }

    private function hydrate(object $comment, int $vendorUserId): ProductReview
    {
        $id = (int) $comment->comment_ID;
        $reply = $this->replyTo($id, (int) $comment->comment_post_ID);
        return new ProductReview(
            $id,
            (int) $comment->comment_post_ID,
            $vendorUserId,
            (int) $comment->user_id,
            (string) $comment->comment_author,
            (int) get_comment_meta($id, 'rating', true),
            (string) $comment->comment_content,
            (string) $comment->comment_approved === '1',
            (string) get_comment_meta($id, 'verified', true) === '1',
            (string) $comment->comment_date,
            $reply === null ? null : (int) $reply->comment_ID,
            $reply === null ? '' : (string) $reply->comment_content
        );
    }

    /** The shop's own answer to one review, if it has given one. */
    private function replyTo(int $reviewId, int $postId): ?object
    {
        $replies = get_comments([
            'post_id' => $postId,
            'parent' => $reviewId,
            'meta_key' => self::REPLY_META,
            'number' => 1,
            'status' => 'all',
        ]);
        return $replies === [] ? null : (object) $replies[0];
    }

    /** @return list<int> the WooCommerce products this shop owns in the marketplace */
    private function productIdsOf(int $vendorUserId): array
    {
        $ids = [];
        foreach ($this->products->allForVendor($vendorUserId) as $product) {
            $wcId = (int) ($product->wcProductId ?? 0);
            if ($wcId > 0) {
                $ids[] = $wcId;
            }
        }
        return $ids;
    }

    /** @return array<int,int> wc product id => vendor user id, marketplace products only */
    private function ownershipMap(): array
    {
        $map = [];
        foreach ($this->products->projected() as $product) {
            $wcId = (int) ($product->wcProductId ?? 0);
            if ($wcId > 0) {
                $map[$wcId] = (int) $product->vendorUserId;
            }
        }
        return $map;
    }

    /** Which marketplace shop owns this WooCommerce product, or 0 for nobody's. */
    private function vendorOf(int $wcProductId): int
    {
        $product = $this->products->findByWcProduct($wcProductId);
        return $product === null ? 0 : (int) $product->vendorUserId;
    }
}
