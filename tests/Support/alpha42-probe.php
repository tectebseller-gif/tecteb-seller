<?php
/**
 * The `alpha.43` findings, asked of a REAL WooCommerce.
 *
 * Every other round in this repo had to report WooCommerce as `Not Run`,
 * because wordpress.org answers 403 from this environment. This round the
 * built plugin was obtained from the project's own release
 * (`github.com/woocommerce/woocommerce/releases/download/11.0.1/woocommerce.zip`,
 * sha256 8883…494c, recorded in the delivery) and installed on the disposable
 * WordPress, so these numbers come from `WC_Product`, its data store and its
 * post meta — not from a stub.
 *
 * It matters most for §1, because the defect IS WooCommerce's behaviour:
 *
 *     $attributes[ sanitize_title( $attribute->get_name() ) ] = $attribute;
 *
 * A stub that stored the array it was handed could not disagree with the
 * plugin, so the test confirmed the plugin's assumption. Here the keys come
 * back from the shop.
 *
 * Prints ONE key per line, which the shell asserts on (the `alpha.41` rule:
 * a reader taking the rest of the line as the value turned `product=12 x=0`
 * into four failures about a correct site).
 *
 *   wp eval-file tests/Support/alpha42-probe.php <verb>
 *
 * Verbs: attr-keys, attr-change, attr-retire, attr-rename, attr-manager,
 *        attr-collision, attr-repeat, variable-merge, variable-remove,
 *        simultaneous-media, control-*
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
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

const TMC_P43_VENDOR = 8803;
const TMC_P43_CATEGORY = 'tmc-probe-gloves';
const TMC_P43_SKU = 'SKU-ALPHA43';
const TMC_P43_SKU_VAR = 'SKU-ALPHA43-VAR';

$verb = (string) ($args[0] ?? 'attr-keys');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
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
$say('wc_version', defined('WC_VERSION') ? WC_VERSION : (function_exists('WC') ? WC()->version : '?'));
// The «it really ran» marker. A verb that printed nothing executed nothing,
// and a shell that cannot tell those apart reports «reproduced» about a probe
// that fatalled (the `alpha.32` trap).
$say('probe', 'ran');

// ---------------------------------------------------------------- fixtures

/** The template, built through the plugin's own repository. */
$template = static function () use ($templates): int {
    $existing = $templates->findByCategory(TMC_P43_CATEGORY);
    if ($existing !== null) {
        return $existing->id;
    }
    $id = $templates->createTemplate(TMC_P43_CATEGORY, 'دستکش آزمون');
    $templates->addField($id, new SpecField(0, 'material', 'جنس', SpecFieldType::Text, true));
    $templates->addField($id, new SpecField(0, 'size', 'اندازه', SpecFieldType::Text, false, 'اینچ'));
    return $id;
};

$fieldId = static function (int $templateId, string $key) use ($templates): int {
    return $templates->find($templateId)?->field($key)?->id ?? 0;
};

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P43_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $sku, string $brand, string $type = 'simple'): ProductDetails {
    return new ProductDetails(
        title: $type === 'simple' ? 'دستکش آزمون ویژگی' : 'دستکش آزمون تنوع',
        categoryKey: TMC_P43_CATEGORY,
        brand: $brand,
        shortDescription: 'یک جمله',
        priceMinor: 200000,
        sku: $sku,
        stock: 5,
        type: $type,
        description: 'متن کامل فروشنده'
    );
};

/** A published, projected simple product with two answered specs. */
$simple = static function (string $brand = 'آریا طب', array $specs = ['material' => 'لاتکس', 'size' => '7'])
use ($products, $findBySku, $detailsOf, $catalog, $template): array {
    $template();
    $id = $findBySku(TMC_P43_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P43_VENDOR, $detailsOf(TMC_P43_SKU, $brand), ProductStatus::Published);
    } else {
        $products->updateDetails($id, $detailsOf(TMC_P43_SKU, $brand));
    }
    $products->saveSpecs($id, $specs, 1);
    $result = $catalog->publish($id);
    $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
    return [$id, $wcId, $result->code];
};

/** The attributes the SHOP holds, read back from a fresh object. */
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

