<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Domain;

/**
 * Where a vendor rating stands with the manager.
 *
 * Three values and no fourth. In particular there is no `deleted`: moderation
 * is a decision about whether the public sees something, not a way to make it
 * never have been said. A rejected rating keeps its text, its author, the
 * manager who rejected it and why — the shop can still read what was written
 * about it, which is the point of telling a shop anything at all.
 */
enum RatingStatus: string
{
    /** Written, not yet looked at. Invisible to shoppers; visible to the shop. */
    case Pending = 'pending';

    /** The manager let it through. This is the only one the storefront counts. */
    case Approved = 'approved';

    /** The manager refused it. Kept, with the reason, and never counted. */
    case Rejected = 'rejected';

    public function isPublic(): bool
    {
        return $this === self::Approved;
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    public static function fromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
