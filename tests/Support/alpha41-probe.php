<?php
declare(strict_types=1);

/*
 * The named defects of `alpha.42`, asked of whatever build is on disk.
 *
 * ### Why a probe and not this round's tests
 *
 * `alpha.32` learned it, `alpha.37` restated it and `alpha.40` paid for it
 * again: a script that reverts `src/` and then runs the NEW round's tests
 * proves nothing. The tests name classes the old tree does not have, PHP
 * fatals at load, **zero tests execute**, and every «the defect reproduced»
 * line is a report about nothing having run.
 *
 * So this file asks only questions BOTH builds can answer — `save()` on
 * `UpdateStoreSettings` and on `ManageProducts`, `import()` on `ProductCsv`,
 * `find()` on the repositories — and prints `key=value` lines the shell
 * compares. It names no symbol this round introduced (`LongDescription`,
 * `ProductCreation`, `createForForm()`, `storedLongDescription()`,
 * `factAttributes()`), and where a signature grew arguments it appends them BY
 * REFLECTION so the earlier arity stays a legal call — `alpha.40`'s fourth
 * rule, which broke the previous round's evidence tool on the very bytes it
 * existed to measure. `detailsFromPost()` grew a parameter this round and is
 * private, so the probe goes through `ProductArea::handle()` with real
 * superglobals instead: the entry point both builds have.
 *
 * Two things this file deliberately does NOT do:
 *
 *  - **it never presents a stub as WooCommerce.** `FakeCatalogProjector` —
 *    the suite's own, so there is not a second one to keep in step — writes
 *    nothing. Nothing here is a claim about WooCommerce's behaviour, which is
 *    `Not Run` in this environment.
 *  - **it never calls a route.** §1's defect is in the input the vendor route
 *    BUILT — every field present, the ones off-tab empty — so the probe builds
 *    that same array and says so in the verb's name. It does not claim to have
 *    pressed the button.
 *
 * Usage:
 *   php tests/Support/alpha41-probe.php <verb>
 *
 * Verbs are listed in `tools/alpha42-reproduction.sh`, the only intended
 * caller. Every verb prints at least one `key=value` line and `probe=ran`; a
 * verb that prints no `probe=ran` did not execute, and the shell treats that
 * as a broken measurement rather than as a result.
 */

require dirname(__DIR__) . '/bootstrap-contract.php';

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\UpdateStoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Application\MobileVerification;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbChangeRequestRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStoreRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "alpha41-probe: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "alpha41-probe: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

const VENDOR = 71;
const NETWORKS = ['instagram', 'telegram'];
const CARRIERS = ['post', 'tipax'];

/** Everything the verbs share, built once per run. */
final class Alpha41World
{
    public WpDatabase $db;
    public SystemClock $clock;
    public AuditLogger $audit;
    public DbProductRepository $products;
    public DbStoreRepository $stores;
    public DbVendorRepository $vendors;
    public StaffAccess $access;
    public FakeProductImages $images;
    public DbSpecTemplateRepository $templates;
    public DbProductRevisionRepository $revisions;

    public function __construct()
    {
        State::$optionsBackedByWpdb = true;
        $GLOBALS['wpdb'] = new \wpdb();
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->ensureOptionsTable();
        $wpdb->pdo()->exec("TRUNCATE TABLE `{$wpdb->options}`");

        $this->db = new WpDatabase($wpdb);
        $this->clock = new SystemClock();
        $this->audit = new AuditLogger(new WpAuditRepository($wpdb), new AuditEventSanitizer(), $this->clock);

        // The real chain, from `Bootstrap::migrations()` — never a list this
        // file keeps, which is how the suite's lists went stale. On the
        // `alpha.41` tree the chain already reaches 23, because this round
        // adds no migration at all — so both trees have the same tables and
        // every difference measured below is behaviour, not schema.
        $this->dropEverything($wpdb);
        foreach (Bootstrap::migrations() as $migration) {
            $migration->up($this->db);
        }

        $this->products = new DbProductRepository($this->db, $this->clock);
        $this->stores = new DbStoreRepository($this->db, $this->clock);
        $this->vendors = new DbVendorRepository($this->db, $this->clock);
        $this->templates = new DbSpecTemplateRepository($this->db, $this->clock);
        $this->revisions = new DbProductRevisionRepository($this->db, $this->clock);
        $this->access = new StaffAccess(new DbStaffRepository($this->db, $this->clock), $this->vendors);

        $this->images = new FakeProductImages();
        $this->images->give(4301, VENDOR);
        $this->vendors->upsertProfile(VENDOR, 'داروخانهٔ probe', true, false);
    }

