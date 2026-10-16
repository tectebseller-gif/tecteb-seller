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
 *   pushQuantities() marketplace → storefront, the vendor's selling rule
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
        if (!$this->mayWriteToStorefront($product)) {
            return OperationResult::failure('not_our_product', [
                'product_id' => $productId,
                'ownership' => $product->linkOwnership,
            ]);
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

    /**
     * WHETHER THIS ROW MAY BE WRITTEN TO THE SHOP AT ALL.
     *
     * An `observed` row is a note in a ledger: it points at somebody else's
     * WooCommerce product — the main shop's, a Dokan vendor's — and nothing
     * follows from it until a manager accepts management explicitly
     * (`LinkOwnership`). Every verb in this class writes to the storefront,
     * so every one of them has to ask.
     *
     * **It was measured missing, and the damage was a DUPLICATE PRODUCT.**
     * `publish()` asks the projector whether it owns the post; an intaken
     * post deliberately carries no ownership stamp, so the answer is no, so
     * the link was dropped and `project()` built a NEW WooCommerce product
     * and pointed the row at it. Measured while fixing `alpha.55`'s stale
     * snapshot: after one `publish()` on an observed row,
     * `rows_at_end=0` for the product being observed and
     * `rows_for_vendor_at_end=1` for a product nobody asked to create. On a
     * live catalogue that is the one outcome this whole module exists to
     * avoid — «ورود بدون ساخت محصول جدید ووکامرس».
     */
    private function mayWriteToStorefront(Product $product): bool
    {
        return $product->ownsItsStorefrontProduct();
    }

    /**
     * The manager's address decision, applied once and stamped.
     *
     * Deliberately not part of `publish()`: a projection must not move an
     * address a person has chosen, and this is the one event that says a
     * person chose one.
     */
    public function applySlug(int $productId, string $slug): bool
    {
        $product = $this->products->find($productId);
        return $product !== null
            && $this->mayWriteToStorefront($product)
            && $this->projector->applySlug($product, $slug);
    }

    /**
     * Give a product that is NOT approved yet a storefront row, as a draft.
     *
     * The manager asked for something WooCommerce and Rank Math already do
     * well — a real editor, a real SEO box — rather than a second editor
     * built here. Both live on the WooCommerce product edit screen, and
     * before approval that screen did not exist: `project()` only ran when a
     * product was approved. So there was nowhere to prepare anything, and
     * «open the editor» meant «publish it first», which is exactly backwards.
     *
     * This creates the row and nothing else. The projector already writes
     * `draft` and `hidden` for every status but Published, so the product is
     * not purchasable, not in the catalogue and not in a sitemap — and that
     * is CHECKED here rather than trusted, because the whole promise of this
     * method is that opening an editor cannot put something on sale.
     */
    public function prepare(int $productId): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->mayWriteToStorefront($product)) {
            return OperationResult::failure('not_our_product', ['product_id' => $productId, 'ownership' => $product->linkOwnership]);
        }
        if ($product->status === ProductStatus::Published) {
            return OperationResult::failure('already_published');
        }
        $result = $this->publish($productId);
        if (!$result->ok) {
            return $result;
        }
        $wcProductId = (int) ($result->context['wc_product_id'] ?? 0);
        if ($this->projector->storefrontStatus($wcProductId) === 'publish') {
            // Should be unreachable, and is checked anyway: a preparation
            // step that put a product on sale would do it silently, and the
            // first person to find out would be a buyer.
            $this->projector->withdraw($product, 'prepared_not_approved');
            return OperationResult::failure('prepare_would_publish');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SYNCED, 0, 'product', (string) $product->id, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $product->id,
            'wc_product_id' => $wcProductId,
            'action' => 'prepare',
        ]);
        return OperationResult::success('prepared', ['wc_product_id' => $wcProductId]);
    }

    /** Out of the shop without deleting anything. */
    public function withdraw(int $productId, string $reason = ''): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->mayWriteToStorefront($product)) {
            return OperationResult::failure('not_our_product', ['product_id' => $productId, 'ownership' => $product->linkOwnership]);
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

    /**
     * The vendor's explicit inventory edit, written through to the storefront.
     *
     * TRUE unless the write itself was refused — deliberately, and unchanged
     * in `alpha.49`. This is a draft or an unpublished product, where the
     * marketplace row is the one that matters and the mirror catching up to
     * whatever the shop decided IS the right answer. The verdict that must be
     * stricter is the approval's, and it has its own verb below.
     */
    public function pushStock(int $productId, int $stock): bool
    {
        return $this->writeAndVerifyStock($productId, $stock)->code !== 'stock_not_written';
    }

    /**
     * The APPROVED stock — written only over the number the decision was made
     * on, and then verified.
     *
     * **`alpha.49` got half of this and reported the whole of it, and the
     * correction belongs here.** That round stopped the approval being recorded
     * on a result nobody had checked: a clamped write and an unreadable
     * read-back each got their own refusal, and the proposal stayed pending.
     * All of that stands. What that round ALSO claimed — in
     * `docs/phase-47-…` §4.1 — was that the read-back covered the window
     * between reading the stock and writing it. **It did not, and saying so
     * was wrong.** `writeStock()` writes the approved number unconditionally,
     * so a read-back that finds that very number proves only that the write
     * landed. Two units sold in between were overwritten and every check
     * agreed with itself.
     *
     * So from `alpha.50` the check and the write are ONE protected operation:
     * `writeStockIfUnchanged()` carries `$expected` into the single statement
     * that competes with WooCommerce's own `decrease`. See the interface for
     * why that is the only primitive the real reduction path respects.
     *
     * «تأیید موجودی فقط با نتیجهٔ معتبر ثبت شود.» Five answers, one success:
     *
     *  - `stock_written` — the shop holds the number that was approved.
     *  - `stock_moved_during_write` — somebody changed it between the check and
     *    the write, so NOTHING was written. Their change survives, the mirror
     *    is corrected to it, and the three numbers are named.
     *  - `stock_mirrored_different` — the write landed and the shop kept
     *    another number (a site filter clamping it). The mirror is corrected so
     *    no screen shows a number the shop does not have.
     *  - `stock_unverified` — the read-back did not come back. Nothing is
     *    mirrored, because a value that could not be read is not a value.
     *  - `stock_not_written` — there was nothing to write to, or the write was
     *    refused, with the reason.
     *
     * `pushStock()` above keeps the unguarded write on purpose and says why.
     */
    public function pushApprovedStock(int $productId, int $stock, int $expected): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null || !$product->isProjected() || !$this->projector->isAvailable()) {
            return OperationResult::failure('stock_not_written', [
                'reason' => 'not_projected',
                'asked' => (string) $stock,
            ]);
        }
        // ONE PROTECTED OPERATION, not a read followed by a write.
        //
        // `alpha.49` wrote the approved number unconditionally and then read it
        // back, and reported that as the guard. It is not one: a read-back that
        // finds the number we just asked for agrees with itself whatever it
        // replaced. Two units sold between the check and the write were
        // overwritten and nothing could tell.
        $write = $this->projector->writeStockIfUnchanged($product, $stock, $expected);
        $outcome = (string) ($write['outcome'] ?? 'not_written');
        if ($outcome === 'moved') {
            $found = $write['found'] ?? null;
            // The shop's number is the true one (ADR-008), so the mirror shows
            // it at once — otherwise the manager re-reads the page and decides
            // again on the number that has just been refused.
            if ($found !== null) {
                $this->products->mirrorStock($productId, (int) $found);
            }
            return OperationResult::failure('stock_moved_during_write', [
                'asked' => (string) $stock,
                'expected' => (string) $expected,
                'shop' => $found === null ? '__unknown__' : (string) $found,
            ]);
        }
        if ($outcome !== 'written') {
            return OperationResult::failure('stock_not_written', [
                'reason' => $outcome,
                'asked' => (string) $stock,
            ]);
        }
        return $this->verifyStock($product, $productId, $stock);
    }

    private function writeAndVerifyStock(int $productId, int $stock): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product !== null && !$this->mayWriteToStorefront($product)) {
            // Somebody else's product. An observed row's stock is a note, and
            // writing it would move a number on a product this marketplace
            // does not run.
            return OperationResult::failure('stock_not_written', [
                'reason' => 'not_our_product',
                'asked' => (string) $stock,
            ]);
        }
        if ($product === null || !$product->isProjected() || !$this->projector->isAvailable()) {
            return OperationResult::failure('stock_not_written', [
                'reason' => 'not_projected',
                'asked' => (string) $stock,
            ]);
        }
        if (!$this->projector->writeStock($product, $stock)) {
            return OperationResult::failure('stock_not_written', [
                'reason' => 'refused',
                'asked' => (string) $stock,
            ]);
        }
        return $this->verifyStock($product, $productId, $stock);
    }

    /**
     * The read-back, which is still worth doing and is NOT the guard.
     *
     * It catches what the shop decided about a write that did land — a site
     * filter clamping the amount, a number that cannot be read afterwards.
     * What it cannot see is what the write replaced, which is why
     * `pushApprovedStock()` above does not rely on it alone.
     */
    private function verifyStock(Product $product, int $productId, int $stock): OperationResult
    {
        // Read back rather than assume: WooCommerce may clamp or refuse, and
        // the mirror must show what the storefront actually holds.
        $actual = $this->projector->readStock($product);
        if ($actual === null) {
            return OperationResult::failure('stock_unverified', ['asked' => (string) $stock]);
        }
        if ($actual !== $stock) {
            $this->products->mirrorStock($productId, $actual);
            return OperationResult::failure('stock_mirrored_different', [
                'asked' => (string) $stock,
                'shop' => (string) $actual,
            ]);
        }
        return OperationResult::success('stock_written', ['stock' => (string) $stock]);
    }

    /**
     * The vendor's purchase-limit edit, written through to the storefront.
     *
     * `pushStock()`'s sibling, and new in `alpha.48` because the owner's own
     * test found there was nothing of the kind: min, max and step were stored
     * on the marketplace row and reached the shop only when `project()` ran.
     * On a published product that is an approval away, so the maximum went
     * 20 → 18, the vendor saw their save succeed, and WooCommerce went on
     * refusing anything above 20 — a limit nobody had asked for, outliving the
     * person entitled to change it.
     *
     * It reports rather than claims. Three answers matter to the vendor and
     * each has its own name: `limits_written` (the shop holds the new rule),
     * `limits_partly_held` (a manager owns one of the rows and it is named, so
     * «ذخیره شد» is not said about a row that did not move), and
     * `limits_not_written` (the shop refused a row; the previous restriction
     * stays and the product is NEVER left less restricted than it was).
     *
     * `nothing_to_push` is a success on purpose: a draft the vendor is still
     * writing has no storefront row, and that is not a failure of their save.
     */
    public function pushQuantities(int $productId): OperationResult
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->mayWriteToStorefront($product)) {
            return OperationResult::failure('not_our_product', ['product_id' => $productId, 'ownership' => $product->linkOwnership]);
        }
        if (!$product->isProjected() || !$this->projector->isAvailable()) {
            return OperationResult::success('nothing_to_push');
        }
        $result = $this->projector->writeQuantities($product);
        $context = [
            'product_id' => $productId,
            'wrote' => implode(',', $result['wrote']),
            'held' => implode(',', $result['held']),
            'failed' => implode(',', $result['failed']),
        ];
        if ($result['code'] === 'not_ours') {
            // A product whose storefront row is not ours is not a product
            // whose limits we may write, and saying it out loud is the whole
            // of ADR-008.
            return OperationResult::success('nothing_to_push', $context);
        }
        if (!$result['ok']) {
            return OperationResult::failure($result['code'], $context);
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_SYNCED, 0, 'product', (string) $productId, [
            'vendor_id' => $product->vendorUserId,
            'product_id' => $productId,
            'wc_product_id' => (int) $product->wcProductId,
            'action' => 'limits',
        ]);
        return OperationResult::success($result['code'], $context);
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
