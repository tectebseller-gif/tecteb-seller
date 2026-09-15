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
        private readonly AuditLogger $audit
    ) {
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
        if ($this->products->findOwned($productId, $vendorUserId) === null) {
            return OperationResult::failure('not_found');
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
