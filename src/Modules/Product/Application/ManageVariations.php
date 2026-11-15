<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;

use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The variable product's axes and its sellable combinations (A.1).
 *
 * Two things this class refuses, for the same reason the parent product
 * refuses them: a combination that names an option the attribute does not
 * offer, and a second variation for a combination that already exists. Both
 * would produce a product whose price depends on which row was read.
 *
 * Stock here behaves exactly as it does on the parent (ADR-008): the vendor's
 * edit is a write-through, and after a sale the number comes back FROM
 * WooCommerce. Nothing in this class pushes a remembered stock value.
 */
final class ManageVariations
{
    public const MAX_ATTRIBUTES = 4;
    public const MAX_VARIATIONS = 60;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly VariationRepositoryInterface $variations,
        private readonly ProductImageLibraryInterface $images,
        private readonly StaffAccess $access,
        private readonly AuditLogger $audit,
        /**
         * Only to ASK whether the shop already holds this product's
         * combinations. Nullable so every existing caller and every test that
         * builds this service by hand keeps working (`alpha.32`); when it is
         * absent the guard below stands down, which is the pre-`alpha.105`
         * behaviour and is correct on a build with no storefront.
         */
        private readonly ?StorefrontVariationReaderInterface $storefront = null
    ) {
    }

    /**
     * ⛔ THE COMBINATIONS ARE IN WOOCOMMERCE, SO THIS PANEL MAY NOT MAKE MORE.
     *
     * An imported product has no row in `tmc_product_attributes` or
     * `tmc_product_variations` — `ImportFromDokan` writes neither — while
     * WooCommerce holds the real children. Before `alpha.105` the vendor's
     * panel said «هنوز ویژگی‌ای تعریف نشده است» about such a product, which is
     * an invitation to define the axes again; and had they accepted it, the
     * next projection would have created a SECOND set of variations beside the
     * first, because `existingVariationIds()` can only recognise children this
     * plugin stamped.
     *
     * So the five writing verbs refuse by name while that is the situation:
     * our tables are empty AND the shop's parent has children. One guard, in
     * the service, rather than one per route — a route that forgot to ask
     * would otherwise be a route that could still duplicate.
     *
     * It is deliberately NOT a permission and NOT a capability: the vendor may
     * edit this product, and will be able to edit these combinations once the
     * marketplace has adopted them. It is «not through this door yet».
     */
    private function shopOwnsTheCombinations(int $productId, ?int $wcProductId): bool
    {
        if ($this->storefront === null || $wcProductId === null || $wcProductId <= 0) {
            return false;
        }
        if ($this->variations->attributes($productId) !== [] || $this->variations->variations($productId) !== []) {
            return false;       // ours, and the shop is the mirror
        }
        return $this->storefront->setFor($wcProductId)->hasVariations();
    }

    /** @param list<string> $options */
    public function saveAttribute(
        int $actorId,
        int $vendorUserId,
        int $productId,
        string $key,
        string $label,
        array $options
    ): OperationResult {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($product->details->type !== ProductType::VARIABLE) {
            return OperationResult::failure('not_variable');
        }
        $key = $this->normaliseKey($key);
        $label = trim($label);
        if ($key === '' || $label === '') {
            return OperationResult::failure('incomplete_attribute');
        }
        $options = $this->cleanOptions($options);
        if ($options === []) {
            return OperationResult::failure('attribute_needs_options');
        }
        $existing = $this->variations->attributes($productId);
        $isNew = true;
        foreach ($existing as $attribute) {
            if ($attribute->key === $key) {
                $isNew = false;
            }
        }
        if ($isNew && count($existing) >= self::MAX_ATTRIBUTES) {
            return OperationResult::failure('too_many_attributes', ['limit' => self::MAX_ATTRIBUTES]);
        }
        $id = $this->variations->saveAttribute($productId, $key, $label, $options, count($existing));
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'attribute_saved');
        return OperationResult::success('attribute_saved', ['attribute_id' => $id]);
    }

    /**
     * Removing an axis removes the variations that were defined along it,
     * because a combination naming an attribute that no longer exists is not
     * sellable and keeping it would leave an unreachable price in the table.
     */
    public function deleteAttribute(int $actorId, int $vendorUserId, int $productId, int $attributeId): OperationResult
    {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        $attribute = null;
        foreach ($this->variations->attributes($productId) as $candidate) {
            if ($candidate->id === $attributeId) {
                $attribute = $candidate;
            }
        }
        if ($attribute === null) {
            return OperationResult::failure('not_found');
        }
        foreach ($this->variations->variations($productId) as $variation) {
            if (array_key_exists($attribute->key, $variation->attributes)) {
                $this->variations->deleteVariation($productId, $variation->id);
            }
        }
        if (!$this->variations->deleteAttribute($productId, $attributeId)) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'attribute_deleted');
        return OperationResult::success('attribute_deleted');
    }

    /**
     * @param array<string,string> $chosen attribute key => option
     */
    public function saveVariation(
        int $actorId,
        int $vendorUserId,
        int $productId,
        array $chosen,
        int $priceMinor,
        ?int $salePriceMinor,
        string $sku,
        int $stock,
        int $mediaId = 0,
        bool $enabled = true,
        ?UploadedFile $image = null
    ): OperationResult {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($product->details->type !== ProductType::VARIABLE) {
            return OperationResult::failure('not_variable');
        }
        $attributes = $this->variations->attributes($productId);
        if ($attributes === []) {
            return OperationResult::failure('attributes_first');
        }
        $verdict = $this->validateCombination($attributes, $chosen);
        if ($verdict !== null) {
            return $verdict;
        }
        if ($priceMinor <= 0) {
            return OperationResult::failure('bad_price');
        }
        if ($salePriceMinor !== null && ($salePriceMinor < 0 || $salePriceMinor > $priceMinor)) {
            return OperationResult::failure('bad_sale_price');
        }
        if ($stock < 0) {
            return OperationResult::failure('bad_stock');
        }

        $combination = ProductVariation::combinationOf($chosen);
        $existing = null;
        foreach ($this->variations->variations($productId) as $candidate) {
            if ($candidate->combination() === $combination) {
                $existing = $candidate;
            }
        }
        if ($existing === null && count($this->variations->variations($productId)) >= self::MAX_VARIATIONS) {
            return OperationResult::failure('too_many_variations', ['limit' => self::MAX_VARIATIONS]);
        }
        if ($image !== null && $image->tempPath !== '' && $image->errorCode !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->images->store($image, $vendorUserId, $product->details->title);
            if (!$stored['ok']) {
                return OperationResult::failure($stored['code']);
            }
            $mediaId = $stored['media_id'];
        }
        if ($mediaId > 0 && !$this->images->ownedBy($mediaId, $vendorUserId)) {
            $mediaId = 0;      // somebody else's attachment never reaches a variation
        }

        $id = $this->variations->saveVariation($productId, new ProductVariation(
            $existing?->id ?? 0,
            $productId,
            $chosen,
            $priceMinor,
            $salePriceMinor,
            trim($sku),
            $stock,
            $mediaId,
            $enabled
        ));
        if ($id <= 0) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, $existing === null ? 'variation_added' : 'variation_saved');
        return OperationResult::success($existing === null ? 'variation_added' : 'variation_saved', ['variation_id' => $id]);
    }

    public function deleteVariation(int $actorId, int $vendorUserId, int $productId, int $variationId): OperationResult
    {
        $product = $this->editable($actorId, $vendorUserId, $productId);
        if ($product instanceof OperationResult) {
            return $product;
        }
        if ($this->variations->findVariation($productId, $variationId) === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->variations->deleteVariation($productId, $variationId)) {
            return OperationResult::failure('storage_failed');
        }
        $this->log($actorId, $vendorUserId, $productId, 'variation_deleted');
        return OperationResult::success('variation_deleted');
    }

    /**
     * A variation's stock, immediately — the same exception the parent
     * product's inventory gets, and for the same reason (A.1).
     */
    public function updateVariationStock(
        int $actorId,
        int $vendorUserId,
        int $productId,
        int $variationId,
        int $stock
    ): OperationResult {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Inventory, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        // ⛔ REPEATED HERE ON PURPOSE. This verb does not go through
        // `editable()`: it asks for the Inventory area, not Product (A.1), so
        // a guard that only sat in `editable()` would cover four verbs out of
        // five — which is no guard (`alpha.22`). And it is reachable: the
        // panel now PRINTS the shop's real child ids, so a stock update
        // naming 7701 would otherwise be answered «پیدا نشد» about a
        // combination that plainly exists.
        if (
            $product->details->type === ProductType::VARIABLE
            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)
        ) {
            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);
        }
        if ($stock < 0) {
            return OperationResult::failure('bad_stock');
        }
        $variation = $this->variations->findVariation($productId, $variationId);
        if ($variation === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->variations->updateVariationStock($variationId, $stock)) {
            return OperationResult::failure('storage_failed');
        }
        $this->audit->log(AuditEventCatalog::PRODUCT_INVENTORY_CHANGED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'stock' => $stock,
        ]);
        return OperationResult::success('inventory_saved', ['variation_id' => $variationId, 'stock' => $stock]);
    }

    /**
     * @param list<ProductAttribute> $attributes
     * @param array<string,string> $chosen
     */
    private function validateCombination(array $attributes, array $chosen): ?OperationResult
    {
        $missing = [];
        foreach ($attributes as $attribute) {
            $value = trim($chosen[$attribute->key] ?? '');
            if ($value === '') {
                $missing[] = $attribute->label;
                continue;
            }
            if (!$attribute->has($value)) {
                return OperationResult::failure('unknown_option', ['attribute' => $attribute->label]);
            }
        }
        if ($missing !== []) {
            return OperationResult::failure('incomplete_combination', ['fields' => implode('، ', $missing)]);
        }
        $known = array_map(static fn (ProductAttribute $a): string => $a->key, $attributes);
        foreach (array_keys($chosen) as $key) {
            if (!in_array((string) $key, $known, true)) {
                return OperationResult::failure('unknown_attribute', ['attribute' => (string) $key]);
            }
        }
        return null;
    }

    /** @return \Tecteb\Marketplace\Modules\Product\Domain\Product|OperationResult */
    private function editable(int $actorId, int $vendorUserId, int $productId): mixed
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $product = $this->products->findOwned($productId, $vendorUserId);
        if ($product === null) {
            return OperationResult::failure('not_found');
        }
        if ($product->status === ProductStatus::Submitted) {
            return OperationResult::failure('in_review');
        }
        if ($product->status === ProductStatus::Archived) {
            return OperationResult::failure('product_archived');
        }
        // After the other refusals, because «you may not edit this at all» is
        // a different sentence from «these combinations live in the shop», and
        // the first one is the one to say when both are true.
        if (
            $product->details->type === ProductType::VARIABLE
            && $this->shopOwnsTheCombinations($productId, $product->wcProductId)
        ) {
            return OperationResult::failure('variations_live_in_shop', ['product_id' => $productId]);
        }
        return $product;
    }

    /** @param list<string> $options @return list<string> */
    private function cleanOptions(array $options): array
    {
        $clean = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }
        return array_slice($clean, 0, ProductAttribute::MAX_OPTIONS);
    }

    private function normaliseKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9_\-]/', '-', $key);
        return substr(trim($key, '-'), 0, 64);
    }

    private function log(int $actorId, int $vendorUserId, int $productId, string $action): void
    {
        $this->audit->log(AuditEventCatalog::PRODUCT_SAVED, $actorId, 'product', (string) $productId, [
            'vendor_id' => $vendorUserId,
            'product_id' => $productId,
            'status' => $action,
            'created' => false,
        ]);
    }
}