$describe = static function (array $attributes): string {
    $parts = [];
    foreach ($attributes as $key => $attribute) {
        $parts[] = $attribute->get_name() . ':' . implode('/', (array) $attribute->get_options());
    }
    sort($parts);
    return implode('|', $parts);
};

switch ($verb) {
    // ------------------------------------------------------------- §1 keys
    case 'attr-keys':
        [$id, $wcId, $code] = $simple();
        $attributes = $shopAttributes($wcId);
        $say('product', $id);
        $say('wc', $wcId);
        $say('code', $code);
        $say('keys', implode('|', array_keys($attributes)));
        $say('facts', $describe($attributes));
        // The question `alpha.42` answered wrong. A prefix the plugin passed
        // in cannot be found in what the shop stored.
        $say('prefixed_keys', count(array_filter(
            array_keys($attributes),
            static fn (string $k): bool => str_starts_with($k, 'tmc-')
        )));
        $say('brand_key_is_sanitized_name', isset($attributes[sanitize_title('برند')]) ? 'yes' : 'no');
        break;

    // -------------------------------------------- §1 (a) a changed answer
    case 'attr-change':
        [$id, $wcId] = $simple();
        $products->saveSpecs($id, ['material' => 'نیتریل', 'size' => '7'], 1);
        $catalog->publish($id);
        $attributes = $shopAttributes($wcId);
        $say('wc', $wcId);
        $say('material_after', implode('/', (array) ($attributes[sanitize_title('جنس')]?->get_options() ?? [])));
        $say('attribute_count', count($attributes));
        break;

    // --------------------------------------- §1 (b) a retired specification
    case 'attr-retire':
        [$id, $wcId] = $simple();
        $templateId = $template();
        $before = $shopAttributes($wcId);
        $say('size_before', isset($before[sanitize_title('اندازه')]) ? 'present' : 'absent');
        $templates->deprecateField($fieldId($templateId, 'size'));
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('size_after', isset($after[sanitize_title('اندازه')]) ? 'present' : 'absent');
        $say('material_after', isset($after[sanitize_title('جنس')]) ? 'present' : 'absent');
        $say('keys_after', implode('|', array_keys($after)));
        $templates->restoreField($fieldId($templateId, 'size'));
        break;

    // ------------------------------------------- §1 (c) a renamed label
    case 'attr-rename':
        [$id, $wcId] = $simple();
        $templateId = $template();
        $templates->updateField($fieldId($templateId, 'material'), 'جنس سازنده', true, '', [], 0);
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('old_label_key', isset($after[sanitize_title('جنس')]) ? 'present' : 'absent');
        $say('new_label_key', isset($after[sanitize_title('جنس سازنده')]) ? 'present' : 'absent');
        $say('facts', $describe($after));
        $templates->updateField($fieldId($templateId, 'material'), 'جنس', true, '', [], 0);
        $catalog->publish($id);
        break;

    // ------------------------- §1 (d) the manager's own, under another name
    case 'attr-manager':
        [$id, $wcId] = $simple();
        $wcProduct = wc_get_product($wcId);
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('کشور سازنده');
        $theirs->set_options(['ایران']);
        $theirs->set_visible(true);
        $theirs->set_position(5);
        $wcProduct->set_attributes(array_merge(array_values($wcProduct->get_attributes()), [$theirs]));
        $wcProduct->save();
        $say('theirs_before', isset($shopAttributes($wcId)[sanitize_title('کشور سازنده')]) ? 'present' : 'absent');
        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('theirs_after', isset($after[sanitize_title('کشور سازنده')]) ? 'present' : 'absent');
        $say('theirs_value', implode('/', (array) ($after[sanitize_title('کشور سازنده')]?->get_options() ?? [])));
        $say('facts', $describe($after));
        break;

    // ------------------------ §1 (e) the manager's own, under OUR OWN name
    case 'attr-collision':
        [$id, $wcId] = $simple();
        delete_post_meta($wcId, '_tmc_attribute_keys');
        $wcProduct = wc_get_product($wcId);
        $kept = [];
        foreach ($wcProduct->get_attributes() as $attribute) {
            if ($attribute instanceof WC_Product_Attribute && $attribute->get_name() !== 'برند') {
                $kept[] = $attribute;
            }
        }
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('برند');
        $theirs->set_options(['چیزی که مدیر نوشته']);
        $theirs->set_visible(true);
        $theirs->set_position(7);
        $kept[] = $theirs;
        $wcProduct->set_attributes($kept);
        $wcProduct->save();

        $catalog->publish($id);
        $after = $shopAttributes($wcId);
        $say('brand_value', implode('/', (array) ($after[sanitize_title('برند')]?->get_options() ?? [])));
        $say('conflicts', implode('|', (array) get_post_meta($wcId, '_tmc_attribute_conflicts', true)));
        $say('owned', implode('|', (array) get_post_meta($wcId, '_tmc_attribute_keys', true)));
        $say('material_still_there', isset($after[sanitize_title('جنس')]) ? 'yes' : 'no');
        break;

    // -------------------------------------- §1 (f) the same run again
    case 'attr-repeat':
        [$id, $wcId] = $simple();
        $first = $shopAttributes($wcId);
        $catalog->publish($id);
        $catalog->publish($id);
        $second = $shopAttributes($wcId);
        $say('count_first', count($first));
        $say('count_second', count($second));
        $say('same_keys', array_keys($first) === array_keys($second) ? 'yes' : 'no');
        $say('facts', $describe($second));
        break;

    // ------------------------------------------------ §2 a variable product
    case 'variable-merge':
    case 'variable-remove':
        $template();
        $id = $findBySku(TMC_P43_SKU_VAR);
        if ($id === 0) {
            $id = $products->create(
                TMC_P43_VENDOR,
                $detailsOf(TMC_P43_SKU_VAR, 'آریا طب', 'variable'),
                ProductStatus::Published
            );
        } else {
            $products->updateDetails($id, $detailsOf(TMC_P43_SKU_VAR, 'آریا طب', 'variable'));
        }
        $products->saveSpecs($id, ['material' => 'لاتکس', 'size' => '7'], 1);
        foreach ($variations->variations($id) as $existing) {
            $variations->deleteVariation($id, $existing->id);
        }
        foreach ($variations->attributes($id) as $existing) {
            $variations->deleteAttribute($id, $existing->id);
        }
        $variations->saveAttribute($id, 'colour', 'رنگ', ['آبی', 'سبز'], 0);
        $v1 = $variations->saveVariation($id, new ProductVariation(0, $id, ['colour' => 'آبی'], 210000, null, 'V-BLUE', 3));
        $v2 = $variations->saveVariation($id, new ProductVariation(0, $id, ['colour' => 'سبز'], 220000, null, 'V-GREEN', 4));
        $catalog->publish($id);
        $wcId = (int) ($products->find($id)?->wcProductId ?? 0);
        $say('product', $id);
        $say('wc', $wcId);
        $say('colour_before', implode('/', (array) ($shopAttributes($wcId)[sanitize_title('رنگ')]?->get_options() ?? [])));

        // A manager's own attribute, which must come through all of it.
        $wcProduct = wc_get_product($wcId);
        $theirs = new WC_Product_Attribute();
        $theirs->set_name('کشور سازنده');
        $theirs->set_options(['ایران']);
        $theirs->set_visible(true);
        $theirs->set_position(9);
        $wcProduct->set_attributes(array_merge(array_values($wcProduct->get_attributes()), [$theirs]));
        $wcProduct->save();

        $wcV1 = (int) ($variations->findVariation($id, $v1)?->wcVariationId ?? 0);
        $wcV2 = (int) ($variations->findVariation($id, $v2)?->wcVariationId ?? 0);
        $say('wc_variation_blue', $wcV1);
        $say('wc_variation_green', $wcV2);

        if ($verb === 'variable-merge') {
            // ADD an option and CHANGE one.
            $attributeId = $variations->attributes($id)[0]->id ?? 0;
            $variations->saveAttribute($id, 'colour', 'رنگ', ['آبی', 'زرد', 'قرمز'], 0);
            $variations->saveVariation($id, new ProductVariation($v2, $id, ['colour' => 'زرد'], 230000, null, 'V-GREEN', 4));
            $catalog->publish($id);
            $after = $shopAttributes($wcId);
            $say('colour_after', implode('/', (array) ($after[sanitize_title('رنگ')]?->get_options() ?? [])));
            $say('theirs_after', isset($after[sanitize_title('کشور سازنده')]) ? 'present' : 'absent');
            $say('brand_after', implode('/', (array) ($after[sanitize_title('برند')]?->get_options() ?? [])));
            $say('variation_blue_same', (int) ($variations->findVariation($id, $v1)?->wcVariationId ?? 0) === $wcV1 ? 'yes' : 'no');
            $say('variation_green_same', (int) ($variations->findVariation($id, $v2)?->wcVariationId ?? 0) === $wcV2 ? 'yes' : 'no');
            $blue = wc_get_product($wcV1);
            $say('blue_price', $blue instanceof WC_Product ? (string) $blue->get_regular_price() : '');
            $say('blue_stock', $blue instanceof WC_Product ? (string) $blue->get_stock_quantity() : '');
            $say('blue_sku', $blue instanceof WC_Product ? (string) $blue->get_sku() : '');
        } else {
            // REMOVE the whole axis the vendor no longer offers.
            foreach ($variations->variations($id) as $existing) {
                $variations->deleteVariation($id, $existing->id);
            }
            foreach ($variations->attributes($id) as $existing) {
                $variations->deleteAttribute($id, $existing->id);
            }
            $catalog->publish($id);
            $after = $shopAttributes($wcId);
            $say('colour_after', isset($after[sanitize_title('رنگ')]) ? 'present' : 'absent');
            $say('theirs_after', isset($after[sanitize_title('کشور سازنده')]) ? 'present' : 'absent');
            $say('keys_after', implode('|', array_keys($after)));
        }
        break;

    // ---------------------------------------- §3 a REAL two-process race
    //
    // Run by two OS processes at once (`alpha43-reproduction.sh`), each with
    // its own connection and its own uploaded file, both carrying ONE
    // `create_token`. Unlike `SimultaneousCreateMediaTest` — which schedules
    // the interleaving and says so — this one is raced for, so it cannot
    // promise the window was entered on any given run. It therefore prints
    // WHICH PATH it took, and the shell asserts the property that must hold
    // on both: no attachment is left that nothing uses.
    case 'simultaneous-media':
        $token = (string) ($args[1] ?? '');
        if ($token === '') {
            $say('usage', 'simultaneous-media_needs_a_token');
            break;
        }
        (new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
            ->upsertProfile(TMC_P43_VENDOR, 'داروخانهٔ رقابت', true, false);
        wp_set_current_user(TMC_P43_VENDOR);
        $template();

        $tempFile = (string) tempnam(sys_get_temp_dir(), 'tmc-race-');
        file_put_contents($tempFile, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        ));
        $before = array_map('intval', get_posts([
            'post_type' => 'attachment',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => TMC_P43_VENDOR,
        ]));

        $_POST = [
            'tmc_vendor_action' => 'save_product',
            'product_id' => '0',
            'step' => '1',
            'revision' => '',
            'create_token' => $token,
            'title' => 'محصول رقابت ' . $token,
            'type' => 'simple',
            'category' => TMC_P43_CATEGORY,
            'brand' => 'آریا طب',
            'short_description' => 'یک جمله',
            'description' => 'متن کامل',
            'description_base' => 'none',
            'price' => '200000',
            'sale_price' => '',
            'sale_from' => '',
            'sale_to' => '',
            'sku' => '',
            'stock' => '5',
            'min_purchase' => '1',
            'max_purchase' => '',
            'weight_grams' => '0',
            'dimensions' => '',
            'tax_class' => '',
            'main_image_id' => '0',
        ];
        $_FILES = ['product_image' => [
            'name' => 'race.png',
            'tmp_name' => $tempFile,
            'size' => (int) filesize($tempFile),
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
        ]];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
            home_url('/vendor/'),
            home_url('/vendor/application/'),
            home_url('/vendor/store/'),
            home_url('/vendor/staff/'),
            home_url('/vendor/invite/'),
            home_url('/vendor/products/')
        );
        $outcome = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))
            ->handle('save_product', \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(), TMC_P43_VENDOR, $urls);
        $after = array_map('intval', get_posts([
            'post_type' => 'attachment',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => TMC_P43_VENDOR,
        ]));
        $mine = array_values(array_diff($after, $before));
        $say('pid', getmypid());
        $say('code', $outcome?->code ?? 'none');
        $say('uploaded_now', implode('/', $mine));
        $say('discarded', (int) ($outcome?->context['discarded_uploads'] ?? 0));
        $say('path', $outcome?->code === 'product_created_replayed'
            ? ($mine === [] ? 'sequential_replay_no_upload' : 'simultaneous_lost_after_upload')
            : 'created');
        if (is_file($tempFile)) {
            unlink($tempFile);
        }
        break;

    case 'race-report':
        // What the site holds after a race: how many products THIS token made,
        // and whether the vendor owns any attachment that nothing uses.
        //
        // The token is part of the title, so a leftover product from an
        // earlier run cannot be counted as this one's — the first version of
        // this verb counted every race ever run and reported «12 products for
        // one token» about a correct site. The orphan question is asked of the
        // WHOLE shop, because an attachment used by any product of this vendor
        // is used.
        $token = (string) ($args[1] ?? '');
        $rows = [];
        $used = [];
        foreach ($products->forVendor(TMC_P43_VENDOR) as $product) {
            foreach ($products->images($product->id) as $mediaId) {
                $used[(int) $mediaId] = true;
            }
            if ($product->mainImageId > 0) {
                $used[$product->mainImageId] = true;
            }
            if ($token !== '' && str_contains($product->details->title, $token)) {
                $rows[] = $product->id;
            }
        }
        $say('products', implode('/', $rows));
        $say('product_count', count($rows));
        $owned = array_map('intval', get_posts([
            'post_type' => 'attachment',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => TMC_P43_VENDOR,
        ]));
        $orphans = array_values(array_filter($owned, static fn (int $id): bool => !isset($used[$id])));
        $say('attachments', count($owned));
        $say('used', count($used));
        $say('orphans', implode('/', $orphans));
        $say('orphan_count', count($orphans));
        break;

    case 'race-reset':
        // Gives back everything the race spent: the gallery rows first (so
        // nothing points at an attachment about to go), then the attachments,
        // then the rows. A fixture that leaves work behind makes the next run
        // a measurement of the last one.
        foreach ($products->forVendor(TMC_P43_VENDOR) as $product) {
            $products->saveImages($product->id, [], 0);
        }
        $removed = 0;
        foreach (array_map('intval', get_posts([
            'post_type' => 'attachment',
            'numberposts' => -1,
            'fields' => 'ids',
            'author' => TMC_P43_VENDOR,
        ])) as $mediaId) {
            if (wp_delete_attachment($mediaId, true) !== false) {
                $removed++;
            }
        }
        $say('attachments_removed', $removed);
        $say('race_reset', 'done');
        break;

    // --------------------------------------------------------- the controls
    case 'control-not-ours':
        // A product this plugin does not own is never written to — the oldest
        // guarantee in the projector, re-asked because this round changes
        // what a projection writes.
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
        $after = $shopAttributes($foreignId);
        $say('foreign_wc', $foreignId);
        $say('foreign_brand', implode('/', (array) ($after[sanitize_title('برند')]?->get_options() ?? [])));
        $say('foreign_owned_meta', (string) get_post_meta($foreignId, '_tmc_attribute_keys', true));
        $say('foreign_count', count($after));
        wp_delete_post($foreignId, true);
        break;

    case 'control-description':
        // §6: the vendor's long description is still their own text and the
        // facts are not glued into it.
        [$id, $wcId] = $simple();
        $wcProduct = wc_get_product($wcId);
        $say('description', trim((string) $wcProduct->get_description()));
        $say('description_has_brand', str_contains((string) $wcProduct->get_description(), 'آریا طب') ? 'yes' : 'no');
        break;

    case 'reset':
        foreach ([TMC_P43_SKU, TMC_P43_SKU_VAR] as $sku) {
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
        break;

    default:
        $say('usage', 'unknown_verb');
}
