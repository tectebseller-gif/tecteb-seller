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

    /** @param list<string> $options @return int attribute id, 0 on failure */
    public function saveAttribute(int $productId, string $key, string $label, array $options, int $sort = 0): int;

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
