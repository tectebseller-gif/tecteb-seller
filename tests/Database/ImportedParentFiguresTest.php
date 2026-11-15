<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Modules\Operations\Infrastructure\Rest\MarketplaceController;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationReaderInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\PriceAndStockOrigin;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontPriceRange;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontVariations;
use Tecteb\Marketplace\Tests\Support\FakeUnpaidOrderGuard;

/**
 * WHAT THIS PROVES: no save of any kind moves an imported variable parent's
 * price, stock or stock status — and the form is never offered a nought to
 * save in the first place.
 *
 * ⛔ THE FIXTURE IS SYNTHETIC AND DELIBERATELY SO. §۳ of the instruction is
 * explicit: «هرگز سفارش #12316 یا محصولات واقعی #11955/#11965 … را برای نوشتن
 * تست استفاده نکن.» The shape is the one measured on the owner's staging — a
 * marketplace row saying `variable` with `price_minor = 0` and `stock = 0`,
 * published, linked to a storefront parent, and our own attribute and
 * variation tables EMPTY while the shop holds three priced children — but the
 * ids are this test's own and nothing here can reach a live site.
 *
 * ⛔ AND THE ROW IS WRITTEN BY SQL, which is also deliberate. The real path
 * that produces it is `ImportFromDokan`, and going through readiness and
 * approval to reach it is impossible precisely BECAUSE of the second defect
 * this class pins (`missingFields()` calls the parent's nought a missing
 * price). A fixture that could only be built through the code under test
 * would be a fixture that cannot express the bug.
 */
final class ImportedParentFiguresTest extends DatabaseTestCase
{
    private const VENDOR = 102;
    private const MANAGER = 9;
    private const WC_PARENT = 7700;

