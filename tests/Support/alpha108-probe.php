<?php
/**
 * §17 scenario one, walked on the bytes that are installed — not read.
 *
 * The owner's mandate states its own definition of success: a vendor makes a
 * variable product, defines «تعداد در بسته» with three values, ticks «استفاده
 * برای ساخت تنوع», gets three variations, prices them, saves, reloads, sees
 * them in WooCommerce, and a buyer picks one and adds it to the cart. §5 then
 * says: «برای هر باگ، ابتدا تست بازتولید بساز و نشان بده که بدون اصلاح شکست
 * می‌خورد.»
 *
 * So this walks that scenario through the plugin's OWN services and prints
 * what each step actually produced. Every claim in the diagnosis report comes
 * from a line this prints.
 *
 * The one thing it adds to the scenario is a SECOND attribute — «جنس» with
 * «پلاستیک» and «فلز» — declared as a fact about the product and not as an
 * axis, because that is the case §3 asks for («اگر «استفاده برای ساخت تنوع»
 * خاموش باشد، این ویژگی فقط مشخصه محصول است و نباید بی‌دلیل ترکیب جدید
 * بسازد») and the case `WooCommerceProjector::wcAttributes()` has no way to
 * express.
 *
 * ⛔ **Nothing on staging or production is touched.** It refuses to run unless
 * `home_url()` is local, builds its own fixture, and `reset` gives back every
 * row, post, term, attachment and user it created.
 *
 * It loads on BOTH trees (`alpha.32`): everything this round may add is asked
 * for with `method_exists`/`property_exists` and reported `__absent__` on the
 * base, so the same script measures before and after.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 */

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Security\CapabilityCheckerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

// wp-cli hands `eval-file` its extra arguments as `$args`. Derived from
// `$argv` as a fallback by taking what follows THIS file's own path, because
// `array_slice($argv, 1)` starts at `--allow-root` and a probe that silently
// reads a global flag as its verb prints the usage line and measures nothing
// — which is how this script spent its first run.
if (!isset($args) || !is_array($args)) {
    $argv = $GLOBALS['argv'] ?? [];
    $at = array_search(__FILE__, $argv, true);
    if ($at === false) {
        foreach ($argv as $i => $a) {
            if (is_string($a) && str_ends_with($a, basename(__FILE__))) {
                $at = $i;
                break;
            }
        }
    }
    $args = $at === false ? [] : array_values(array_slice($argv, (int) $at + 1));
}

if (!defined('ABSPATH')) {
    echo "refused=1 reason=not_inside_wordpress\n";
    return;
}
$home = (string) home_url();
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', $home)) {
    echo "refused=1 reason=home_url_is_not_local home={$home}\n";
    return;
}

$FIXTURE = 'tmc_p108_fixture';
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $value = (string) $value;
    echo $key . '=' . ($value === '' ? '__empty__' : $value) . "\n";
};

