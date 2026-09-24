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
        public readonly ?ApprovedBaseline $baseline = null
    ) {
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