    /** `information_schema`, not `SHOW TABLES LIKE`: the prefix can hold `_`. */
    private function dropEverything(\wpdb $wpdb): void
    {
        $stmt = $wpdb->pdo()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
        );
        $stmt->execute([$wpdb->prefix . 'tmc\_%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $wpdb->dropTable((string) $table);
        }
    }

    public function settingsService(): UpdateStoreSettings
    {
        return new UpdateStoreSettings(
            $this->stores,
            new DbChangeRequestRepository($this->db, $this->clock),
            $this->access,
            $this->audit,
            new MobileVerification(new NullOtpProvider())
        );
    }

    public function manage(?\Tecteb\Marketplace\Contracts\DatabaseInterface $over = null): ManageProducts
    {
        $db = $over ?? $this->db;
        $products = $over === null ? $this->products : new DbProductRepository($db, $this->clock);
        $variations = new DbVariationRepository($db, $this->clock);
        $images = $this->images;
        return new ManageProducts(
            $products,
            $this->templates,
            $over === null ? $this->revisions : new DbProductRevisionRepository($db, $this->clock),
            new ProductReadiness($this->templates, $variations),
            new SyncCatalog($products, $variations, new FakeCatalogProjector(), $this->audit),
            $images,
            $this->access,
            new ProductPublishPolicy(new \Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore()),
            new ProductStateMachine(),
            $this->audit,
            new DbProductDecisionRepository($db, $this->clock)
        );
    }

    /**
     * `ProductCsv` grew a sanitiser argument this round, so it is appended by
     * reflection rather than written out: on the `alpha.40` tree the shorter
     * arity is the only legal call, and a probe that assumed the new one would
     * not load on the very bytes it exists to measure.
     */
    public function csv(): ProductCsv
    {
        $args = [$this->products, $this->templates, $this->manage(), $this->access, $this->audit];
        $wanted = (new \ReflectionClass(ProductCsv::class))->getConstructor()?->getNumberOfParameters() ?? count($args);
        if ($wanted > count($args)) {
            $args[] = new \Tecteb\Marketplace\Tests\Support\FakeHtmlSanitizer();
        }
        return new ProductCsv(...$args);
    }

    public function seedStore(): void
    {
        $settings = new StoreSettings(
            storeName: 'داروخانهٔ probe',
            city: 'تهران',
            intro: 'معرفی فروشگاه',
            preparationDays: 4,
            carriers: ['post'],
            social: ['telegram' => 'https://t.me/probe']
        );
        $save = (new \ReflectionMethod(DbStoreRepository::class, 'save'));
        $args = [VENDOR, $settings];
        if ($save->getNumberOfParameters() > 2) {
            $args[] = null;         // «every column», the new parameter's default
        }
        $save->invokeArgs($this->stores, $args);
    }

    /** Appends `$createToken` only when this build's `save()` has room for it. */
    public function saveProduct(
        ManageProducts $manage,
        int $productId,
        ProductDetails $details,
        array $imageIds = [],
        int $mainImageId = 0,
        string $revision = '',
        string $createToken = ''
    ): \Tecteb\Marketplace\Modules\Vendor\Application\OperationResult {
        $args = [VENDOR, VENDOR, $productId, $details, [], $imageIds, $mainImageId, $revision];
        $wanted = (new \ReflectionMethod(ManageProducts::class, 'save'))->getNumberOfParameters();
        if ($wanted > count($args) && $createToken !== '') {
            $args[] = $createToken;
        }
        return $manage->save(...$args);
    }

