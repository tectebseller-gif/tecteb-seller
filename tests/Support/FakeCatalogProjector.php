<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;

/**
 * A storefront without WooCommerce.
 *
 * It keeps the two properties the real one is trusted for and that the rules
 * in ADR-008 depend on: one post per marketplace row (so a second projection
 * cannot produce a second id), and a stock number that only ever changes
 * through `writeStock` or a simulated sale — never as a side effect of
 * projecting again.
 */
final class FakeCatalogProjector implements CatalogProjectorInterface
{
    public bool $available = true;

    /** @var list<int> tmc product ids whose withdrawal will fail */
    public array $refusesToWithdraw = [];

    /** @var array<int,int> tmc product id => storefront id */
    public array $links = [];

    /** @var array<int,string> storefront id => status */
    public array $statuses = [];

    /** @var array<int,int> storefront id => stock */
    public array $stock = [];

    /** @var array<int,int> storefront id => how many times it was written */
    public array $writes = [];

    /** @var array<int,int> tmc variation id => storefront variation id */
    public array $variationLinks = [];

    private int $nextId = 1000;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function project(Product $product, array $attributes = [], array $variations = []): array
    {
        if (!$this->available) {
            return ['ok' => false, 'code' => 'woocommerce_missing', 'wc_product_id' => 0, 'variation_links' => []];
        }
        $id = $this->links[$product->id] ?? 0;
        $isNew = $id <= 0;
        if ($isNew) {
            $id = $this->nextId++;
            $this->links[$product->id] = $id;
            $this->stock[$id] = $product->details->stock;
        }
        $this->statuses[$id] = $product->status->isLive() ? 'publish' : 'draft';
        $this->writes[$id] = ($this->writes[$id] ?? 0) + 1;
        $variationLinks = [];
        foreach ($variations as $variation) {
            $variationLinks[$variation->id] = $this->variationLinks[$variation->id]
                ??= $this->nextId++;
        }
        return ['ok' => true, 'code' => 'projected', 'wc_product_id' => $id, 'variation_links' => $variationLinks];
    }

    public function withdraw(Product $product, string $reason = ''): bool
    {
        $id = $this->links[$product->id] ?? 0;
        if ($id <= 0) {
            return false;
        }
        if (in_array($product->id, $this->refusesToWithdraw, true)) {
            // A storefront that reports the write and does not do it — the
            // shape of the real failure this guards against, where a filter,
            // a stale cache or a replica leaves the post published.
            return false;
        }
        $this->statuses[$id] = 'draft';
        return true;
    }

    public function increaseStock(Product $product, int $by): ?int
    {
        $id = $this->links[$product->id] ?? 0;
        if ($id <= 0 || $by <= 0) {
            return null;
        }
        return $this->stock[$id] = ($this->stock[$id] ?? 0) + $by;
    }

    public function readStock(Product $product): ?int
    {
        $id = $this->links[$product->id] ?? 0;
        return $id > 0 ? ($this->stock[$id] ?? null) : null;
    }

    public function writeStock(Product $product, int $stock): bool
    {
        $id = $this->links[$product->id] ?? 0;
        if ($id <= 0) {
            return false;
        }
        $this->stock[$id] = max(0, $stock);
        return true;
    }

    public function readVariationStock(Product $product): array
    {
        return [];
    }

    public function owns(int $wcProductId, int $productId): bool
    {
        return ($this->links[$productId] ?? 0) === $wcProductId && $wcProductId > 0;
    }

    /** Test hook: somebody bought one. */
    public function sell(int $productId, int $quantity = 1): void
    {
        $id = $this->links[$productId] ?? 0;
        if ($id > 0) {
            $this->stock[$id] = max(0, ($this->stock[$id] ?? 0) - $quantity);
        }
    }
}
