<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/** One product row, with its owner and its state. */
final class Product
{
    /** @param array<string,string> $specs @param list<int> $imageIds */
    public function __construct(
        public readonly int $id,
        public readonly int $vendorUserId,
        public readonly ProductDetails $details,
        public readonly ProductStatus $status,
        public readonly array $specs = [],
        public readonly array $imageIds = [],
        public readonly int $mainImageId = 0,
        public readonly string $reviewNote = '',
        public readonly int $specSchemaVersion = 0,
        public readonly ?string $publishedAt = null,
        public readonly string $updatedAt = '',
        public readonly ?int $wcProductId = null,
        public readonly ProductSeo $seo = new ProductSeo(),
        /**
         * Optimistic-lock counter, as a string because it travels through a
         * form field and an empty one means «this form predates the column».
         * It is NOT the «نسخهٔ پیشنهادی» of a published product — that is
         * ProductRevision, and calling both of them revision is how a reader
         * picks the wrong one.
         */
        public readonly string $rowVersion = '',
        /**
         * The fields as they stood at the last recorded agreement.
         *
         * Null for a product that has not been through an approval since the
         * baseline existed, and nothing invents one: an invented base would
         * license writing over text nobody has read. Null falls back to the
         * `alpha.28` rules (see `FieldMerge`), so an upgrade changes nothing
         * until the first approval records it.
         */
        public readonly ?ApprovedBaseline $baseline = null,
        /**
         * Whether this row OWNS its WooCommerce product or merely points at
         * it — the `link_ownership` column, carried on the object since
         * `alpha.55`.
         *
         * It is here so that a list of rows can say which is which without a
         * query per row: an intake of existing products creates `observed`
         * rows, they appear in the manager's list beside owned ones, and a
         * manager who cannot tell them apart would read «this product is in
         * the marketplace» off a row that decides nothing about it.
         *
         * A string rather than the enum, and last with a default, for the
         * reason the three parameters above are: these are addressed
         * positionally in places, so appending is the only safe direction,
         * and an unrecognised value from a future column reads as the
         * conservative `marketplace` through `LinkOwnership::tryFrom()` at
         * the one place that asks.
         */
        public readonly string $linkOwnership = LinkOwnership::MARKETPLACE_VALUE
    ) {
    }

    /** Whether this plugin may decide anything about the linked product. */
    public function ownsItsStorefrontProduct(): bool
    {
        return (LinkOwnership::tryFrom($this->linkOwnership) ?? LinkOwnership::Marketplace)->isOperational();
    }

    /** Whether this row has a WooCommerce product behind it (ADR-008). */
    public function isProjected(): bool
    {
        return $this->wcProductId !== null && $this->wcProductId > 0;
    }

    /** Ownership is a property of the row, never of the request. */
    public function belongsTo(int $vendorUserId): bool
    {
        return $this->vendorUserId === $vendorUserId;
    }
}