    /** The details both trees can build: `description` is appended by name. */
    public function details(?string $description = null, string $sku = 'SKU-P'): ProductDetails
    {
        $args = [
            'title' => 'دستکش لاتکس',
            'categoryKey' => 'gloves',
            'shortDescription' => 'یک جمله',
            'priceMinor' => 200000,
            'sku' => $sku,
            'stock' => 5,
        ];
        if ($description !== null && property_exists(ProductDetails::class, 'description')) {
            $args['description'] = $description;
        }
        return new ProductDetails(...$args);
    }

    /**
     * The plugin booted far enough for `ProductArea::handle()` to run.
     *
     * The area resolves its own services from the container and asks
     * `StaffAccess::storeFor()` who the vendor is, so the probe cannot build
     * it by hand — it has to be the real container with a real approved
     * profile behind it.
     */
    public function bootArea(): void
    {
        Bootstrap::init(dirname(__DIR__, 2) . '/tecteb-marketplace-core.php', '0.0.0-probe');
        do_action('plugins_loaded');
        State::loginAs(VENDOR, []);
    }

    public function legacyProduct(): int
    {
        return $this->products->create(
            VENDOR,
            new ProductDetails(
                title: 'دستکش لاتکس',
                categoryKey: 'gloves',
                shortDescription: 'یک جمله',
                priceMinor: 200000,
                sku: 'SKU-PROBE',
                stock: 5
            ),
            ProductStatus::Draft
        );
    }

    public function revisionOf(int $productId): string
    {
        return $this->products->find($productId)?->rowVersion ?? '';
    }

    /** @return list<int> */
    public function galleryOf(int $productId): array
    {
        return $this->products->find($productId)?->imageIds ?? [];
    }

    public function giveImage(int $mediaId): void
    {
        $this->images->give($mediaId, VENDOR);
    }

    public function storedDescription(int $productId): string
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return 'no-product';
        }
        if (!property_exists(ProductDetails::class, 'description')) {
            return 'no-field';
        }
        $value = $product->details->description;
        return $value === null ? 'null' : $value;
    }
}

function say(string $key, int|string|bool|null $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key, '=', $value === null ? 'null' : (string) $value, "\n";
}


/** One real POST through the area router, on whichever build is on disk. */
function postToArea(array $fields, int $vendor): \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome
{
    $_POST = $fields;
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
    $area = new \Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductArea(
        \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::container()
    );
    $outcome = $area->handle(
        'save_product',
        \Tecteb\Marketplace\Infrastructure\WordPress\Http\Request::capture(),
        $vendor,
        $urls
    );
    if ($outcome === null) {
        throw new \RuntimeException('the area router did not handle save_product');
    }
    return $outcome;
}

/**
 * The fields the rendered form sends — including the ones only ONE build
 * sends.
 *
 * `description_given` is `alpha.41`'s flag and `description_base` is this
 * round's marker, and BOTH are posted on purpose: each build reads its own and
 * ignores the other, so one submission measures both trees without the probe
 * choosing a side. That is the whole shape of the defect — `alpha.41` turns
 * its flag into an empty string, this build reads the base and does nothing.
 *
 * @param array<string,mixed> $override
 * @return array<string,mixed>
 */
function formFields(int $productId, string $revision, array $override = []): array
{
    return array_merge([
        'tmc_vendor_action' => 'save_product',
        'product_id' => (string) $productId,
        'step' => '1',
        'revision' => $revision,
        'title' => 'دستکش لاتکس',
        'type' => 'simple',
        'category' => 'gloves',
        'brand' => 'آریا طب',
        'short_description' => 'یک جمله',
        'price' => '200000',
        'sale_price' => '',
        'sale_from' => '',
        'sale_to' => '',
        'sku' => 'SKU-PROBE',
        'stock' => '5',
        'min_purchase' => '1',
        'max_purchase' => '',
        'weight_grams' => '0',
        'dimensions' => '',
        'tax_class' => '',
        'main_image_id' => '0',
        // One submission, both dialects.
        'description' => '',
        'description_given' => '1',
        'description_base' => 'none',
    ], $override);
}

$verb = $argv[1] ?? '';