$command = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$manager = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($manager);
$c->bind(CapabilityCheckerInterface::class, static function () use ($manager) {
    return new class ($manager) implements CapabilityCheckerInterface {
        public function __construct(private $id)
        {
        }
        public function can(string $capability): bool
        {
            return in_array($capability, Capabilities::all(), true);
        }
        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
});

$fixture = static function () use ($FIXTURE): array {
    $raw = get_option($FIXTURE, []);
    $base = ['vendor' => 0, 'product' => 0, 'attrs' => [], 'variations' => [], 'media' => []];
    return is_array($raw) ? $raw + $base : $base;
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);
/** @var VariationRepositoryInterface $variations */
$variations = $c->get(VariationRepositoryInterface::class);
/** @var ManageVariations $varService */
$varService = $c->get(ManageVariations::class);
/** @var ManageProducts $productService */
$productService = $c->get(ManageProducts::class);

// ---------------------------------------------------------------- the fixture

$arrange = static function () use ($c, $products, $productService, $manager, $fixture, $remember, $say): void {
    $f = $fixture();

    if ($f['vendor'] <= 0) {
        $login = 'p108vendor';
        $id = (int) (get_user_by('login', $login)->ID ?? 0);
        if ($id <= 0) {
            $id = (int) wp_insert_user([
                'user_login' => $login,
                'user_pass' => wp_generate_password(20),
                'user_email' => 'p108vendor@example.invalid',
                'role' => 'subscriber',
            ]);
        }
        $f['vendor'] = $id;
    }
    // Approved through the DOCUMENTED path and never by writing a status
    // column: the service is the only thing that may admit a shop, and a row
    // written directly leaves a shop that is «approved» and cannot sell
    // (`alpha.17`).
    /** @var VendorRepositoryInterface $vendors */
    $vendors = $c->get(VendorRepositoryInterface::class);
    if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
        $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
            'فروشگاه آزمون ۱۰۸',
            'شرکت آزمون ۱۰۸',
            'p108vendor@example.invalid',
            '09120000108',
            'تهران، خیابان آزمایش، پلاک ۱۰۸',
            true
        ));
        $vendors->updateStatus(
            (int) $vendors->findApplicationByUser((int) $f['vendor'])->id,
            ApplicationStatus::Submitted
        );
    }
    $application = $vendors->findApplicationByUser((int) $f['vendor']);
    if ($application !== null && $application->status !== ApplicationStatus::Approved) {
        $c->get(ReviewApplication::class)->approve($application->id);
    }
    $say('vendor_can_sell', $vendors->findProfileByUser((int) $f['vendor'])?->canSell ?? '__none__');
    $say('vendor', $f['vendor']);

    if ($f['product'] <= 0) {
        // `save()` with product id 0 is the create path — `create()` itself is
        // private, which is the service telling callers there is one door.
        $result = $productService->save(
            $f['vendor'],
            $f['vendor'],
            0,
            new ProductDetails(
                title: 'سرسوزن آزمون ۱۰۸',
                type: ProductType::VARIABLE,
                priceMinor: 0,
                sku: 'P108-PARENT',
                stock: 0,
                shortDescription: 'محصول متغیر آزمونی',
                description: 'این محصول فقط برای اندازه‌گیری مسیر محصول متغیر ساخته شده است.'
            ),
            [],
            [],
            0,
            '',
            'p108-create-token'
        );
        $say('create_code', $result->code);
        $f['product'] = (int) ($result->context['product_id'] ?? 0);
    }
    $say('product', $f['product']);
    $product = $products->find($f['product']);
    $say('product_type', $product?->details->type ?? '__none__');
    $remember($f);
};

// ------------------------------------------------- §3: the two kinds of axis

$attributes = static function () use ($varService, $variations, $fixture, $remember, $say): void {
    $f = $fixture();
    if ($f['product'] <= 0) {
        $say('refused', 'arrange_first');
        return;
    }

    // The axis the scenario names. `saveAttribute()`'s signature is read with
    // reflection so this script runs on both trees: a round that adds the two
    // flags adds parameters, and a probe that passed them positionally would
    // simply fail to load on the base (`alpha.32`).
    $arity = (new ReflectionMethod(ManageVariations::class, 'saveAttribute'))
        ->getNumberOfParameters();
    $say('save_attribute_parameters', $arity);
    $say('attribute_flags_supported', $arity > 6);

    $call = static function (array $positional) use ($varService) {
        return $varService->saveAttribute(...$positional);
    };

    $axis = $call([$f['vendor'], $f['vendor'], $f['product'], 'pack-count', 'تعداد در بسته',
        ['۵۰ عددی', '۱۰۰ عددی', '۲۰۰ عددی']]);
    $say('axis_code', $axis->code);

    // The FACT. §3: «اگر «استفاده برای ساخت تنوع» خاموش باشد، این ویژگی فقط
    // مشخصه محصول است». There is no way to say that on the base tree.
    // On a tree that HAS the flags, the fact is saved as a fact: visible on
    // the product page, not an axis. On the base tree the two extra arguments
    // do not exist and the same call is made without them, so this one script
    // measures both — which is the only way «before» and «after» can be the
    // same measurement rather than two different ones.
    $factArgs = [$f['vendor'], $f['vendor'], $f['product'], 'material', 'جنس', ['پلاستیک', 'فلز']];
    if ($arity > 6) {
        $factArgs[] = true;     // listed among the product's properties
        $factArgs[] = false;    // NOT an axis
    }
    $fact = $call($factArgs);
    $say('fact_code', $fact->code);
    $say('fact_key_in_answer', $fact->context['key'] ?? '__absent__');
    $say('fact_role_in_answer', $fact->context['variation'] ?? '__absent__');

    $rows = $variations->attributes($f['product']);
    $say('attribute_rows', count($rows));
    foreach ($rows as $row) {
        $say('attr_' . $row->key . '_options', $row->options);
        $say('attr_' . $row->key . '_visible', property_exists($row, 'visible') ? $row->visible : '__absent__');
        $say('attr_' . $row->key . '_variation', property_exists($row, 'variation') ? $row->variation : '__absent__');
    }
    $f['attrs'] = array_map(static fn ($r) => $r->key, $rows);
    $remember($f);
};

