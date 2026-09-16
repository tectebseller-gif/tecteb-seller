<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * What one buyer thought of one shop, hung off the purchase that entitles them
 * to say it.
 *
 * `orderItemId` is not decoration and not a back-reference: it is the identity
 * of the rating. «نظر فقط برای خریدار واقعی است» (Master A.5) is enforced by
 * there being nowhere to put a rating that is not attached to a recorded line,
 * and the unique index on that column is what stops the same purchase being
 * rated twice.
 *
 * The shop's answer lives here too, as one field. «فروشنده فقط پاسخ می‌دهد»:
 * there is no way for a shop to change `stars`, `body` or `status` — those
 * belong to the buyer and the manager respectively, and no method on this
 * object or its service offers a shop a path to them.
 */
final class VendorRating
{
    public const MIN_STARS = 1;
    public const MAX_STARS = 5;

    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly int $buyerUserId,
        public readonly int $orderItemId,
        public readonly int $stars,
        public readonly string $body,
        public readonly RatingStatus $status,
        public readonly string $reply = '',
        public readonly ?string $repliedAt = null,
        public readonly ?int $moderatedBy = null,
        public readonly ?string $moderatedAt = null,
        public readonly string $moderationReason = '',
        public readonly string $createdAt = '',
        public readonly string $updatedAt = ''
    ) {
    }

    public function hasReply(): bool
    {
        return trim($this->reply) !== '';
    }

    public static function starsAreValid(int $stars): bool
    {
        return $stars >= self::MIN_STARS && $stars <= self::MAX_STARS;
    }
}
