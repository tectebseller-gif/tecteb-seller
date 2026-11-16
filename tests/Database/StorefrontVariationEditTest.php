<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\EditStorefrontVariations;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontVariationOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeStorefrontVariations;
use Tecteb\Marketplace\Tests\Support\RecordingStorefrontVariationWriter;

/**
 * WHAT THIS PROVES: who may change an imported variation's figures, which
 * product's children they may change, and what is refused before any write.
 *
 * ⛔ **THE DIVISION OF LABOUR, AND WHY IT IS TWO FILES.** The writer's own
 * mechanics — the guarded statement, the read-back, «the parent is never
 * written» — need real `WC_Product_Variation` objects and are measured in
 * `StorefrontVariationWriteTest` against the WooCommerce doubles. What THAT
 * suite has no access to is real repositories, real `StaffAccess` and a real
 * audit table, so it cannot answer «did the service refuse before writing»
 * or «whose parent did it hand over». This file answers those, against
 * MariaDB, with a recording writer. Neither file is the whole guard and
 * neither pretends to be.
 *
 * ⛔ **THE FIXTURE IS SYNTHETIC.** §3 of the instruction: «هیچ عملیات نوشتنی
 * روی این شناسه‌های واقعی انجام نده» about #11955/#11965/#11966/#11967. Parent
 * 7700 and children 7701-7703 are this repository's own, and the marketplace
 * row is bent into the imported shape by SQL for the reason
 * `ImportedParentFiguresTest` gives: the real path that produces it is
 * `ImportFromDokan`, and a fixture that could only be built through the code
 * under test could not express the state the code is about.
 */
final class StorefrontVariationEditTest extends DatabaseTestCase
{
    private const VENDOR = 102;
    private const OTHER_VENDOR = 128;
    private const CLERK = 55;
    private const WC_PARENT = 7700;
    private const WC_CHILD = 7701;

