<?php
/**
 * «ثبت کلید به‌تنهایی نشان نمی‌دهد مدیر آن ویژگی را تغییر نداده است» — measured.
 *
 * `alpha.43` recorded WHICH attribute keys it wrote and treated that record as
 * a licence to rewrite or delete them. The record says nothing about the
 * VALUE, so a manager's edit to one of those attributes was overwritten by the
 * next projection without a word. This probe asks the question the record
 * could not answer, on REAL WooCommerce 11.0.1 — because the whole subject is
 * what the shop holds after a save and a reload, and a stub that stores what
 * it was handed cannot disagree with the plugin.
 *
 * Every verb follows one shape, and the shape is the point:
 *
 *     project  →  the MANAGER edits it in WooCommerce  →  project again
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha43-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\SpecField;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;

// --- refuses to run anywhere but a disposable install ---------------------
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_P44_VENDOR = 8804;
const TMC_P44_CATEGORY = 'tmc-p44-gloves';
const TMC_P44_SKU = 'SKU-ALPHA44';
const TMC_P44_SKU_VAR = 'SKU-ALPHA44-VAR';
const TMC_P44_OWNED_META = '_tmc_attribute_keys';
const TMC_P44_CONFLICT_META = '_tmc_attribute_conflicts';

$verb = (string) ($args[0] ?? 'manager-edit');
$c = Bootstrap::container();
wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$products = new DbProductRepository($db, $clock);
/** @var SpecTemplateRepositoryInterface $templates */
$templates = $c->get(SpecTemplateRepositoryInterface::class);
/** @var VariationRepositoryInterface $variations */
$variations = $c->get(VariationRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);

$say = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) $value) . "\n";
};

if (!$catalog->isAvailable()) {
    $say('woocommerce', 'absent');
    return;
}
$say('wc_version', defined('WC_VERSION') ? WC_VERSION : '?');
$say('probe', 'ran');

// ---------------------------------------------------------------- fixtures

$templateId = static function () use ($templates): int {
    $existing = $templates->findByCategory(TMC_P44_CATEGORY);
    if ($existing !== null) {
        return $existing->id;
    }
    $id = $templates->createTemplate(TMC_P44_CATEGORY, 'دستکش آزمون ۴۴');
    $templates->addField($id, new SpecField(0, 'material', 'جنس', SpecFieldType::Text, true));
    $templates->addField($id, new SpecField(0, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ'));
    return $id;
};

$fieldId = static function (int $template, string $key) use ($templates): int {
    return $templates->find($template)?->field($key)?->id ?? 0;
};

/** Both template fields asked, with their original labels. */
$restoreTemplate = static function () use ($templates, $templateId, $fieldId): void {
    $id = $templateId();
    $templates->restoreField($fieldId($id, 'size'));
    $templates->updateField($fieldId($id, 'material'), 'جنس', true, '', [], 0);
    $templates->updateField($fieldId($id, 'size'), 'اندازه', false, 'اینچ', [], 1);
};

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P44_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $sku, string $brand, string $type = 'simple'): ProductDetails {
    return new ProductDetails(
        title: $type === 'simple' ? 'دستکش مالکیت' : 'دستکش مالکیت متنوع',
        categoryKey: TMC_P44_CATEGORY,
        brand: $brand,
        shortDescription: 'یک جمله',
        priceMinor: 200000,
        sku: $sku,
        stock: 5,
        type: $type,
        description: 'متن کامل فروشنده'
    );
};

/**
 * A published, projected simple product — and the ownership record REBUILT
 * from scratch, so a verb never measures the record another verb left behind.
 */
$simple = static function (string $brand = 'آریا طب', array $specs = ['material' => 'لاتکس', 'size' => '7'])
use ($products, $findBySku, $detailsOf, $catalog, $templateId, $restoreTemplate): array {
    $templateId();
    $restoreTemplate();
    $id = $findBySku(TMC_P44_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P44_VENDOR, $detailsOf(TMC_P44_SKU, $brand), ProductStatus::Published);
    } else {
        $products->updateDetails($id, $detailsOf(TMC_P44_SKU, $brand));
    }
    $products->saveSpecs($id, $specs, 1);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    if ($wcId > 0) {
        // Give back whatever a previous verb spent: the record, the conflict
        // list, and any attribute a manager-edit verb left on the product.
        delete_post_meta($wcId, TMC_P44_OWNED_META);
        delete_post_meta($wcId, TMC_P44_CONFLICT_META);
        $wcProduct = wc_get_product($wcId);
        if ($wcProduct instanceof WC_Product) {
            $wcProduct->set_attributes([]);
            $wcProduct->save();
        }
    }
    $result = $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    return [$id, $wcId, $result->code];
};

