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
        public readonly ProductSeo $seo = new ProductSeo()
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
