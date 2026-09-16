<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * A read model of a review that lives in WooCommerce, not here.
 *
 * This plugin does not store product reviews — the storefront, the star
 * average and the moderation queue all read WordPress comments, and a second
 * copy would be a second truth. What this object is for is carrying one of
 * those comments across the Application boundary: the layer that decides who
 * may reply is not allowed to call `get_comment()`, so the gateway hands it
 * this instead.
 *
 * `vendorUserId` is the shop whose product was reviewed, resolved by the
 * gateway from the marketplace's own product ownership. A review on a product
 * this marketplace does not own never becomes one of these — that is the
 * `not_ours` rule, and it is why a shop cannot reply on somebody else's
 * product by guessing a comment id.
 */
final class ProductReview
{
    public function __construct(
        public readonly int $id,
        public readonly int $wcProductId,
        public readonly int $vendorUserId,
        public readonly int $authorUserId,
        public readonly string $authorName,
        public readonly int $stars,
        public readonly string $body,
        public readonly bool $approved,
        public readonly bool $verifiedBuyer,
        public readonly string $createdAt = '',
        public readonly ?int $replyId = null,
        public readonly string $reply = ''
    ) {
    }

    public function hasReply(): bool
    {
        return $this->replyId !== null && trim($this->reply) !== '';
    }
}