/** The attributes STORAGE holds, from a fresh object. */
$shopAttributes = static function (int $wcId): array {
    $fresh = wc_get_product($wcId);
    if (!$fresh instanceof WC_Product) {
        return [];
    }
    $out = [];
    foreach ($fresh->get_attributes() as $key => $attribute) {
        if ($attribute instanceof WC_Product_Attribute) {
            $out[(string) $key] = $attribute;
        }
    }
    return $out;
};

$valueOf = static function (array $attributes, string $label): string {
    $key = sanitize_title($label);
    return isset($attributes[$key]) ? implode('/', (array) $attributes[$key]->get_options()) : '';
};

$describe = static function (array $attributes): string {
    $parts = [];
    foreach ($attributes as $attribute) {
        $parts[] = $attribute->get_name() . ':' . implode('/', (array) $attribute->get_options());
    }
    sort($parts);
    return implode('|', $parts);
};

/**
 * What a manager does in the WooCommerce editor: change the VALUE of one
 * attribute and save, leaving everything else as it is.
 */
$managerEdits = static function (int $wcId, string $label, string $newValue) use ($shopAttributes): bool {
    $wcProduct = wc_get_product($wcId);
    if (!$wcProduct instanceof WC_Product) {
        return false;
    }
    // FRESH objects, every one of them. Mutating the attribute the product
    // already holds and handing the same array back registers NO change —
    // `set_prop()` compares against what is there, and what is there is the
    // object that was just edited. The first version of this helper did
    // exactly that and reported «the manager's edit did not happen» on a
    // product the manager had edited.
    $rewritten = [];
    foreach ($shopAttributes($wcId) as $attribute) {
        $copy = new WC_Product_Attribute();
        $copy->set_id((int) $attribute->get_id());
        $copy->set_name((string) $attribute->get_name());
        $copy->set_options(
            $attribute->get_name() === $label ? [$newValue] : (array) $attribute->get_options()
        );
        $copy->set_position((int) $attribute->get_position());
        $copy->set_visible((bool) $attribute->get_visible());
        $copy->set_variation((bool) $attribute->get_variation());
        $rewritten[] = $copy;
    }
    $wcProduct->set_attributes($rewritten);
    $wcProduct->save();
    return true;
};

$recordShape = static function (int $wcId): string {
    $stored = get_post_meta($wcId, TMC_P44_OWNED_META, true);
    if (!is_array($stored)) {
        return 'absent';
    }
    return array_is_list($stored) ? 'list' : 'map';
};

