<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;

/**
 * Writing a marketplace product into the storefront (ADR-008).
 *
 * The contract is narrow on purpose. There is no `delete`, because a product
 * a buyer has ordered must keep existing; there is no `pushStock` that a sync
 * could call in bulk, because stock only ever travels TO WooCommerce as the
 * vendor's explicit edit and comes back FROM it afterwards.
 */
interface CatalogProjectorInterface
{
    /** False when WooCommerce is not running; every other method then no-ops. */
    public function isAvailable(): bool;

    /**
     * Creates or updates the storefront product for this marketplace row.
     *
     * Must be idempotent: called twice it updates the same post, never a
     * second one. Must refuse a post it does not own.
     *
     * `variation_links` maps each marketplace variation to the storefront
     * variation it became, so the link can be stored on OUR side too. The
     * storefront's own meta is enough to avoid duplicates, but a column that
     * stays NULL forever is a column that lies about what is linked.
     *
     * @param list<ProductAttribute> $attributes
     * @param list<ProductVariation> $variations
     * @return array{ok:bool, code:string, wc_product_id:int, variation_links:array<int,int>}
     */
    public function project(Product $product, array $attributes = [], array $variations = []): array;

    /**
     * Takes the product out of the shop WITHOUT deleting it: the post stays,
     * its status stops being public, and every order that referenced it still
     * resolves.
     */
    public function withdraw(Product $product, string $reason = ''): bool;

    /** The stock WooCommerce holds right now, or null when there is no link. */
    public function readStock(Product $product): ?int;

    /** The vendor's explicit inventory edit, written through to WooCommerce. */
    public function writeStock(Product $product, int $stock): bool;

    /** Per variation, same rules. @return array<int,int> tmc variation id => stock */
    public function readVariationStock(Product $product): array;

    /**
     * Whether this storefront post is one the marketplace created for this
     * row. Anything else — a shop product, a Dokan product, a post somebody
     * made by hand — answers false and is never written to.
     */
    public function owns(int $wcProductId, int $productId): bool;
}
