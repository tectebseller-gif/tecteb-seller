<?php
/**
 * Two decisions about one product, and which of them wins.
 *
 * **The shape the owner named.** `proposeStock()` carries the WHOLE pending
 * payload forward — deliberately, so a stock edit does not throw away a title
 * or a brand the vendor is still waiting on (`alpha.48`). `ManageBrands::finish()`
 * applies an approved brand straight onto the product, on its own table and its
 * own cycle. And `ReviewProducts::detailsFromPayload()` then reads `brand` out
 * of that payload. So the payload's brand is a SNAPSHOT, and approving the
 * stock replays it over a brand decision a manager made afterwards.
 *
 * Three places have to be measured, because the owner named three: the
 * marketplace row, WooCommerce's own Brand attribute, and the brand term id —
 * the identity, which is what every later comparison asks.
 *
 * **It loads on both trees** (`alpha.32`). Nothing this round adds is named
 * without being asked for first: `approveRevision()` is called through
 * reflection so the brand-override argument is sent only to a build that has
 * it, and the payload's new baseline map is read with `??`.
 *
 * Prints ONE key per line (the `alpha.41` rule).
 *
 *   wp eval-file tests/Support/alpha51-probe.php <verb>
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\BrandDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\BrandProposalRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
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

const TMC_P52_VENDOR = 9120;
const TMC_P52_CATEGORY = 'tmc-p52-interference';
const TMC_P52_SKU = 'SKU-ALPHA52';
/** Brand A: the brand the product already has, approved and in the list. */
const TMC_P52_BRAND_A = 'آریا طب ۵۲';
/** Brand B: the name the vendor asks for, NOT in the list until approved. */
const TMC_P52_BRAND_B = 'سینا طب ۵۲';
/** Brand C: a second approved brand, for the real-conflict case. */
const TMC_P52_BRAND_C = 'مدلاین ۵۲';

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
/** @var ManageProducts $manage */
$manage = $c->get(ManageProducts::class);
/** @var ReviewProducts $review */
$review = $c->get(ReviewProducts::class);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
$say('probe', 'ran');

(new \Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository($db, $clock))
    ->upsertProfile(TMC_P52_VENDOR, 'تجهیزات آزمون ۵۲', true, false);

// ---------------------------------------------------------------- fixtures

$findBySku = static function (string $sku) use ($products): int {
    foreach ($products->forVendor(TMC_P52_VENDOR) as $product) {
        if ($product->details->sku === $sku) {
            return $product->id;
        }
    }
    return 0;
};

