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

    /**
     * Writes this plugin's link meta onto a storefront product it did NOT
     * create — the one sanctioned write to a foreign post.
     *
     * Everything else in this interface refuses a post it does not own, and
     * `owns()` is that refusal: it asks whether `_tmc_product_id` on the post
     * names this row. A migrated product never has that meta, which is why a
     * mapped Dokan product cannot be withdrawn, projected or guarded — and
     * why «انتقال مالکیت عملیاتی» has to be able to write it.
     *
     * Only TransferOwnership calls this, only with a manager's capability, and
     * only after the person has been told what it means. `releaseStorefrontPost()`
     * removes the same meta and hands the post back.
     */
    public function claimStorefrontPost(Product $product, int $wcProductId): bool;

    /** Undoes claimStorefrontPost(): the post stops being ours. */
    public function releaseStorefrontPost(Product $product, int $wcProductId): bool;

    /** The stock WooCommerce holds right now, or null when there is no link. */
    public function readStock(Product $product): ?int;

    /** The vendor's explicit inventory edit, written through to WooCommerce. */
    public function writeStock(Product $product, int $stock): bool;

    /**
     * Puts returned goods back on the shelf, atomically.
     *
     * A read-then-write would lose a concurrent sale between the two steps;
     * this is an increase BY an amount, not an assignment TO one, so two
     * returns landing at the same moment both count.
     *
     * @return int|null the stock afterwards, or null when there is no link
     *                  or WooCommerce does not manage stock for this product
     */
    public function increaseStock(Product $product, int $by): ?int;

    /** Per variation, same rules. @return array<int,int> tmc variation id => stock */
    public function readVariationStock(Product $product): array;

    /**
     * Whether this storefront post is one the marketplace created for this
     * row. Anything else — a shop product, a Dokan product, a post somebody
     * made by hand — answers false and is never written to.
     */
    public function owns(int $wcProductId, int $productId): bool;

    /**
     * What the storefront says this post's status IS, read fresh.
     *
     * Asked rather than assumed: `prepare()` promises that opening an editor
     * cannot put a product on sale, and a promise verified against the value
     * we just wrote is a promise verified against ourselves.
     */
    public function storefrontStatus(int $wcProductId): string;

    /**
     * Set the product's public address, as an explicit manager decision.
     *
     * Separate from `project()` on purpose. A projection may NOT move an
     * address a person has changed — a vendor pressing save is not a request
     * to rename anybody's URL, and until this round it was exactly that. But
     * the manager typing a slug on the review screen IS a request, and a rule
     * that refused it would leave them typing into a box that does nothing.
     *
     * So the projection is evidence-driven and this is decision-driven, and
     * the two are different methods because they are different events.
     */
    public function applySlug(Product $product, string $slug): bool;
}
