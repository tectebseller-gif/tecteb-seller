<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;

/**
 * Attributes and variations of one product.
 *
 * Everything here is addressed by product id, because a variation has no
 * meaning outside its product and a caller that could reach one directly
 * could reach another shop's.
 */
interface VariationRepositoryInterface
{
    /** @return list<ProductAttribute> */
    public function attributes(int $productId): array;

    /**
     * Whether the store can record what an attribute is FOR.
     *
     * `true` it can, `false` the columns are not there yet, `null` the
     * question could not be answered. Three answers because swapping this
     * plugin's files does not run a migration (`alpha.67`), so a release can
     * serve one request against a table without the columns — and because a
     * failed catalogue read is not an answer but a reason to refuse a role
     * change rather than write one that will not be honoured (`alpha.68`).
     */
    public function rolesSupported(): ?bool;

    /**
     * @param list<string> $options
     * @param bool $visible    listed among the product's properties
     * @param bool $variation  used to build combinations
     * @return int attribute id, 0 on failure
     */
    public function saveAttribute(
        int $productId,
        string $key,
        string $label,
        array $options,
        int $sort = 0,
        bool $visible = true,
        bool $variation = true
    ): int;

    public function deleteAttribute(int $productId, int $attributeId): bool;

    /** @return list<ProductVariation> */
    public function variations(int $productId): array;

    public function findVariation(int $productId, int $variationId): ?ProductVariation;

    /** Creates or updates by combination. @return int variation id, 0 on failure */
    public function saveVariation(int $productId, ProductVariation $variation): int;

    public function deleteVariation(int $productId, int $variationId): bool;

    /** Stock is written back from WooCommerce after a sale (ADR-008). */
    public function updateVariationStock(int $variationId, int $stock): bool;

    public function linkVariation(int $variationId, ?int $wcVariationId): bool;

    /** @return list<ProductVariation> every variation of every product in the list */
    public function forProducts(array $productIds): array;
}
