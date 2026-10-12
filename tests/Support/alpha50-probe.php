<?php
/**
 * The brand, as ONE field — measured on real WooCommerce through real POSTs.
 *
 * **What `alpha.50` did, and what the owner rejected.** The brand was a picker:
 * a radio list of approved names, a search box with its own submit, a separate
 * «my brand is missing» text box with its own submit, and three paragraphs of
 * prose. Typing a name that was not in the list was REFUSED
 * (`brand_not_approved`), and the only way to ask for one was the second
 * button. The owner read that screen and asked for one field.
 *
 * So the measurement is at the POST, not at the service: the dialect the form
 * speaks is exactly what changed. Each verb builds `$_POST` as the rendered
 * form sends it and goes through `ProductArea::handle()`, which is how a
 * WordPress request reaches this code.
 *
 * **It loads on both trees**, which is the only way a before/after is worth
 * anything (`alpha.32`). Nothing new is named without being asked for first:
 * `ManageBrands::approve()` is called with two arguments because the second
 * has a default on the old build too, and `BrandProposal::SUPERSEDED` is
 * reached through `constant()` behind a guard.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha50-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
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

const TMC_P51_VENDOR = 9110;
const TMC_P51_CATEGORY = 'tmc-p51-brandfield';
const TMC_P51_SKU = 'SKU-ALPHA51';
/** A brand the manager's list really holds, created by `arrange`. */
const TMC_P51_APPROVED = 'آریا طب ۵۱';
/** A name the list does not hold. */
const TMC_P51_WANTED = 'سینا طب ۵۱';

$verb = (string) ($args[0] ?? 'reset');
$c = Bootstrap::container();
$adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adminId);
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
/** @var BrandDirectoryInterface $directory */
$directory = $c->get(BrandDirectoryInterface::class);
/** @var BrandProposalRepositoryInterface $proposals */
$proposals = $c->get(BrandProposalRepositoryInterface::class);
/** @var ManageBrands $brands */
$brands = $c->get(ManageBrands::class);
/** @var ProductRevisionRepositoryInterface $revisions */
$revisions = $c->get(ProductRevisionRepositoryInterface::class);
/** @var SyncCatalog $catalog */
$catalog = $c->get(SyncCatalog::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
$say('probe', 'ran');

(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P51_VENDOR, 'تجهیزات آزمون ۵۱', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P51_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $brand = ''): ProductDetails {
    $named = [
        'title' => 'ماسک آزمون ۵۱',
        'type' => 'simple',
        'categoryKey' => TMC_P51_CATEGORY,
        'brand' => $brand,
        'shortDescription' => 'یک جمله',
        'priceMinor' => 320000,
        'sku' => TMC_P51_SKU,
        'stock' => 12,
        'minPurchase' => 1,
        'maxPurchase' => 20,
        'description' => 'متن کامل فروشنده',
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

/**
 * One real POST of step 1, in the dialect of whichever build is on disk.
 *
 * **Both dialects are sent, always.** `alpha.50`'s picker reads `brand_term`
 * and ignores `brand`; this round reads `brand` and ignores `brand_term`. A
 * probe that sent only one would measure «the field was not there» on one of
 * the two trees and call it a behaviour. So `brand` carries the typed name and
 * `brand_term` carries `keep` — the old form's «leave the brand alone» — which
 * makes the old build's answer «nothing changed» rather than an artefact.
 */
$postStepOne = static function (int $productId, string $typedBrand, array $override = [])
use ($products): \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome {
    $stored = $productId > 0 ? $products->find($productId) : null;
    $fields = [
        'tmc_vendor_action' => 'save_product',
        'product_id' => (string) $productId,
        'step' => '1',
        'revision' => $stored?->rowVersion ?? '',
        'title' => $stored?->details->title ?? 'ماسک آزمون ۵۱',
        'type' => 'simple',
        'category' => $stored?->details->categoryKey ?? TMC_P51_CATEGORY,
        'brand' => $typedBrand,
        'brand_term' => 'keep',
        'short_description' => $stored?->details->shortDescription ?? 'یک جمله',
        'price' => (string) ($stored?->details->priceMinor ?? 320000),
        'sale_price' => '',
        'sale_from' => '',
        'sale_to' => '',
        'sku' => $stored?->details->sku ?? TMC_P51_SKU,
        'stock' => (string) ($stored?->details->stock ?? 12),
        'min_purchase' => '1',
        'max_purchase' => '20',
        'purchase_step' => '1',
        'weight_grams' => '0',
        'dimensions' => '',
        'tax_class' => '',
        'main_image_id' => (string) ($stored?->mainImageId ?? 0),
        'description' => 'متن کامل فروشنده',
        'description_base' => 'vendor',
    ];
    $_POST = array_merge($fields, $override);
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
        'https://example.test/vendor/',
        'https://example.test/vendor/application/',
        'https://example.test/vendor/store/',
        'https://example.test/vendor/staff/',
        'https://example.test/vendor/invite/',
        'https://example.test/vendor/products/'
    );
    $outcome = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea(
        Bootstrap::container()
    ))->handle(
        'save_product',
        \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
        TMC_P51_VENDOR,
        $urls
    );
    if ($outcome === null) {
        throw new \RuntimeException('the area router did not handle save_product');
    }
    return $outcome;
};

/** The product under test, published and projected, with no brand. */
$arrange = static function (string $brand = '')
use ($products, $findBySku, $detailsOf, $catalog, $proposals, $directory, $db): int {
    $id = $findBySku(TMC_P51_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P51_VENDOR, $detailsOf($brand), ProductStatus::Published);
    } else {
        if (!$products->updateDetails($id, $detailsOf($brand), $products->find($id)?->rowVersion ?? '')) {
            throw new \RuntimeException('the fixture could not reset the product row');
        }
    }
    // The brand term id is reset with its guard, not behind it: a fixture that
    // writes the row directly leaves an identity the service never agreed to
    // (the `alpha.45` rule about fixtures and their guards).
    $products->setBrandTerm($id, $brand, $brand === '' ? null : $directory->findByKey(BrandName::key($brand))?->termId);
    // ANY PROPOSAL THIS FIXTURE HAS SPENT BEFORE, given back — the
    // `alpha.45` rule. A pending revision left by an earlier verb makes the
    // next verb's «no proposal was made» a true sentence about the previous
    // measurement, reported as a failure of this one.
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_product_revisions`
            SET status = %s WHERE product_id = %d AND status = %s',
        ['superseded', $id, 'pending']
    );
    // Any brand request it has spent. Closed rather than deleted, so the
    // table keeps looking like a real one.
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_brand_proposals`
            SET status = %s, open_key = CONCAT("#", id)
          WHERE product_id = %d AND status IN (%s, %s)',
        ['superseded', $id, 'pending', 'registered']
    );
    $products->updateStatus($id, ProductStatus::Published, '');
    $catalog->publish($id);
    return $id;
};