$detailsOf = static function (string $brand, string $description = 'متن کامل اول'): ProductDetails {
    $named = [
        'title' => 'ماسک آزمون ۵۲',
        'type' => 'simple',
        'categoryKey' => TMC_P52_CATEGORY,
        'brand' => $brand,
        'shortDescription' => 'یک جمله',
        'priceMinor' => 320000,
        'sku' => TMC_P52_SKU,
        'stock' => 12,
        'minPurchase' => 1,
        'maxPurchase' => 20,
        'description' => $description,
    ];
    if (property_exists(ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    return new ProductDetails(...$named);
};

/** A brand really in WooCommerce's own list. */
$ensureBrand = static function (string $name) use ($directory): ?int {
    $found = $directory->findByKey(BrandName::key($name));
    if ($found !== null) {
        return $found->termId;
    }
    return $directory->register($name)?->termId;
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

/**
 * The product under test: published, projected, brand A, nothing open.
 *
 * Everything this fixture spends it gives back (the `alpha.45` rule): a
 * revision or a brand request left behind by an earlier verb would make the
 * next verb's answer a true sentence about the previous measurement.
 */
$arrange = static function (string $description = 'متن کامل اول')
use ($products, $findBySku, $detailsOf, $catalog, $directory, $db, $ensureBrand): int {
    // BRAND B MUST NOT BE IN THE LIST, and an earlier verb put it there.
    // Measured the first time this probe ran: `brand_request_code=
    // brand_already_approved`, which is `propose()` answering correctly about
    // a list the previous measurement had changed — the `alpha.45` rule, that
    // a fixture must give back what it spends. Deleting the term is a fixture
    // doing it, not `src/` (`NoParallelTaxonomyTest` forbids that there).
    //
    // FIRST, AND WITHOUT ASKING THE DIRECTORY. `WooCommerceBrandDirectory`
    // memoises the whole list on its first read, so a delete that runs after
    // any `findByKey()` is invisible for the rest of the request — measured:
    // the delete succeeded, the term was gone from `get_terms()`, and
    // `propose()` still answered `brand_already_approved` from the memo. The
    // same shape as `alpha.48`'s term cache and `alpha.50`'s instance cache,
    // one layer out: an object cache is not a fresh read.
    foreach (get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]) as $term) {
        if ($term instanceof \WP_Term && BrandName::key($term->name) === BrandName::key(TMC_P52_BRAND_B)) {
            wp_delete_term($term->term_id, 'product_brand');
        }
    }
    $termA = $ensureBrand(TMC_P52_BRAND_A);
    // THE FLASH, GIVEN BACK (the `alpha.45` rule). A verb that leaves a
    // carried form behind makes the next verb's render show the previous
    // verb's values — measured: `step2_save_code=inventory_saved`, a true
    // sentence about the last measurement reported as this one's.
    delete_transient('tmc_flash_product_form_' . TMC_P52_VENDOR);
    $id = $findBySku(TMC_P52_SKU);
    if ($id === 0) {
        $id = $products->create(TMC_P52_VENDOR, $detailsOf(TMC_P52_BRAND_A, $description), ProductStatus::Published);
    } elseif (!$products->updateDetails($id, $detailsOf(TMC_P52_BRAND_A, $description), $products->find($id)?->rowVersion ?? '')) {
        throw new \RuntimeException('the fixture could not reset the product row');
    }
    $products->setBrandTerm($id, TMC_P52_BRAND_A, $termA);
    // AN IMAGE, or the approval path never reaches the brand at all: readiness
    // answers `missing_image` and the revision is refused before any of this
    // round's question is asked. Measured, not assumed — the first run of this
    // probe reported exactly that and said nothing about the brand.
    if (($products->find($id)?->imageIds ?? []) === []) {
        $file = wp_upload_dir()['path'] . '/tmc-p52.png';
        if (!file_exists($file)) {
            file_put_contents($file, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
            ));
        }
        $attachment = (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => 'tmc-p52',
            'post_status' => 'inherit',
            'post_author' => TMC_P52_VENDOR,
        ], $file);
        if ($attachment > 0) {
            // The ownership stamp, without which `cleanImages()` drops the id
            // from every save and the gallery is silently empty again.
            update_post_meta($attachment, '_tmc_product_image', TMC_P52_VENDOR);
            $products->saveImages($id, [$attachment], $attachment);
        }
    }
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_product_revisions`
            SET status = %s WHERE product_id = %d AND status = %s',
        ['superseded', $id, 'pending']
    );
    $db->execute(
        'UPDATE `' . $db->prefix() . 'tmc_brand_proposals`
            SET status = %s, open_key = CONCAT("#", id)
          WHERE product_id = %d AND status IN (%s, %s)',
        ['superseded', $id, 'pending', 'registered']
    );
    $products->updateStatus($id, ProductStatus::Published, '');
    $catalog->publish($id);
    // AND THE SHOP'S STOCK, because that is where the baseline comes from.
    // `effectiveStock()` reads WooCommerce (ADR-008 makes our column the
    // mirror), and `updateDetails()` does not push stock outward — so after a
    // verb that approved «make it 7», the next verb proposing 7 is proposing
    // no change at all. Measured: `stock_proposal_code=inventory_saved`, a
    // true sentence about the previous run's result reported as this one's.
    $catalog->pushStock($id, 12);
    return $id;
};

/** The two open things the owner's scenario needs, side by side. */
$openBoth = static function (int $id, int $stock = 7)
use ($manage, $brands, $say): void {
    $inventory = $manage->updateInventory(TMC_P52_VENDOR, TMC_P52_VENDOR, $id, $stock, TMC_P52_SKU, 1, 20, 1);
    $say('stock_proposal_code', $inventory->code);
    $asked = $brands->propose(TMC_P52_VENDOR, TMC_P52_VENDOR, $id, TMC_P52_BRAND_B);
    $say('brand_request_code', $asked->code);
};

/**
 * `approveRevision()` with whatever arity the build on disk has.
 *
 * The `alpha.40` trap in a new place: an evidence tool that hands a service
 * more arguments than it takes stops loading on the very bytes it exists to
 * measure. So the extras go only where there is room for them.
 */
$approveRevision = static function (
    int $revisionId,
    bool $acceptBrand = false,
    ?int $seenStock = null,
    ?string $seenBrand = null
) use ($review) {
    $arity = (new \ReflectionMethod(ReviewProducts::class, 'approveRevision'))->getNumberOfParameters();
    $argv = [$revisionId, [], $seenStock !== null, $seenStock, $acceptBrand, $seenBrand];
    return $review->approveRevision(...array_slice($argv, 0, $arity));
};

/** Every answer the owner asked to see, in one block. */
$report = static function (int $id, string $prefix = '')
use ($products, $shopBrand, $revisions, $proposals, $say): void {
    $product = $products->find($id);
    $say($prefix . 'row_brand', $product?->details->brand ?: '__empty__');
    $say($prefix . 'row_term', $products->brandTermIdOf($id) ?? '__null__');
    $say($prefix . 'shop_brand', $shopBrand($id));
    $say($prefix . 'row_description', $product?->details->description ?? '__null__');
    $say($prefix . 'row_stock', $product?->details->stock ?? '__null__');
    $say($prefix . 'open_revision', $revisions->pendingFor($id) !== null);
    $say($prefix . 'open_brand_request', $proposals->openFor($id) !== null);
};

$brandBaselineOf = static function (int $id) use ($revisions): string {
    $pending = $revisions->pendingFor($id);
    if ($pending === null) {
        return '__no_revision__';
    }
    $map = $pending->payload['field_baseline'] ?? null;
    if (!is_array($map) || !array_key_exists('brand', $map)) {
        return '__not_recorded__';
    }
    return (string) $map['brand'] === '' ? '__empty__' : (string) $map['brand'];
};

// ------------------------------------------------------------------- verbs

switch ($verb) {
    /**
     * THE FINDING, in the owner's own order: approve brand B, then the stock.
     *
     * On `alpha.51` the second approval replays the payload's brand A over all
     * three places. On this build the brand stays B and the stock still moves.
     */
    case 'brand-then-stock':
        $id = $arrange();
        $openBoth($id);
        $open = $proposals->openFor($id);
        $brandDecision = $open === null ? null : $brands->approve($open->id);
        $say('brand_approve_code', $brandDecision?->code ?? '__not_run__');
        $report($id, 'after_brand_');
        $pending = $revisions->pendingFor($id);
        $say('revision_id', $pending?->id ?? 0);
        $say('payload_brand', (string) ($pending->payload['details']['brand'] ?? '__absent__'));
        $say('payload_brand_baseline', $brandBaselineOf($id));
        $stockDecision = $pending === null ? null : $approveRevision($pending->id);
        $say('stock_approve_code', $stockDecision?->code ?? '__not_run__');
        $say('stock_approve_ok', $stockDecision?->ok ?? false);
        $say('brand_kept', (string) ($stockDecision->context['brand_kept'] ?? '__absent__'));
        $report($id, 'after_stock_');
        $say('brand_survived', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_B);
        $say('term_survived', ($products->brandTermIdOf($id) ?? 0)
            === ($directory->findByKey(BrandName::key(TMC_P52_BRAND_B))?->termId ?? -1));
        $say('shop_survived', $shopBrand($id) === TMC_P52_BRAND_B);
        $say('stock_applied', ($products->find($id)?->details->stock ?? 0) === 7);
        break;

    /** The reverse order: the stock first, then the brand. */
    case 'stock-then-brand':
        $id = $arrange();
        $openBoth($id);
        $pending = $revisions->pendingFor($id);
        $stockDecision = $pending === null ? null : $approveRevision($pending->id);
        $say('stock_approve_code', $stockDecision?->code ?? '__not_run__');
        $report($id, 'after_stock_');
        $open = $proposals->openFor($id);
        $say('request_still_open', $open !== null);
        $brandDecision = $open === null ? null : $brands->approve($open->id);
        $say('brand_approve_code', $brandDecision?->code ?? '__not_run__');
        $report($id, 'after_brand_');
        $say('brand_is_b', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_B);
        $say('shop_is_b', $shopBrand($id) === TMC_P52_BRAND_B);
        $say('stock_applied', ($products->find($id)?->details->stock ?? 0) === 7);
        break;

    /**
     * A description proposal open at the same time as the brand request.
     *
     * Two questions in one: approving the brand must not revert the pending
     * description, and approving the revision must not revert the brand.
     */
    case 'description-and-brand':
        $id = $arrange('متن کامل اول');
        $saved = $manage->save(
            TMC_P52_VENDOR,
            TMC_P52_VENDOR,
            $id,
            $detailsOf(TMC_P52_BRAND_A, 'متن کامل دوم'),
            [],
            $products->find($id)?->imageIds ?? [],
            $products->find($id)?->mainImageId ?? 0,
            $products->find($id)?->rowVersion ?? ''
        );
        $say('description_proposal_code', $saved->code);
        $asked = $brands->propose(TMC_P52_VENDOR, TMC_P52_VENDOR, $id, TMC_P52_BRAND_B);
        $say('brand_request_code', $asked->code);
        $open = $proposals->openFor($id);
        $brandDecision = $open === null ? null : $brands->approve($open->id);
        $say('brand_approve_code', $brandDecision?->code ?? '__not_run__');
        $report($id, 'after_brand_');
        $say('description_still_proposed', ($revisions->pendingFor($id)->payload['details']['description'] ?? '') === 'متن کامل دوم');
        $pending = $revisions->pendingFor($id);
        $revisionDecision = $pending === null ? null : $approveRevision($pending->id);
        $say('revision_approve_code', $revisionDecision?->code ?? '__not_run__');
        $report($id, 'after_revision_');
        $say('description_applied', ($products->find($id)?->details->description ?? '') === 'متن کامل دوم');
        $say('brand_survived', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_B);
        $say('shop_survived', $shopBrand($id) === TMC_P52_BRAND_B);
        break;

    /**
     * A REAL conflict: the vendor asked for brand C in the revision AND for
     * brand B as a request, and B was approved first. Two vendor requests
     * about one field, both legitimately recorded — so it is named rather than
     * resolved by whichever ran last.
     */
    case 'real-conflict':
        // ARRANGE FIRST. Reading the directory before `arrange()` deletes
        // brand B loads the memo with B still in it, and `propose()` then
        // answers `brand_already_approved` about a list that no longer says
        // so — measured, and the same cache trap as in `arrange()` itself.
        $id = $arrange();
        $ensureBrand(TMC_P52_BRAND_C);
        // Brand C is in the list, so this is a revision rather than a request.
        $saved = $manage->save(
            TMC_P52_VENDOR,
            TMC_P52_VENDOR,
            $id,
            $detailsOf(TMC_P52_BRAND_C),
            [],
            $products->find($id)?->imageIds ?? [],
            $products->find($id)?->mainImageId ?? 0,
            $products->find($id)?->rowVersion ?? ''
        );
        $say('revision_code', $saved->code);
        $say('payload_brand', (string) ($revisions->pendingFor($id)->payload['details']['brand'] ?? '__absent__'));
        $say('payload_brand_baseline', $brandBaselineOf($id));
        $asked = $brands->propose(TMC_P52_VENDOR, TMC_P52_VENDOR, $id, TMC_P52_BRAND_B);
        $say('brand_request_code', $asked->code);
        $open = $proposals->openFor($id);
        $brandDecision = $open === null ? null : $brands->approve($open->id);
        $say('brand_approve_code', $brandDecision?->code ?? '__not_run__');
        $report($id, 'after_brand_');
        $pending = $revisions->pendingFor($id);
        $decision = $pending === null ? null : $approveRevision($pending->id);
        $say('revision_approve_code', $decision?->code ?? '__not_run__');
        $say('revision_approve_ok', $decision?->ok ?? false);
        $say('named_baseline', (string) ($decision->context['baseline_brand'] ?? '__absent__'));
        $say('named_current', (string) ($decision->context['current_brand'] ?? '__absent__'));
        $say('named_proposed', (string) ($decision->context['proposed_brand'] ?? '__absent__'));
        $report($id, 'after_refusal_');
        $say('still_pending', $revisions->pendingFor($id) !== null);
        // …AND THE OVERRIDE IS FOR THE BRAND THAT WAS SHOWN. A tick that
        // carries no brand is refused rather than trusted (`alpha.49`'s rule,
        // one field over), so the blind override is measured first and the
        // honest one second.
        $pending = $revisions->pendingFor($id);
        $blind = $pending === null ? null : $approveRevision($pending->id, true);
        $say('blind_override_code', $blind?->code ?? '__not_run__');
        $say('blind_override_ok', $blind?->ok ?? false);
        $report($id, 'after_blind_');
        $pending = $revisions->pendingFor($id);
        $seenBrand = $products->find($id)?->details->brand ?? '';
        $forced = $pending === null ? null : $approveRevision($pending->id, true, null, $seenBrand);
        $say('override_code', $forced?->code ?? '__not_run__');
        $say('override_ok', $forced?->ok ?? false);
        $report($id, 'after_override_');
        $say('override_applied_proposal', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_C);
        break;

    /**
     * THE THIRD FINDING: the record of the request failed, and the vendor's
     * typing has to survive it.
     *
     * The failure is INJECTED at the collaborator — the proposals table is
     * renamed away for the length of one save — and said to be injected. It is
     * not a failure the code produces on its own.
     */
    case 'request-failure-keeps-typing':
        $id = $arrange();
        $table = $db->prefix() . 'tmc_brand_proposals';
        $db->execute('RENAME TABLE `' . $table . '` TO `' . $table . '_away`');
        try {
            $outcome = null;
            $flash = null;
            try {
                $outcome = (static function () use ($id, $products, $c) {
                    $stored = $products->find($id);
                    $_POST = [
                        'tmc_vendor_action' => 'save_product',
                        'product_id' => (string) $id,
                        'step' => '1',
                        'revision' => $stored?->rowVersion ?? '',
                        'title' => $stored?->details->title ?? '',
                        'type' => 'simple',
                        'category' => TMC_P52_CATEGORY,
                        'brand' => TMC_P52_BRAND_B,
                        'brand_term' => 'keep',
                        'short_description' => 'یک جمله',
                        'price' => '320000',
                        'sale_price' => '',
                        'sale_from' => '',
                        'sale_to' => '',
                        'sku' => TMC_P52_SKU,
                        'stock' => '12',
                        'min_purchase' => '1',
                        'max_purchase' => '20',
                        'purchase_step' => '1',
                        'weight_grams' => '0',
                        'dimensions' => '',
                        'tax_class' => '',
                        'main_image_id' => (string) ($stored?->mainImageId ?? 0),
                        'description' => 'متن کامل اول',
                        'description_base' => 'vendor',
                    ];
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
                    return (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->handle(
                        'save_product',
                        \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
                        TMC_P52_VENDOR,
                        $urls
                    );
                })();
            } catch (\Throwable $e) {
                $say('threw', get_class($e));
            }
            $say('outcome_code', $outcome?->code ?? '__none__');
            // The flash store is where a save keeps what was typed, and the
            // key is the one `ProductArea::formKey()` builds — read here with
            // the store's own prefix rather than a hand-written guess.
            $payload = get_transient('tmc_flash_product_form_' . TMC_P52_VENDOR);
            $payload = is_array($payload) ? $payload : [];
            $say('typed_kept', (string) ($payload['brand_typed'] ?? '__absent__'));
            $say('typing_survived', (string) ($payload['brand_typed'] ?? '') === TMC_P52_BRAND_B);
            $say('product_saved', ($products->find($id)?->details->shortDescription ?? '') === 'یک جمله');
        } finally {
            $db->execute('RENAME TABLE `' . $table . '_away` TO `' . $table . '`');
            $say('table_restored', $db->getVar('SELECT COUNT(*) FROM `' . $table . '`') !== null);
        }
        break;

    /**
     * CONTROL — the same answer on both trees.
     *
     * A revision that really does ask for a brand change, with no brand
     * decision in between, still applies it. The fix must narrow nothing here:
     * «برند تأییدشده‌ای که فروشنده انتخاب کرده» is a legitimate request and the
     * approval is what grants it.
     */
    case 'control-vendor-brand-change':
        $termC = $ensureBrand(TMC_P52_BRAND_C);
        $id = $arrange();
        $saved = $manage->save(
            TMC_P52_VENDOR,
            TMC_P52_VENDOR,
            $id,
            $detailsOf(TMC_P52_BRAND_C),
            [],
            $products->find($id)?->imageIds ?? [],
            $products->find($id)?->mainImageId ?? 0,
            $products->find($id)?->rowVersion ?? ''
        );
        $say('revision_code', $saved->code);
        $pending = $revisions->pendingFor($id);
        $decision = $pending === null ? null : $approveRevision($pending->id);
        $say('approve_code', $decision?->code ?? '__not_run__');
        $report($id, 'after_');
        $say('brand_is_c', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_C);
        $say('term_is_c', ($products->brandTermIdOf($id) ?? 0) === ($termC ?? -1));
        $say('shop_is_c', $shopBrand($id) === TMC_P52_BRAND_C);
        break;

    /** CONTROL — a stock-only proposal on a product whose brand nobody touched. */
    case 'control-stock-only':
        $id = $arrange();
        $inventory = $manage->updateInventory(TMC_P52_VENDOR, TMC_P52_VENDOR, $id, 7, TMC_P52_SKU, 1, 20, 1);
        $say('stock_proposal_code', $inventory->code);
        $pending = $revisions->pendingFor($id);
        $decision = $pending === null ? null : $approveRevision($pending->id);
        $say('approve_code', $decision?->code ?? '__not_run__');
        $report($id, 'after_');
        $say('brand_is_a', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_A);
        $say('stock_applied', ($products->find($id)?->details->stock ?? 0) === 7);
        break;

    /** CONTROL — rejecting the brand request leaves the revision alone. */
    case 'control-reject-keeps-revision':
        $id = $arrange();
        $openBoth($id);
        $open = $proposals->openFor($id);
        $rejected = $open === null ? null : $brands->reject($open->id, 'این برند تأیید نمی‌شود.');
        $say('reject_code', $rejected?->code ?? '__not_run__');
        $report($id, 'after_reject_');
        $pending = $revisions->pendingFor($id);
        $decision = $pending === null ? null : $approveRevision($pending->id);
        $say('approve_code', $decision?->code ?? '__not_run__');
        $report($id, 'after_stock_');
        $say('brand_is_a', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_A);
        $say('stock_applied', ($products->find($id)?->details->stock ?? 0) === 7);
        break;

    /**
     * THE FOURTH FINDING, and the form is read from its own HTML.
     *
     * `carryOver()` emits step 1's values as hidden inputs so saving step 2
     * does not reset them — and it takes them from `$details`, which on a
     * published product with a pending proposal IS THE PAYLOAD. The brand BOX
     * is rendered from the live row (deliberately). So after a brand approval
     * the form holds two different brands: «%s» in the box and the payload's
     * in a hidden field, and a step-2 save posts the hidden one.
     *
     * Hand-writing the POST would measure what I believe the form sends. The
     * `alpha.46` rule is to read it out of the rendered page, so the fields
     * come from the HTML `ProductArea` actually prints.
     */
    case 'carry-over-brand':
        $id = $arrange();
        $openBoth($id);
        $open = $proposals->openFor($id);
        $brandDecision = $open === null ? null : $brands->approve($open->id);
        $say('brand_approve_code', $brandDecision?->code ?? '__not_run__');
        $say('row_brand_now', $products->find($id)?->details->brand ?: '__empty__');
        $pending = $revisions->pendingFor($id);
        $say('payload_brand', (string) ($pending->payload['details']['brand'] ?? '__absent__'));

        // The rendered step-2 form, from the area itself.
        $_POST = [];
        $_GET = ['product' => (string) $id, 'step' => '2'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $urls = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            'https://example.test/vendor/products/'
        );
        $view = new \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView(
            \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
            TMC_P52_VENDOR,
            $urls,
            '',
            null
        );
        $html = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))
            ->render($view);
        $say('rendered', $html === null ? 'no' : 'yes');
        $shown = '';
        if (preg_match('~id="f-brand"[^>]*value="([^"]*)"~u', (string) $html, $m) === 1) {
            $shown = $m[1];
        } elseif (preg_match('~value="([^"]*)"[^>]*id="f-brand"~u', (string) $html, $m) === 1) {
            $shown = $m[1];
        }
        $say('box_shows', $shown === '' ? '__empty__' : $shown);
        // EXISTENCE AND VALUE ARE TWO QUESTIONS (`alpha.28`). The first run of
        // this verb printed `__empty__` for both «the field is not there» and
        // «the field is there and blank», which are different defects.
        $hidden = null;
        if (preg_match('~<input[^>]*name="brand"[^>]*>~u', (string) $html, $tag) === 1) {
            $hidden = preg_match('~value="([^"]*)"~u', $tag[0], $m) === 1 ? $m[1] : '';
            $say('hidden_tag', preg_replace('~\s+~', '_', $tag[0]));
        }
        $say('hidden_present', $hidden !== null);
        $say('hidden_carries', $hidden === null ? '__absent__' : ($hidden === '' ? '__empty__' : $hidden));
        $say('form_agrees_with_row', BrandName::key((string) $hidden) === BrandName::key($products->find($id)?->details->brand ?? ''));
        $say('form_carries_payload_brand', BrandName::key((string) $hidden) === BrandName::key((string) ($pending->payload['details']['brand'] ?? '')));

        // Now the step-2 save the browser would send, with the page's own fields.
        $fields = ['tmc_vendor_action' => 'save_product', 'product_id' => (string) $id, 'step' => '2'];
        if (preg_match_all('~<input type="hidden" name="([^"]+)" value="([^"]*)"~u', (string) $html, $all, PREG_SET_ORDER) !== false) {
            foreach ($all as $pair) {
                if (!in_array($pair[1], ['tmc_vendor_action', 'product_id', 'step'], true)) {
                    $fields[$pair[1]] = $pair[2];
                }
            }
        }
        foreach ([
            'price' => '320000', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '',
            'sku' => TMC_P52_SKU, 'stock' => '12', 'min_purchase' => '1',
            'max_purchase' => '20', 'purchase_step' => '1',
        ] as $name => $default) {
            $fields[$name] = $fields[$name] ?? $default;
        }
        // THE GALLERY, which arrives as a list and not as a scalar — so the
        // regex above cannot collect it. Without it the save proposes a
        // product with no pictures and the approval answers `missing_image`:
        // a true sentence about a POST this probe built badly, reported as a
        // result about the build (measured, and corrected).
        $fields['image_ids'] = array_map('strval', $products->find($id)?->imageIds ?? []);
        $fields['revision'] = $products->find($id)?->rowVersion ?? '';
        $_POST = $fields;
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $outcome = (new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea($c))->handle(
            'save_product',
            \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
            TMC_P52_VENDOR,
            $urls
        );
        $say('step2_save_code', $outcome?->code ?? '__none__');
        $fresh = $revisions->pendingFor($id);
        $say('revision_payload_brand', (string) ($fresh->payload['details']['brand'] ?? '__absent__'));
        $decision = $fresh === null ? null : $approveRevision($fresh->id);
        $say('approve_code', $decision?->code ?? '__not_run__');
        $report($id, 'after_');
        $say('brand_survived', ($products->find($id)?->details->brand ?? '') === TMC_P52_BRAND_B);
        $say('shop_survived', $shopBrand($id) === TMC_P52_BRAND_B);
        break;

    case 'reset':
        $id = $findBySku(TMC_P52_SKU);
        $say('product_id', $id);
        if ($id > 0) {
            $arrange();
        }
        $say('reset', 'done');
        break;

    default:
        fwrite(STDERR, "unknown verb: {$verb}\n");
        echo "unknown_verb=1\n";
}
