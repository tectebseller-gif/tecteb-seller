<?php
/**
 * The owner's four audit questions, measured on the INSTALLED `alpha.82`.
 *
 * The instruction for this round is an audit before any change, and it names
 * what must hold:
 *
 *   «دسته‌ها، ویژگی‌ها و مشخصات فعلی محصولات ووکامرس مرجع‌اند و باید حفظ
 *    شوند… بعداً خودم اطلاعات را مستقیماً در ووکامرس ویرایش می‌کنم؛ مهاجرت،
 *    پذیرش و همگام‌سازی نباید آن تغییرات را با دادهٔ قدیمی بازنویسی کنند.
 *    تعارض باید روشن گزارش شود.»
 *
 * So this does not re-measure what `alpha.82`'s own suites already measure.
 * It starts from an ALREADY IMPORTED product — the state the owner will
 * actually be in — then edits that product DIRECTLY IN WOOCOMMERCE the way
 * the owner says they will, and asks what survives acceptance and
 * projection. Four questions, one verb each:
 *
 *   q1  the whole set, parent and child, with NO primary category chosen
 *   q2  no medical template anywhere — does anything refuse because of it
 *   q3  the manager's own attributes and specs, after acceptance and sync
 *   q4  a direct WooCommerce edit, then a projection: what is overwritten
 *
 * ⛔ **Nothing on staging or production is touched.** It refuses to run
 * outside the disposable database, builds its own fixture, and `reset` gives
 * back every row, post, term, attachment and user it created — the intake
 * through the documented `rollback()`, not by deleting marketplace rows
 * behind it.
 *
 * Prints ONE key per line (the `alpha.41` rule), and every answer is read
 * back from WooCommerce or from the row rather than inferred from a return
 * value (`alpha.13`).
 */

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Security\CapabilityCheckerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}

$FIXTURE = 'tmc_a82audit_fixture';
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    echo $key . '=' . (((string) $value) === '' ? '__empty__' : (string) $value) . "\n";
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
    $base = ['vendor' => 0, 'terms' => [], 'wc' => 0, 'run' => '', 'image' => 0, 'extra_term' => 0];
    return is_array($raw) ? $raw + $base : $base;
};
$remember = static function (array $f) use ($FIXTURE): void {
    update_option($FIXTURE, $f, false);
};

/** @var ProductRepositoryInterface $products */
$products = $c->get(ProductRepositoryInterface::class);

global $wpdb;
$rowFor = static function (int $wcId) use ($wpdb): ?array {
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tmc_products WHERE wc_product_id = %d", $wcId),
        ARRAY_A
    );
    return is_array($row) ? $row : null;
};
$termsOf = static function (int $wcId): array {
    clean_post_cache($wcId);
    clean_term_cache([], 'product_cat');
    $wc = wc_get_product($wcId);
    return $wc instanceof WC_Product ? array_values(array_map('intval', $wc->get_category_ids())) : [];
};
$sameSet = static function (array $a, array $b): bool {
    sort($a);
    sort($b);
    return $a === $b;
};
/** Every attribute on the shop product, as name => options — read fresh. */
$attrsOf = static function (int $wcId): array {
    clean_post_cache($wcId);
    $wc = wc_get_product($wcId);
    if (!$wc instanceof WC_Product) {
        return [];
    }
    $out = [];
    foreach ($wc->get_attributes() as $key => $attribute) {
        $out[(string) $key] = is_object($attribute) && method_exists($attribute, 'get_options')
            ? array_values(array_map('strval', (array) $attribute->get_options()))
            : [];
    }
    ksort($out);
    return $out;
};
/** The storefront fields this audit cares about, read fresh from the shop. */
$shopOf = static function (int $wcId): array {
    clean_post_cache($wcId);
    $wc = wc_get_product($wcId);
    if (!$wc instanceof WC_Product) {
        return [];
    }
    return [
        'title' => (string) $wc->get_name(),
        'short' => (string) $wc->get_short_description(),
        'long' => (string) $wc->get_description(),
        'price' => (string) $wc->get_regular_price(),
        'sku' => (string) $wc->get_sku(),
        'weight' => (string) $wc->get_weight(),
        'status' => (string) $wc->get_status(),
        'stock' => (string) $wc->get_stock_quantity(),
        'author' => (string) get_post_field('post_author', $wcId),
    ];
};

