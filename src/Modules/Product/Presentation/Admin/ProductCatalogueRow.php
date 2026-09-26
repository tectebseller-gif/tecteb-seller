<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * One line of the manager's list — and deliberately not a product.
 *
 * The page reads everything a row shows before the view runs, so the view
 * holds no container and asks no repository. That was already the rule for the
 * review card; here it does something more: it puts a ceiling on what a long
 * list costs. A row carries a thumbnail URL, a store name and two statuses,
 * and that is all — no gallery, no specification table, no field-by-field
 * comparison against WooCommerce, no decision history. Those belong to one
 * product at a time, on its own page, because that is the only place somebody
 * reads them.
 */
final class ProductCatalogueRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $sku,
        public readonly string $storeName,
        public readonly ProductStatus $status,
        public readonly bool $projected,
        /** WooCommerce's own status, read on the spot; '' when the post is gone. */
        public readonly string $shopStatus,
        public readonly string $updatedAt,
        public readonly string $thumbnailUrl,
        /** An unanswered proposal on a published product: the row a manager is looking for. */
        public readonly bool $hasPendingRevision
    ) {
    }
}