switch ($verb) {
    // ===================================================== §1 the manager's edit
    case 'manager-edit':
        [$id, $wcId] = $simple();
        $before = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('brand_projected', $valueOf($before, 'برند'));
        $say('record_shape', $recordShape($wcId));
        $managerEdits($wcId, 'برند', 'برندی که مدیر نوشته');
        $say('brand_after_manager', $valueOf($shopAttributes($wcId), 'برند'));
        // The vendor's own data has NOT changed: this projection is an
        // ordinary one, which is exactly what makes the overwrite silent.
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('brand_after_projection', $valueOf($after, 'برند'));
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        $say('facts', $describe($after));
        break;

    // The same, with the VENDOR also changing their value. The shop no longer
    // holds what we wrote either way, so the manager's edit still stands.
    case 'manager-edit-then-vendor-change':
        [$id, $wcId] = $simple();
        $managerEdits($wcId, 'جنس', 'جنسی که مدیر نوشته');
        $products->saveSpecs($id, ['material' => 'نیتریل', 'size' => '7'], 1);
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('material_after', $valueOf($after, 'جنس'));
        $say('size_after', $valueOf($after, 'اندازه'));
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        break;

    // ------------------------------- the manager's edit, then a RETIREMENT
    case 'manager-edit-then-retire':
        [$id, $wcId] = $simple();
        $template = $templateId();
        $managerEdits($wcId, 'اندازه', 'اندازه‌ای که مدیر نوشته');
        $say('wc', $wcId);
        $say('size_after_manager', $valueOf($shopAttributes($wcId), 'اندازه'));
        $templates->deprecateField($fieldId($template, 'size'));
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('size_after_retire', $valueOf($after, 'اندازه'));
        $say('size_present', isset($after[sanitize_title('اندازه')]) ? 'yes' : 'no');
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        $restoreTemplate();
        break;

    // ------------------------------ the manager's edit, then a LABEL CHANGE
    case 'manager-edit-then-rename':
        [$id, $wcId] = $simple();
        $template = $templateId();
        $managerEdits($wcId, 'جنس', 'جنسی که مدیر نوشته');
        $templates->updateField($fieldId($template, 'material'), 'جنس سازنده', true, '', [], 0);
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('old_label_value', $valueOf($after, 'جنس'));
        $say('old_label_present', isset($after[sanitize_title('جنس')]) ? 'yes' : 'no');
        $say('new_label_value', $valueOf($after, 'جنس سازنده'));
        $say('facts', $describe($after));
        $restoreTemplate();
        break;

    // ===================================================== controls (both trees)
    case 'control-vendor-change':
        [$id, $wcId] = $simple();
        $products->saveSpecs($id, ['material' => 'نیتریل', 'size' => '7'], 1);
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('material_after', $valueOf($after, 'جنس'));
        $say('attribute_count', count($after));
        break;

    case 'control-retire-untouched':
        [$id, $wcId] = $simple();
        $template = $templateId();
        $templates->deprecateField($fieldId($template, 'size'));
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('size_present', isset($after[sanitize_title('اندازه')]) ? 'yes' : 'no');
        $say('material_present', isset($after[sanitize_title('جنس')]) ? 'yes' : 'no');
        $restoreTemplate();
        break;

    case 'control-manager-independent':
        [$id, $wcId] = $simple();
        $wcProduct = wc_get_product($wcId);
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('کشور سازنده');
        $theirs->set_options(['ایران']);
        $theirs->set_visible(true);
        $theirs->set_position(9);
        $wcProduct->set_attributes(array_merge(array_values($shopAttributes($wcId)), [$theirs]));
        $wcProduct->save();
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('theirs_value', $valueOf($after, 'کشور سازنده'));
        $say('material_value', $valueOf($after, 'جنس'));
        break;

    case 'control-not-ours':
        $foreign = new WC_Product_Simple();
        $foreign->set_name('محصول فروشگاه خودش');
        $foreign->set_status('publish');
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('برند');
        $theirs->set_options(['برند فروشگاه']);
        $theirs->set_visible(true);
        $foreign->set_attributes([$theirs]);
        $foreignId = (int) $foreign->save();
        [$id] = $simple();
        $catalog->publish($id);
        $say('foreign_brand', $valueOf($shopAttributes($foreignId), 'برند'));
        $say('foreign_owned_meta', (string) get_post_meta($foreignId, TMC_P44_OWNED_META, true));
        wp_delete_post($foreignId, true);
        break;

    // ===================================================== §4 variable product
    case 'variable-manager-edit':
    case 'variable-vendor-change':
        $templateId();
        $restoreTemplate();
        $id = $findBySku(TMC_P44_SKU_VAR);
        if ($id === 0) {
            $id = $products->create(
                TMC_P44_VENDOR,
                $detailsOf(TMC_P44_SKU_VAR, 'آریا طب', 'variable'),
                ProductStatus::Published
            );
        } else {
            $products->updateDetails($id, $detailsOf(TMC_P44_SKU_VAR, 'آریا طب', 'variable'));
        }
        $products->saveSpecs($id, ['material' => 'لاتکس', 'size' => '7'], 1);
        foreach ($variations->variations($id) as $existing) {
            $variations->deleteVariation($id, $existing->id);
        }
        foreach ($variations->attributes($id) as $existing) {
            $variations->deleteAttribute($id, $existing->id);
        }
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        if ($wcId > 0) {
            delete_post_meta($wcId, TMC_P44_OWNED_META);
            delete_post_meta($wcId, TMC_P44_CONFLICT_META);
            // The STOREFRONT variations go too, not just the marketplace
            // rows. A projection HIDES a combination the vendor removed
            // rather than deleting it (`alpha.9`), so its SKU stays taken —
            // and the next run's `set_sku('V44-BLUE')` then throws, is
            // caught, and the variation keeps the parent's SKU. Measured:
            // `blue_sku=SKU-ALPHA44-VAR` on the second verb of a pair, which
            // is a fixture spending something it did not give back, not a
            // defect in the build.
            foreach ((array) get_children(['post_parent' => $wcId, 'post_type' => 'product_variation']) as $child) {
                wp_delete_post((int) $child->ID, true);
            }
            $parent = wc_get_product($wcId);
            if ($parent instanceof WC_Product) {
                $parent->set_attributes([]);
                $parent->save();
            }
        }
        $variations->saveAttribute($id, 'colour', 'رنگ', ['آبی', 'سبز'], 0);
        $v1 = $variations->saveVariation($id, new ProductVariation(0, $id, ['colour' => 'آبی'], 210000, null, 'V44-BLUE', 3));
        $v2 = $variations->saveVariation($id, new ProductVariation(0, $id, ['colour' => 'سبز'], 220000, null, 'V44-GREEN', 4));
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $wcV1 = (int) ($variations->findVariation($id, $v1)?->wcVariationId ?? 0);
        $say('product', $id);
        $say('wc', $wcId);
        $say('colour_projected', $valueOf($shopAttributes($wcId), 'رنگ'));
        $say('wc_variation_blue', $wcV1);

        // A manager's own attribute, which must survive either way.
        $parent = wc_get_product($wcId);
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('کشور سازنده');
        $theirs->set_options(['ایران']);
        $theirs->set_visible(true);
        $theirs->set_position(9);
        $parent->set_attributes(array_merge(array_values($shopAttributes($wcId)), [$theirs]));
        $parent->save();

        if ($verb === 'variable-manager-edit') {
            $managerEdits($wcId, 'رنگ', 'آبی | زرد');
            $say('colour_after_manager', $valueOf($shopAttributes($wcId), 'رنگ'));
            $catalog->publish($id);
        } else {
            // CONTROL: the vendor changes the axis and no manager touched it.
            $variations->saveAttribute($id, 'colour', 'رنگ', ['آبی', 'قرمز'], 0);
            $catalog->publish($id);
        }
        $after = $shopAttributes($wcId);
        $say('colour_after_projection', $valueOf($after, 'رنگ'));
        $say('theirs_after', $valueOf($after, 'کشور سازنده'));
        $say('brand_after', $valueOf($after, 'برند'));
        $say('variation_blue_same', (int) ($variations->findVariation($id, $v1)?->wcVariationId ?? 0) === $wcV1 ? 'yes' : 'no');
        $blue = wc_get_product($wcV1);
        $say('blue_price', $blue instanceof WC_Product ? (string) $blue->get_regular_price() : '');
        $say('blue_stock', $blue instanceof WC_Product ? (string) $blue->get_stock_quantity() : '');
        $say('blue_sku', $blue instanceof WC_Product ? (string) $blue->get_sku() : '');
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        break;

    // ======================================= the alpha.43 record, as it stands
    //
    // A product `alpha.43` projected carries a LIST of keys and no record of
    // what it wrote. This verb puts the product in exactly that state and then
    // has the manager edit one of those attributes.
    case 'legacy-record':
        [$id, $wcId] = $simple();
        $current = $shopAttributes($wcId);
        update_post_meta($wcId, TMC_P44_OWNED_META, array_keys($current));   // the alpha.43 shape
        $say('wc', $wcId);
        $say('record_shape', $recordShape($wcId));
        $managerEdits($wcId, 'برند', 'برندی که مدیر نوشته');
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('brand_after_projection', $valueOf($after, 'برند'));
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        break;

    // And the other half of the legacy policy: an untouched alpha.43 product.
    case 'legacy-record-untouched':
        [$id, $wcId] = $simple();
        update_post_meta($wcId, TMC_P44_OWNED_META, array_keys($shopAttributes($wcId)));
        $products->saveSpecs($id, ['material' => 'نیتریل', 'size' => '7'], 1);
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('record_shape', $recordShape($wcId));
        $say('material_after', $valueOf($after, 'جنس'));
        $say('conflicts', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        break;

    // The legacy record UPGRADES ITSELF on the first projection where nothing
    // has moved: every key passes the no-op test, so every key is adopted
    // with a fingerprint — and the vendor's next edit then applies normally.
    // This is the path a product `alpha.43` projected actually takes.
    case 'legacy-record-upgrades':
        [$id, $wcId] = $simple();
        update_post_meta($wcId, TMC_P44_OWNED_META, array_keys($shopAttributes($wcId)));
        $say('wc', $wcId);
        $say('shape_before', $recordShape($wcId));
        $catalog->publish($id);                      // nothing changed by anybody
        $say('shape_after', $recordShape($wcId));
        $say('conflicts_after_upgrade', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        // …and now the vendor's ordinary edit lands.
        $products->saveSpecs($id, ['material' => 'نیتریل', 'size' => '7'], 1);
        $catalog->publish($id);
        $say('material_after_vendor_edit', $valueOf($shopAttributes($wcId), 'جنس'));
        $say('conflicts_after_edit', implode('|', (array) get_post_meta($wcId, TMC_P44_CONFLICT_META, true)));
        break;

    // The manager DELETED one of ours. There is no value to preserve.
    case 'manager-deleted-ours':
        [$id, $wcId] = $simple();
        $wcProduct = wc_get_product($wcId);
        $kept = [];
        foreach ($shopAttributes($wcId) as $attribute) {
            if ($attribute->get_name() !== 'اندازه') {
                $kept[] = $attribute;
            }
        }
        $wcProduct->set_attributes($kept);
        $wcProduct->save();
        $say('wc', $wcId);
        $say('size_after_delete', isset($shopAttributes($wcId)[sanitize_title('اندازه')]) ? 'present' : 'absent');
        $catalog->publish($id);
        $say('size_after_projection', isset($shopAttributes($wcId)[sanitize_title('اندازه')]) ? 'present' : 'absent');
        break;

    case 'reset':
        foreach ([TMC_P44_SKU, TMC_P44_SKU_VAR] as $sku) {
            $id = $findBySku($sku);
            if ($id === 0) {
                continue;
            }
            $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
            if ($wcId > 0) {
                foreach ((array) get_children(['post_parent' => $wcId, 'post_type' => 'product_variation']) as $child) {
                    wp_delete_post((int) $child->ID, true);
                }
                wp_delete_post($wcId, true);
            }
            $products->updateStatus($id, ProductStatus::Archived);
            $say('removed', $id);
        }
        $restoreTemplate();
        break;

    default:
        $say('usage', 'unknown_verb');
}
