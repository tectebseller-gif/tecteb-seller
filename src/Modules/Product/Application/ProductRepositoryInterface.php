<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSeo;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;

/**
 * Product persistence.
 *
 * Every read that a vendor can reach takes the owner as an argument rather
 * than trusting the caller to filter afterwards: the query itself is scoped,
 * so a forgotten `if` cannot leak another shop's catalogue (AC-PRIV).
 */
interface ProductRepositoryInterface
{
    public function find(int $productId): ?Product;

    /** The same row, but only if it belongs to this vendor. */
    public function findOwned(int $productId, int $vendorUserId): ?Product;

    /**
     * @param string $search matched against title and SKU; empty means no filter
     * @return list<Product>
     */
    public function forVendor(
        int $vendorUserId,
        ?ProductStatus $status = null,
        int $limit = 200,
        int $offset = 0,
        string $search = ''
    ): array;

    public function countForVendor(int $vendorUserId, ?ProductStatus $status = null, string $search = ''): int;

    /** @return array<string,int> status value => count, for the vendor's filter chips */
    public function countsByStatus(int $vendorUserId): array;

    /** @return list<Product> the manager's review queue, newest request first */
    public function inStatus(ProductStatus $status, int $limit = 200, int $offset = 0): array;

    public function countInStatus(ProductStatus $status): int;

    /** @return int the new product id, or 0 when the insert failed */
    public function create(int $vendorUserId, ProductDetails $details, ProductStatus $status): int;

    public function updateDetails(int $productId, ProductDetails $details): bool;

    public function updateStatus(int $productId, ProductStatus $status, string $reviewNote = ''): bool;

    /** Stock and the other immediate fields, which never wait for a review. */
    public function updateInventory(int $productId, int $stock, string $sku, int $minPurchase, ?int $maxPurchase): bool;

    /** @param array<string,string> $values */
    public function saveSpecs(int $productId, array $values, int $schemaVersion): bool;

    /** @return array<string,string> */
    public function specs(int $productId): array;

    /** @param list<int> $mediaIds */
    public function saveImages(int $productId, array $mediaIds, int $mainImageId): bool;

    /** @return list<int> */
    public function images(int $productId): array;

    /** Whether this vendor already used this SKU on a different product. */
    public function skuTaken(int $vendorUserId, string $sku, int $exceptProductId = 0): bool;

    /**
     * The WooCommerce link, one product each way (ADR-008).
     *
     * Passing null unlinks — used when the projected post has gone (somebody
     * deleted it in wp-admin), so the next projection creates a fresh one
     * instead of writing into a hole.
     */
    public function link(int $productId, ?int $wcProductId): bool;

    public function findByWcProduct(int $wcProductId): ?Product;

    /**
     * Mirrors the stock WooCommerce now holds onto the marketplace row.
     *
     * This is the ONLY way stock travels from WooCommerce to here, and there
     * is deliberately no method for the other direction that a sync could
     * call: a sale must never be undone by a mirror (ADR-008).
     */
    public function mirrorStock(int $productId, int $stock): bool;

    public function updateSeo(int $productId, ProductSeo $seo): bool;

    /** @return list<Product> every product of this vendor, whatever its status */
    public function allForVendor(int $vendorUserId): array;

    /** @return list<Product> products that are live and projected */
    public function projected(int $limit = 500): array;
}
