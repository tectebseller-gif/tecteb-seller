<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;
use Tecteb\Marketplace\Modules\Product\Domain\FieldMerge;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;

/**
 * The marketplace product, written into WooCommerce (ADR-008).
 *
 * Three rules govern every line below, and they are the ones the owner asked
 * for by name:
 *
 *  - **no duplicates.** The link is stored on both sides and a projection
 *    updates the linked post or creates exactly one. A post that is not ours
 *    is never adopted, however convincing its title.
 *  - **no restored stock.** On CREATE the stock comes from the marketplace,
 *    because WooCommerce has none yet. On UPDATE stock is not touched at all:
 *    it moved when something sold, and that number is the true one. The only
 *    write is `writeStock()`, which is the vendor's explicit edit.
 *  - **no foreign writes.** Every write is preceded by `owns()`, so a shop
 *    product or a Dokan product is never modified, re-statused or deleted.
 */
final class WooCommerceProjector implements CatalogProjectorInterface
{
    /**
     * The address, stamped like every other projected field — but kept OUT of
     * `ProjectedFieldOwnership::FIELDS` on purpose: the slug is the manager's
     * either way, so it is a write to stop rather than a disagreement to put
     * a button on.
     */
    public const SLUG_FIELD = 'slug';

    /** Whether the run in progress wrote the address; see `applySeo()`. */
    private bool $wroteSlug = false;

    public const PRODUCT_META = '_tmc_product_id';
    public const VENDOR_META = '_tmc_vendor_id';

    /**
     * The status a claimed post had before this plugin took it over.
     *
     * Only ever written by claimStorefrontPost(), and only for a post this
     * plugin did not create. It is what makes «انتقال مالکیت عملیاتی»
     * reversible rather than one-way.
     */
    public const CLAIMED_FROM_STATUS_META = '_tmc_claimed_from_status';
    public const VARIATION_META = '_tmc_variation_id';
    /** Marketplace categories live under their own slugs, so no shop term is renamed. */
    public const CATEGORY_SLUG_PREFIX = 'tmc-';

    public function __construct(
        private readonly SpecTemplateRepositoryInterface $templates,
        private readonly ?ProductCategoryDirectoryInterface $categories = null
    )
    {
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product') && class_exists('WC_Product_Simple');
    }

