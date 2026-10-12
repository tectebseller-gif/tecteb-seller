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

    /**
     * The vendor asked for a different name before anybody decided this one.
     *
     * Its own status rather than a deletion, for the reason every ledger in
     * this plugin is append-only: the manager may be looking at the queue
     * while the vendor retypes, and a request that vanished would leave them
     * deciding about nothing. A superseded request is out of every open query
     * — `openFor()`, `pending()`, `unfinished()`, `countPending()` and
     * `openProductIds()` all name the statuses they want — and its `open_key`
     * is freed, so the name can be asked for again.
     *
     * No migration: `status` is a `VARCHAR(20)` and this is a value in it.
     */
    public const SUPERSEDED = 'superseded';

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