// ------------------------------------------- §4: does a generate verb exist?

$generate = static function () use ($varService, $variations, $fixture, $remember, $say): void {
    $f = $fixture();
    $say('build_verb_exists', method_exists($varService, 'buildVariations'));
    $say('preview_verb_exists', method_exists($varService, 'previewCombinations'));

    if (method_exists($varService, 'buildVariations')) {
        $built = $varService->buildVariations($f['vendor'], $f['vendor'], $f['product']);
        $say('build_code', $built->code);
        $say('build_data', $built->context);
    } else {
        // The base tree: the vendor has to post the bottom form once per
        // combination. Measured, so «it is only tedious» is a fact and not
        // an opinion.
        foreach ([['۵۰ عددی', 120000], ['۱۰۰ عددی', 220000], ['۲۰۰ عددی', 400000]] as [$option, $price]) {
            $r = $varService->saveVariation(
                $f['vendor'], $f['vendor'], $f['product'],
                ['pack-count' => $option],
                $price, null, 'P108-' . substr(md5($option), 0, 6), 7
            );
            $say('manual_' . substr(md5($option), 0, 4) . '_code', $r->code);
        }
    }
    $rows = $variations->variations($f['product']);
    $say('variation_rows', count($rows));
    foreach ($rows as $row) {
        $say('var_' . $row->id . '_combination', $row->combination());
        $say('var_' . $row->id . '_price', $row->priceMinor);
        $say('var_' . $row->id . '_stock', $row->stock);
    }
    $f['variations'] = array_map(static fn ($r) => $r->id, $rows);
    $remember($f);
};

// ------------------------------------------- §5: can one variation be edited?

$editOne = static function () use ($varService, $variations, $fixture, $say): void {
    $f = $fixture();
    $rows = $variations->variations($f['product']);
    if ($rows === []) {
        $say('refused', 'generate_first');
        return;
    }
    $one = $rows[0];
    $say('target_variation', $one->id);
    $say('price_before', $one->priceMinor);

    // Is there a verb that edits ONE variation by its id? On the base the
    // only way in is `saveVariation()` with the whole combination retyped,
    // which is what the form offers.
    $say('edit_one_verb_exists', method_exists($varService, 'editVariation'));

    $r = $varService->saveVariation(
        $f['vendor'], $f['vendor'], $f['product'],
        $one->attributes,
        $one->priceMinor + 5000, null, $one->sku, $one->stock
    );
    $say('resave_code', $r->code);
    $after = $variations->findVariation($f['product'], $one->id);
    $say('price_after', $after?->priceMinor ?? '__gone__');
    $say('rows_after', count($variations->variations($f['product'])));

    // The fields §5 asks for, against what the row can hold.
    foreach (['salePriceMinor', 'saleFrom', 'saleTo', 'weight', 'length', 'width',
              'height', 'shippingClass', 'description', 'manageStock', 'backorders'] as $field) {
        $say('row_field_' . $field, property_exists($one, $field) ? 'present' : '__absent__');
    }
};

// ------------------------------- the paperwork a submit needs, filled honestly

