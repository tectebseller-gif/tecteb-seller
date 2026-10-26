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

    /**
     * The payload key holding the values this proposal was MEASURED FROM.
     *
     * One map, `field => value`. It lives here rather than on either service
     * because the writer (`ManageProducts`) and the reader (`ReviewProducts`)
     * have to agree about it, and two lists mean two rules — the `alpha.28`
     * reason `StorefrontField::REQUIRED` sits in the domain too.
     *
     * Today it carries exactly one field: the brand, which is the only one
     * with a second decision path of its own
     * ({@see ProposedBrand}). A map rather than a scalar so a second such
     * field needs no new key — not because a second one is planned.
     *
     * A payload written by `alpha.51` or earlier has no such key, and that
     * absence is meaningful: «I do not know what this was measured from».
     */
    public const BASELINE = 'field_baseline';

    /**
     * This payload's price is a MIRROR of the live row, not a request.
     *
     * ⛔ Written by every proposal from `alpha.83` on, and read by the
     * approval — because without it that round's own decision would have
     * reverted a price. The sequence: the vendor changes the title (a
     * proposal is written, carrying the price as it then stood), then changes
     * the PRICE, which from `alpha.83` applies at once. Approving the title
     * would have written the payload's older price back over it — «همگام‌سازی
     * نباید قیمت ویرایش‌شده را با نسخهٔ قدیمی جایگزین کند» broken by the very
     * round that forbade it, one object along.
     *
     * A KEY and not a changed default, for the reason `stock_proposal` is one:
     * a proposal written by `alpha.82` or earlier carries a price change that
     * really was a request and really was never applied, so approving it must
     * still apply it. «A payload is not a request until it says it is» — and
     * this key is how a payload says it is NOT.
     */
    public const PRICE_MIRRORED = 'price_mirrored';

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
