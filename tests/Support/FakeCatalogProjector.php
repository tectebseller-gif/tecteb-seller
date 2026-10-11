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
    /** @var array<int,string> product id => the address the manager asked for */
    public array $slugs = [];

    public bool $available = true;

    /** @var array<int,string> wc product id => `min/max/step` as the shop holds it */
    public array $quantities = [];

    /** @var list<int> tmc product ids whose purchase-limit write will refuse */
    public array $quantityWriteFails = [];

    /** @var list<int> tmc product ids whose withdrawal will fail */
    public array $refusesToWithdraw = [];

    /**
     * @var list<int> tmc product ids whose PROJECTION will fail
     *
     * The other half of `refusesToWithdraw`, and the shape of the failure the
     * review path could not see until `alpha.30`: WooCommerce is there, the
     * write is attempted, and it does not happen.
     */
    public array $refusesToProject = [];

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
        if (in_array($product->id, $this->refusesToProject, true)) {
            return [
                'ok' => false,
                'code' => 'storage_failed',
                'wc_product_id' => $this->links[$product->id] ?? 0,
                'variation_links' => [],
            ];
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

    public function claimStorefrontPost(Product $product, int $wcProductId): bool
    {
        if ($wcProductId <= 0) {
            return false;
        }
        $this->links[$product->id] = $wcProductId;
        $this->statuses[$wcProductId] ??= 'publish';
        return true;
    }

    public function releaseStorefrontPost(Product $product, int $wcProductId): bool
    {
        unset($this->links[$product->id]);
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

    /**
     * The three quantity rows, as a shop that cannot be edited by hand.
     *
     * There is no manager here to hold a row, so `held` is always empty and
     * `failed` is driven by `$quantityWriteFails` — the one thing an
     * Application-level test needs to ask: does the caller report a refusal
     * instead of a success.
     */
    public function writeQuantities(Product $product): array
    {
        $blank = ['wrote' => [], 'held' => [], 'failed' => []];
        if (!$this->available) {
            return ['ok' => false, 'code' => 'woocommerce_missing', ...$blank];
        }
        $id = $this->links[$product->id] ?? 0;
        if ($id <= 0) {
            return ['ok' => false, 'code' => 'not_ours', ...$blank];
        }
        $rule = $product->details->purchaseQuantity();
        if (in_array($product->id, $this->quantityWriteFails, true)) {
            return [
                'ok' => false,
                'code' => 'limits_not_written',
                'wrote' => [],
                'held' => [],
                'failed' => ['_tmc_max_purchase'],
            ];
        }
        $this->quantities[$id] = $rule->min . '/' . ($rule->max === null ? '' : $rule->max) . '/' . $rule->step;
        return [
            'ok' => true,
            'code' => 'limits_written',
            'wrote' => ['_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step'],
            'held' => [],
            'failed' => [],
        ];
    }

    public function readVariationStock(Product $product): array
    {
        return [];
    }

    public function owns(int $wcProductId, int $productId): bool
    {
        return ($this->links[$productId] ?? 0) === $wcProductId && $wcProductId > 0;
    }

    /** Recorded, not simulated: the tests that care assert on the calls. */
    public function applySlug(Product $product, string $slug): bool
    {
        $this->slugs[$product->id] = $slug;
        return true;
    }

    public function storefrontStatus(int $wcProductId): string
    {
        return $this->statuses[$wcProductId] ?? '';
    }

    /** Test hook: somebody bought one. */
    public function sell(int $productId, int $quantity = 1): void
    {
        $id = $this->links[$productId] ?? 0;
        if ($id > 0) {
            $this->stock[$id] = max(0, ($this->stock[$id] ?? 0) - $quantity);
        }
    }

    /**
     * The shop's own stock moving upward — a refund, or a manager typing a
     * number into WooCommerce.
     *
     * The counterpart of `sell()`, and it exists for the same reason: from
     * `alpha.48` a vendor's stock edit on a live product is a proposal, so a
     * test that needs the SHOP to hold a different number has to say so on the
     * shop's side rather than through the vendor's form.
     */
    public function restock(int $productId, int $quantity): void
    {
        $id = $this->links[$productId] ?? 0;
        if ($id > 0) {
            $this->stock[$id] = max(0, $quantity);
        }
    }
}