/** The approved brand really in WooCommerce's own list. */
$ensureApproved = static function () use ($directory): ?int {
    $found = $directory->findByKey(BrandName::key(TMC_P51_APPROVED));
    if ($found !== null) {
        return $found->termId;
    }
    return $directory->register(TMC_P51_APPROVED)?->termId;
};

/** What WooCommerce itself says this product's Brand attribute is. */
$shopBrand = static function (int $productId) use ($products): string {
    $wcId = $products->find($productId)?->wcProductId ?? 0;
    if ($wcId <= 0 || !function_exists('wc_get_product')) {
        return '__no_shop_product__';
    }
    wc_delete_product_transients($wcId);
    $wcProduct = wc_get_product($wcId);
    if (!$wcProduct instanceof \WC_Product) {
        return '__unreadable__';
    }
    foreach ($wcProduct->get_attributes() as $attribute) {
        $name = method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : '';
        if (BrandName::key($name) === BrandName::key('برند') || $name === 'برند') {
            $options = method_exists($attribute, 'get_options') ? (array) $attribute->get_options() : [];
            return implode('/', array_map('strval', $options));
        }
    }
    return '__absent__';
};

$openOf = static function (int $productId) use ($proposals): ?object {
    return $proposals->openFor($productId);
};

$rowCount = static function (int $productId) use ($db): int {
    return (int) $db->getVar(
        'SELECT COUNT(*) FROM `' . $db->prefix() . 'tmc_brand_proposals` WHERE product_id = %d',
        [$productId]
    );
};

// ------------------------------------------------------------------- verbs