    private DbProductRepository $products;
    private DbVariationRepository $variations;
    private DbStaffRepository $staff;
    private FakeStorefrontVariations $storefront;
    private RecordingStorefrontVariationWriter $writer;
    private EditStorefrontVariations $service;
    private StaffAccess $access;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->products = new DbProductRepository($this->db, $clock);
        $this->variations = new DbVariationRepository($this->db, $clock);
        $this->staff = new DbStaffRepository($this->db, $clock);
        $vendors = new DbVendorRepository($this->db, $clock);
        $this->access = new StaffAccess($this->staff, $vendors);
        $this->storefront = new FakeStorefrontVariations();
        $this->writer = new RecordingStorefrontVariationWriter();
        $this->service = new EditStorefrontVariations(
            $this->products,
            new StorefrontVariationOwnership($this->variations, $this->storefront),
            $this->writer,
            $this->access,
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock)
        );

        $vendors->upsertProfile(self::VENDOR, 'تجهیزات پزشکی نمونه', true, false);
        $vendors->upsertProfile(self::OTHER_VENDOR, 'فروشگاه دیگر', true, false);
    }

    // ------------------------------------------------------------ the happy path

    /**
     * The vendor's typing reaches the shop as an exact figure at the SHOP's
     * scale, on the child they named, under the parent the ROW says is theirs.
     */
    public function testTypedPricesReachTheWriterAsExactMinorUnits(): void
    {
        $productId = $this->importedParent();

        // Persian digits and a thousands separator, which is how a Persian
        // vendor types a price.
        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '۲٬۹۰۰٬۰۰۰',
            '',
            2_600_000,
            null,
            0
        );

        self::assertTrue($out->ok, $out->code);
        self::assertSame('variation_price_saved', $out->code);
        self::assertCount(1, $this->writer->calls);
        self::assertSame([
            'verb' => 'prices',
            // ⛔ THE PARENT CAME FROM THE OWNED ROW, not from the caller.
            'parent' => self::WC_PARENT,
            'variation' => self::WC_CHILD,
            'regular' => 2_900_000,
            'sale' => null,
            'seen_regular' => 2_600_000,
            'seen_sale' => null,
        ], $this->writer->calls[0]);
    }

    /** And the stock verb, with «no number of its own» travelling as null. */
    public function testStockReachesTheWriterWithWhatTheScreenShowed(): void
    {
        $productId = $this->importedParent();

        $out = $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, 7703, 5, null);

        self::assertTrue($out->ok, $out->code);
        self::assertSame('variation_stock_saved', $out->code);
        self::assertSame([[
            'verb' => 'stock',
            'parent' => self::WC_PARENT,
            'variation' => 7703,
            'stock' => 5,
            'seen' => null,
        ]], $this->writer->calls);
    }

    /**
     * ⛔ AND THE AUDIT ROW SAYS WHICH CHILD.
     *
     * `variation_id` had to join the allowlist for this: a key that is not on
     * it is dropped in silence (`alpha.91`), so the row would have said a
     * number changed on a variable product without saying which of its
     * children — the one fact somebody reading the trail needs.
     */
    public function testTheAuditRowNamesTheVariation(): void
    {
        $productId = $this->importedParent();
        $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, self::WC_CHILD, 4, 8);

        $table = $this->auditTable();
        $row = $this->wpdb->get_row(
            "SELECT * FROM `{$table}` WHERE event_type = 'product.inventory_changed' ORDER BY id DESC LIMIT 1",
            'ARRAY_A'
        );
        self::assertIsArray($row, 'the write must leave an audit row');
        $context = json_decode((string) $row['payload'], true);
        self::assertIsArray($context);
        self::assertSame(self::WC_CHILD, (int) $context['variation_id']);
        self::assertSame(4, (int) $context['stock']);
        self::assertSame(self::VENDOR, (int) $context['vendor_id']);
    }

    // --------------------------------------------------------------- refusals

    /**
     * ⛔ ANOTHER VENDOR'S PRODUCT: refused by the row, and the writer is never
     * reached — so no amount of guessing at `product_id` and `variation_id`
     * can write to a shop that is not yours.
     */
    public function testAnotherVendorsProductIsRefusedBeforeAnyWrite(): void
    {
        $productId = $this->importedParent();

        $prices = $this->service->updatePrices(
            self::OTHER_VENDOR,
            self::OTHER_VENDOR,
            $productId,
            self::WC_CHILD,
            '1',
            '',
            null,
            null,
            0
        );
        $stock = $this->service->updateStock(
            self::OTHER_VENDOR,
            self::OTHER_VENDOR,
            $productId,
            self::WC_CHILD,
            1,
            8
        );

        self::assertFalse($prices->ok);
        self::assertSame('not_found', $prices->code);
        self::assertFalse($stock->ok);
        self::assertSame('not_found', $stock->code);
        self::assertSame([], $this->writer->calls, 'nothing may be handed to the writer');
    }

    /**
     * ⛔ THE MIRROR GUARD: a product whose combinations the MARKETPLACE holds
     * is not editable through this door.
     *
     * This is the other side of `ManageVariations`' refusal, and the two read
     * one rule — so a product is behind exactly one of the two doors and a
     * figure can never have two writers.
     */
    public function testAProductWhoseVariationsAreOursIsRefusedByName(): void
    {
        $productId = $this->importedParent();
        // One axis and one combination of OUR own, which is what «ours» means.
        $attribute = $this->variations->saveAttribute($productId, 'bundle', 'بسته', ['۵۰ عددی'], 0);
        self::assertGreaterThan(0, $attribute);

        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '1',
            '',
            null,
            null,
            0
        );

        self::assertFalse($out->ok);
        self::assertSame('variations_not_live_in_shop', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /**
     * ⛔ FAIL-CLOSED: a shop that could not be read is not evidence that these
     * children are the shop's, and this door writes straight at WooCommerce.
     *
     * `alpha.105` got this half wrong in the opposite direction and
     * `alpha.106` fixed it; the same rule object answers both doors, so the
     * fix cannot be true of one and false of the other.
     */
    public function testAnUnreadableShopIsRefusedRatherThanAssumed(): void
    {
        $productId = $this->importedParent();
        $this->storefront->answers(
            self::WC_PARENT,
            StorefrontVariationSet::unavailable(StorefrontVariationSet::UNREADABLE)
        );

        $out = $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, self::WC_CHILD, 4, 8);

        self::assertFalse($out->ok);
        self::assertSame('variations_not_live_in_shop', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /** A marketplace row with no storefront product has no child to edit. */
    public function testARowWithNoStorefrontProductIsRefusedByName(): void
    {
        $productId = $this->importedParent();
        $table = $this->wpdb->prefix . M0005CreateProductTables::PRODUCTS;
        $this->wpdb->query("UPDATE `{$table}` SET `wc_product_id` = NULL WHERE `id` = {$productId}");

        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '1',
            '',
            null,
            null,
            0
        );

        self::assertFalse($out->ok);
        self::assertSame('variation_no_storefront_product', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /**
     * ⛔ TWO AREAS, NOT ONE — the split A.1 asks for and the one
     * `ManageVariations::updateVariationStock()` has always used.
     *
     * A clerk who may correct stock may not change what a product costs, and a
     * clerk who may edit the product may not be assumed to be the stock clerk.
     */
    public function testThePriceNeedsTheProductAreaAndTheStockTheInventoryArea(): void
    {
        $productId = $this->importedParent();
        // A member of staff with INVENTORY edit and nothing else — a CUSTOM
        // preset, because `ProductAndInventory` grants both and would measure
        // nothing about the split.
        $staffId = $this->staff->add(
            self::VENDOR,
            self::CLERK,
            'انباردار نمونه',
            'clerk',
            'inventory@example.test',
            '09120000001',
            StaffRolePreset::Custom,
            StaffPermissions::of([StaffArea::Inventory->value => StaffLevel::Edit]),
            hash('sha256', 'clerk-token'),
            '2030-01-01 00:00:00'
        );
        self::assertGreaterThan(0, $staffId);
        self::assertTrue($this->staff->activate($staffId));

        $price = $this->service->updatePrices(
            self::CLERK,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '1',
            '',
            null,
            null,
            0
        );
        $stock = $this->service->updateStock(self::CLERK, self::VENDOR, $productId, self::WC_CHILD, 4, 8);

        self::assertFalse($price->ok, 'the stock clerk may not reprice');
        self::assertSame('forbidden', $price->code);
        self::assertTrue($stock->ok, $stock->code);
        self::assertCount(1, $this->writer->calls, 'only the stock verb got through');
    }

    /**
     * ⛔ A FIGURE THE SHOP'S SCALE CANNOT HOLD IS REFUSED, NOT ROUNDED.
     *
     * `(int) round((float) …)` is the shape `alpha.53` found in the Dokan order
     * reader: on an exponent-nought shop it would turn «۴۵۰٬۰۰۰٫۵۰» into a
     * price nobody set. `DecimalAmount` is exact or nothing, and «nothing» is
     * a named refusal rather than a quiet nought.
     */
    public function testAPriceTheShopsScaleCannotHoldIsRefused(): void
    {
        $productId = $this->importedParent();

        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '2600000.50',
            '',
            2_600_000,
            null,
            0
        );

        self::assertFalse($out->ok);
        self::assertSame('variation_bad_price', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /**
     * And the scale itself moving between the render and the save: the figures
     * on the screen meant something else, so nothing is written.
     */
    public function testAShopWhoseScaleMovedSinceTheRenderIsRefused(): void
    {
        $productId = $this->importedParent();
        $this->writer->exponent = 2;             // the shop now keeps two decimals

        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '2900000',
            '',
            2_600_000,
            null,
            0                                      // what the screen printed in
        );

        self::assertFalse($out->ok);
        self::assertSame('variation_price_moved', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /** A shop with no readable scale: no figure here has a meaning. */
    public function testAShopWithNoReadableScaleIsRefused(): void
    {
        $productId = $this->importedParent();
        $this->writer->exponent = null;

        $out = $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '2900000',
            '',
            2_600_000,
            null,
            0
        );

        self::assertFalse($out->ok);
        self::assertSame('variation_shop_unreadable', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /** Negative stock is not a number this door accepts. */
    public function testNegativeStockIsRefusedByName(): void
    {
        $productId = $this->importedParent();

        $out = $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, self::WC_CHILD, -1, 8);

        self::assertFalse($out->ok);
        self::assertSame('variation_bad_stock', $out->code);
        self::assertSame([], $this->writer->calls);
    }

    /** The writer's own refusals arrive as named codes, never as «saved». */
    public function testTheWritersRefusalsAreReportedByName(): void
    {
        $productId = $this->importedParent();
        $this->writer->stockAnswer = ['outcome' => 'moved', 'found' => 6];

        $out = $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, self::WC_CHILD, 4, 8);

        self::assertFalse($out->ok);
        self::assertSame('variation_stock_moved', $out->code);
        self::assertSame(6, $out->context['found']);
    }

    /**
     * ⛔ AND NOTHING OF OURS MOVES. §3: «ترکیب‌های ساخته‌شده توسط افزونه بدون
     * تغییر باقی بمانند» — asked of the tables rather than argued.
     */
    public function testNoMarketplaceVariationRowIsTouched(): void
    {
        $productId = $this->importedParent();
        $before = $this->ourVariationRows();

        $this->service->updatePrices(
            self::VENDOR,
            self::VENDOR,
            $productId,
            self::WC_CHILD,
            '2900000',
            '2500000',
            2_600_000,
            null,
            0
        );
        $this->service->updateStock(self::VENDOR, self::VENDOR, $productId, self::WC_CHILD, 4, 8);

        self::assertSame($before, $this->ourVariationRows());
        // And the marketplace row's own figures are where they were: this door
        // writes the shop, not the mirror.
        $product = $this->products->find($productId);
        self::assertSame(0, $product?->details->priceMinor);
        self::assertSame(0, $product?->details->stock);
    }

    // ------------------------------------------------------------- internals

    /** @return list<array<string,mixed>> */
    private function ourVariationRows(): array
    {
        $attributes = $this->wpdb->prefix . M0006CatalogAndOrders::ATTRIBUTES;
        $variations = $this->wpdb->prefix . M0006CatalogAndOrders::VARIATIONS;
        return [
            $this->wpdb->get_results("SELECT * FROM `{$attributes}` ORDER BY id", 'ARRAY_A'),
            $this->wpdb->get_results("SELECT * FROM `{$variations}` ORDER BY id", 'ARRAY_A'),
        ];
    }

    /**
     * The measured shape of an imported variable product: published, variable,
     * parent figures nought, linked to a storefront parent, OUR tables empty,
     * and the shop holding three priced children.
     */
    private function importedParent(): int
    {
        $productId = $this->products->create(
            self::VENDOR,
            new ProductDetails(
                title: 'چسب پنبه‌ای نمونه',
                type: ProductType::SIMPLE,
                categoryKey: '9559',
                priceMinor: 1,
                sku: 'FIXTURE-BPI',
                stock: 1
            ),
            ProductStatus::Draft
        );
        self::assertGreaterThan(0, $productId);

        $table = $this->wpdb->prefix . M0005CreateProductTables::PRODUCTS;
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE `{$table}` SET `type` = 'variable', `price_minor` = 0, `stock` = 0,"
            . " `status` = 'published', `wc_product_id` = %d, `link_ownership` = 'marketplace'"
            . " WHERE `id` = %d",
            self::WC_PARENT,
            $productId
        ));

        $this->storefront->holds(self::WC_PARENT, [
            $this->child(7701, '۵۰ عددی', 2_600_000, 8),
            $this->child(7702, '۱۰۰ عددی', 5_187_400, 3),
            $this->child(7703, '۲۰۰ عددی', 9_900_000, null),
        ]);
        return $productId;
    }

    private function child(int $id, string $size, int $price, ?int $qty): StorefrontVariation
    {
        return new StorefrontVariation(
            $id,
            ['بسته' => $size],
            'BPI-' . $id,
            $price,
            null,
            $price,
            true,
            $qty !== null,
            $qty,
            $qty === null || $qty > 0 ? 'instock' : 'outofstock',
            'no',
            0
        );
    }
}