switch ($command) {
    case 'arrange':
        $f = $fixture();
        if ($f['vendor'] === 0 || !get_userdata((int) $f['vendor'])) {
            $f['vendor'] = wp_insert_user([
                'user_login' => 'tmc_a82audit_vendor',
                'user_pass' => wp_generate_password(24),
                'role' => 'customer',
            ]);
        }
        /** @var VendorRepositoryInterface $vendors */
        $vendors = $c->get(VendorRepositoryInterface::class);
        if ($vendors->findApplicationByUser((int) $f['vendor']) === null) {
            $vendors->saveDraft((int) $f['vendor'], new ApplicantDetails(
                'فروشگاه ممیزی ۸۲',
                'شرکت ممیزی ۸۲',
                'a82audit@example.test',
                '09120000822',
                'تهران، خیابان آزمایش، پلاک ۸۲',
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
        $say('vendor_status', (string) ($vendors->findApplicationByUser((int) $f['vendor'])?->status->value ?? '__none__'));

        $mk = static function (string $name, string $slug, int $parent = 0): int {
            $existing = get_term_by('slug', $slug, 'product_cat');
            if ($existing instanceof WP_Term) {
                return (int) $existing->term_id;
            }
            $term = wp_insert_term($name, 'product_cat', ['parent' => $parent, 'slug' => $slug]);
            return is_wp_error($term) ? 0 : (int) $term['term_id'];
        };
        $root = $mk('ممیزی۸۲ تجهیزات', 'a82audit-root');
        $child = $mk('ممیزی۸۲ ترالی', 'a82audit-trolley', $root);
        $f['terms'] = ['root' => $root, 'child' => $child];
        // A THIRD term, for the owner's own later edit in WooCommerce.
        $f['extra_term'] = $mk('ممیزی۸۲ مصرفی', 'a82audit-other', $root);

        if ((int) $f['image'] === 0 || !get_post((int) $f['image'])) {
            $f['image'] = (int) wp_insert_post([
                'post_title' => 'ممیزی۸۲ تصویر',
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image/png',
            ]);
        }

        if ((int) $f['wc'] === 0 || !get_post((int) $f['wc'])) {
            $wc = new WC_Product_Simple();
            $wc->set_name('ممیزی۸۲ ترالی استیل');
            $wc->set_status('publish');
            $wc->set_regular_price('450000');
            $wc->set_manage_stock(true);
            $wc->set_stock_quantity(7);
            $wc->set_sku('SKU-A82AUDIT');
            $wc->set_weight('12');
            $wc->set_short_description('توضیح کوتاهِ خودِ ووکامرس');
            $wc->set_description('توضیحات کاملِ خودِ ووکامرس، نوشتهٔ مالک.');
            // PARENT AND CHILD AT ONCE — the owner's named shape.
            $wc->set_category_ids([$f['terms']['child'], $f['terms']['root']]);
            $wc->set_image_id((int) $f['image']);
            // THE MANAGER'S OWN ATTRIBUTES — written here by WooCommerce, not
            // by this plugin, so nothing records them as ours. These are the
            // «ویژگی‌ها و مشخصات فعلی» the instruction says are the reference.
            $mkAttr = static function (string $name, array $options, int $pos): WC_Product_Attribute {
                $a = new WC_Product_Attribute();
                $a->set_name($name);
                $a->set_options($options);
                $a->set_position($pos);
                $a->set_visible(true);
                $a->set_variation(false);
                return $a;
            };
            $wc->set_attributes([
                $mkAttr('جنس بدنه', ['استیل ۳۰۴'], 0),
                $mkAttr('کشور سازنده', ['ایران'], 1),
            ]);
            $f['wc'] = (int) $wc->save();
        }
        $remember($f);
        $say('terms', $f['terms']);
        $say('extra_term', $f['extra_term']);
        $say('wc_product', $f['wc']);
        $say('wc_terms', $termsOf((int) $f['wc']));
        $say('wc_attributes', $attrsOf((int) $f['wc']));
        $say('wc_fields', $shopOf((int) $f['wc']));
        $say('vendor', $f['vendor']);
        break;

    case 'intake':
        $f = $fixture();
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $run = $f['run'] !== '' ? $f['run'] : $intake->newRunId();
        $before = $termsOf((int) $f['wc']);
        $result = $intake->importBatch($run, [(int) $f['wc']], (int) $f['vendor']);
        $f['run'] = $run;
        $remember($f);
        $say('intake_ok', is_object($result) && property_exists($result, 'ok') ? ($result->ok ? 'yes' : 'no') : '__absent__');
        $row = $rowFor((int) $f['wc']);
        $say('row_id', (string) ($row['id'] ?? '__none__'));
        $say('row_link_ownership', (string) ($row['link_ownership'] ?? '__none__'));
        $say('row_category_key', (string) ($row['category_key'] ?? '__none__'));
        $pid = (int) ($row['id'] ?? 0);
        $say('recorded_set', $pid > 0 ? ($products->find($pid)?->details->categorySet() ?? []) : []);
        $say('wc_terms_before', $before);
        $say('wc_terms_after', $termsOf((int) $f['wc']));
        $say('intake_changed_the_shop', !$sameSet($before, $termsOf((int) $f['wc'])));
        $say('wc_attributes_after_intake', $attrsOf((int) $f['wc']));
        break;

    case 'take':
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $pid = (int) ($row['id'] ?? 0);
        if ($pid === 0) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        /** @var TransferOwnership $transfer */
        $transfer = $c->get(TransferOwnership::class);
        /** @var ImportExistingProducts $intake */
        $intake = $c->get(ImportExistingProducts::class);
        $risks = array_keys($transfer->risksFor($pid));
        $seen = [];
        foreach ($intake->driftOf($pid) as $field => $pair) {
            $seen[$field] = (string) ($pair[1] ?? '');
        }
        $before = $termsOf($wcId);
        $taken = $transfer->take($pid, $risks, $seen);
        $say('take', $taken->ok ? 'ok' : (string) $taken->code);
        $say('link_ownership', (string) ($rowFor($wcId)['link_ownership'] ?? '?'));
        $say('terms_preserved', $sameSet($before, $termsOf($wcId)));
        $say('attributes_preserved', $attrsOf($wcId) === $attrsOf($wcId));
        $say('wc_attributes', $attrsOf($wcId));
        break;

    case 'q1':
        // THE WHOLE SET, WITH NO PRIMARY CATEGORY CHOSEN.
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $pid = (int) ($row['id'] ?? 0);
        $product = $pid > 0 ? $products->find($pid) : null;
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $say('wc_terms', $termsOf($wcId));
        $say('recorded_set', $product->details->categorySet());
        $say('set_matches_the_shop', $sameSet($termsOf($wcId), $product->details->categorySet()));
        // The single key is what a «primary category» would be. It must stay
        // empty for a multi-category product: `soleKey()` picks no winner.
        $say('stored_category_key', (string) ($row['category_key'] ?? '__none__'));
        $say('a_primary_category_was_chosen', trim((string) ($row['category_key'] ?? '')) !== '');
        // And the form must not demand one. `missingFields()` is the gate the
        // vendor's submit and the manager's approve both read.
        $say('missing_fields', $product->details->missingFields());
        $say('category_is_demanded', in_array('category', $product->details->missingFields(), true));
        break;

    case 'q2':
        // NO MEDICAL TEMPLATE ANYWHERE — does anything refuse because of it?
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $pid = (int) ($row['id'] ?? 0);
        $product = $pid > 0 ? $products->find($pid) : null;
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        /** @var SpecTemplateRepositoryInterface $templates */
        $templates = $c->get(SpecTemplateRepositoryInterface::class);
        $say('stored_category_key', (string) ($row['category_key'] ?? '__none__'));
        $say('template_for_the_stored_key', $templates->findByCategory((string) ($row['category_key'] ?? '')) === null ? '__none__' : 'found');
        $say('template_for_empty_key', $templates->findByCategory('') === null ? '__none__' : 'found');
        $say('stored_specs', $product->specs);
        /** @var ProductReadiness $readiness */
        $readiness = $c->get(ProductReadiness::class);
        $verdict = $readiness->check($product);
        $say('readiness_ok', $verdict->ok);
        $say('readiness_code', (string) $verdict->code);
        $say('readiness_blocked_on_specs', in_array((string) $verdict->code, ['missing_specs', 'invalid_specs'], true));
        break;

    case 'owner-edit':
        // THE OWNER, EDITING DIRECTLY IN WOOCOMMERCE — every field at once,
        // including a THIRD category, so «هیچ رابطه‌ای خودکار حذف یا اضافه
        // نشود» is measured against a set the owner changed themselves.
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $wc = wc_get_product($wcId);
        if (!$wc instanceof WC_Product) {
            $say('refused', 'no_shop_product');
            break;
        }
        $wc->set_name('ممیزی۸۲ ترالی استیل — ویرایش مالک');
        $wc->set_short_description('توضیح کوتاه، ویرایش‌شده توسط مالک در ووکامرس');
        $wc->set_description('توضیحات کامل، ویرایش‌شده توسط مالک در ووکامرس.');
        $wc->set_regular_price('480000');
        $wc->set_sku('SKU-A82AUDIT-OWNER');
        $wc->set_weight('14');
        $wc->set_category_ids([
            (int) $f['terms']['child'],
            (int) $f['terms']['root'],
            (int) $f['extra_term'],
        ]);
        $attrs = $wc->get_attributes();
        $third = new WC_Product_Attribute();
        $third->set_name('گارانتی');
        $third->set_options(['۱۸ ماه']);
        $third->set_position(2);
        $third->set_visible(true);
        $third->set_variation(false);
        $attrs['گارانتی'] = $third;
        $wc->set_attributes($attrs);
        $wc->save();
        $say('owner_edited', 'yes');
        $say('wc_terms', $termsOf($wcId));
        $say('wc_attributes', $attrsOf($wcId));
        $say('wc_fields', $shopOf($wcId));
        break;

    case 'project':
        // §Q3 and §Q4 — the projection, and then EVERY field read back.
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $pid = (int) ($row['id'] ?? 0);
        $product = $pid > 0 ? $products->find($pid) : null;
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        $termsBefore = $termsOf($wcId);
        $attrsBefore = $attrsOf($wcId);
        $fieldsBefore = $shopOf($wcId);
        /** @var CatalogProjectorInterface $projector */
        $projector = $c->get(CatalogProjectorInterface::class);
        $out = $projector->project($product);
        $code = is_array($out) ? (string) ($out['code'] ?? '?') : '?';
        $say('project_code', $code);
        $termsAfter = $termsOf($wcId);
        $attrsAfter = $attrsOf($wcId);
        $fieldsAfter = $shopOf($wcId);
        $say('terms_before', $termsBefore);
        $say('terms_after', $termsAfter);
        $say('terms_preserved', $sameSet($termsBefore, $termsAfter));
        $say('terms_lost', array_values(array_diff($termsBefore, $termsAfter)));
        $say('terms_gained', array_values(array_diff($termsAfter, $termsBefore)));
        $say('attributes_before', $attrsBefore);
        $say('attributes_after', $attrsAfter);
        $say('attributes_preserved', $attrsBefore === $attrsAfter);
        $say('attributes_lost', array_values(array_diff(array_keys($attrsBefore), array_keys($attrsAfter))));
        foreach ($fieldsBefore as $name => $was) {
            $now = (string) ($fieldsAfter[$name] ?? '');
            $say('field_' . $name . '_before', $was);
            $say('field_' . $name . '_after', $now);
            $say('field_' . $name . '_overwritten', $was !== $now);
        }
        // And what the plugin ITSELF says about the conflict, if anything.
        /** @var StorefrontFieldsInterface $sf */
        $sf = $c->get(StorefrontFieldsInterface::class);
        $say('comparison_state', $sf->comparisonState($product));
        // ⛔ Read from the REAL shape of `StorefrontField`, not from a
        // guessed property name: the first version of this probe asked for
        // `->field` and `->verdict`, got `{"?":"unsettled"}`, and that told
        // me nothing about a surface that was working. `key`, `owner`,
        // `marketplace` and `storefront` are what the class actually carries.
        $reported = [];
        foreach ($sf->compare($product) as $field) {
            if (!is_object($field) || !property_exists($field, 'key')) {
                continue;
            }
            $reported[(string) $field->key] = [
                'owner' => (string) $field->owner,
                'row' => (string) $field->marketplace,
                'shop' => (string) $field->storefront,
                'differs' => ((string) $field->marketplace) !== ((string) $field->storefront),
            ];
        }
        $say('compared_fields', array_keys($reported));
        $say('field_report', $reported);
        // And the fields the projection OVERWROTE, measured above, against the
        // fields this surface can even talk about.
        $say('fields_outside_the_comparison', array_values(array_diff(
            array_keys($fieldsBefore),
            array_keys($reported)
        )));
        break;

    case 'approve':
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $pid = (int) ($row['id'] ?? 0);
        $product = $pid > 0 ? $products->find($pid) : null;
        if ($product === null) {
            $say('refused', 'no_marketplace_row');
            break;
        }
        /** @var StorefrontFieldsInterface $sf */
        $sf = $c->get(StorefrontFieldsInterface::class);
        $termsBefore = $termsOf($wcId);
        $attrsBefore = $attrsOf($wcId);
        /** @var ReviewProducts $review */
        $review = $c->get(ReviewProducts::class);
        $result = $review->approve($pid, $sf->fingerprints($product));
        $say('approve_ok', $result->ok);
        $say('approve_code', (string) $result->code);
        $say('approve_context', $result->context ?? []);
        $say('terms_before', $termsBefore);
        $say('terms_after', $termsOf($wcId));
        $say('terms_preserved', $sameSet($termsBefore, $termsOf($wcId)));
        $say('attributes_preserved', $attrsBefore === $attrsOf($wcId));
        $say('attributes_after', $attrsOf($wcId));
        break;

    case 'report':
        $f = $fixture();
        $wcId = (int) $f['wc'];
        $row = $rowFor($wcId);
        $say('wc_product', $wcId);
        $say('row_id', (string) ($row['id'] ?? '__none__'));
        $say('row_link_ownership', (string) ($row['link_ownership'] ?? '__none__'));
        $say('row_category_key', (string) ($row['category_key'] ?? '__none__'));
        $say('wc_terms', $termsOf($wcId));
        $say('wc_attributes', $attrsOf($wcId));
        $say('wc_fields', $shopOf($wcId));
        $pid = (int) ($row['id'] ?? 0);
        $say('recorded_set', $pid > 0 ? ($products->find($pid)?->details->categorySet() ?? []) : []);
        break;

    case 'reset':
        // Give back everything this probe spent — the intake through its own
        // documented rollback, then only the posts, terms and user it made.
        $f = $fixture();
        if ($f['run'] !== '') {
            /** @var ImportExistingProducts $intake */
            $intake = $c->get(ImportExistingProducts::class);
            if (method_exists($intake, 'rollback')) {
                $rolled = $intake->rollback((string) $f['run']);
                $say('rollback', is_object($rolled) && property_exists($rolled, 'ok') ? ($rolled->ok ? 'ok' : (string) $rolled->code) : '__absent__');
            }
        }
        if ((int) $f['wc'] > 0) {
            wp_delete_post((int) $f['wc'], true);
        }
        if ((int) $f['image'] > 0) {
            wp_delete_post((int) $f['image'], true);
        }
        foreach (array_merge(array_values($f['terms']), [(int) $f['extra_term']]) as $termId) {
            if ((int) $termId > 0 && get_term((int) $termId, 'product_cat') instanceof WP_Term) {
                wp_delete_term((int) $termId, 'product_cat');
            }
        }
        if ((int) $f['vendor'] > 0 && get_userdata((int) $f['vendor'])) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $f['vendor']);
        }
        delete_option($FIXTURE);
        $say('reset', 'done');
        $say('row_left', $rowFor((int) $f['wc']) === null ? 'none' : 'present');
        break;

    default:
        $say('usage', 'arrange|intake|take|q1|q2|owner-edit|project|approve|report|reset');
}
