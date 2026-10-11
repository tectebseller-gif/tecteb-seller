<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * A vendor asking for a brand that is not in the list yet.
 *
 * Its own record rather than a field on the product revision, because the
 * owner asked for the two halves to be TELLABLE APART and RETRYABLE: «ثبت برند
 * و اعمال آن روی محصول باید قابل تفکیک و تلاش دوباره باشد». Registering the
 * brand adds a name to the catalogue for every shop; applying it changes one
 * product. A single flag could not say that the first had happened and the
 * second had not, and an approval that half worked would then look like a
 * failure of both.
 *
 * `termId` is the first half's answer: non-zero once the brand exists in the
 * list, whatever happened afterwards.
 */
final class BrandProposal
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** The name was registered, and writing it onto the product did not finish. */
    public const REGISTERED = 'registered';

    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $vendorUserId,
        public readonly string $name,
        public readonly string $normalised,
        public readonly string $status,
        public readonly int $termId,
        public readonly int $decidedBy,
        public readonly string $note,
        public readonly string $createdAt,
        public readonly string $decidedAt
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Whether the brand itself exists in the list, whatever is left to do. */
    public function isRegistered(): bool
    {
        return $this->termId > 0;
    }

    /** What is left to do, as one word, so no screen has to work it out twice. */
    public function outstanding(): string
    {
        return match (true) {
            $this->status === self::PENDING => 'decision',
            $this->status === self::REGISTERED => 'apply_to_product',
            default => 'nothing',
        };
    }
}