switch ($verb) {
    /**
     * THE FINDING: one field, an unapproved name, the ordinary save.
     *
     * On `alpha.50` the save is REFUSED and nothing is recorded, so the only
     * way to ask was the second button the owner asked to have removed. On
     * this build the product saves, the name is recorded for the manager, and
     * the product's own brand does not move.
     */
    case 'typed-unapproved':
        $ensureApproved();
        $id = $arrange('');
        $before = $rowCount($id);
        $outcome = $postStepOne($id, TMC_P51_WANTED);
        $open = $openOf($id);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('brand_after', $products->find($id)?->details->brand ?: '__empty__');
        $say('request_recorded', $open !== null);
        $say('request_name', $open?->name ?? '__none__');
        $say('rows_before', $before);
        $say('rows_after', $rowCount($id));
        $say('lands_on', str_contains($outcome->target, 'step=2') ? 'step_2' : $outcome->target);
        break;

    /** The same POST again — a double click. One request, no second row. */
    case 'typed-unapproved-twice':
        $id = $findBySku(TMC_P51_SKU);
        $first = $openOf($id);
        $rows = $rowCount($id);
        $outcome = $postStepOne($id, TMC_P51_WANTED);
        $open = $openOf($id);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('same_request', $first !== null && $open !== null && $first->id === $open->id);
        $say('rows_unchanged', $rows === $rowCount($id));
        $say('rows_now', $rowCount($id));
        break;

    /** A different name: the open request is replaced, as one unit of work. */
    case 'typed-retyped':
        $id = $findBySku(TMC_P51_SKU);
        $first = $openOf($id);
        $outcome = $postStepOne($id, 'آمبو بگ ۵۱');
        $open = $openOf($id);
        $superseded = $first === null ? null : $proposals->find($first->id);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('request_name', $open?->name ?? '__none__');
        $say('is_a_new_request', $first !== null && $open !== null && $first->id !== $open->id);
        $say('earlier_status', $superseded?->status ?? '__none__');
        $say('exactly_one_open', count(array_filter(
            $proposals->pending(),
            static fn (object $p): bool => $p->productId === $id
        )));
        $say('brand_after', $products->find($id)?->details->brand ?: '__empty__');
        break;

    /** Typing a name the list HAS: that brand, under the list's spelling. */
    case 'typed-approved':
        $termId = $ensureApproved();
        $id = $arrange('');
        // An Arabic yeh and no space: the same brand, differently spelled.
        $outcome = $postStepOne($id, str_replace(['ی', ' '], ['ي', ''], TMC_P51_APPROVED));
        $pending = $revisions->pendingFor($id);
        $say('product_id', $id);
        $say('term_in_list', $termId ?? '__none__');
        $say('save_code', $outcome->code);
        $say('request_recorded', $openOf($id) !== null);
        $say('revision_brand', (string) ($pending?->payload['details']['brand'] ?? '__none__'));
        $say('row_brand_now', $products->find($id)?->details->brand ?: '__empty__');
        break;

    /** §2(الف): the manager attaches the request to a brand that exists. */
    case 'manager-attach':
        $termId = $ensureApproved();
        $id = $arrange('');
        $postStepOne($id, TMC_P51_WANTED);
        $open = $openOf($id);
        $before = count($directory->search('', 200));
        $approved = $open === null
            ? null
            : $brands->approve($open->id, TMC_P51_APPROVED);
        $say('product_id', $id);
        $say('asked', $open?->name ?? '__none__');
        $say('approve_code', $approved?->code ?? '__not_run__');
        $say('approve_ok', $approved?->ok ?? false);
        $say('row_brand', $products->find($id)?->details->brand ?: '__empty__');
        $say('row_term', $products->brandTermIdOf($id) ?? '__null__');
        $say('attached_to_existing', ($products->brandTermIdOf($id) ?? 0) === ($termId ?? -1));
        $say('list_grew', count($directory->search('', 200)) - $before);
        $say('shop_attribute_brand', $shopBrand($id));
        $say('nothing_left_open', $openOf($id) === null);
        break;

    /** §2(ب): the manager corrects the name and approves it as a new brand. */
    case 'manager-correct':
        $ensureApproved();
        $id = $arrange('');
        $postStepOne($id, 'مدلاين تجهيز ۵۱');          // the vendor's spelling
        $open = $openOf($id);
        $corrected = 'مدلاین ۵۱';                        // the manager's
        $approved = $open === null ? null : $brands->approve($open->id, $corrected);
        $say('product_id', $id);
        $say('asked', $open?->name ?? '__none__');
        $say('approve_code', $approved?->code ?? '__not_run__');
        $say('row_brand', $products->find($id)?->details->brand ?: '__empty__');
        $say('managers_spelling_in_list', $directory->findByKey(BrandName::key($corrected)) !== null);
        $say('vendors_spelling_in_list', $directory->findByKey(BrandName::key('مدلاين تجهيز ۵۱')) !== null);
        $say('shop_attribute_brand', $shopBrand($id));
        break;

    /** §2(ج): rejecting leaves the product's brand exactly as it was. */
    case 'manager-reject':
        $termId = $ensureApproved();
        $id = $arrange(TMC_P51_APPROVED);
        $postStepOne($id, TMC_P51_WANTED);
        $open = $openOf($id);
        $rejected = $open === null ? null : $brands->reject($open->id, 'این برند تأیید نمی‌شود.');
        $say('product_id', $id);
        $say('reject_code', $rejected?->code ?? '__not_run__');
        $say('row_brand', $products->find($id)?->details->brand ?: '__empty__');
        $say('brand_untouched', $products->find($id)?->details->brand === TMC_P51_APPROVED);
        $say('not_in_list', $directory->findByKey(BrandName::key(TMC_P51_WANTED)) === null);
        $say('nothing_left_open', $openOf($id) === null);
        break;

    /**
     * THE NOTIFICATION. A brand request is a thing to decide, so it has to
     * reach the red badge — and until `M0026ReviewSeenBrand` it did not: the
     * identity a mark compares against moved for a submission and for a
     * revision and for nothing else.
     */
    /**
     * THE NOTIFICATION, measured as a DELTA.
     *
     * The absolute count is not this product's to claim: the queue holds
     * whatever other fixtures have left in it, and an expectation of «0» would
     * be a sentence about the rest of the database (the `alpha.33` rule —
     * measure the baseline, do not assume it). So the manager is brought up to
     * date first, the count is read, the request is made, and the count is read
     * again. The DIFFERENCE is the thing this verb is about, and it is the same
     * question on both trees.
     */
    case 'badge':
        $ensureApproved();
        $id = $arrange('');
        /** @var ReviewSeen $seen */
        $seen = $c->get(ReviewSeen::class);
        // The manager reads the whole queue, in pages, so «unseen» starts from
        // what they have actually looked at rather than from nothing.
        for ($page = 0; $page < 20; $page++) {
            $rows = $products->forManagerWithSubmission(null, '', 100, $page * 100, 0, null, false);
            if ($rows === []) {
                break;
            }
            $marks = [];
            foreach ($rows as $row) {
                $marks[(int) $row['id']] = (string) $row['submission'];
            }
            $seen->markSeen($adminId, $marks);
        }
        $before = $seen->unseenCount($adminId);
        $say('product_id', $id);
        $say('unseen_before_request', $before);
        $postStepOne($id, TMC_P51_WANTED);
        $after = $seen->unseenCount($adminId);
        $say('in_queue', $openOf($id) !== null);
        $say('unseen_after_request', $after);
        $say('unseen_delta', $after - $before);
        $say('badge_saw_it', $after > $before);
        break;

    /**
     * THE OLD DIALECT, so the removal is measured rather than assumed.
     *
     * `alpha.50` asked for a brand through a second field and a second button
     * (`brand_request` + `request_brand`). On that build this POST records a
     * request — the capability existed and was MOVED, not deleted. On this
     * build the two fields are gone and the same POST does nothing, which is
     * the subtraction the owner asked for, stated as a measurement.
     */
    case 'old-dialect':
        $ensureApproved();
        $id = $arrange('');
        $outcome = $postStepOne($id, '', [
            'brand' => '',
            'brand_term' => 'keep',
            'brand_request' => TMC_P51_WANTED,
            'request_brand' => '1',
        ]);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('request_recorded', $openOf($id) !== null);
        $say('request_name', $openOf($id)?->name ?? '__none__');
        break;

    /**
     * CONTROLS — the same answer on both trees, or the measurement above is
     * about the script and not about the build (`alpha.46`).
     */
    case 'control-unchanged':
        $ensureApproved();
        $id = $arrange(TMC_P51_APPROVED);
        $outcome = $postStepOne($id, TMC_P51_APPROVED);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('brand_after', $products->find($id)?->details->brand ?: '__empty__');
        $say('no_request', $openOf($id) === null);
        $say('no_revision', $revisions->pendingFor($id) === null);
        break;

    /** An empty box is "no brand", on both builds, and it is written. */
    case 'control-cleared':
        $ensureApproved();
        $id = $arrange(TMC_P51_APPROVED);
        $products->updateStatus($id, ProductStatus::Draft, '');
        $outcome = $postStepOne($id, '', ['brand_term' => '0']);
        $say('product_id', $id);
        $say('save_code', $outcome->code);
        $say('brand_after', $products->find($id)?->details->brand ?: '__empty__');
        break;

    /** A product of another shop is nobody else's to save. */
    case 'control-not-ours':
        $id = $arrange('');
        $_POST = [];
        $outcome = null;
        try {
            $urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
                'https://example.test/vendor/',
                'https://example.test/vendor/application/',
                'https://example.test/vendor/store/',
                'https://example.test/vendor/staff/',
                'https://example.test/vendor/invite/',
                'https://example.test/vendor/products/'
            );
            $asked = $brands->propose(TMC_P51_VENDOR + 7, TMC_P51_VENDOR + 7, $id, TMC_P51_WANTED);
            $outcome = $asked->code;
        } catch (\Throwable $e) {
            $outcome = 'threw:' . $e->getMessage();
        }
        $say('product_id', $id);
        $say('propose_code', $outcome);
        $say('no_request', $openOf($id) === null);
        break;

    case 'reset':
        $id = $findBySku(TMC_P51_SKU);
        $say('product_id', $id);
        if ($id > 0) {
            $arrange('');
        }
        $say('reset', 'done');
        break;

    default:
        fwrite(STDERR, "unknown verb: {$verb}\n");
        echo "unknown_verb=1\n";
}