$paperwork = static function () use ($c, $products, $productService, $fixture, $remember, $say): void {
    $f = $fixture();
    if ($f['product'] <= 0) {
        $say('refused', 'arrange_first');
        return;
    }
    $term = term_exists('آزمون ۱۰۸', 'product_cat');
    $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;
    if ($termId <= 0) {
        $created = wp_insert_term('آزمون ۱۰۸', 'product_cat');
        $termId = is_array($created) ? (int) $created['term_id'] : 0;
    }
    $say('category_term', $termId);

    // A real attachment, because `missingFields()` is satisfied by an image
    // id and `imageIds === []` is its own refusal.
    $mediaId = (int) ($f['media'][0] ?? 0);
    if ($mediaId <= 0) {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $upload = wp_upload_bits('p108.png', null, $png);
        if (empty($upload['error'])) {
            // ⛔ `post_author` IS THE OWNERSHIP. `ManageProducts` drops any
            // image id `ProductImageLibrary::ownedBy()` refuses, so an
            // attachment with no author silently does not attach and the
            // product stays «بدون تصویر» — which measured as `row_images=0`
            // against a save that said `product_saved`.
            $mediaId = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'p108',
                'post_status' => 'inherit',
                'post_author' => (int) $f['vendor'],
            ], $upload['file']);
            require_once ABSPATH . 'wp-admin/includes/image.php';
            wp_update_attachment_metadata($mediaId, wp_generate_attachment_metadata($mediaId, $upload['file']));
            // `ownedBy()` wants BOTH the author and this meta, so an image
            // uploaded any other way is dropped from the save in silence.
            update_post_meta($mediaId, WpProductImages::OWNER_META, (int) $f['vendor']);
            $f['media'] = [$mediaId];
        }
    }
    $say('media', $mediaId);

    $product = $products->find($f['product']);
    $d = $product->details;
    // ⛔ THE PARENT PRICE IS REQUIRED, measured not assumed: `missingFields()`
    // names `price` for a variable product whose combinations are ours, so a
    // vendor must type a parent figure that WooCommerce then ignores in
    // favour of the children's. Reported as a finding rather than worked
    // around silently.
    $r = $productService->save(
        $f['vendor'],
        $f['vendor'],
        $f['product'],
        new ProductDetails(
            title: $d->title,
            type: $d->type,
            priceMinor: 100000,
            sku: $d->sku,
            stock: 0,
            shortDescription: $d->shortDescription,
            description: $d->description,
            categoryKey: (string) $termId,
            categoryIds: [$termId]
        ),
        [],
        [$mediaId],
        $mediaId,
        // The optimistic lock (`alpha.14`): the form carries the row version
        // it rendered and a save without it is refused. A probe that omitted
        // it got `revision_missing` and would have looked like a broken save.
        $product->rowVersion
    );
    $say('paperwork_code', $r->code);
    $say('paperwork_context', $r->context);
    $after = $products->find($f['product']);
    $say('row_price', $after?->details->priceMinor ?? 0);
    $say('row_category', $after?->details->categoryKey ?? '__none__');
    $say('row_images', count($after?->imageIds ?? []));
    $say('missing_now', $after === null ? '__none__' : $after->details->missingFields());
    $remember($f);
};

// ------------------------------- §6/§9: what WooCommerce ends up holding

$project = static function () use ($c, $products, $productService, $fixture, $remember, $say): void {
    $f = $fixture();
    /** @var SyncCatalog $sync */
    $sync = $c->get(SyncCatalog::class);
    $before = $products->find($f['product']);
    $say('wc_before', $before?->wcProductId ?? 0);

    // The REAL path a product reaches a buyer by: the vendor submits, the
    // manager approves, and approval is what projects and publishes. Calling
    // `prepare()` instead would measure the before-approval mirror and say
    // nothing about what a buyer sees.
    $submit = $productService->submit($f['vendor'], $f['vendor'], $f['product']);
    $say('submit_code', $submit->code);
    $say('submit_context', $submit->context);
    /** @var ReviewProducts $review */
    $review = $c->get(ReviewProducts::class);
    $approved = $review->approve($f['product']);
    $say('approve_code', $approved->code);
    $say('approve_context', $approved->context);
    $after = $products->find($f['product']);
    $wcId = (int) ($after?->wcProductId ?? 0);
    $say('wc_product', $wcId);
    if ($wcId <= 0) {
        return;
    }
    $f['wc'] = $wcId;
    $remember($f);

    clean_post_cache($wcId);
    $wc = wc_get_product($wcId);
    $say('wc_class', $wc === false ? '__none__' : get_class($wc));
    $say('wc_type', $wc === false ? '__none__' : $wc->get_type());
    if (!$wc instanceof WC_Product) {
        return;
    }
    $say('wc_status', $wc->get_status());

    // ⛔ THE MEASUREMENT THIS WHOLE PROBE EXISTS FOR.
    foreach ($wc->get_attributes() as $key => $attribute) {
        if (!$attribute instanceof WC_Product_Attribute) {
            continue;
        }
        $name = (string) $attribute->get_name();
        $say('wcattr_' . $key . '_name', $name);
        $say('wcattr_' . $key . '_visible', $attribute->get_visible());
        $say('wcattr_' . $key . '_variation', $attribute->get_variation());
        $say('wcattr_' . $key . '_options', array_values((array) $attribute->get_options()));
    }
    $say('wc_variation_attribute_count', count(array_filter(
        $wc->get_attributes(),
        static fn ($a) => $a instanceof WC_Product_Attribute && $a->get_variation()
    )));

    $children = $wc instanceof WC_Product_Variable ? $wc->get_children() : [];
    $say('wc_children', count($children));
    foreach ($children as $childId) {
        $child = wc_get_product($childId);
        if (!$child instanceof WC_Product_Variation) {
            continue;
        }
        $say('child_' . $childId . '_attributes', $child->get_attributes());
        $say('child_' . $childId . '_price', $child->get_regular_price());
        $say('child_' . $childId . '_stock', $child->get_stock_quantity());
        $say('child_' . $childId . '_purchasable', $child->is_purchasable());
    }
    $say('parent_purchasable', $wc->is_purchasable());
    $say('parent_price_html_has_range', str_contains((string) $wc->get_price_html(), '&ndash;')
        || str_contains((string) $wc->get_price_html(), '–'));
    $say('permalink', get_permalink($wcId));
};

