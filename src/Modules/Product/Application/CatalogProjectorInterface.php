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
     * The approved stock, written ONLY IF the shop still holds `$expected`.
     *
     * **The finding this closes, and `alpha.49` did not.** That round made the
     * approval verify its write by reading it back, and the delivery document
     * said that covered the window between the check and the write. It does
     * not, and the owner was right to reject it: `writeStock()` writes the
     * approved number unconditionally, so a read-back that finds exactly the
     * number we just asked for proves the write landed and says NOTHING about
     * what it replaced. A sale of two units between the check and the write
     * leaves 7 on the shelf; the write puts 5 there; the read-back sees 5,
     * agrees with itself, and the two sold units are gone.
     *
     * **Why a compare-and-set, and why in ONE statement.** Read from the
     * source rather than from memory — WooCommerce's real reduction path is
     * `wc_reduce_stock_levels()` → `wc_update_product_stock($p, $qty,
     * 'decrease')` → `WC_Product_Data_Store_CPT::update_product_stock()`, and
     * that method's `decrease` branch is a single relative statement,
     * `UPDATE wp_postmeta SET meta_value = meta_value %+f WHERE post_id = %d
     * AND meta_key = '_stock'` (lines 1820-1829 of
     * `class-wc-product-data-store-cpt.php` in WooCommerce 11.0.1). There is
     * no transaction around it and no `SELECT … FOR UPDATE`: the only thing
     * serialising two writers is InnoDB's row lock for the duration of each
     * statement.
     *
     * So the guard has to live INSIDE one statement on that same row. An
     * advisory lock would be worthless — nothing in WooCommerce takes it — and
     * «read it again just before writing» only narrows the window instead of
     * closing it. With the condition in the statement, the two orders are both
     * sound: the sale lands first and our `WHERE` matches nothing, so we
     * refuse and the sale survives; or we land first and the sale's relative
     * `decrease` applies to the number the manager approved.
     *
     * @param int $expected the stock the decision was made on — the number the
     *                      baseline was compared against and the number the
     *                      manager was shown. Not a hint: the write does not
     *                      happen unless the shop still holds it.
     * @return array{outcome:string, found:?int} `outcome` is `written` when the
     *         shop now holds the approved number, `moved` when it holds
     *         something else (and `found` says what, or null when the row has
     *         gone), `not_managed` when WooCommerce is not managing this
     *         product's stock at all, and `not_linked`/`not_written` when there
     *         was nothing to write to or the write itself was refused.
     */
    public function writeStockIfUnchanged(Product $product, int $stock, int $expected): array;

    /**
     * The vendor's purchase-limit edit, written through to WooCommerce.
     *
     * Separate from `project()` for the reason `writeStock()` is separate: a
     * vendor saving min, max and step is an edit of the shop's own selling
     * rule and must land AT ONCE, and until `alpha.48` nothing carried it.
     * Measured on `alpha.47` with real WooCommerce: the three rows reached the
     * shop only when `project()` ran — on a publish or an approval — so a
     * published product kept the limits of its last approval and the vendor's
     * save changed nothing a shopper could meet. The owner's own test said it
     * in one line: the maximum went 20 → 18, was saved, and WooCommerce still
     * said 20.
     *
     * It is NOT a projection: it writes the three quantity rows and nothing
     * else, so it cannot move a title, a price or a stock on its way past, and
     * it obeys the same ownership record `project()` obeys — a row a manager
     * has edited is held, named and left alone (the `alpha.44` rule).
     *
     * `wrote` are the rows this call now owns, `held` the ones a manager owns,
     * `failed` the ones that would not store. A `failed` row is NEVER reported
     * as success and never leaves the product less restricted than it was: the
     * previous value stays.
     *
     * @return array{ok:bool, code:string, wrote:list<string>, held:list<string>, failed:list<string>}
     */
    public function writeQuantities(Product $product): array;

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