    public function project(Product $product, array $attributes = [], array $variations = []): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'code' => 'woocommerce_missing', 'wc_product_id' => 0, 'variation_links' => []];
        }
        $linked = $this->linkedProduct($product);
        $isNew = $linked === null;
        $wcProduct = $linked ?? $this->blank($product);
        if ($wcProduct === null) {
            return ['ok' => false, 'code' => 'storage_failed', 'wc_product_id' => 0, 'variation_links' => []];
        }

        $details = $product->details;

        // Text the manager may have edited in WooCommerce. Each one is written
        // only while it is still what we last wrote; otherwise the vendor's
        // value is held as a proposal and the manager decides.
        //
        // `$isNew` travels with every one of them. A post created a moment
        // ago by this call is ours in full; an EXISTING post with no stamp is
        // not, however much it looks the same from here — that is a product
        // an older version projected, and whatever is in it may be the
        // manager's own text.
        $outcome = [];
        $this->writeOwned(
            $wcProduct,
            'title',
            $details->title !== '' ? $details->title : __('بدون عنوان', 'tecteb-marketplace-core'),
            static fn (\WC_Product $p): string => (string) $p->get_name(),
            static fn (\WC_Product $p, string $v): mixed => $p->set_name($v),
            $outcome,
            $isNew,
            $product->baseline
        );
        $this->writeOwned(
            $wcProduct,
            'short_description',
            $details->shortDescription,
            static fn (\WC_Product $p): string => (string) $p->get_short_description(),
            static fn (\WC_Product $p, string $v): mixed => $p->set_short_description($v),
            $outcome,
            $isNew,
            $product->baseline
        );
        $this->writeOwned(
            $wcProduct,
            'description',
            $this->storefrontDescription($product),
            static fn (\WC_Product $p): string => (string) $p->get_description(),
            static fn (\WC_Product $p, string $v): mixed => $p->set_description($v),
            $outcome,
            $isNew,
            $product->baseline
        );
        $wcProduct->set_status($product->status === ProductStatus::Published ? 'publish' : 'draft');
        $wcProduct->set_catalog_visibility($product->status === ProductStatus::Published ? 'visible' : 'hidden');
        $this->applySku($wcProduct, $details->sku);
        $this->applySeo($wcProduct, $product);

        if ($details->weightGrams > 0) {
            $wcProduct->set_weight((string) round($details->weightGrams / 1000, 3));
        }
        // Pictures and category follow the same rule as the text: a gallery
        // the manager rearranged in WooCommerce, or a category they moved the
        // product into, is not undone by the vendor's next save.
        $this->writeOwned(
            $wcProduct,
            'images',
            // Main named, gallery in ITS order, nothing sorted: swapping the
            // featured picture or dragging the gallery about are the two
            // edits a manager actually makes, and the sorted form made both
            // of them invisible.
            ProjectedFieldOwnership::imagesToValue($product->mainImageId, $product->imageIds),
            static fn (\WC_Product $p): string => ProjectedFieldOwnership::imagesToValue(
                (int) $p->get_image_id(),
                $p->get_gallery_image_ids()
            ),
            static function (\WC_Product $p) use ($product): void {
                // Built from the same value object the comparison above is
                // built from, so «what we want» and «what we write» cannot
                // drift apart the way they did when one of them filtered the
                // list by hand.
                $images = StorefrontImages::of($product->mainImageId, $product->imageIds);
                // UNCONDITIONAL, and that is the whole of this fix. The line
                // this replaces was `if ($product->mainImageId > 0)`, so zero
                // never reached WooCommerce: a vendor who removed the
                // featured picture kept it on the shop for ever, and it was
                // the one change the marketplace owned and could not make.
                // Zero is a value here — `main:0` — not a missing one.
                $p->set_image_id($images->main);
                // The gallery is written as it stands. Nothing is promoted
                // into an emptied featured slot: WooCommerce draws its
                // placeholder, which is what «بدون تصویر اصلی» looks like,
                // and quietly publishing the next picture instead would be
                // this plugin choosing somebody's main photo for them.
                $p->set_gallery_image_ids($images->gallery);
            },
            $outcome,
            $isNew,
            $product->baseline
        );
        $wantCategories = $this->categoryIds(
            $details->categoryKey,
            array_map('intval', $wcProduct->get_category_ids())
        );
        $this->writeOwned(
            $wcProduct,
            'category',
            ProjectedFieldOwnership::idsToValue($wantCategories),
            static fn (\WC_Product $p): string => ProjectedFieldOwnership::idsToValue(
                array_map('intval', $p->get_category_ids())
            ),
            static fn (\WC_Product $p): mixed => $p->set_category_ids($wantCategories),
            $outcome,
            $isNew,
            $product->baseline
        );

        if ($details->type === ProductType::VARIABLE) {
            $wcProduct->set_attributes($this->wcAttributes($attributes));
        } else {
            $wcProduct->set_regular_price((string) $details->priceMinor);
            $this->applySale($wcProduct, $product);
            $wcProduct->set_manage_stock(true);
            if ($isNew) {
                // Only here: afterwards WooCommerce's number is the true one.
                $wcProduct->set_stock_quantity($details->stock);
                $wcProduct->set_stock_status($details->stock > 0 ? 'instock' : 'outofstock');
            }
        }

        try {
            $wcProductId = (int) $wcProduct->save();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'storage_failed', 'wc_product_id' => 0, 'variation_links' => []];
        }
        if ($wcProductId <= 0) {
            return ['ok' => false, 'code' => 'storage_failed', 'wc_product_id' => 0, 'variation_links' => []];
        }

        update_post_meta($wcProductId, self::PRODUCT_META, $product->id);
        update_post_meta($wcProductId, self::VENDOR_META, $product->vendorUserId);
        // Stamp AFTER the save and from a fresh read: WooCommerce runs its own
        // filters on the way in, so the value that comes back is routinely not
        // the one that went out. Stamping what we *sent* would mark every
        // product manager-edited on its next projection.
        $this->stampOwned($wcProductId, $outcome);
        // ONLY when this run actually wrote it. Stamping an address we
        // refused to touch would launder somebody else's slug into ours, and
        // the projection after that would happily overwrite it — the same
        // «don't stamp what you did not write» rule `alpha.27` established
        // for every other field, arriving late for this one.
        if ($this->wroteSlug) {
            $this->stampSlug($wcProductId);
        }
        // The author is the vendor, so WordPress' own "posts by this user"
        // views and any theme byline attribute the product correctly.
        wp_update_post(['ID' => $wcProductId, 'post_author' => $product->vendorUserId]);

        $variationLinks = $details->type === ProductType::VARIABLE
            ? $this->projectVariations($wcProductId, $product, $attributes, $variations)
            : [];
        return [
            'ok' => true,
            'code' => 'projected',
            'wc_product_id' => $wcProductId,
            'variation_links' => $variationLinks,
        ];
    }

    /**
     * Takes the product out of the shop — and then CHECKS that it is out.
     *
     * Three things happen here in order, and the order is the whole design:
     *
     *  1. The clean path: `WC_Product::save()`, which writes the status, the
     *     catalogue visibility and a dozen meta rows at once.
     *  2. If that throws, or if it returned without the status actually
     *     landing, a **single-row fallback** — `wp_update_post()` writing only
     *     `post_status`. A save that fails on some meta row must not leave a
     *     product on sale, and one column is the least that can fail.
     *  3. A re-read. The answer this method returns is what WordPress says the
     *     status IS, not what we asked it to be.
     *
     * Why the re-read matters more than it looks: the caller reports success
     * to a manager who is about to replace the package, and after that our
     * code is gone. `post_status = draft` is a fact WooCommerce honours on its
     * own — `is_purchasable()` requires `publish` — so it survives our
     * absence. A boolean we returned optimistically does not.
     */
    public function withdraw(Product $product, string $reason = ''): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $wcProduct = $this->linkedProduct($product);
        if ($wcProduct === null) {
            return false;
        }
        $wcProductId = (int) $wcProduct->get_id();
        // Draft, never trash: an order that referenced this product must keep
        // resolving, and the master spec forbids destructive rewrites.
        try {
            $wcProduct->set_status('draft');
            $wcProduct->set_catalog_visibility('hidden');
            $wcProduct->save();
        } catch (\Throwable) {
            // fall through to the single-row write below
        }
        if (self::storefrontStatusOf($wcProductId) !== 'draft') {
            wp_update_post(['ID' => $wcProductId, 'post_status' => 'draft']);
            clean_post_cache($wcProductId);
        }
        if (self::storefrontStatusOf($wcProductId) !== 'draft') {
            return false;       // it is still on sale; say so
        }
        if ($reason !== '') {
            update_post_meta($wcProductId, '_tmc_withdrawn_reason', $reason);
        }
        return true;
    }

    /** What WordPress says this post's status IS, read fresh. */
    public function storefrontStatus(int $wcProductId): string
    {
        return self::storefrontStatusOf($wcProductId);
    }

    private static function storefrontStatusOf(int $wcProductId): string
    {
        return (string) get_post_status($wcProductId);
    }

    public function readStock(Product $product): ?int
    {
        $wcProduct = $this->linkedProduct($product);
        if ($wcProduct === null) {
            return null;
        }
        $stock = $wcProduct->get_stock_quantity();
        return $stock === null ? null : (int) $stock;
    }

    public function writeStock(Product $product, int $stock): bool
    {
        $wcProduct = $this->linkedProduct($product);
        if ($wcProduct === null) {
            return false;
        }
        $wcProduct->set_manage_stock(true);
        $wcProduct->set_stock_quantity(max(0, $stock));
        $wcProduct->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        try {
            $wcProduct->save();
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    public function claimStorefrontPost(Product $product, int $wcProductId): bool
    {
        if (!$this->isAvailable() || $wcProductId <= 0) {
            return false;
        }
        $post = get_post($wcProductId);
        if ($post === null || !in_array($post->post_type, ['product', 'product_variation'], true)) {
            return false;
        }
        $existing = (int) get_post_meta($wcProductId, self::PRODUCT_META, true);
        if ($existing > 0 && $existing !== $product->id) {
            // Another marketplace row already owns this post. Two owners is
            // not a state any later question could resolve.
            return false;
        }
        // The ONLY write this plugin ever makes to a post it did not create,
        // and it writes exactly three keys: the link, the vendor, and the
        // status the post had at this moment. Nothing about the product
        // itself — not its title, price, stock or author — is touched.
        //
        // The third key is what makes the transfer reversible, and it was
        // added because leaving it out was measurably not reversible: take
        // ownership, stop selling, give ownership back, and the Dokan
        // vendor's product stayed a draft with nothing left to restore it.
        update_post_meta($wcProductId, self::PRODUCT_META, $product->id);
        update_post_meta($wcProductId, self::VENDOR_META, $product->vendorUserId);
        if (get_post_meta($wcProductId, self::CLAIMED_FROM_STATUS_META, true) === '') {
            update_post_meta($wcProductId, self::CLAIMED_FROM_STATUS_META, (string) get_post_status($wcProductId));
        }
        clean_post_cache($wcProductId);
        return $this->owns($wcProductId, $product->id);
    }

    public function releaseStorefrontPost(Product $product, int $wcProductId): bool
    {
        if (!$this->isAvailable() || !$this->owns($wcProductId, $product->id)) {
            return false;
        }
        // Put the post back the way it was found, THEN stop owning it. A
        // product taken over, withdrawn by a stop and then handed back must
        // not be left on the shelf as a draft: from the shop's point of view
        // the whole episode has to be undoable.
        $claimedFrom = (string) get_post_meta($wcProductId, self::CLAIMED_FROM_STATUS_META, true);
        if ($claimedFrom !== '' && (string) get_post_status($wcProductId) !== $claimedFrom) {
            wp_update_post(['ID' => $wcProductId, 'post_status' => $claimedFrom]);
        }
        delete_post_meta($wcProductId, self::PRODUCT_META);
        delete_post_meta($wcProductId, self::VENDOR_META);
        delete_post_meta($wcProductId, self::CLAIMED_FROM_STATUS_META);
        clean_post_cache($wcProductId);
        return !$this->owns($wcProductId, $product->id);
    }

    public function increaseStock(Product $product, int $by): ?int
    {
        if ($by <= 0) {
            return null;
        }
        $wcProduct = $this->linkedProduct($product);
        if ($wcProduct === null || !function_exists('wc_update_product_stock')) {
            return null;
        }
        if (!$wcProduct->get_manage_stock()) {
            // WooCommerce is not counting this product, so there is no number
            // to correct. Saying so is the honest answer; inventing a stock
            // level for a product whose shop chose not to track it is not.
            return null;
        }
        // WooCommerce's own atomic increment — one UPDATE with `+ %d`, not a
        // read and a write, so a sale landing between the two cannot be lost.
        $after = wc_update_product_stock($wcProduct, $by, 'increase');
        if ($after === false || $after === null) {
            return null;
        }
        wc_delete_product_transients((int) $wcProduct->get_id());
        return (int) $after;
    }

    public function readVariationStock(Product $product): array
    {
        if (!$this->isAvailable() || !$product->isProjected()) {
            return [];
        }
        $out = [];
        foreach ($this->existingVariationIds((int) $product->wcProductId) as $tmcId => $wcVariationId) {
            $variation = wc_get_product($wcVariationId);
            if (!$variation) {
                continue;
            }
            $stock = $variation->get_stock_quantity();
            if ($stock !== null) {
                $out[$tmcId] = (int) $stock;
            }
        }
        return $out;
    }

    public function owns(int $wcProductId, int $productId): bool
    {
        if ($wcProductId <= 0 || $productId <= 0) {
            return false;
        }
        $post = get_post($wcProductId);
        if ($post === null || !in_array($post->post_type, ['product', 'product_variation'], true)) {
            return false;
        }
        return (int) get_post_meta($wcProductId, self::PRODUCT_META, true) === $productId;
    }

    // ------------------------------------------------------------ internals

    /** The linked WooCommerce product, or null when there is none we own. */
    private function linkedProduct(Product $product): mixed
    {
        if (!$this->isAvailable() || !$product->isProjected()) {
            return null;
        }
        $wcProductId = (int) $product->wcProductId;
        if (!$this->owns($wcProductId, $product->id)) {
            return null;     // the post went away, or was never ours
        }
        $wcProduct = wc_get_product($wcProductId);
        return $wcProduct instanceof \WC_Product ? $wcProduct : null;
    }

    private function blank(Product $product): mixed
    {
        return $product->details->type === ProductType::VARIABLE
            ? new \WC_Product_Variable()
            : new \WC_Product_Simple();
    }

    /**
     * A SKU WooCommerce already knows belongs elsewhere throws; the product is
     * still worth publishing without one, and the vendor's own SKU check has
     * already made it unique within their shop.
     */
    private function applySku(mixed $wcProduct, string $sku): void
    {
        if (trim($sku) === '') {
            return;
        }
        try {
            $wcProduct->set_sku($sku);
        } catch (\Throwable) {
            // left as it was
        }
    }

    private function applySale(mixed $wcProduct, Product $product): void
    {
        $details = $product->details;
        if ($details->salePriceMinor === null) {
            $wcProduct->set_sale_price('');
            $wcProduct->set_date_on_sale_from(null);
            $wcProduct->set_date_on_sale_to(null);
            return;
        }
        $wcProduct->set_sale_price((string) $details->salePriceMinor);
        $wcProduct->set_date_on_sale_from($details->saleFrom);
        $wcProduct->set_date_on_sale_to($details->saleTo);
    }

    /**
     * The public address, and the one rule that was missing from it.
     *
     * `set_slug()` ran on every projection while the marketplace had a slug
     * — with no ownership test at all. So a manager who corrected the address
     * in WooCommerce or in Rank Math had it silently put back the next time
     * the VENDOR pressed save, which is the one thing a vendor is explicitly
     * not allowed to touch (Master A.5: «فروشنده فیلد تخصصی SEO ندارد»).
     *
     * So the slug gets the same stamp everything else has. We write it while
     * it is still the one we wrote; the moment somebody else changes it, it
     * is theirs and this method stops. It is deliberately NOT added to the
     * review screen's field list: the address belongs to the manager either
     * way, so there is no disagreement to put a button on — only a write to
     * stop making.
     */
    private function applySeo(mixed $wcProduct, Product $product): void
    {
        $seo = $product->seo;
        $wcProductId = (int) $wcProduct->get_id();
        $currentSlug = (string) $wcProduct->get_slug();
        $slugStamp = $wcProductId > 0
            ? (string) get_post_meta($wcProductId, ProjectedFieldOwnership::STAMP_PREFIX . self::SLUG_FIELD, true)
            : '';
        // A post this projection is creating has no address yet; anything
        // else must still be carrying OUR address for us to move it.
        $slugIsOurs = $wcProductId <= 0
            || $currentSlug === ''
            || ($slugStamp !== '' && hash_equals($slugStamp, ProjectedFieldOwnership::fingerprint($currentSlug)));
        $this->wroteSlug = trim($seo->slug) !== '' && $slugIsOurs;
        if ($this->wroteSlug) {
            $wcProduct->set_slug($seo->slug);
        }
        if ($wcProductId <= 0) {
            return;    // set after save, on the next projection
        }
        if (trim($seo->title) !== '') {
            update_post_meta($wcProductId, '_tmc_seo_title', $seo->title);
        }
        if (trim($seo->description) !== '') {
            update_post_meta($wcProductId, '_tmc_seo_description', $seo->description);
        }
    }

    /**
     * Stamp the slug from what WordPress KEPT, after the save.
     *
     * `sanitize_title()` runs on the way in and `wp_unique_post_slug()` may
     * append `-2`, so the value that comes back is routinely not the one that
     * went out — stamping what we sent would mark the address foreign on its
     * very next projection.
     */
    /**
     * The manager's explicit decision about the address.
     *
     * A projection is evidence-driven and will not move a slug somebody else
     * chose. This is the other path: the manager typing an address on the
     * review screen, which is a decision and is applied once — and stamped,
     * so the next projection knows the address is ours again.
     */
    public function applySlug(Product $product, string $slug): bool
    {
        $slug = trim($slug);
        if (!$this->isAvailable() || !$product->isProjected() || $slug === '') {
            return false;
        }
        $wcProductId = (int) $product->wcProductId;
        if (!$this->owns($wcProductId, $product->id)) {
            return false;       // not ours; never rename somebody else's post
        }
        $updated = wp_update_post(['ID' => $wcProductId, 'post_name' => $slug], true);
        if (is_wp_error($updated)) {
            return false;
        }
        clean_post_cache($wcProductId);
        $this->stampSlug($wcProductId);
        return true;
    }

    private function stampSlug(int $wcProductId): void
    {
        $fresh = get_post($wcProductId);
        if (!$fresh instanceof \WP_Post) {
            return;
        }
        update_post_meta(
            $wcProductId,
            ProjectedFieldOwnership::STAMP_PREFIX . self::SLUG_FIELD,
            ProjectedFieldOwnership::fingerprint((string) $fresh->post_name)
        );
    }

    /**
     * The medical specification, rendered as the product description.
     *
     * Empty fields are skipped (UX §6.2), retired ones are not asked for, and
     * the whole thing is plain paragraphs rather than markup a theme has to
     * cooperate with.
     */
    /**
     * What `project()` WOULD write as the description, for a caller that has
     * to compare it against what WooCommerce has.
     *
     * Public because the review screen must not build its own version of this
     * string: a comparison against a second implementation reports a
     * difference on the day the two drift, not on the day somebody edited
     * anything.
     */
    public function storefrontDescriptionFor(Product $product): string
    {
        return $this->storefrontDescription($product);
    }

    /** The same, for the category: the ids `project()` would write. */
    public function storefrontCategoryFor(Product $product, array $current = []): string
    {
        return ProjectedFieldOwnership::idsToValue(
            $this->categoryIds($product->details->categoryKey, array_map('intval', $current))
        );
    }

    private function storefrontDescription(Product $product): string
    {
        $parts = [];
        if (trim($product->details->shortDescription) !== '') {
            $parts[] = $product->details->shortDescription;
        }
        $template = $this->templates->findByCategory($product->details->categoryKey);
        foreach ($template?->askedFields() ?? [] as $field) {
            $value = trim($product->specs[$field->key] ?? '');
            if ($value === '') {
                continue;
            }
            $unit = $field->unit !== '' ? ' ' . $field->unit : '';
            $parts[] = $field->label . ': ' . $value . $unit;
        }
        if ($product->details->brand !== '') {
            array_unshift($parts, __('برند', 'tecteb-marketplace-core') . ': ' . $product->details->brand);
        }
        return implode("\n\n", $parts);
    }

    /**
     * Write a field, or refuse and say why — and record which it was.
     *
     * @param callable(\WC_Product):string        $read    what WooCommerce has now
     * @param callable(\WC_Product,string):mixed  $write   how to set it
     * @param array<string,string>                $outcome filled with what happened
     *
     * `$outcome[$field]` is how `stampOwned()` knows what actually happened:
     * `wrote` gets a stamp, `held` gets a pending proposal, and `frozen` gets
     * NEITHER. That last one is new in `alpha.27` and it is the point of the
     * whole change: a field on a product older than the stamps must come out
     * of a projection with the marketplace having touched nothing and
     * claimed nothing.
     */
    private function writeOwned(
        \WC_Product $wcProduct,
        string $field,
        string $want,
        callable $read,
        callable $write,
        array &$outcome,
        bool $createdByUs,
        ?ApprovedBaseline $baseline = null
    ): void {
        $id = (int) $wcProduct->get_id();
        $stamp = $id > 0 ? (string) get_post_meta($id, ProjectedFieldOwnership::STAMP_PREFIX . $field, true) : '';
        $current = (string) $read($wcProduct);
        // Before asking who owns it: a GENERATED field that no longer matches
        // our stamp has been edited in WooCommerce by a person, and that is
        // recorded now rather than rediscovered on every save.
        $this->recordDerivedTakeover($id, $field, $stamp, $current);
        $managerOwns = $id > 0 && (string) get_post_meta($id, ProjectedFieldOwnership::MANAGER_PREFIX . $field, true) === '1';

        // Three values, not two. `$want` is what the marketplace row says,
        // `$current` is what the shop holds, and the baseline is what the
        // vendor's edit was measured from — without which «the manager
        // changed a field nobody asked about» and «the two sides disagree»
        // are the same fact.
        $verdict = FieldMerge::decide(
            $baseline !== null && $baseline->has($field),
            $baseline?->get($field) ?? '',
            $want,
            $current,
            $stamp,
            $managerOwns,
            $createdByUs,
            !in_array($field, ProjectedFieldOwnership::ROUND_TRIP, true)
        );

        switch ($verdict) {
            case FieldMerge::WRITE:
                $write($wcProduct, $want);
                $outcome[$field] = 'wrote';
                return;

            case FieldMerge::SKIP:
                // The vendor did not touch this field. Whatever the manager
                // did with it stands, and NOTHING is recorded: no write, no
                // proposal, no question. This one line is what turns one
                // vendor edit back into one question instead of five.
                $outcome[$field] = 'skipped';
                return;

            case FieldMerge::CONFLICT:
                // A real disagreement: both sides moved it, to different
                // values. Recording a proposal equal to what is already there
                // would show the manager a «change» that changes nothing, and
                // `decide()` has already ruled that case out.
                $outcome[$field] = 'held';
                $outcome[$field . ':value'] = $want;
                return;

            case FieldMerge::MANAGER:
                // `description` is the field this matters most for. The
                // marketplace BUILDS that text, so once a manager has edited
                // it every short-description change would otherwise re-raise
                // the same question for ever.
                $outcome[$field] = 'frozen';
                return;

            default:
                // `unsettled`: older than the stamps, and nobody knows who
                // wrote what is there. Not ours to write and not ours to
                // stamp — agreeing with a value is not the same as having
                // written it. But the vendor's value IS recorded beside it,
                // exactly as `alpha.27` recorded it, because the «فیلدهای
                // بدون سابقه» block is where somebody settles this and a
                // block with nothing to compare against settles nothing.
                if (ProjectedFieldOwnership::fingerprint($want) !== ProjectedFieldOwnership::fingerprint($current)) {
                    $outcome[$field] = 'held';
                    $outcome[$field . ':value'] = $want;
                    return;
                }
                $outcome[$field] = 'frozen';
                return;
        }
    }

    /**
     * A generated field the manager has edited belongs to the manager — and
     * that is recorded rather than re-derived every run.
     *
     * `description` is not authored by anyone here: the projector renders it
     * out of the short description, the brand and the medical specification.
     * So if the stamp says we wrote X and the shop holds Y, the only thing
     * that can have happened is that somebody edited it in WooCommerce. That
     * is evidence, not a guess, and the safe conclusion — «stop writing it» —
     * is recorded once, with an audit line, instead of being rediscovered on
     * every save. The manager can hand the field back from the review screen.
     */
    private function recordDerivedTakeover(int $wcProductId, string $field, string $stamp, string $current): void
    {
        if ($wcProductId <= 0 || $stamp === '' || $field !== 'description') {
            return;
        }
        if (hash_equals($stamp, ProjectedFieldOwnership::fingerprint($current))) {
            return;
        }
        update_post_meta($wcProductId, ProjectedFieldOwnership::MANAGER_PREFIX . $field, '1');
        delete_post_meta($wcProductId, ProjectedFieldOwnership::PENDING_PREFIX . $field);
    }

    /**
     * Record what we just wrote, and what we did not.
     *
     * @param array<string,string> $outcome field => wrote|held|frozen, plus
     *        `<field>:value` for the ones held back
     */
    private function stampOwned(int $wcProductId, array $outcome): void
    {
        $wcProduct = wc_get_product($wcProductId);
        if (!$wcProduct instanceof \WC_Product) {
            return;
        }
        $now = [
            'title' => (string) $wcProduct->get_name(),
            'short_description' => (string) $wcProduct->get_short_description(),
            'description' => (string) $wcProduct->get_description(),
            'images' => ProjectedFieldOwnership::imagesToValue(
                (int) $wcProduct->get_image_id(),
                $wcProduct->get_gallery_image_ids()
            ),
            'category' => ProjectedFieldOwnership::idsToValue(
                array_map('intval', $wcProduct->get_category_ids())
            ),
        ];
        foreach (ProjectedFieldOwnership::FIELDS as $field) {
            $pending = ProjectedFieldOwnership::PENDING_PREFIX . $field;
            switch ($outcome[$field] ?? 'frozen') {
                case 'wrote':
                    delete_post_meta($wcProductId, $pending);
                    update_post_meta(
                        $wcProductId,
                        ProjectedFieldOwnership::STAMP_PREFIX . $field,
                        ProjectedFieldOwnership::fingerprint($now[$field] ?? '')
                    );
                    break;
                case 'held':
                    // A proposal the manager has not answered yet. The stamp
                    // is deliberately left alone: the field is still theirs.
                    update_post_meta($wcProductId, $pending, $outcome[$field . ':value'] ?? '');
                    break;
                default:
                    // `frozen`: either the manager owns it, or nobody knows
                    // who does. Not ours to stamp. Stamping would say «the
                    // marketplace wrote this», and the next projection would
                    // believe it and overwrite — the exact failure this whole
                    // mechanism exists to stop.
                    break;
            }
        }
    }

    /**
     * The WooCommerce term this product belongs to — resolved, never created.
     *
     * Until `alpha.24` this built a `tmc-…` slug out of the template key and
     * called `wp_insert_term()` when it did not exist. On a shop with 1,070
     * real categories that is a second, invisible taxonomy: the product lands
     * under a term the shop's menus, filters, Elementor templates and Rank
     * Math sitemap have never heard of, and the category the owner actually
     * uses stays empty. The owner's instruction is explicit — «دسته‌بندی
     * موازی یا تعریف دوبارهٔ آن‌ها نمی‌خواهیم» — so nothing here writes to the
     * taxonomy any more.
     *
     * `$current` is what the product already carries. An unresolvable value
     * KEEPS it rather than clearing it: a stored key whose term somebody
     * renamed or deleted is a reason to leave the product where the shop put
     * it, not a reason to strip its category on the next sync.
     *
     * @param list<int> $current
     * @return list<int>
     */
    private function categoryIds(string $categoryKey, array $current = []): array
    {
        if (trim($categoryKey) === '' || !taxonomy_exists('product_cat')) {
            return $current;
        }
        $category = $this->categories?->find($categoryKey);
        if ($category !== null) {
            return [$category->id];
        }
        return $current;
    }

    /**
     * @param list<ProductAttribute> $attributes
     * @return array<string,\WC_Product_Attribute>
     */
    private function wcAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $index => $attribute) {
            if (!$attribute->isUsable()) {
                continue;
            }
            $wcAttribute = new \WC_Product_Attribute();
            $wcAttribute->set_name($attribute->label);
            $wcAttribute->set_options($attribute->options);
            $wcAttribute->set_position($index);
            $wcAttribute->set_visible(true);
            $wcAttribute->set_variation(true);
            $out[sanitize_title($attribute->label)] = $wcAttribute;
        }
        return $out;
    }

    /**
     * @param list<ProductAttribute> $attributes
     * @param list<ProductVariation> $variations
     * @return array<int,int> tmc variation id => the storefront variation it became
     */
    private function projectVariations(int $wcProductId, Product $product, array $attributes, array $variations): array
    {
        $links = [];
        $labels = [];
        foreach ($attributes as $attribute) {
            $labels[$attribute->key] = $attribute->label;
        }
        $existing = $this->existingVariationIds($wcProductId);

        foreach ($variations as $variation) {
            $wcVariationId = $existing[$variation->id] ?? 0;
            $isNew = $wcVariationId <= 0;
            $wcVariation = $isNew ? new \WC_Product_Variation() : wc_get_product($wcVariationId);
            if (!$wcVariation instanceof \WC_Product_Variation) {
                $wcVariation = new \WC_Product_Variation();
                $isNew = true;
            }
            $wcVariation->set_parent_id($wcProductId);
            $wcVariation->set_status($variation->enabled ? 'publish' : 'private');
            $chosen = [];
            foreach ($variation->attributes as $key => $value) {
                $chosen[sanitize_title($labels[$key] ?? $key)] = $value;
            }
            $wcVariation->set_attributes($chosen);
            $wcVariation->set_regular_price((string) $variation->priceMinor);
            $wcVariation->set_sale_price($variation->salePriceMinor === null ? '' : (string) $variation->salePriceMinor);
            if ($variation->sku !== '') {
                try {
                    $wcVariation->set_sku($variation->sku);
                } catch (\Throwable) {
                    // keep whatever it had
                }
            }
            if ($variation->mediaId > 0) {
                $wcVariation->set_image_id($variation->mediaId);
            }
            $wcVariation->set_manage_stock(true);
            if ($isNew) {
                $wcVariation->set_stock_quantity($variation->stock);
                $wcVariation->set_stock_status($variation->stock > 0 ? 'instock' : 'outofstock');
            }
            try {
                $savedId = (int) $wcVariation->save();
            } catch (\Throwable) {
                continue;
            }
            if ($savedId > 0) {
                update_post_meta($savedId, self::VARIATION_META, $variation->id);
                update_post_meta($savedId, self::PRODUCT_META, $product->id);
                $links[$variation->id] = $savedId;
                unset($existing[$variation->id]);
            }
        }

        // Combinations the vendor removed: hidden, not deleted, for the same
        // reason the parent is drafted rather than trashed.
        foreach ($existing as $staleWcId) {
            $stale = wc_get_product($staleWcId);
            if ($stale instanceof \WC_Product_Variation) {
                $stale->set_status('private');
                $stale->set_stock_status('outofstock');
                try {
                    $stale->save();
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        \WC_Product_Variable::sync($wcProductId);
        return $links;
    }

    /** @return array<int,int> tmc variation id => WooCommerce variation id */
    private function existingVariationIds(int $wcProductId): array
    {
        $parent = wc_get_product($wcProductId);
        if (!$parent instanceof \WC_Product_Variable) {
            return [];
        }
        $out = [];
        foreach ($parent->get_children() as $childId) {
            $tmcId = (int) get_post_meta((int) $childId, self::VARIATION_META, true);
            if ($tmcId > 0) {
                $out[$tmcId] = (int) $childId;
            }
        }
        return $out;
    }
}
