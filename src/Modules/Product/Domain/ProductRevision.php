<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A proposed change to a live product, waiting for the manager.
 *
 * The live product is untouched while this exists — that is the requirement
 * («نسخه فعلی تا تأیید آنلاین بماند»). Approval copies the payload onto the
 * product; rejection throws the revision away and changes nothing.
 */
final class ProductRevision
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    /** Replaced by a newer proposal before anybody reviewed it. */
    public const SUPERSEDED = 'superseded';

    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly array $payload,
        public readonly string $status,
        public readonly string $note = '',
        public readonly string $createdAt = ''
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
