<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

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
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

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
            $audit
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