// ------------------------------------------------- §9/§10: the buyer's side

$buy = static function () use ($fixture, $say): void {
    $f = $fixture();
    $wcId = (int) ($f['wc'] ?? 0);
    if ($wcId <= 0) {
        $say('refused', 'project_first');
        return;
    }
    $wc = wc_get_product($wcId);
    if (!$wc instanceof WC_Product_Variable) {
        $say('refused', 'parent_not_variable');
        return;
    }
    // What the selector would demand of the buyer, from WooCommerce's own
    // answer rather than from the template.
    $say('selector_axes', array_keys($wc->get_variation_attributes()));
    $say('selector_axis_options', $wc->get_variation_attributes());

    $available = $wc->get_available_variations();
    $say('available_variations', count($available));
    $first = $available[0] ?? null;
    if (is_array($first)) {
        $say('first_variation_id', $first['variation_id'] ?? 0);
        $say('first_variation_attributes', $first['attributes'] ?? []);
        $say('first_variation_in_stock', $first['is_in_stock'] ?? null);
        $say('first_variation_purchasable', $first['is_purchasable'] ?? null);
    }

    // The data layer of «add to cart»: find the child for one chosen value.
    $table = new WC_Product_Data_Store_CPT();
    $match = $table->find_matching_product_variation($wc, ['attribute_' . 'تعداد-در-بسته' => '۱۰۰ عددی']);
    $say('match_by_persian_key', $match);
    $encoded = 'attribute_' . sanitize_title('تعداد در بسته');
    $match2 = $table->find_matching_product_variation($wc, [$encoded => '۱۰۰ عددی']);
    $say('match_by_sanitised_key', $match2);
    $say('sanitised_key', $encoded);
};

$reset = static function () use ($c, $products, $variations, $fixture, $FIXTURE, $say): void {
    $f = $fixture();
    $wcId = (int) ($f['wc'] ?? 0);
    if ($wcId > 0) {
        $wc = wc_get_product($wcId);
        if ($wc instanceof WC_Product_Variable) {
            foreach ($wc->get_children() as $child) {
                wp_delete_post($child, true);
            }
        }
        wp_delete_post($wcId, true);
        $say('wc_deleted', $wcId);
    }
    if ($f['product'] > 0) {
        global $wpdb;
        foreach (['tmc_product_variations', 'tmc_product_attributes'] as $t) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$t} WHERE product_id = %d", $f['product']));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}tmc_products WHERE id = %d", $f['product']));
        $say('product_deleted', $f['product']);
    }
    if ($f['vendor'] > 0) {
        global $wpdb;
        foreach (['tmc_vendor_profiles', 'tmc_vendor_settings', 'tmc_vendor_applications'] as $t) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$t} WHERE vendor_user_id = %d", $f['vendor']));
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($f['vendor']);
        $say('vendor_deleted', $f['vendor']);
    }
    delete_option($FIXTURE);
    $say('reset', 'done');
};

switch ($command) {
    case 'arrange':     $arrange(); break;
    case 'attributes':  $attributes(); break;
    case 'generate':    $generate(); break;
    case 'edit-one':    $editOne(); break;
    case 'paperwork':   $paperwork(); break;
    case 'project':     $project(); break;
    case 'buy':         $buy(); break;
    case 'reset':       $reset(); break;
    default:
        $say('usage', 'arrange|attributes|generate|edit-one|paperwork|project|buy|reset');
}
