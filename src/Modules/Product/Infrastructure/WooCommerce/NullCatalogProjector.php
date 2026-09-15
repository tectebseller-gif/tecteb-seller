<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;

/**
 * What the marketplace does when WooCommerce is not running: nothing, and it
 * says so.
 *
 * The alternative — inventing a storefront of our own — is the thing this
 * plugin has refused since phase 1. A product stays «منتشرشده» in the
 * marketplace and has no public page, the health screen says why, and no
 * caller has to guard every call site with a `class_exists`.
 */
final class NullCatalogProjector implements CatalogProjectorInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function project(Product $product, array $attributes = [], array $variations = []): array
    {
        return ['ok' => false, 'code' => 'woocommerce_missing', 'wc_product_id' => 0, 'variation_links' => []];
    }

    public function withdraw(Product $product, string $reason = ''): bool
    {
        return false;
    }

    public function readStock(Product $product): ?int
    {
        return null;
    }

    public function writeStock(Product $product, int $stock): bool
    {
        return false;
    }

    public function readVariationStock(Product $product): array
    {
        return [];
    }

    public function owns(int $wcProductId, int $productId): bool
    {
        return false;
    }
}
