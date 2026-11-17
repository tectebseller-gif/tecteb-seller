<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0040AttributeRoles;
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

/**
 * Variable products on real MariaDB.
 *
 * The rule the owner stated is the one under test: a variable product is not
 * "ready to sell" until its combinations carry price, stock, SKU and image —
 * so every assertion here is about what happens BEFORE that is true.
 */
final class VariationFlowTest extends DatabaseTestCase
{
    private const VENDOR = 41;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbVariationRepository $variations;
    private ManageProducts $manage;
    private ManageVariations $manageVariations;
    private ReviewProducts $review;
    private FakeProductImages $images;
    private FakeCatalogProjector $projector;
    private FakeStorefrontVariations $storefront;
    private ManageVariations $manageWithShop;
    private ManageVariations $manageWithoutTransaction;
    /**
     * Kept so a test can build a FOURTH service by hand. Three tests added in
     * `alpha.109` needed one — a repository with no role columns, and a unit
     * of work whose commit does not answer — and both of those are states no
     * container ever wires, which is the only way their guards are reachable
     * (`alpha.80`).
     */
    private StaffAccess $access;
    private AuditLogger $auditLogger;

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
        $vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->images = new FakeProductImages();
        $access = new StaffAccess($staff, $vendors);
        $this->access = $access;
        $this->auditLogger = $audit;
        $readiness = new ProductReadiness($templates, $this->variations);
        $this->projector = new FakeCatalogProjector();
        $catalog = new SyncCatalog($this->products, $this->variations, $this->projector, $audit);
        $publishing = new ProductPublishPolicy($options);
        $states = new ProductStateMachine();

        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $revisions,
            $readiness,
            $catalog,
            $this->images,
            $access,
            $publishing,
            $states,
            $audit
        );
        $this->manageVariations = new ManageVariations(
            $this->products,
            $this->variations,
            $this->images,
            $access,
            $audit,
            null,
            // ⛔ THE REAL GATEWAY, because the container gives it one. Built
            // without it, the generator refuses with `build_not_atomic` — and
            // it DID, on the first run of these tests, which is the guard
            // working rather than the fixture being clever. A fixture that
            // wires a service differently from production measures a service
            // that does not ship (`alpha.105`).
            $db,
            // ⛔ AND THE SHOP DOOR, for the same reason: on a published
            // product the container gives this service a `SyncCatalog`, and a
            // fixture without one answers `shop => not_asked` where the real
            // panel answers `shop => updated`. The first run of the test below
            // said exactly that, which is the fixture being honest about
            // itself rather than the door being broken.
            $catalog
        );
        // ⛔ A SECOND INSTANCE, wired to the shop. The ordinary one above is
        // left exactly as it was, because every other test here is about a
        // product this marketplace made and the shop holds nothing for it —
        // and widening the fixture would have made those tests answer about
        // the new guard instead of about their own rule.
        $this->storefront = new FakeStorefrontVariations();
        $this->manageWithShop = new ManageVariations(
            $this->products,
            $this->variations,
            $this->images,
            $access,
            $audit,
            $this->storefront
        );
        // ⛔ A THIRD INSTANCE, WITH NO TRANSACTION. The generator refuses to
        // run without one, and that refusal is unreachable from the two
        // services above — both get the real gateway. A guard that no fixture
        // can reach is indistinguishable from a guard that is not there
        // (`alpha.80`), so the only way to measure «a build with no unit of
        // work writes nothing» is to build the service the way a container
        // that forgot to wire it would.
        $this->manageWithoutTransaction = new ManageVariations(
            $this->products,
            $this->variations,
            $this->images,
            $access,
            $audit,
            null,
            null
        );
        $this->review = new ReviewProducts(
            $this->products,
            $revisions,
            $templates,
            $readiness,
            $catalog,
            $publishing,
            $states,
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS])
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه نمونه', true, false);
    }

    public function testAVariableProductWithNoVariationsIsNotReadyToSell(): void
    {
        $productId = $this->variableProduct();

        $verdict = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($verdict->ok);
        self::assertSame('variable_needs_attributes', $verdict->code);

        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه', ['کوچک', 'بزرگ']);
        $withoutCombinations = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertSame('variable_needs_variations', $withoutCombinations->code);
    }

    public function testAVariationCarriesItsOwnPriceStockSkuAndImage(): void
    {
        $productId = $this->variableProduct();
        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه', ['کوچک', 'بزرگ']);
        $this->images->give(820, self::VENDOR);

        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'کوچک'],
            120000,
            99000,
            'GLV-S',
            7,
            820
        );
        self::assertTrue($saved->ok, $saved->code);

        $variations = $this->variations->variations($productId);
        self::assertCount(1, $variations);
        self::assertSame(120000, $variations[0]->priceMinor);
        self::assertSame(99000, $variations[0]->salePriceMinor);
        self::assertSame('GLV-S', $variations[0]->sku);
        self::assertSame(7, $variations[0]->stock);
        self::assertSame(820, $variations[0]->mediaId);
        self::assertSame('size=کوچک', $variations[0]->combination());
        self::assertSame(99000, $variations[0]->effectivePriceMinor('2026-09-15'));
    }

    public function testACombinationIsOneRowHoweverOftenItIsSaved(): void
    {
        $productId = $this->variableProduct();
        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه', ['کوچک']);
        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'color', 'رنگ', ['آبی']);

        $first = $this->manageVariations->saveVariation(self::VENDOR, self::VENDOR, $productId, ['size' => 'کوچک', 'color' => 'آبی'], 100000, null, 'A', 3);
        // The same combination, keys in the other order: still one variation.
        $second = $this->manageVariations->saveVariation(self::VENDOR, self::VENDOR, $productId, ['color' => 'آبی', 'size' => 'کوچک'], 150000, null, 'A', 4);

        self::assertTrue($first->ok, $first->code);
        self::assertTrue($second->ok, $second->code);
        self::assertSame('variation_saved', $second->code, 'the second save updates rather than adds');
        self::assertCount(1, $this->variations->variations($productId));
        self::assertSame(150000, $this->variations->variations($productId)[0]->priceMinor);
    }

    public function testAnOptionTheAttributeDoesNotOfferIsRefused(): void
    {
        $productId = $this->variableProduct();
        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه', ['کوچک', 'بزرگ']);

        $refused = $this->manageVariations->saveVariation(self::VENDOR, self::VENDOR, $productId, ['size' => 'غول‌پیکر'], 100000, null, '', 1);
        self::assertFalse($refused->ok);
        self::assertSame('unknown_option', $refused->code);

        $missing = $this->manageVariations->saveVariation(self::VENDOR, self::VENDOR, $productId, [], 100000, null, '', 1);
        self::assertSame('incomplete_combination', $missing->code);
        self::assertSame([], $this->variations->variations($productId));
    }

    public function testAVariationWithoutAPriceIsRefusedAndBlocksSubmission(): void
    {
        $productId = $this->readyVariableProduct();

        $noPrice = $this->manageVariations->saveVariation(self::VENDOR, self::VENDOR, $productId, ['size' => 'بزرگ'], 0, null, '', 5);
        self::assertFalse($noPrice->ok);
        self::assertSame('bad_price', $noPrice->code);

        // Written directly, as an older build or an import could have: the
        // readiness rule must still refuse it rather than trust the table.
        $this->variations->saveVariation($productId, new ProductVariation(0, $productId, ['size' => 'بزرگ'], 0, null, '', 2));
        $verdict = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($verdict->ok);
        self::assertSame('variation_without_price', $verdict->code);
        self::assertSame(1, $verdict->context['count']);
    }

    public function testACompleteVariableProductSubmitsAndPublishes(): void
    {
        $productId = $this->readyVariableProduct();

        $submitted = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertTrue($submitted->ok, $submitted->code);
        self::assertTrue($this->review->approve($productId)->ok);
        self::assertTrue($this->products->find($productId)?->status->isLive());
    }

    /**
     * The link has to exist on both sides.
     *
     * The storefront's own meta is what stops a re-projection duplicating a
     * combination, so this was invisible until the evidence dump printed the
     * marketplace's column and every row read NULL: the table had a place for
     * the answer and nothing ever wrote it.
     */
    public function testEachVariationRemembersTheStorefrontVariationItBecame(): void
    {
        $productId = $this->readyVariableProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);

        foreach ($this->variations->variations($productId) as $variation) {
            self::assertNotNull($variation->wcVariationId, 'the combination knows its storefront twin');
            self::assertGreaterThan(0, $variation->wcVariationId);
        }
    }

    public function testRemovingAnAttributeRemovesTheCombinationsThatUsedIt(): void
    {
        $productId = $this->readyVariableProduct();
        $attributeId = $this->variations->attributes($productId)[0]->id;

        self::assertCount(1, $this->variations->variations($productId));
        $removed = $this->manageVariations->deleteAttribute(self::VENDOR, self::VENDOR, $productId, $attributeId);
        self::assertTrue($removed->ok, $removed->code);
        self::assertSame([], $this->variations->attributes($productId));
        self::assertSame([], $this->variations->variations($productId), 'an unreachable price is not left behind');
    }

    public function testVariationStockMovesAtOnceLikeTheParentProducts(): void
    {
        $productId = $this->readyVariableProduct();
        $variationId = $this->variations->variations($productId)[0]->id;

        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);

        // Published, so ordinary edits would become a revision — but stock
        // does not wait for anyone (A.1).
        $result = $this->manageVariations->updateVariationStock(self::VENDOR, self::VENDOR, $productId, $variationId, 2);
        self::assertTrue($result->ok, $result->code);
        self::assertSame(2, $this->variations->findVariation($productId, $variationId)?->stock);
    }

    public function testAnotherShopCannotTouchTheseVariations(): void
    {
        $productId = $this->readyVariableProduct();
        $stranger = 99;

        $refused = $this->manageVariations->saveVariation($stranger, $stranger, $productId, ['size' => 'بزرگ'], 100000, null, '', 1);
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
        self::assertCount(1, $this->variations->variations($productId));
    }

    // -------------------------------------------------------------- helpers

    /**
     * ⛔ THE SHOP'S COMBINATIONS ARE NOT EDITED FROM HERE — and the refusal
     * says so instead of letting a second set be built beside them.
     *
     * The state is the imported one: this marketplace holds NO attribute and
     * NO variation row, the product points at a WooCommerce product, and that
     * product has the combinations. Before `alpha.105` every verb on this
     * screen was open, and the first option a vendor added here became a
     * combination WooCommerce already had under another id.
     */
    public function testTheShopsOwnCombinationsAreNotEditedFromThisPanel(): void
    {
        $productId = $this->variableProduct();
        self::assertTrue($this->products->link($productId, 7700));
        $this->storefront->holds(7700, [$this->shopChild(7701, 'BPI-50', 2600000)]);

        $refused = $this->manageWithShop->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'bundle',
            'بسته',
            ['۵۰ عددی']
        );

        self::assertFalse($refused->ok);
        self::assertSame('variations_live_in_shop', $refused->code);
        self::assertSame($productId, $refused->context['product_id'] ?? 0);
        // And nothing was written: the refusal is before the write, not after.
        self::assertSame([], $this->variations->attributes($productId));
        self::assertSame([], $this->variations->variations($productId));

        // Every other verb on the same screen answers the same way, because a
        // guard on one of five is no guard at all (`alpha.22`).
        // `deleteAttribute()` and `deleteVariation()` ask `editable()` first,
        // so they answer before they look for a row of ours; `updateVariationStock()`
        // carries its own copy of the question, because it guards the Inventory
        // area instead (A.1) and `editable()` never runs for it.
        self::assertSame(
            'variations_live_in_shop',
            $this->manageWithShop->deleteAttribute(self::VENDOR, self::VENDOR, $productId, 1)->code
        );
        self::assertSame(
            'variations_live_in_shop',
            $this->manageWithShop->deleteVariation(self::VENDOR, self::VENDOR, $productId, 1)->code
        );
        self::assertSame(
            'variations_live_in_shop',
            $this->manageWithShop->updateVariationStock(self::VENDOR, self::VENDOR, $productId, 1, 5)->code
        );
    }

    /**
     * THE CONTROL, and it is the one that proves nothing was widened: a
     * product whose combinations are OURS stays editable, even though the
     * same reader is wired in.
     */
    public function testAProductWhoseCombinationsAreOursStaysEditable(): void
    {
        $productId = $this->variableProduct();
        self::assertTrue($this->products->link($productId, 7710));

        $first = $this->manageWithShop->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'size',
            'اندازه',
            ['کوچک', 'بزرگ']
        );
        self::assertTrue($first->ok, $first->code);

        // …and it stays editable AFTER that first row exists, even once the
        // shop does hold combinations too: the question is whose rows these
        // are, and from the second save on, this marketplace has its own.
        $this->storefront->holds(7710, [$this->shopChild(7711, 'X-1', 100000)]);
        $second = $this->manageWithShop->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'size',
            'اندازه',
            ['کوچک', 'بزرگ', 'خیلی بزرگ']
        );
        self::assertTrue($second->ok, $second->code);
    }

    /**
     * ⛔ THE THIRD PRODUCT SHAPE §4 ASKS FOR BY NAME: a SIMPLE product.
     *
     * The other two shapes have a dozen tests between them — a variable
     * product this plugin built, and an imported one whose children live in
     * WooCommerce — and the simple product had none, because nothing in this
     * panel offers it a combination. «Nothing offers it» is not a guard
     * though: these five verbs are POST actions, and a hand-made request
     * naming a simple product's id is one `curl` away.
     *
     * All five refuse, and the two that refuse with `not_variable` do so
     * BEFORE any row is written, which is what makes the refusal safe rather
     * than merely tidy.
     */
    public function testASimpleProductIsRefusedByEveryVariationVerb(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'دستکش ساده',
            type: ProductType::SIMPLE,
            categoryKey: 'gloves',
            priceMinor: 450000,
            sku: 'SIMPLE-1',
            stock: 5
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];

        $axis = $this->manageVariations->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'size',
            'اندازه',
            ['کوچک', 'بزرگ']
        );
        self::assertFalse($axis->ok);
        self::assertSame('not_variable', $axis->code);

        $combination = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'کوچک'],
            450000,
            null,
            'SIMPLE-1-S',
            3
        );
        self::assertFalse($combination->ok);
        self::assertSame('not_variable', $combination->code);

        // And nothing was written on the way to either refusal.
        self::assertSame([], $this->variations->attributes($productId));
        self::assertSame([], $this->variations->variations($productId));

        // The three verbs that address an existing row cannot find one.
        foreach ([
            'delete_attribute' => $this->manageVariations->deleteAttribute(self::VENDOR, self::VENDOR, $productId, 1),
            'delete_variation' => $this->manageVariations->deleteVariation(self::VENDOR, self::VENDOR, $productId, 1),
            'stock' => $this->manageVariations->updateVariationStock(self::VENDOR, self::VENDOR, $productId, 1, 9),
        ] as $label => $result) {
            self::assertFalse($result->ok, $label);
            self::assertSame('not_found', $result->code, $label);
        }

        // The product itself is untouched: still simple, still priced, still
        // in stock.
        $product = $this->products->findOwned($productId, self::VENDOR);
        self::assertNotNull($product);
        self::assertSame(ProductType::SIMPLE, $product->details->type);
        self::assertSame(450000, $product->details->priceMinor);
        self::assertSame(5, $product->details->stock);
    }

    private function shopChild(int $id, string $sku, int $priceMinor): StorefrontVariation
    {
        return new StorefrontVariation(
            $id,
            ['بسته' => '۵۰ عددی'],
            $sku,
            $priceMinor,
            null,
            $priceMinor,
            true,
            true,
            4,
            'instock',
            'no',
            0
        );
    }

    private function variableProduct(): int
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, new ProductDetails(
            title: 'دستکش نیتریل — چند اندازه',
            type: ProductType::VARIABLE,
            categoryKey: 'gloves',
            priceMinor: 100000,
            sku: 'VAR-1',
            stock: 0
        ));
        self::assertTrue($created->ok, $created->code);
        $productId = (int) $created->context['product_id'];

        $mediaId = 900 + $productId;
        $this->images->give($mediaId, self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, new ProductDetails(
            title: 'دستکش نیتریل — چند اندازه',
            type: ProductType::VARIABLE,
            categoryKey: 'gloves',
            priceMinor: 100000,
            sku: 'VAR-1',
            stock: 0
        ), [], [$mediaId], $mediaId, $this->stampOf($productId));
        return $productId;
    }

    /**
     * ⛔ THE P0, WRITTEN AS THE SCENARIO THAT MEASURED IT.
     *
     * On `alpha.108`'s bytes this test is impossible to write: there was no
     * flag to pass. With the flag, a FACT attribute has to stop being an axis,
     * and the proof is that saving a combination which names only the real
     * axis still succeeds — the exact call that answered
     * `incomplete_combination` on the installed package, with
     * `{"fields":"جنس"}`.
     */
    public function testAFactAttributeIsNotAnAxisAndDoesNotBlockCombinations(): void
    {
        $productId = $this->readyVariableProduct();

        $fact = $this->manageVariations->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'material',
            'جنس',
            ['پلاستیک', 'فلز'],
            true,   // listed on the product page
            false   // NOT an axis
        );
        self::assertTrue($fact->ok, $fact->code);
        // The context carries the role as the audit row carries it: a word,
        // not a boolean. `'no'` is what a reader of the ledger sees.
        self::assertSame('no', $fact->context['variation'] ?? '');
        self::assertSame('yes', $fact->context['visible'] ?? '');

        $attributes = $this->variations->attributes($productId);
        self::assertCount(2, $attributes);
        self::assertSame(['size'], array_map(
            static fn (ProductAttribute $a): string => $a->key,
            ProductAttribute::axesOf($attributes)
        ));

        // The call that `alpha.108` refused.
        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'بزرگ'],
            220000,
            null,
            'GLV-L',
            4
        );
        self::assertTrue($saved->ok, $saved->code);
    }

    /**
     * And the control, on the same fixture: an attribute saved as an AXIS does
     * still demand a value. Without this, «the fact does not block» would also
     * pass on a build that stopped validating combinations altogether.
     */
    public function testASecondAxisStillDemandsItsValue(): void
    {
        $productId = $this->readyVariableProduct();

        $axis = $this->manageVariations->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'material',
            'جنس',
            ['پلاستیک', 'فلز'],
            true,
            true    // an axis, this time
        );
        self::assertTrue($axis->ok, $axis->code);

        $refused = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'بزرگ'],
            220000,
            null,
            'GLV-L2',
            4
        );
        self::assertFalse($refused->ok);
        self::assertSame('incomplete_combination', $refused->code);
        self::assertSame('جنس', $refused->context['fields'] ?? '');
    }

    /** A value sent for a fact attribute is refused BY NAME, not silently kept. */
    public function testAValueForAFactAttributeIsRefusedByItsOwnCode(): void
    {
        $productId = $this->readyVariableProduct();
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک'], true, false
        );

        $refused = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'بزرگ', 'material' => 'پلاستیک'],
            220000,
            null,
            'GLV-L3',
            4
        );
        self::assertFalse($refused->ok);
        self::assertSame('not_an_axis', $refused->code);
        self::assertSame('material', $refused->context['attribute'] ?? '');
    }

    /**
     * §8: DEMOTING AN AXIS THAT COMBINATIONS NAME IS REFUSED, WITH THE COUNT,
     * AND NOTHING IS DELETED.
     *
     * The fixture's one combination names `size`, so turning `size` into a
     * fact would orphan it. The owner's own words: «از حذف خودکار بدون هشدار
     * جلوگیری کند» and «تعداد تنوع‌های تحت تأثیر را نشان دهد».
     */
    public function testDemotingAnAxisWithCombinationsIsRefusedAndDeletesNothing(): void
    {
        $productId = $this->readyVariableProduct();
        $before = count($this->variations->variations($productId));
        self::assertSame(1, $before);

        $refused = $this->manageVariations->saveAttribute(
            self::VENDOR,
            self::VENDOR,
            $productId,
            'size',
            'اندازه',
            ['کوچک', 'بزرگ'],
            true,
            false
        );
        self::assertFalse($refused->ok);
        self::assertSame('axis_has_variations', $refused->code);
        self::assertSame(1, $refused->context['variations'] ?? 0);
        self::assertSame('اندازه', $refused->context['attribute'] ?? '');

        // Nothing moved: not the rows, and not the role.
        self::assertCount($before, $this->variations->variations($productId));
        $size = null;
        foreach ($this->variations->attributes($productId) as $attribute) {
            if ($attribute->key === 'size') {
                $size = $attribute;
            }
        }
        self::assertNotNull($size);
        self::assertTrue($size->variation);
    }

    /**
     * And the control: an axis with NO combinations on it demotes cleanly.
     * Without this, the guard above would also pass on a build that refused
     * every demotion.
     */
    public function testAnAxisWithNoCombinationsMayBecomeAFact(): void
    {
        $productId = $this->variableProduct();
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک', 'فلز']
        );

        $demoted = $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک', 'فلز'], true, false
        );
        self::assertTrue($demoted->ok, $demoted->code);
        self::assertSame([], ProductAttribute::axesOf($this->variations->attributes($productId)));
    }

    /**
     * §3: the vendor is not asked to invent an English key.
     *
     * An empty key with a Persian label gets a derived, stable, ASCII one —
     * and it is DERIVED FROM THE LABEL rather than random, so the same label
     * does not grow a second attribute on the next save.
     */
    public function testAnEmptyKeyIsDerivedFromTheLabelAndIsStable(): void
    {
        $productId = $this->variableProduct();

        $first = $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, '', 'تعداد در بسته', ['۵۰ عددی', '۱۰۰ عددی']
        );
        self::assertTrue($first->ok, $first->code);
        $key = (string) ($first->context['key'] ?? '');
        self::assertNotSame('', $key);
        self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $key);

        $again = $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, '', 'تعداد در بسته', ['۵۰ عددی', '۱۰۰ عددی', '۲۰۰ عددی']
        );
        self::assertTrue($again->ok, $again->code);
        self::assertSame($key, $again->context['key'] ?? '');
        self::assertCount(1, $this->variations->attributes($productId));
    }

    /**
     * §4: THE COUNT IS PREDICTED BEFORE ANYTHING IS BUILT, and the preview
     * writes nothing.
     */
    public function testThePreviewCountsTheCombinationsWithoutWritingAnything(): void
    {
        $productId = $this->readyVariableProduct();
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک', 'فلز'], true, true
        );

        $rowsBefore = $this->variations->variations($productId);
        $preview = $this->manageVariations->previewCombinations($productId);

        // Two axes: two sizes × two materials.
        self::assertSame(4, $preview['total']);
        // ⛔ `0`, NOT `1`, AND THE FIRST VERSION OF THIS LINE SAID `1`. The
        // one stored row names only `size`, so once `material` became an axis
        // it stopped being a combination of this product's axes. Asserting
        // «existing = 1» here was asserting the defect a live render then
        // found: it is what let the panel print «۳ ثبت شده» beside «۶ تازه»
        // with a total of ۶. The row is not lost — it is `stale`, counted
        // under its own name, and deleted by nobody.
        self::assertSame(0, $preview['existing']);
        self::assertSame(1, $preview['stale']);
        self::assertFalse($preview['over']);
        self::assertSame(count($rowsBefore), count($this->variations->variations($productId)));
    }

    /** §4: the generator creates only what is missing, and never a duplicate. */
    public function testBuildCreatesOnlyTheMissingCombinationsAndIsRepeatable(): void
    {
        $productId = $this->readyVariableProduct();

        $built = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);
        self::assertTrue($built->ok, $built->code);
        self::assertSame('variations_built', $built->code);
        // One of the two sizes already existed.
        self::assertSame(1, $built->context['built'] ?? 0);
        self::assertCount(2, $this->variations->variations($productId));

        $again = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);
        self::assertTrue($again->ok, $again->code);
        self::assertSame('variations_already_built', $again->code);
        self::assertCount(2, $this->variations->variations($productId));
    }

    /**
     * ⛔ AND A GENERATE WITH NO TRANSACTION IS REFUSED, NOT RUN HALF-WAY.
     *
     * The service wired without one is the only way to reach this, and the
     * assertion that matters is the ROW COUNT: `alpha.49`'s rule is that a
     * transaction which will not open is a refusal, never grounds for writing
     * the first combination and leaving the set inconsistent.
     */
    public function testAGenerateWithNoTransactionWritesNothingAtAll(): void
    {
        $productId = $this->readyVariableProduct();
        $before = count($this->variations->variations($productId));

        $refused = $this->manageWithoutTransaction->buildVariations(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($refused->ok);
        self::assertSame('build_not_atomic', $refused->code);
        self::assertCount($before, $this->variations->variations($productId));
    }


    /**
     * ⛔ §9: A PUBLISHED PRODUCT'S VARIATION EDIT REACHES THE SHOP.
     *
     * Measured on the live fixture before this door existed: the row moved to
     * 125,000 and the storefront child still said 120,000, so the vendor's
     * work was saved and the buyer never saw it. The answer now carries
     * `shop => updated`, which is read from the projection rather than assumed.
     */
    public function testAPublishedProductsVariationEditIsPushedToTheShop(): void
    {
        $productId = $this->readyVariableProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);

        $variationId = $this->variations->variations($productId)[0]->id;
        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'کوچک'],
            125000,
            null,
            'GLV-S',
            9
        );
        self::assertTrue($saved->ok, $saved->code);
        self::assertSame('updated', $saved->context['shop'] ?? '');
        self::assertSame(125000, $this->variations->findVariation($productId, $variationId)?->priceMinor);
    }

    /**
     * And the control: a DRAFT product is not pushed. A draft projects when it
     * is approved, and pushing it early would put an unapproved change on the
     * shop — §11's question, which this method does not answer on its own.
     */
    public function testADraftProductsVariationEditIsNotPushedToTheShop(): void
    {
        $productId = $this->readyVariableProduct();

        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'بزرگ'],
            220000,
            null,
            'GLV-L9',
            4
        );
        self::assertTrue($saved->ok, $saved->code);
        self::assertArrayNotHasKey('shop', $saved->context);
    }


    /**
     * ⛔ §4/§8: ADDING AN AXIS ORPHANS WHAT WAS COMPLETE BEFORE IT, AND THE
     * THREE NUMBERS MUST ADD UP.
     *
     * Found by rendering the real vendor form on a live site after a real POST
     * promoted a fact attribute back to an axis. The panel said «۶ ترکیب
     * می‌سازند و ۳ ترکیب تا حالا ثبت شده است» beside «۶ ترکیب تازه ساخته
     * می‌شود» — both figures true about what they counted, and 3 + 6 is not 6.
     *
     * No unit fixture could reach it: every fixture declares its axes before
     * its combinations, so nothing is ever stale.
     */
    public function testAddingAnAxisLeavesTheOldRowsStaleAndSaysSo(): void
    {
        $productId = $this->readyVariableProduct();      // one axis, one row
        self::assertSame(1, count($this->variations->variations($productId)));

        // A second axis. The stored row names only `size`, so from this moment
        // it is not a combination of the product's axes at all.
        self::assertTrue($this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک', 'فلز'], true, true
        )->ok);

        $preview = $this->manageVariations->previewCombinations($productId);
        self::assertSame(4, $preview['total']);          // 2 sizes × 2 materials
        self::assertSame(0, $preview['existing']);       // none of them is stored
        self::assertSame(1, $preview['stale']);          // and the old row is stale
        self::assertSame(4, $preview['missing']);

        // The invariants the sentence depends on.
        self::assertSame(
            $preview['total'],
            $preview['existing'] + $preview['missing'],
            'existing + missing must be the total the axes describe'
        );
        self::assertSame(
            count($this->variations->variations($productId)),
            $preview['existing'] + $preview['stale'],
            'existing + stale must be the number of rows actually stored'
        );
    }

    /** And a build over stale rows adds the missing set without deleting one. */
    public function testBuildingOverStaleRowsDeletesNothing(): void
    {
        $productId = $this->readyVariableProduct();
        self::assertTrue($this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس', ['پلاستیک', 'فلز'], true, true
        )->ok);

        $built = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);
        self::assertTrue($built->ok, $built->code);
        self::assertSame(4, $built->context['built'] ?? 0);

        // Four new, and the stale one still there — untouched, not tidied away.
        self::assertCount(5, $this->variations->variations($productId));
        $combinations = array_map(
            static fn (ProductVariation $v): string => $v->combination(),
            $this->variations->variations($productId)
        );
        self::assertContains('size=کوچک', $combinations);
    }

    /**
     * ⛔ §۴ ASKED FOR A REFUSAL ABOVE A SAFE CAP, **WITH THE REASON** — and
     * `alpha.108`'s systematic count is what found this one had no test at
     * all: `too_many_variations` is produced at three call sites and not one
     * assertion in this repository reached any of them. A refusal nothing
     * asserts may not even be REACHABLE, and only arithmetic says which.
     *
     * Measured rather than assumed: two sizes × five × seven is seventy
     * candidates, which the walker builds in full — its own give-up is at
     * `MAX_VARIATIONS * 2`, a hundred and twenty — so the CAP is what refuses,
     * and it refuses with a number.
     */
    public function testACombinationCountOverTheCapIsRefusedWithTheCapNamed(): void
    {
        $productId = $this->readyVariableProduct();
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'colour', 'رنگ',
            ['الف', 'ب', 'پ', 'ت', 'ث'], true, true
        );
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'grade', 'درجه',
            ['۱', '۲', '۳', '۴', '۵', '۶', '۷'], true, true
        );

        // It says so in the preview, before any button exists to press.
        $preview = $this->manageVariations->previewCombinations($productId);
        self::assertSame(70, $preview['total']);
        self::assertTrue($preview['over']);
        self::assertSame(ManageVariations::MAX_VARIATIONS, $preview['limit']);

        $before = count($this->variations->variations($productId));
        $built = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);

        self::assertFalse($built->ok);
        self::assertSame('too_many_variations', $built->code);
        self::assertSame(ManageVariations::MAX_VARIATIONS, $built->context['limit'] ?? null);
        self::assertGreaterThan(ManageVariations::MAX_VARIATIONS, $built->context['would_be'] ?? 0);
        // The figures the message may print still add up, so the cap cannot be
        // reported with numbers that contradict each other — the defect a live
        // render found on the preview panel, one method over.
        self::assertSame(
            ($built->context['existing'] ?? -1) + ($built->context['new'] ?? -1),
            $built->context['would_be'] ?? null
        );
        // ⛔ AND NOTHING WAS BUILT. A cap that refuses after writing half the
        // set is the «ثبت نیمه‌کارهٔ مجموعه‌ای ناسازگار» §۴ forbids by name.
        self::assertCount($before, $this->variations->variations($productId));
    }

    /**
     * ⛔ AND WHEN THE SET IS TOO LARGE TO EVEN WALK, THE REFUSAL INVENTS NO
     * NUMBER. Two sizes × eight × eight is a hundred and twenty-eight, past
     * the walker's own give-up, so this code genuinely does not know how many
     * combinations there would be — and a `0` in that slot would be a claim,
     * not a gap (`alpha.28`). The cap is the one number here that is true.
     */
    public function testACountTooLargeToWalkRefusesWithoutInventingAFigure(): void
    {
        $productId = $this->readyVariableProduct();
        $eight = ['الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح'];
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'colour', 'رنگ', $eight, true, true
        );
        $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'grade', 'درجه', $eight, true, true
        );

        $preview = $this->manageVariations->previewCombinations($productId);
        self::assertNull($preview['total'], 'an unwalkable set has no printable total');
        self::assertNull($preview['missing']);
        self::assertTrue($preview['over']);

        $before = count($this->variations->variations($productId));
        $built = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);

        self::assertFalse($built->ok);
        self::assertSame('too_many_variations', $built->code);
        self::assertSame(ManageVariations::MAX_VARIATIONS, $built->context['limit'] ?? null);
        // ⛔ Deliberately ABSENT rather than nought, and asserted with
        // `array_key_exists`: a missing key and a `0` are the same thing to
        // `??`, and the whole point is that they are not.
        self::assertArrayNotHasKey('would_be', $built->context);
        self::assertArrayNotHasKey('new', $built->context);
        self::assertCount($before, $this->variations->variations($productId));
    }

    /**
     * ⛔ AND A PRODUCT WHOSE ONLY ATTRIBUTE IS A FACT HAS NOTHING TO BUILD
     * FROM, which is a different answer from «everything is already built».
     *
     * `axes_first` was the fourth unasserted code in this round's count. It
     * matters because the two answers send the vendor to opposite places: one
     * says tick a box, the other says the work is done.
     */
    public function testABuildWithNoAxesSaysSoRatherThanClaimingItIsDone(): void
    {
        $productId = $this->variableProduct();
        $saved = $this->manageVariations->saveAttribute(
            self::VENDOR, self::VENDOR, $productId, 'material', 'جنس',
            ['پلاستیک', 'فلز'], true, false
        );
        self::assertTrue($saved->ok, $saved->code);

        $built = $this->manageVariations->buildVariations(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($built->ok);
        self::assertSame('axes_first', $built->code);
        self::assertNotSame('variations_already_built', $built->code);
        self::assertSame([], $this->variations->variations($productId));
    }

    /**
     * ⛔ A ROLE THIS DATABASE CANNOT RECORD IS REFUSED, NOT WRITTEN AND
     * FORGOTTEN — the fifth unasserted code, and the one that makes migration
     * 40 safe to ship.
     *
     * Swapping the plugin's files does not run the migration (`UpgradeGate`
     * does it on the next admin request, `alpha.67`), so this release's code
     * really can meet a table with neither column. The columns are dropped
     * here and PUT BACK in `finally`: a fixture that spends a schema and does
     * not give it back poisons every test after it (`alpha.67`'s own rule).
     *
     * ⛔ And the control is in the same test: the ordinary case — both roles
     * true — must still go through on that same table, because a guard that
     * stops a vendor doing what they already did today is wider than the
     * defect (`alpha.60`).
     */
    public function testARoleChangeIsRefusedWhenTheColumnsAreNotThereYet(): void
    {
        $db = new WpDatabase($this->wpdb);
        $table = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ATTRIBUTES);
        $productId = $this->variableProduct();
        $this->wpdb->pdo()->exec(
            'ALTER TABLE `' . $table . '` DROP COLUMN `' . M0040AttributeRoles::VISIBLE . '`,'
            . ' DROP COLUMN `' . M0040AttributeRoles::VARIATION . '`'
        );
        try {
            // A FRESH repository, because the live one memoised «supported»
            // before the columns went away — and it is right to memoise per
            // instance for one request.
            $repo = new DbVariationRepository($db, new SystemClock());
            $without = new ManageVariations(
                $this->products,
                $repo,
                $this->images,
                $this->access,
                $this->auditLogger,
                null,
                $db
            );

            $refused = $without->saveAttribute(
                self::VENDOR, self::VENDOR, $productId, 'material', 'جنس',
                ['پلاستیک', 'فلز'], true, false
            );
            self::assertFalse($refused->ok);
            self::assertSame('attribute_roles_unavailable', $refused->code);
            self::assertSame('absent', $refused->context['supported'] ?? null);
            self::assertSame([], $repo->attributes($productId));

            // The control: nothing a vendor does today starts failing.
            $ordinary = $without->saveAttribute(
                self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه',
                ['کوچک', 'بزرگ'], true, true
            );
            self::assertTrue($ordinary->ok, $ordinary->code);
        } finally {
            $this->wpdb->pdo()->exec(
                'ALTER TABLE `' . $table . '`'
                . ' ADD COLUMN `' . M0040AttributeRoles::VISIBLE . '` TINYINT(1) NOT NULL DEFAULT 1,'
                . ' ADD COLUMN `' . M0040AttributeRoles::VARIATION . '` TINYINT(1) NOT NULL DEFAULT 1'
            );
        }
    }

    /**
     * ⛔ A COMMIT THAT DOES NOT ANSWER REPORTS WHAT WAS **ATTEMPTED**, AND
     * NEVER `built => 0`.
     *
     * The sixth unasserted code. `alpha.74` wrote the rule for this exact
     * spot: the rows went to the gateway and the commit is what failed, so
     * «nothing was written» is a claim about the database made from a failure
     * to read it.
     *
     * ⛔ **AND WHAT THIS TEST DOES NOT PROVE, SAID OUT LOUD.** The double
     * rolls the real transaction back before answering `false`, because a
     * double that merely lied would leave this connection inside an open
     * transaction and poison every test after it. So the row count afterwards
     * measures MY ROLLBACK, not the service — which is why the assertions
     * that matter here are the CODE, the `attempted` figure, and the absence
     * of `built`.
     */
    public function testACommitThatDoesNotAnswerReportsAttemptedRatherThanBuilt(): void
    {
        $db = new WpDatabase($this->wpdb);
        $unconfirmed = new ManageVariations(
            $this->products,
            $this->variations,
            $this->images,
            $this->access,
            $this->auditLogger,
            null,
            new class ($db) implements TransactionInterface {
                public function __construct(private readonly WpDatabase $db)
                {
                }

                public function begin(): bool
                {
                    return $this->db->begin();
                }

                public function commit(): bool
                {
                    $this->db->rollback();
                    return false;
                }

                public function rollback(): bool
                {
                    return $this->db->rollback();
                }

                public function inTransaction(): bool
                {
                    return $this->db->inTransaction();
                }
            }
        );

        $productId = $this->readyVariableProduct();
        $built = $unconfirmed->buildVariations(self::VENDOR, self::VENDOR, $productId);

        self::assertFalse($built->ok);
        self::assertSame('build_not_confirmed', $built->code);
        self::assertSame(1, $built->context['attempted'] ?? null);
        self::assertArrayNotHasKey('built', $built->context);
    }

    /**
     * ⛔ §۹/§۷: AND WHEN THE SHOP REFUSES, THE ANSWER SAYS THE SHOP WAS NOT
     * UPDATED — it does not report a clean save.
     *
     * The seventh unasserted code, and the other half of
     * `testAPublishedProductsVariationEditIsPushedToTheShop`: that one proves
     * the door exists, this one proves its answer is READ. «نمایش نتیجه واقعی
     * عملیات، نه پیام موفقیت پیش از ذخیره» is §۷ verbatim, and a push whose
     * result is discarded is exactly the success-before-saving it forbids.
     */
    public function testAPublishedProductWhoseShopRefusesSaysTheShopWasNotUpdated(): void
    {
        $productId = $this->readyVariableProduct();
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);

        $this->projector->refusesToProject[] = $productId;

        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'کوچک'],
            131000,
            null,
            'GLV-S',
            4
        );

        self::assertFalse($saved->ok, 'a save the shop refused is not a clean save');
        self::assertSame('saved_but_shop_not_updated', $saved->code);
        // ⛔ AND OUR ROW DID MOVE — the code says «saved, but», not «refused».
        $row = null;
        foreach ($this->variations->variations($productId) as $variation) {
            if ($variation->combination() === ProductVariation::combinationOf(['size' => 'کوچک'])) {
                $row = $variation;
            }
        }
        self::assertNotNull($row);
        self::assertSame(131000, $row->priceMinor);
    }

    private function readyVariableProduct(): int
    {
        $productId = $this->variableProduct();
        $this->manageVariations->saveAttribute(self::VENDOR, self::VENDOR, $productId, 'size', 'اندازه', ['کوچک', 'بزرگ']);
        $this->images->give(830, self::VENDOR);
        $saved = $this->manageVariations->saveVariation(
            self::VENDOR,
            self::VENDOR,
            $productId,
            ['size' => 'کوچک'],
            120000,
            null,
            'GLV-S',
            9,
            830
        );
        self::assertTrue($saved->ok, $saved->code);
        return $productId;
    }

    /**
     * The stamp a real form would carry: the row's counter, read now.
     *
     * Every save of an EXISTING product goes through this, because from
     * alpha.15 a save with no usable version is refused rather than written
     * unguarded. A test that omitted it would be testing a path the product
     * form cannot reach.
     */
    private function stampOf(int $productId): string
    {
        return $this->products->find($productId)?->rowVersion ?? '';
    }

}