    private DbProductRepository $products;
    private DbVariationRepository $variations;
    private DbProductRevisionRepository $revisions;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private SyncCatalog $catalog;
    private FakeCatalogProjector $projector;
    private FakeStorefrontVariations $storefront;
    private FakeProductImages $images;
    private ProductReadiness $readiness;
    private StorefrontStop $stop;
    private StorefrontSwitch $switch;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->products = new DbProductRepository($db, $clock);
        $this->variations = new DbVariationRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $revisions = new DbProductRevisionRepository($db, $clock);
        $this->revisions = $revisions;
        $vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->images = new FakeProductImages();
        $access = new StaffAccess($staff, $vendors);
        $this->projector = new FakeCatalogProjector();
        $this->storefront = new FakeStorefrontVariations();
        $this->readiness = new ProductReadiness($templates, $this->variations, $this->storefront);
        $readiness = $this->readiness;
        $this->catalog = new SyncCatalog(
            $this->products,
            $this->variations,
            $this->projector,
            $audit,
            $this->storefront
        );
        $publishing = new ProductPublishPolicy($options);
        $states = new ProductStateMachine();

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $revisions,
            $readiness,
            $this->catalog,
            $this->images,
            $access,
            $publishing,
            $states,
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $revisions,
            $templates,
            $readiness,
            $this->catalog,
            $publishing,
            $states,
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS])
        );

        $this->switch = new StorefrontSwitch($options, $clock);
        $this->stop = new StorefrontStop(
            $this->products,
            $this->catalog,
            $this->readiness,
            $access,
            $this->switch,
            $audit,
            new FakeUnpaidOrderGuard()
        );

        $vendors->upsertProfile(self::VENDOR, 'تجهیزات پزشکی نمونه', true, false);
    }

    /**
     * The measured shape: published, variable, parent figures nought, linked,
     * our tables empty, the shop holding three priced children.
     */
    private function importedParent(): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'ست کامل تجهیزات نمونه',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 1,
            sku: 'FIXTURE-PARENT',
            stock: 1
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];

        $this->images->give(4200, self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'ست کامل تجهیزات نمونه',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 1,
            sku: 'FIXTURE-PARENT',
            stock: 1
        ), [], [4200], 4200, $this->products->rowVersion($productId));

        // Now the imported shape, by SQL — see the class docblock for why.
        $table = $this->wpdb->prefix . M0005CreateProductTables::PRODUCTS;
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$table}` SET `type` = 'variable', `price_minor` = 0, `stock` = 0,"
            . " `status` = 'published', `wc_product_id` = %d WHERE `id` = %d",
            self::WC_PARENT,
            $productId
        ));

        // The storefront parent, and the projector's link to it.
        $this->projector->project($this->products->find($productId));
        $this->projector->links[$productId] = self::WC_PARENT;
        $this->storefront->holds(self::WC_PARENT, [
            $this->child(11966, '50', 2_593_700),
            $this->child(11965, '100', 5_187_400),
            $this->child(11967, '200', 10_374_800),
        ]);
        return $productId;
    }

    private function child(
        int $id,
        string $size,
        int $price,
        // ⛔ Named and defaulted, because the first version of this helper took
        // three arguments and a test passed a fourth. PHP accepts extra
        // arguments to a non-variadic function and drops them, so «every child
        // disabled» silently measured «every child enabled» and the refusal it
        // was written to prove reported ready. A measurement that cannot fail
        // is not a measurement (`alpha.46`).
        bool $enabled = true
    ): StorefrontVariation {
        return new StorefrontVariation(
            $id,
            ['pa_size' => $size],
            'BPI-' . $size,
            $price,
            null,
            $price,
            $enabled,
            false,
            null,
            'instock',
            'no',
            0
        );
    }

    private function parentFigures(int $productId): array
    {
        $product = $this->products->find($productId);
        return [
            'price' => $product?->details->priceMinor,
            'stock' => $product?->details->stock,
            'type' => $product?->details->type,
            'wc' => (int) $product?->wcProductId,
        ];
    }

    /**
     * ⛔ THE DEFECT, AT ITS WORST: a title-only save took the product off sale.
     *
     * Traced on the `alpha.105` bytes. `saveAsRevision()` reads
     * `effectiveStock()`, which asks `pullStock()`; a variable parent leaves
     * stock to its children so WooCommerce answers `null`, and the fallback is
     * our own row — nought. So `$details->stock === $effectiveStock` is `0 === 0`,
     * `$stockProposed` is FALSE, and the method pushes nought outward
     * unconditionally. `WooCommerceProjector::writeStock()` then writes
     * `set_manage_stock(true)`, `set_stock_quantity(0)` and
     * `set_stock_status('outofstock')` on the PARENT — which on WooCommerce
     * stops the whole product selling, all three variations with it.
     *
     * The vendor changed a word in the title.
     */
    public function testATitleOnlySaveWritesNothingToTheParentsFigures(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);

        $saved = $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'ست کامل تجهیزات نمونه — ویرایش عنوان',
            type: ProductType::VARIABLE,
            categoryKey: '9559',
            priceMinor: 0,
            stock: 0,
            sku: 'FIXTURE-PARENT'
        ), [], [4200], 4200, $this->products->rowVersion($productId));

        self::assertTrue($saved->ok, $saved->code);
        self::assertSame(
            [],
            $this->projector->unguardedStockWrites,
            'not one unguarded stock write reached the shop'
        );
        self::assertSame($before, $this->parentFigures($productId), 'and our own row did not move either');
    }

    /**
     * The independent path, which does not go through the form at all.
     *
     * `updateInventory()` is reachable from the inventory screen and from any
     * caller that holds the service, and in `alpha.105` it pushed the stock
     * outward on exactly the same arithmetic — answering `inventory_saved`.
     */
    public function testTheIndependentInventoryPathRefusesByName(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);

        $result = $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $productId,
            12,
            'FIXTURE-PARENT',
            1,
            null
        );

        self::assertFalse($result->ok, 'a stock edit on a parent whose stock is not ours is refused');
        self::assertSame('parent_figures_live_in_shop', $result->code);
        self::assertSame('stock', $result->context['fields']);
        self::assertSame('shop_combinations', $result->context['origin']);
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame($before, $this->parentFigures($productId));
    }

    /**
     * ⛔ AND THE OTHER HALF OF §D, which matters as much: the SKU and the
     * three purchase limits ARE the parent's own on WooCommerce, so an edit to
     * those still goes through — and still writes nothing to the figures.
     *
     * Refusing the whole verb would have closed the inventory screen for a
     * vendor who only wanted to correct a SKU. A guard wider than the defect
     * is the bigger defect (`alpha.60`).
     */
    public function testTheSkuAndThePurchaseLimitsStillSaveAndTouchNoFigures(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);

        $result = $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $productId,
            0,                      // unchanged, which is the form's round trip
            'BPI-PARENT-NEW',
            2,
            10,
            2
        );

        self::assertTrue($result->ok, $result->code);
        self::assertSame('BPI-PARENT-NEW', $this->products->find($productId)?->details->sku);
        self::assertSame(2, $this->products->find($productId)?->details->minPurchase);
        self::assertSame(10, $this->products->find($productId)?->details->maxPurchase);
        self::assertSame(
            [],
            $this->projector->unguardedStockWrites,
            'the unchanged nought was still never written outward'
        );
        self::assertSame($before['price'], $this->parentFigures($productId)['price']);
        self::assertSame($before['stock'], $this->parentFigures($productId)['stock']);
    }

    /**
     * A HAND-MADE POST, which is the test §۱-ب asks for: the protection must
     * live below the form, so a request that never rendered one is refused
     * just the same.
     */
    public function testAHandMadePostCarryingAPriceChangesNothing(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);

        $saved = $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'ست کامل تجهیزات نمونه',
            type: ProductType::VARIABLE,
            categoryKey: '9559',
            priceMinor: 999_000,
            stock: 77,
            sku: 'FIXTURE-PARENT'
        ), [], [4200], 4200, $this->products->rowVersion($productId));

        self::assertFalse($saved->ok);
        self::assertSame('parent_figures_live_in_shop', $saved->code);
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame($before, $this->parentFigures($productId), 'no partial write before the refusal');
    }

    /**
     * ⛔ AND THE CONTROL, which matters as much as the refusals: a simple
     * product's own stock edit still works. A guard wider than the defect
     * would have closed the inventory screen for the whole catalogue
     * (`alpha.60`).
     */
    public function testASimpleProductsOwnInventoryEditStillGoesThrough(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'باند کشی ساده',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'SIMPLE-1',
            stock: 9
        ));
        $productId = (int) $created->context['product_id'];

        $result = $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $productId,
            4,
            'SIMPLE-1',
            1,
            null
        );

        self::assertTrue($result->ok, $result->code);
        self::assertSame(4, $this->products->find($productId)?->details->stock);
    }

    /** The rule itself, read through the service the save paths consult. */
    public function testTheServiceReportsWhereTheFiguresLive(): void
    {
        $productId = $this->importedParent();

        $origin = $this->catalog->priceAndStockOrigin($productId);
        self::assertSame(PriceAndStockOrigin::SHOP, $origin->state);
        self::assertSame('shop_combinations', $origin->reason);
    }

    /**
     * A SHOP THAT WILL NOT SAY is not permission to write — the fail-closed
     * direction §P0-پ asks for by name.
     */
    public function testAnUnreadableShopIsStillNotPermissionToWrite(): void
    {
        $productId = $this->importedParent();
        $this->storefront->answers(
            self::WC_PARENT,
            StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE)
        );
        $before = $this->parentFigures($productId);

        $origin = $this->catalog->priceAndStockOrigin($productId);
        self::assertSame(PriceAndStockOrigin::UNKNOWN, $origin->state);
        self::assertSame('unreadable', $origin->reason);

        $result = $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $productId,
            5,
            'FIXTURE-PARENT',
            1,
            null
        );
        self::assertFalse($result->ok);
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame($before, $this->parentFigures($productId));
    }

    /**
     * ⛔ P0-الف: READINESS SAID «no price» ABOUT A PRODUCT THAT HAS THREE.
     *
     * `missingFields()` names `price` for `priceMinor <= 0` on every product
     * type, and `check()` asks it FIRST — so an imported parent never reached
     * `variableIsSellable()` at all. On the `alpha.105` bytes this fixture
     * answers `incomplete_product` with `fields = price`.
     */
    public function testAnImportedParentWithRealVariationsIsReady(): void
    {
        $productId = $this->importedParent();

        $verdict = $this->readiness->check($this->products->find($productId));

        self::assertTrue($verdict->ok, $verdict->code . ' ' . ($verdict->context['fields'] ?? ''));
        self::assertSame('ready', $verdict->code);
    }

    /**
     * And the refusals that must SURVIVE the fix, each by its own name. A
     * readiness check that said yes to everything would be the `alpha.60`
     * mistake in the other direction.
     */
    public function testAnImportedParentWhoseShopHoldsNothingBuyableIsStillRefused(): void
    {
        $productId = $this->importedParent();

        // Every child disabled: read, real, and not one of them sellable.
        $this->storefront->holds(self::WC_PARENT, [
            $this->child(11966, '50', 2_593_700, false),
        ]);
        $verdict = $this->readiness->check($this->products->find($productId));
        self::assertFalse($verdict->ok);
        self::assertSame('variable_needs_priced_variation', $verdict->code);

        // A read that failed is refused with a reason of its own rather than
        // being declared ready — «در وضعیت نامطمئن با دلیلِ مشخص fail-closed».
        $this->storefront->answers(
            self::WC_PARENT,
            StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE)
        );
        $unreadable = $this->readiness->check($this->products->find($productId));
        self::assertFalse($unreadable->ok);
        self::assertSame('variations_unreadable', $unreadable->code);
        self::assertSame('unreadable', $unreadable->context['reason']);
    }

    /**
     * A missing TITLE is still missing. Only the price entry is dropped, so a
     * genuinely incomplete product is still named — the half of the fix that
     * stops it becoming a blanket yes.
     */
    public function testOnlyThePriceEntryIsDroppedNotTheWholeCheck(): void
    {
        $productId = $this->importedParent();
        $table = $this->wpdb->prefix . M0005CreateProductTables::PRODUCTS;
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$table}` SET `title` = '' WHERE `id` = %d",
            $productId
        ));

        $verdict = $this->readiness->check($this->products->find($productId));

        self::assertFalse($verdict->ok);
        self::assertSame('incomplete_product', $verdict->code);
        self::assertSame('title', $verdict->context['fields'], 'title only — price is not named');
    }

    /**
     * ⛔ THE ONE THAT DOES REAL HARM: a healthy imported product taken down by
     * a temporary stop could not be put back.
     *
     * `StorefrontStop::refusalFor()` runs the same readiness check, so on the
     * `alpha.105` bytes `resume()` answered `incomplete` for this product and
     * left it out of the shop — with no screen anywhere saying why, and no
     * number of retries changing it.
     */
    public function testAStopAndResumeBringsTheImportedParentBack(): void
    {
        $productId = $this->importedParent();

        $stopped = $this->stop->stop('آزمون محلی');
        self::assertArrayHasKey('withdrawn', $stopped);

        $resumed = $this->stop->resume();

        self::assertSame(
            [],
            $resumed['refused'],
            'nothing was refused — and on alpha.105 this product was'
        );
        self::assertSame(1, $resumed['published']);
        self::assertFalse($this->switch->isStopped());
    }
    /**
     * ⛔ P0-ت: A FORGED `type` IS NOT A MIGRATION PLAN.
     *
     * `ProductArea::detailsFromPost()` reads `type` from the request and
     * `validate()` only asks whether the word is one of the four this plugin
     * knows. On `alpha.105` that was the whole of it, so a POST naming
     * `simple` for a parent whose three variations live in WooCommerce was
     * accepted — and from the next projection the shop would hold a simple
     * product with three children parented to something that no longer has
     * any, which nothing in this plugin writes back.
     *
     * Refused by name, before anything is written.
     */
    public function testAForgedTypeFlipOnAnImportedParentIsRefusedByName(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);
        // The fixture projects once, so an absolute `[]` here would measure the
        // fixture rather than the refusal (`alpha.46`).
        $statusesBefore = $this->projector->statuses;

        $saved = $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'ست کامل تجهیزات نمونه',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 0,
            stock: 0,
            sku: 'FIXTURE-PARENT'
        ), [], [4200], 4200, $this->products->rowVersion($productId));

        self::assertFalse($saved->ok, 'a type flip on a parent whose combinations are the shop’s is refused');
        self::assertSame('type_change_not_allowed', $saved->code);
        self::assertSame(ProductType::VARIABLE, $saved->context['from']);
        self::assertSame(ProductType::SIMPLE, $saved->context['to']);
        self::assertSame('shop_combinations', $saved->context['origin']);
        self::assertSame($before, $this->parentFigures($productId), 'and the row did not move');
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame(
            $statusesBefore,
            $this->projector->statuses,
            'nothing was published or drafted either'
        );
    }

    /**
     * ⛔ AND THE ROW THAT IS ALREADY IN THE QUEUE.
     *
     * The guard above closes the way IN, which says nothing about a revision
     * recorded before it existed. Such a row carries `details.type` as a
     * snapshot, and `approveRevision()` is the one path that copies a payload
     * onto a LIVE product — so a guard that held only because `save()` refuses
     * first would be an ordering, not a guard (`alpha.98`).
     *
     * The revision is written DIRECTLY, because only an older version of this
     * code could have produced it (`alpha.91`): `save()` would now refuse it.
     */
    public function testAPendingRevisionCarryingATypeFlipCannotBeApproved(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);
        $statusesBefore = $this->projector->statuses;
        $revisionId = $this->revisionFor($productId, [
            'title' => 'ست کامل تجهیزات نمونه',
            'type' => ProductType::SIMPLE,
            'categoryKey' => '9559',
            'categoryIds' => [9559],
            'priceMinor' => 0,
            'stock' => 0,
            'sku' => 'FIXTURE-PARENT',
        ]);

        $approved = $this->review->approveRevision($revisionId);

        self::assertFalse($approved->ok, 'the manager cannot approve a type flip onto an imported parent');
        self::assertSame('type_change_not_allowed', $approved->code);
        self::assertSame($revisionId, $approved->context['revision_id']);
        self::assertSame(ProductType::VARIABLE, $approved->context['from']);
        self::assertSame(ProductType::SIMPLE, $approved->context['to']);
        self::assertSame($before, $this->parentFigures($productId), 'the live product is untouched');
        self::assertTrue(
            $this->revisions->find($revisionId)?->isPending(),
            'and the proposal is still pending — refusing it is not deciding it'
        );
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame($statusesBefore, $this->projector->statuses);
    }

    /**
     * ⛔ AND THE SAME ROW WITH A PRICE ON IT, which is the case that
     * isolates this guard.
     *
     * Measured: with the approval guard removed, the payload above is refused
     * anyway — but by `incomplete_product`, because its nought price is read
     * as a missing one. That is readiness answering about something else, and
     * a guard proved only against a case another rule already blocks is not
     * proved at all. A payload carrying a real price passes readiness, and an
     * `alpha.82`-era payload with no `PRICE_MIRRORED` key is read as a price
     * REQUEST — so this is the row that would actually land.
     */
    public function testAPricedRevisionCarryingATypeFlipIsRefusedByTheTypeGuard(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);
        $revisionId = $this->revisionFor($productId, [
            'title' => 'ست کامل تجهیزات نمونه',
            'type' => ProductType::SIMPLE,
            'categoryKey' => '9559',
            'categoryIds' => [9559],
            'priceMinor' => 450_000,
            'stock' => 0,
            'sku' => 'FIXTURE-PARENT',
        ]);

        $approved = $this->review->approveRevision($revisionId);

        self::assertFalse($approved->ok, $approved->code);
        self::assertSame('type_change_not_allowed', $approved->code);
        self::assertSame($before, $this->parentFigures($productId));
        self::assertSame(
            [],
            $this->projector->pricePushes,
            'and no price was pushed to the shop on the way'
        );
    }

    /**
     * THE CONTROL: approving a revision that does NOT change the type still
     * applies. Without this the refusal above would be satisfied by a method
     * that refuses every approval.
     */
    public function testApprovingATextOnlyRevisionStillApplies(): void
    {
        $productId = $this->importedParent();
        $revisionId = $this->revisionFor($productId, [
            'title' => 'ست کامل تجهیزات نمونه — نسخهٔ تأییدشده',
            'type' => ProductType::VARIABLE,
            'categoryKey' => '9559',
            'categoryIds' => [9559],
            'priceMinor' => 0,
            'stock' => 0,
            'sku' => 'FIXTURE-PARENT',
        ]);

        $approved = $this->review->approveRevision($revisionId);

        self::assertTrue($approved->ok, $approved->code);
        self::assertSame(
            'ست کامل تجهیزات نمونه — نسخهٔ تأییدشده',
            $this->products->find($productId)?->details->title
        );
        self::assertSame(
            ProductType::VARIABLE,
            $this->products->find($productId)?->details->type,
            'and the type is where it was'
        );
        self::assertSame(
            [],
            $this->projector->unguardedStockWrites,
            'an approved title change still writes no figures outward'
        );
    }

    /**
     * AND THE OTHER CONTROL: a product whose combinations are OURS — which is
     * every ordinary product in this catalogue — may still change its type.
     *
     * A guard wider than the defect is the bigger defect (`alpha.60`), and the
     * shape this refuses is narrow on purpose: a parent that has children in
     * WooCommerce and none of them ours.
     */
    public function testAnOrdinaryProductMayStillChangeItsType(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'کالای معمولی نمونه',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'FIXTURE-ORDINARY',
            stock: 3
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];
        $this->images->give(4300, self::VENDOR);

        $saved = $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'کالای معمولی نمونه',
            type: ProductType::VARIABLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'FIXTURE-ORDINARY',
            stock: 3
        ), [], [4300], 4300, $this->products->rowVersion($productId));

        self::assertTrue($saved->ok, $saved->code);
        self::assertSame(ProductType::VARIABLE, $this->products->find($productId)?->details->type);
    }

    /**
     * ⛔ P1: THE REST ENDPOINT NO LONGER PASSES THE PARENT'S NOUGHT OFF AS
     * THE PRICE — and does not break what reads it today.
     *
     * On `alpha.105` `GET /tecteb/v1/vendors/{id}/products` answered
     * `price_minor: 0, stock: 0` for this product and said nothing beside it,
     * so a consumer could not tell «free» from «ask the variations». The old
     * keys are untouched; what is new is `type`, `figures_live_in` and
     * `price_range`.
     *
     * The verdict is derived from ONE indexed read for the page
     * (`rangesFor()`), and the fake counts how many ids it was asked about —
     * a per-row product load is the shape `alpha.91` closed.
     */
    public function testTheRestProductListSaysWhereTheFiguresLive(): void
    {
        $productId = $this->importedParent();
        $this->storefront->range(self::WC_PARENT, StorefrontPriceRange::of(
            2_593_700,
            10_374_800,
            0,
            'instock',
            null
        ));

        $items = $this->restProducts();
        $row = $items[0];

        self::assertSame($productId, $row['id']);
        // Unchanged, byte for byte: a consumer reading these keys today still
        // gets the same numbers.
        self::assertSame(0, $row['price_minor']);
        self::assertSame(0, $row['stock']);
        // And now the means to read them correctly.
        self::assertSame(ProductType::VARIABLE, $row['type']);
        self::assertSame('variations', $row['figures_live_in']);
        self::assertSame(2_593_700, $row['price_range']['min_minor']);
        self::assertSame(10_374_800, $row['price_range']['max_minor']);
        self::assertSame(0, $row['price_range']['exponent']);
    }

    /**
     * «نمی‌دانم» is its own answer here too: a read that did not answer is
     * `unknown`, never `row` and never a range of nought.
     */
    public function testAnUnreadableShopIsUnknownRatherThanNought(): void
    {
        $this->importedParent();
        // No `range()` planted, so the fake answers `unreadable()`.

        $row = $this->restProducts()[0];

        self::assertSame('unknown', $row['figures_live_in']);
        self::assertNull($row['price_range']);
        self::assertSame(0, $row['price_minor'], 'and the row field is still reported as it stands');
    }

    /**
     * THE CONTROL: a simple product's own row IS its price, and says so.
     */
    public function testASimpleProductReportsItsOwnRowAsTheSource(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'کالای معمولی نمونه',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'FIXTURE-REST-SIMPLE',
            stock: 3
        ));
        self::assertTrue($created->ok, $created->code);

        $rows = $this->restProducts();
        $row = $rows[0];

        self::assertSame('row', $row['figures_live_in']);
        self::assertNull($row['price_range']);
        self::assertSame(450_000, $row['price_minor']);
    }

    /**
     * The endpoint's own handler, with the real repository and the fake
     * storefront reader behind it.
     *
     * The permission callbacks are NOT exercised here — they have their own
     * tests in `StoreAndStaffFlowTest`, and this one is about the shape of the
     * answer.
     *
     * @return list<array<string,mixed>>
     */
    private function restProducts(): array
    {
        $container = new Container();
        $container->bind(ProductRepositoryInterface::class, fn () => $this->products);
        $container->bind(StorefrontVariationReaderInterface::class, fn () => $this->storefront);
        $request = new \WP_REST_Request();
        $request['vendor'] = (string) self::VENDOR;
        $data = (new MarketplaceController($container))->products($request)->get_data();
        /** @var list<array<string,mixed>> $items */
        $items = $data['items'] ?? [];
        return $items;
    }

    /**
     * A pending revision on THIS product, written straight into the table.
     *
     * @param array<string,mixed> $details
     */
    private function revisionFor(int $productId, array $details): int
    {
        return $this->revisions->create($productId, self::VENDOR, ['details' => $details]);
    }
    /**
     * ⛔ THE CALLER THAT FORGETS TO ASK — written because falsification said
     * the guard beneath it was unreachable.
     *
     * Every test above enters through `ManageProducts`, which refuses FIRST,
     * so `SyncCatalog`'s own refusal was never executed: mutation 3 of this
     * round's loop deleted it and the database suite stayed green. A guard
     * that holds because its callers happen to ask first is an ordering, not
     * a guard (`alpha.98`), and a guard no fixture can reach is
     * indistinguishable from an absent one (`alpha.95`).
     *
     * `pushStock()` is public and documented as the vendor's explicit edit, so
     * a fifth caller added next round reaches it without asking anything.
     */
    public function testPushStockRefusesTheParentsFiguresOnItsOwn(): void
    {
        $productId = $this->importedParent();
        $before = $this->parentFigures($productId);

        self::assertFalse(
            $this->catalog->pushStock($productId, 0),
            'the service refuses on its own, not because a caller asked first'
        );
        self::assertSame([], $this->projector->unguardedStockWrites);
        self::assertSame($before, $this->parentFigures($productId));
    }

    /** The price half of the same reachability gap (mutation 4). */
    public function testPushPriceRefusesTheParentsFiguresOnItsOwn(): void
    {
        $productId = $this->importedParent();

        $result = $this->catalog->pushPrice($productId);

        self::assertFalse($result->ok);
        self::assertSame('parent_figures_live_in_shop', $result->code);
        self::assertSame('shop_combinations', $result->context['origin'] ?? '');
    }

    /**
     * AND THE CONTROL, because a refusal for every product would be the wider
     * guard that is the bigger defect (`alpha.60`). A simple product may be
     * refused here for other reasons — it is not projected in this fixture —
     * but never for THIS reason.
     */
    public function testPushPriceOnASimpleProductIsNotRefusedForTheParentRule(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'باند کشی سادهٔ کنترل',
            type: ProductType::SIMPLE,
            categoryKey: '9559',
            priceMinor: 450_000,
            sku: 'SIMPLE-PUSH',
            stock: 9
        ));
        $productId = (int) $created->context['product_id'];

        $origin = $this->catalog->priceAndStockOrigin($productId);
        self::assertSame(PriceAndStockOrigin::OURS, $origin->state);
        self::assertNotSame('parent_figures_live_in_shop', $this->catalog->pushPrice($productId)->code);
    }
}
