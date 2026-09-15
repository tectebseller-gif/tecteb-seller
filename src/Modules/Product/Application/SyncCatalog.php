<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * Keeps the storefront and the marketplace record in step, in the ONE
 * direction each field is allowed to travel (ADR-008).
 *
 * The method names are the whole policy:
 *
 *   publish()      marketplace → storefront (title, price, status, media)
 *   withdraw()     marketplace → storefront (out of the shop, never deleted)
 *   pushStock()    marketplace → storefront, and ONLY as a vendor's edit
 *   pullStock()    storefront  → marketplace, which is how a sale gets home
 *
 * There is deliberately no `syncEverything()` that would push stock: a bulk
 * job that wrote remembered stock back over WooCommerce is precisely the
 * "old stock comes back" failure this design exists to prevent.
 */
final class SyncCatalog
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly VariationRepositoryInterface $variations,
        private readonly CatalogProjectorInterface $projector,
        private readonly AuditLogger $audit
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->projector->isAvailable();
    }

    /**
     * Writes this product into the storefront and stores the link.
     *
     * Called after every decision that changes what buyers should see: an
     * approval, a republish, an approved revision, an SEO edit.
     */
    public function publish(int $productId): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->projector->isAvailable()) {
            return OperationResult::failure('woocommerce_missing');
        }
        // A link pointing at a post that is gone (deleted by hand in
        // wp-admin) must not stop the product from being published again.
        if ($product->isProjected() && !$this->projector->owns((int) $product->wcProductId, $product->id)) {
            $this->products->link($product->id, null);
            $product = $this->products->find($productId);
        }
        if ($product === null) {
            return OperationResult::failure('not_found');
        }

        $attributes = [];
        $variations = [];
        if ($product->details->type === ProductType::VARIABLE) {
            $attributes = $this->variations->attributes($product->id);
            $variations = $this->variations->variations($product->id);
        }
        $result = $this->projector->project($product, $attributes, $variations);
        if (!$result['ok']) {
            return OperationResult::failure($result['code']);
        }
        $this->products->link($product->id, $result['wc_product_id']);
        // The link has to exist on BOTH sides. The storefront's own meta is
        // what keeps a re-projection from duplicating anything, but without
        // this the marketplace cannot answer "which storefront variation is
        // mine?" and the column it has for the answer stays NULL for ever.
        foreach ($result['variation_links'] ?? [] as $variationId => $wcVariationId) {
            $this->variations->linkVariation((int) $variationId, (int) $wcVariationId);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SYNCED, 0, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'wc_product_id' => $result['wc_product_id'],
            'action' => 'publish',
        ]);
        return OperationResult::success('projected', ['wc_product_id' => $result['wc_product_id']]);
    }

    /** Out of the shop without deleting anything. */
    public function withdraw(int $productId, string $reason = ''): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$product->isProjected() || !$this->projector->isAvailable()) {
            return OperationResult::success('nothing_to_withdraw');
        }
        if (!$this->projector->withdraw($product, $reason)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SYNCED, 0, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'wc_product_id' => (int) $product->wcProductId,
            'action' => 'withdraw',
        ]);
        return OperationResult::success('withdrawn');
    }

    /** Every product of one shop, when the shop itself is suspended. */
    public function withdrawVendor(int $vendorUserId, string $reason = ''): int
    {
        $withdrawn = 0;
        foreach ($this->products->allForVendor($vendorUserId) as $product) {
            if ($product->isProjected() && $this->withdraw($product->id, $reason)->ok) {
                $withdrawn++;
            }
        }
        return $withdrawn;
    }

    /**
     * Puts a reinstated shop's products back — the ones that still qualify.
     *
     * `$conditions` is what makes this different from "undo the withdrawal".
     * A shop that is allowed to trade again is not a promise that each of its
     * products may be sold: one may have lost its last image while the shop
     * was closed, and the marketplace as a whole may still be stopped. When a
     * StorefrontStop is passed, every product is asked separately and the ones
     * that fail stay out of the shop.
     */
    public function republishVendor(int $vendorUserId, ?StorefrontStop $conditions = null): int
    {
        $published = 0;
        foreach ($this->products->allForVendor($vendorUserId) as $product) {
            if ($product->status !== ProductStatus::Published) {
                continue;
            }
            if ($conditions !== null && !$conditions->mayGoLive($product->id)) {
                continue;
            }
            if ($this->publish($product->id)->ok) {
                $published++;
            }
        }
        return $published;
    }

    /** The vendor's explicit inventory edit, written through to the storefront. */
    public function pushStock(int $productId, int $stock): bool
    {
        $product = $this->products->find($productId);
        if ($product === null || !$product->isProjected() || !$this->projector->isAvailable()) {
            return false;
        }
        if (!$this->projector->writeStock($product, $stock)) {
            return false;
        }
        // Read back rather than assume: WooCommerce may clamp or refuse, and
        // the mirror must show what the storefront actually holds.
        $actual = $this->projector->readStock($product);
        if ($actual !== null && $actual !== $stock) {
            $this->products->mirrorStock($productId, $actual);
        }
        return true;
    }

    /**
     * Brings WooCommerce's stock home after a sale.
     *
     * This is the direction that matters: the marketplace's own column is a
     * mirror, and a mirror that lags is how a sold-out product looks
     * available on the vendor's dashboard.
     */
    public function pullStock(int $productId): ?int
    {
        $product = $this->products->find($productId);
        if ($product === null || !$product->isProjected() || !$this->projector->isAvailable()) {
            return null;
        }
        $stock = $this->projector->readStock($product);
        if ($stock !== null && $stock !== $product->details->stock) {
            $this->products->mirrorStock($productId, $stock);
        }
        foreach ($this->projector->readVariationStock($product) as $variationId => $variationStock) {
            $this->variations->updateVariationStock((int) $variationId, (int) $variationStock);
        }
        return $stock;
    }

    /**
     * Whether the storefront copy still matches what the marketplace decided.
     *
     * Reported rather than silently corrected: a price edited by hand in
     * wp-admin is a person's action, and the honest answer is to say the two
     * disagree and let the next marketplace-side change settle it.
     *
     * @return array{linked:bool, published:bool, stock:?int}
     */
    public function state(Product $product): array
    {
        return [
            'linked' => $product->isProjected() && $this->projector->owns((int) $product->wcProductId, $product->id),
            'published' => $product->status === ProductStatus::Published,
            'stock' => $this->projector->isAvailable() ? $this->projector->readStock($product) : null,
        ];
    }
}
