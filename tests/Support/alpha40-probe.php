<?php
declare(strict_types=1);

/*
 * The named defects of `alpha.41`, asked of whatever build is on disk.
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
 * compares. It names no symbol this round introduced (`HtmlSanitizerInterface`,
 * `StoreSettings::TAB_FIELDS`, `publicShop()`, `findByCreateToken()`,
 * `M0023ProductDescriptionAndCreateToken`), and where a signature grew
 * arguments it appends them BY REFLECTION so the earlier arity stays a legal
 * call — `alpha.40`'s fourth rule, which broke the previous round's evidence
 * tool on the very bytes it existed to measure.
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
 *   php tests/Support/alpha40-probe.php <verb>
 *
 * Verbs are listed in `tools/alpha41-reproduction.sh`, the only intended
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
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use TmcWpStubs\State;

foreach (['TMC_TEST_DB_DSN', 'TMC_TEST_DB_USER', 'TMC_TEST_DB_PASS'] as $var) {
    if (getenv($var) === false) {
        fwrite(STDERR, "alpha40-probe: {$var} is not set\n");
        exit(1);
    }
}
if (!str_contains((string) getenv('TMC_TEST_DB_DSN'), 'tmc_test')) {
    fwrite(STDERR, "alpha40-probe: refuses any DSN that is not the disposable tmc_test\n");
    exit(1);
}

const VENDOR = 71;
const NETWORKS = ['instagram', 'telegram'];
const CARRIERS = ['post', 'tipax'];

/** Everything the verbs share, built once per run. */
final class Alpha40World
{
    public WpDatabase $db;
    public SystemClock $clock;
    public AuditLogger $audit;
    public DbProductRepository $products;
    public DbStoreRepository $stores;
    public DbVendorRepository $vendors;
    public StaffAccess $access;
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
        // `alpha.40` tree that chain stops at 22, so `tmc_products` has
        // neither `description` nor `create_token`: exactly the state §2 and
        // §4 are about.
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
        $images = new FakeProductImages();
        $images->give(4301, VENDOR);
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

$verb = $argv[1] ?? '';

switch ($verb) {
    // --------------------------------------------- §1 store settings by tab

    /*
     * The input the vendor route BUILT, not a call to the route: every field
     * present, the off-tab ones empty. That is the shape `alpha.40` posted for
     * one tab, and it is the whole reason a shipping save wiped the city.
     */
    case 'tab-legacy-input':
        $w = new Alpha40World();
        $w->seedStore();
        $result = $w->settingsService()->save(VENDOR, VENDOR, [
            'city' => '', 'intro' => '', 'logo_id' => 0, 'banner_id' => 0,
            'preparation_days' => 9, 'origin_warehouse' => 'انبار تازه', 'carriers' => ['tipax'],
            'closed' => false, 'closed_from' => '', 'closed_to' => '', 'reopen_message' => '',
            'social' => [],
        ], NETWORKS, CARRIERS);
        $after = $w->stores->find(VENDOR);
        say('save_code', $result->code);
        say('city_after', $after?->city === '' ? 'empty' : (string) $after?->city);
        say('intro_after', $after?->intro === '' ? 'empty' : 'kept');
        say('days_after', (int) $after?->preparationDays);
        say('probe', 'ran');
        break;

    /*
     * The honest per-tab post: the tab is named and only its own fields are
     * sent. `alpha.40` ignores the name, so the fields whose ABSENCE is their
     * answer — the carrier checkboxes and the social URLs — are rebuilt from
     * an input that does not mention them, and emptied.
     */
    case 'tab-named':
        $w = new Alpha40World();
        $w->seedStore();
        $result = $w->settingsService()->save(VENDOR, VENDOR, [
            'tab' => 'shipping',
            'preparation_days' => 9,
            'origin_warehouse' => 'انبار تازه',
            'carriers' => ['tipax'],
        ], NETWORKS, CARRIERS);
        $after = $w->stores->find(VENDOR);
        say('save_code', $result->code);
        say('days_after', (int) $after?->preparationDays);
        say('carriers_after', implode('|', $after?->carriers ?? []));
        say('social_after', count($after?->social ?? []) > 0 ? 'kept' : 'empty');
        say('city_after', $after?->city === '' ? 'empty' : (string) $after?->city);
        say('probe', 'ran');
        break;

    /* A tab the page does not have. */
    case 'tab-unknown':
        $w = new Alpha40World();
        $w->seedStore();
        $result = $w->settingsService()->save(VENDOR, VENDOR, [
            'tab' => 'not-a-tab',
            'city' => 'شیراز',
        ], NETWORKS, CARRIERS);
        say('save_code', $result->code);
        say('city_after', (string) $w->stores->find(VENDOR)?->city);
        say('probe', 'ran');
        break;

    // ------------------------------------------------- §2 one create, once

    /*
     * The same create form posted twice — a timed-out request's replay.
     *
     * The SKU is EMPTY, and that is the whole reason the duplicate is
     * reachable. The owner was on step 1, «معرفی», and the SKU field is on
     * step 2 — so the post carries no SKU, and the unique index that would
     * have caught a second row has nothing to be unique about. Measured: with
     * a SKU filled in, `alpha.40` answers the replay with `sku_taken` and
     * makes one product (that is the `control-duplicate-sku-is-refused`
     * control, which still holds on both trees). The defect was never «a
     * create can be replayed»; it was «a create with no SKU has no identity».
     */
    case 'create-replay':
        $w = new Alpha40World();
        $first = $w->saveProduct($w->manage(), 0, $w->details(null, ''), [4301], 4301, '', 'tok-abc');
        $second = $w->saveProduct($w->manage(), 0, $w->details(null, ''), [4301], 4301, '', 'tok-abc');
        say('first_code', $first->code);
        say('second_code', $second->code);
        say('second_replayed', isset($second->context['replayed']) ? 'yes' : 'no');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    /* A create whose gallery write is refused after the row is in. */
    case 'half-written-draft':
        $w = new Alpha40World();
        $failing = new FailingDatabase(new WpDatabase($GLOBALS['wpdb']));
        $failing->failWhen(['INSERT INTO', M0005CreateProductTables::PRODUCT_IMAGES]);
        $result = $w->saveProduct($w->manage($failing), 0, $w->details(), [4301], 4301, '', 'tok-img');
        say('code', $result->code);
        say('names_draft', isset($result->context['product_id']) ? 'yes' : 'no');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    // ---------------------------------------------- §4 the two descriptions

    /* Is there a second description field at all, and does it survive a save? */
    case 'two-descriptions':
        $w = new Alpha40World();
        say('field_exists', property_exists(ProductDetails::class, 'description'));
        $made = $w->saveProduct($w->manage(), 0, $w->details('<p>متن کامل</p>'), [4301], 4301, '', 'tok-desc');
        say('create_code', $made->code);
        $productId = (int) ($made->context['product_id'] ?? 0);
        say('stored_long', $productId > 0 ? $w->storedDescription($productId) : 'no-product');
        say('probe', 'ran');
        break;

    /* A CSV row carrying markup the form would have filtered. */
    case 'csv-markup':
        $w = new Alpha40World();
        $csv = "sku,title,price,stock,description\n"
            . 'SKU-C,محصول,150000,3,"<p>سلام</p><script>alert(1)</script>"' . "\n";
        $report = $w->csv()->import(VENDOR, VENDOR, $csv, true);
        say('import_code', (string) $report['code']);
        say('created', (int) $report['created']);
        $rows = $w->products->forVendor(VENDOR);
        $stored = $rows === [] ? 'no-product' : $w->storedDescription($rows[0]->id);
        say('has_script', str_contains($stored, '<script') ? 'yes' : 'no');
        say('keeps_markup', str_contains($stored, '<p>سلام</p>') ? 'yes' : 'no');
        say('probe', 'ran');
        break;

    /* An old file, with no `description` column at all. */
    case 'csv-legacy-file':
        $w = new Alpha40World();
        $made = $w->saveProduct($w->manage(), 0, $w->details('<p>متن کامل</p>', 'SKU-L'), [4301], 4301, '', 'tok-leg');
        $productId = (int) ($made->context['product_id'] ?? 0);
        say('before', $productId > 0 ? $w->storedDescription($productId) : 'no-product');
        $report = $w->csv()->import(VENDOR, VENDOR, "sku,title,price,stock\nSKU-L,محصول,160000,6\n", true);
        say('import_code', (string) $report['code']);
        say('after', $productId > 0 ? $w->storedDescription($productId) : 'no-product');
        say('probe', 'ran');
        break;

    // -------------------------------------------------------- §5 the shop box

    /*
     * Which source names the shop on a product page, asked of the private
     * method itself: `alpha.40` reads the WordPress account's `display_name`,
     * which is the person's name and not the shop's.
     */
    case 'shop-name':
        $w = new Alpha40World();
        $w->seedStore();
        State::$users[VENDOR] = ['display_name' => 'رضا الف'];
        $method = new \ReflectionMethod(
            \Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\RatingStorefront::class,
            'shopName'
        );
        $method->setAccessible(true);
        $container = Bootstrap::container();
        $container->bind(
            \Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class,
            static fn () => $w->stores
        );
        $args = $method->getNumberOfParameters() > 1 ? [$container, VENDOR] : [VENDOR];
        say('shop_name', (string) $method->invokeArgs(null, $args));
        say('reads_account_name', $method->getNumberOfParameters() === 1);
        say('probe', 'ran');
        break;

    // -------------------------------------------------------------- controls
    //
    // These must answer IDENTICALLY on both trees. One that changes means the
    // revert broke the checkout, so every «defect» line above is about a
    // broken tree rather than about `alpha.40`.

    case 'control-rename-is-not-a-save':
        $w = new Alpha40World();
        $w->seedStore();
        $w->settingsService()->save(VENDOR, VENDOR, [
            'tab' => 'general',
            'city' => 'شیراز',
            'intro' => 'معرفی',
            'logo_id' => 0,
            'banner_id' => 0,
            'store_name' => 'نام دیگر',
        ], NETWORKS, CARRIERS);
        say('store_name', (string) $w->stores->find(VENDOR)?->storeName);
        say('probe', 'ran');
        break;

    case 'control-another-shop-is-refused':
        $w = new Alpha40World();
        $w->seedStore();
        $result = $w->settingsService()->save(999, VENDOR, [
            'tab' => 'general', 'city' => 'شیراز', 'intro' => '', 'logo_id' => 0, 'banner_id' => 0,
        ], NETWORKS, CARRIERS);
        say('code', $result->code);
        say('city_after', (string) $w->stores->find(VENDOR)?->city);
        say('probe', 'ran');
        break;

    case 'control-two-names-are-two-products':
        $w = new Alpha40World();
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-1'), [4301], 4301, '', 'tok-1');
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-2'), [4301], 4301, '', 'tok-2');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    case 'control-duplicate-sku-is-refused':
        $w = new Alpha40World();
        $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-S'), [4301], 4301, '', 'tok-a');
        $second = $w->saveProduct($w->manage(), 0, $w->details(null, 'SKU-S'), [4301], 4301, '', 'tok-b');
        say('second_code', $second->code);
        say('second_replayed', isset($second->context['replayed']) ? 'yes' : 'no');
        say('products_after', $w->products->countForVendor(VENDOR));
        say('probe', 'ran');
        break;

    case 'control-csv-never-publishes':
        $w = new Alpha40World();
        $report = $w->csv()->import(VENDOR, VENDOR, "sku,title,price,stock\nSKU-N,محصول,150000,3\n", true);
        say('created', (int) $report['created']);
        $rows = $w->products->forVendor(VENDOR);
        say('status', $rows === [] ? 'no-product' : $rows[0]->status->value);
        say('probe', 'ran');
        break;

    default:
        fwrite(STDERR, "alpha40-probe: unknown verb '" . $verb . "'\n");
        exit(2);
}