switch ($verb) {
    // ------------------------- §1 the legacy product's long description

    /*
     * Open a legacy product's form, change the title, save. `alpha.41` turns
     * the empty box into `''` — «delete the shop's description» — about a
     * field nobody touched.
     */
    case 'legacy-title-save':
        $w = new Alpha41World();
        $w->bootArea();
        $productId = $w->legacyProduct();
        say('before', $w->storedDescription($productId));
        $outcome = postToArea(formFields($productId, $w->revisionOf($productId), [
            'title' => 'عنوان تازه',
        ]), VENDOR);
        say('code', $outcome->code);
        say('title_after', (string) $w->products->find($productId)?->details->title);
        say('after', $w->storedDescription($productId));
        say('probe', 'ran');
        break;

    /* The explicit clear, which must work on the fixed build. */
    case 'legacy-explicit-clear':
        $w = new Alpha41World();
        $w->bootArea();
        $productId = $w->legacyProduct();
        $outcome = postToArea(formFields($productId, $w->revisionOf($productId), [
            'description_clear' => '1',
        ]), VENDOR);
        say('code', $outcome->code);
        say('after', $w->storedDescription($productId));
        say('probe', 'ran');
        break;

    // ------------------------------------------- §2 the create redirect

    case 'create-redirect':
        $w = new Alpha41World();
        $w->bootArea();
        $outcome = postToArea(formFields(0, '', [
            'title' => 'محصول تازه',
            'create_token' => 'tok-redirect',
        ]), VENDOR);
        say('code', $outcome->code);
        say('lands_on_step', preg_match('/step=(\d)/', $outcome->target, $m) === 1 ? $m[1] : 'none');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    // ------------------------------------------------- §3 the replay

    /* A replay of a create whose gallery failed. */
    case 'replay-after-failure':
        $w = new Alpha41World();
        $failing = new FailingDatabase(new WpDatabase($GLOBALS['wpdb']));
        $failing->failWhen(['INSERT INTO', M0005CreateProductTables::PRODUCT_IMAGES]);
        $first = $w->saveProduct($w->manage($failing), 0, $w->details(), [4301], 4301, '', 'tok-replay');
        say('first_code', $first->code);
        $again = $w->saveProduct($w->manage(), 0, $w->details(), [4301], 4301, '', 'tok-replay');
        say('replay_code', $again->code);
        say('claims_created', $again->code === 'product_created');
        say('probe', 'ran');
        break;

    /*
     * TWO SIMULTANEOUS submissions of one form, and what the LOSER does next.
     *
     * This is the real §3(b), and the probe's first version measured the wrong
     * thing: a SEQUENTIAL replay never overwrote anything, because `alpha.41`
     * answered it in `save()` before `create()` was reached. The overwrite is
     * reachable only when both requests get past that read — then the unique
     * key refuses one insert, the loser is handed the WINNER'S id, and on
     * `alpha.41` it carried on and wrote its own gallery over that row.
     *
     * SCHEDULED, not raced, and named as such: the other request runs inside
     * this process immediately before this one's `INSERT`, on the plain
     * gateway. The index is what decides, and an index does not care which
     * request arrives first. A real two-process race belongs where there is a
     * transaction to get past.
     */
    case 'simultaneous-overwrite':
        $w = new Alpha41World();
        $w->giveImage(4303);
        $interfering = new InterferingDatabase(new WpDatabase($GLOBALS['wpdb']));
        $interfering->before(['INSERT INTO', M0005CreateProductTables::PRODUCTS], 1, static function () use ($w): void {
            // The winner, with ITS own picture.
            $w->saveProduct($w->manage(), 0, $w->details(), [4301], 4301, '', 'tok-sim');
        });
        // The loser, with a different one, so an overwrite is unmistakable.
        $lost = $w->saveProduct($w->manage($interfering), 0, $w->details(), [4303], 4303, '', 'tok-sim');
        say('interference_fired', $interfering->fired !== []);
        say('loser_code', $lost->code);
        $productId = (int) ($lost->context['product_id'] ?? 0);
        say('products_after', $w->products->countForVendor(VENDOR));
        say('gallery', $productId > 0 ? implode(',', $w->galleryOf($productId)) : 'no-product');
        say('probe', 'ran');
        break;

    /*
     * A SEQUENTIAL replay arriving after the vendor edited the draft.
     *
     * A CONTROL, and it earned that label by failing as a defect line: both
     * trees answer the same, because `alpha.41` short-circuited a sequential
     * replay before any write. Kept, because it is the property the fix must
     * not lose.
     */
    case 'control-replay-after-edit':
        $w = new Alpha41World();
        $first = $w->saveProduct($w->manage(), 0, $w->details(), [4301], 4301, '', 'tok-edit');
        $productId = (int) ($first->context['product_id'] ?? 0);
        say('created', $productId > 0);
        $w->giveImage(4302);
        $edited = $w->saveProduct(
            $w->manage(),
            $productId,
            $w->details(),
            [4302],
            4302,
            $w->revisionOf($productId)
        );
        say('edit_code', $edited->code);
        say('gallery_after_edit', implode(',', $w->galleryOf($productId)));
        $w->saveProduct($w->manage(), 0, $w->details(), [4301], 4301, '', 'tok-edit');
        say('gallery_after_replay', implode(',', $w->galleryOf($productId)));
        say('probe', 'ran');
        break;

    // --------------------------------- §4 the brand and the specification

    /*
     * Structural, and deliberately so: driving `project()` needs WooCommerce
     * product classes that the `alpha.41` tree's own test environment does not
     * define either, so what is measured here is whether the projector has a
     * facts block AT ALL. The behaviour is measured by `ProjectedFactsTest`,
     * which falsifies against the same bytes through the contract suite.
     */
    case 'facts-block':
        $projector = new \ReflectionClass(
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector::class
        );
        say('has_facts_block', $projector->hasMethod('factAttributes'));
        say('merges_attributes', $projector->hasMethod('mergeAttributes'));
        say('reads_templates', $projector->getConstructor()?->getNumberOfParameters() === 2);
        say('probe', 'ran');
        break;

    // ------------------------------------------------ §5 the one box rule

    case 'one-box':
        $storefront = new \ReflectionClass(
            \Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\RatingStorefront::class
        );
        say('has_once_guard', $storefront->hasMethod('box'));
        say('probe', 'ran');
        break;

    // -------------------------------------------------------------- controls
    //
    // The same answer on both trees, or the revert broke the checkout and
    // every «defect» line above is about that instead.

    case 'control-another-shop-is-refused':
        $w = new Alpha41World();
        $w->bootArea();
        $productId = $w->legacyProduct();
        $outcome = postToArea(formFields($productId, $w->revisionOf($productId), [
            'title' => 'دستِ یکی دیگر',
        ]), 999999);
        say('code', $outcome->code);
        say('title_after', (string) $w->products->find($productId)?->details->title);
        say('probe', 'ran');
        break;

    case 'control-stale-revision-is-refused':
        $w = new Alpha41World();
        $w->bootArea();
        $productId = $w->legacyProduct();
        $outcome = postToArea(formFields($productId, '1', ['title' => 'با مهر کهنه']), VENDOR);
        say('code', $outcome->code);
        say('probe', 'ran');
        break;

    case 'control-two-names-are-two-products':
        $w = new Alpha41World();
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-1'), [4301], 4301, '', 'tok-1');
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-2'), [4301], 4301, '', 'tok-2');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    case 'control-duplicate-sku-is-refused':
        $w = new Alpha41World();
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-S'), [4301], 4301, '', 'tok-a');
        $second = $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-S'), [4301], 4301, '', 'tok-b');
        say('second_code', $second->code);
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    case 'control-csv-never-publishes':
        $w = new Alpha41World();
        $report = $w->csv()->import(VENDOR, VENDOR, "sku,title,price,stock\nSKU-N,محصول,150000,3\n", true);
        say('created', (int) $report['created']);
        $rows = $w->products->forVendor(VENDOR);
        say('status', $rows === [] ? 'no-product' : $rows[0]->status->value);
        say('probe', 'ran');
        break;

    default:
        fwrite(STDERR, "alpha41-probe: unknown verb '" . $verb . "'\n");
        exit(2);
}
