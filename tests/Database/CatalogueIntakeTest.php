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
use Tecteb\Marketplace\Modules\Migration\Application\CatalogueInventory;
use Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeCatalogueReader;

/**
 * Bringing products the shop already has into the marketplace — on real
 * MariaDB, where «what is in the table afterwards» can actually be asked.
 *
 * The catalogue reader is a double, and says so: what it reads needs
 * WooCommerce, which no suite has. What is real here is everything that
 * decides and everything that is written — the rows, the baseline, the run
 * manifest, the rollback — which is the half a suite can hold to account.
 * The other half is measured on WooCommerce 11.0.1 by
 * `tools/alpha55-reproduction.sh` and reported separately.
 */
final class CatalogueIntakeTest extends DatabaseTestCase
{
    private const MANAGER = 9;
    private const VENDOR = 520;
    private const OTHER_VENDOR = 521;
    private const WC_READY = 7001;
    private const WC_SHOP = 7002;

    private ImportExistingProducts $intake;
    private FakeCatalogueReader $reader;
    private DbProductRepository $products;
    private DbVendorRepository $vendors;
    private DbProductRevisionRepository $revisions;
    private WpOptionStore $options;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->options = new WpOptionStore();
        $this->options->delete(ImportExistingProducts::RUNS_OPTION);
        $this->products = new DbProductRepository($this->db, $clock);
        $this->vendors = new DbVendorRepository($this->db, $clock);
        $this->revisions = new DbProductRevisionRepository($this->db, $clock);

        // An approved vendor, through the vendor repository rather than by
        // hand: a profile written straight into the table is «approved and
        // unable to sell» (`alpha.17`).
        $this->vendors->upsertProfile(self::VENDOR, "داروخانهٔ آزمون", true, false);
        $this->vendors->upsertProfile(self::OTHER_VENDOR, "فروشگاه دوم", true, false);

        $this->reader = new FakeCatalogueReader();
        $this->reader->add(self::WC_READY, [
            'title' => 'ماسک سه‌لایه',
            'short_description' => 'خلاصهٔ فروشگاه',
            'description' => 'متن کامل فروشگاه',
            'regular_price' => '450000',
            'stock_quantity' => 22,
            'sku' => 'WC-7001',
            'category_ids' => [31, 12],
            'image_id' => 4100,
            'gallery_image_ids' => [4101, 4102],
            'brand' => 'آریا طب',
            'brand_term_id' => 89,
            'weight' => '1.5',
        ], ['author_id' => self::VENDOR, 'vendor_profile' => 'approved']);
        $this->reader->add(self::WC_SHOP, [
            'title' => 'محصول فروشگاه اصلی',
        ], ['author_id' => 1, 'author_is_admin' => true, 'author_roles' => ['administrator']]);

        $this->intake = $this->service();
    }

    protected function tearDown(): void
    {
        $this->options->delete(ImportExistingProducts::RUNS_OPTION);
        parent::tearDown();
    }

    // ============================================== §3 what an intake writes

    /**
     * The row points at the EXISTING product and carries what WooCommerce
     * holds now — the whole point of «ورود بدون ساخت محصول جدید ووکامرس».
     */
    public function testAnIntakeLinksTheExistingProductAndCopiesItsCurrentValues(): void
    {
        $batch = $this->intake->importBatch('intake-1', [self::WC_READY]);

        self::assertTrue($batch['ok'], (string) $batch['code']);
        self::assertSame(1, $batch['counts']['imported']);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);
        self::assertNotNull($product, 'an OBSERVED row, not an owned one');
        self::assertSame(self::WC_READY, (int) $product->wcProductId, 'the same id, never a new product');
        self::assertSame(self::VENDOR, $product->vendorUserId);
        self::assertSame('ماسک سه‌لایه', $product->details->title);
        self::assertSame(450000, $product->details->priceMinor);
        self::assertSame(22, $product->details->stock);
        self::assertSame('WC-7001', $product->details->sku);
        self::assertSame('خلاصهٔ فروشگاه', $product->details->shortDescription);
        self::assertSame('متن کامل فروشگاه', $product->details->description);
        self::assertSame('31', $product->details->categoryKey, 'one category, the first');
        self::assertSame(1500, $product->details->weightGrams);
        // Published in WooCommerce, so published here — a draft row would
        // take the live product off sale at the first projection.
        self::assertSame(ProductStatus::Published, $product->status);
        self::assertFalse($product->ownsItsStorefrontProduct(), 'and nothing follows from the row yet');
    }

    /**
     * The agreement baseline is built from the same values, with the same
     * encoders the approval path uses.
     */
    public function testTheBaselineRecordsWhatBothSidesAgreeOnAtIntake(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);

        self::assertNotNull($product?->baseline);
        self::assertSame('ماسک سه‌لایه', $product->baseline->get('title'));
        self::assertSame('خلاصهٔ فروشگاه', $product->baseline->get('short_description'));
        self::assertSame('متن کامل فروشگاه', $product->baseline->get('description'));
        self::assertSame(
            StorefrontField::idsToValue([31, 12]),
            $product->baseline->get('category'),
            'the same encoder the projection stamp uses'
        );
        self::assertSame(
            StorefrontImages::encode(4100, [4101, 4102]),
            $product->baseline->get('images')
        );
    }

    /** The brand comes across with its term, and no second term is made. */
    public function testTheBrandIsKeptWithTheTermTheProductAlreadyHas(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);

        self::assertSame('آریا طب', $product?->details->brand);
        self::assertSame(89, $this->products->brandTermIdOf((int) $product->id));
    }

    /** The product's own quantity rule travels too, so a projection is a no-op. */
    public function testTheQuantityRuleOnTheProductIsCarriedIntoTheRow(): void
    {
        $this->reader->quantities[self::WC_READY] = ['min' => 2, 'max' => 10, 'step' => 2];

        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);

        self::assertSame(2, $product?->details->minPurchase);
        self::assertSame(10, $product->details->maxPurchase);
        self::assertSame(2, $product->details->purchaseStep);
    }

    // ================================================ §2 whose product is it

    /**
     * THE RULE: the shop's own product is not given to anybody, and the
     * refusal names the decision.
     */
    public function testTheShopsOwnProductIsNotImportedWithoutAnOwnerDecision(): void
    {
        $batch = $this->intake->importBatch('intake-1', [self::WC_SHOP]);

        self::assertSame(0, $batch['counts']['imported']);
        self::assertSame('shop_product_needs_owner_decision', $batch['results'][0]['reason']);
        self::assertNull($this->products->findObservedByWcProduct(self::WC_SHOP));
    }

    /** …and it IS imported once a manager names a vendor explicitly. */
    public function testAManagerCanNameTheVendorForTheShopsOwnProduct(): void
    {
        $batch = $this->intake->importBatch('intake-1', [self::WC_SHOP], self::OTHER_VENDOR);

        self::assertSame(1, $batch['counts']['imported']);
        $product = $this->products->findObservedByWcProduct(self::WC_SHOP);
        self::assertSame(self::OTHER_VENDOR, $product?->vendorUserId);
    }

    /** A named «vendor» who is not one is refused rather than created. */
    public function testANamedVendorWhoIsNotAVendorIsRefused(): void
    {
        $batch = $this->intake->importBatch('intake-1', [self::WC_SHOP], 99999);

        self::assertSame(0, $batch['counts']['imported']);
        self::assertSame('assigned_vendor_is_not_a_vendor', $batch['results'][0]['reason']);
    }

    // ================================= §4 repeatable, resumable, reversible

    /** A second run over the same product creates no second row. */
    public function testRunningTwiceCreatesNoDuplicateRowAndNoDuplicateLink(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $first = $this->products->findObservedByWcProduct(self::WC_READY);
        $this->reader->link(self::WC_READY, [[
            'product_id' => (int) $first?->id,
            'vendor_user_id' => self::VENDOR,
            'ownership' => LinkOwnership::Observed->value,
            'status' => 'published',
        ]]);

        $second = $this->intake->importBatch('intake-2', [self::WC_READY]);

        self::assertSame(0, $second['counts']['imported']);
        self::assertSame('already_linked_observed', $second['results'][0]['reason']);
        self::assertSame(
            1,
            (int) $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_products` WHERE wc_product_id = %d',
                [self::WC_READY]
            )
        );
    }

    /** An ambiguous product does not stop a sound one in the same batch. */
    public function testAnAmbiguousProductDoesNotHoldUpTheSoundOnes(): void
    {
        $batch = $this->intake->importBatch('intake-1', [self::WC_SHOP, self::WC_READY]);

        self::assertSame(1, $batch['counts']['imported']);
        self::assertSame(1, $batch['counts']['skipped']);
        self::assertNotNull($this->products->findObservedByWcProduct(self::WC_READY));
    }

    /** The manifest is written per product, so a half-finished run is undoable. */
    public function testTheRunManifestKnowsEachRowAsItIsCreated(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);

        $runs = $this->intake->runs();
        self::assertArrayHasKey('intake-1', $runs);
        self::assertCount(1, $runs['intake-1']['products']);
        self::assertSame(
            $runs['intake-1']['products'],
            $this->products->idsFromImportRun('intake-1'),
            'the manifest and the stamped rows say the same thing'
        );
    }

    /** The rollback removes exactly this run's rows. */
    public function testTheRollbackRemovesOnlyTheRowsThisRunCreated(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $this->intake->importBatch('intake-2', [self::WC_SHOP], self::OTHER_VENDOR);

        $result = $this->intake->rollback('intake-1');

        self::assertTrue($result->ok, $result->code);
        self::assertSame(1, $result->context['removed']);
        self::assertNull($this->products->findObservedByWcProduct(self::WC_READY));
        self::assertNotNull(
            $this->products->findObservedByWcProduct(self::WC_SHOP),
            'the other run is untouched'
        );
        self::assertArrayNotHasKey('intake-1', $this->intake->runs());
    }

    /** A row somebody has accepted since is KEPT, and named. */
    public function testTheRollbackKeepsARowThatHasBeenAcceptedSince(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);
        self::assertTrue($this->products->setLinkOwnership((int) $product?->id, LinkOwnership::Marketplace));

        $result = $this->intake->rollback('intake-1');

        self::assertFalse($result->ok);
        self::assertSame('intake_rollback_kept', $result->code);
        self::assertStringContainsString(':accepted', (string) $result->context['kept']);
        self::assertNotNull($this->products->find((int) $product->id), 'the row is still there');
    }

    /** A row carrying an unanswered proposal is kept too. */
    public function testTheRollbackKeepsARowWithAnUnansweredProposal(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);
        self::assertGreaterThan(
            0,
            $this->revisions->create((int) $product?->id, self::VENDOR, ['title' => 'عنوان تازه'])
        );

        $result = $this->intake->rollback('intake-1');

        self::assertFalse($result->ok);
        self::assertStringContainsString(':open_proposal', (string) $result->context['kept']);
        self::assertNotNull($this->products->find((int) $product->id));
    }

    /** An unknown run is said so rather than reported as «nothing to do». */
    public function testAnUnknownRunIsRefusedByName(): void
    {
        $result = $this->intake->rollback('intake-never-happened');

        self::assertFalse($result->ok);
        self::assertSame('intake_run_unknown', $result->code);
    }

    // ============================================= §3 what it will not take

    /** A price with a fraction is refused, never rounded. */
    public function testAPriceThatIsNotAnExactIntegerIsRefused(): void
    {
        $this->reader->details[self::WC_READY]['regular_price'] = '450000.50';

        $batch = $this->intake->importBatch('intake-1', [self::WC_READY]);

        self::assertSame(0, $batch['counts']['imported']);
        self::assertSame('price_not_an_integer', $batch['results'][0]['reason']);
        self::assertNull($this->products->findObservedByWcProduct(self::WC_READY));
    }

    /** A SKU already used in that shop is a conflict for a person to settle. */
    public function testASkuAlreadyUsedInTheShopIsAConflict(): void
    {
        $this->products->create(
            self::VENDOR,
            new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(
                title: 'محصول قبلی',
                priceMinor: 1000,
                sku: 'WC-7001'
            ),
            ProductStatus::Draft
        );

        $batch = $this->intake->importBatch('intake-1', [self::WC_READY]);

        self::assertSame(0, $batch['counts']['imported']);
        self::assertSame('sku_already_used_in_this_shop', $batch['results'][0]['reason']);
    }

    /** The preview writes nothing — not a row, not a manifest entry. */
    public function testThePreviewWritesNothingAtAll(): void
    {
        $preview = $this->intake->preview([self::WC_READY, self::WC_SHOP]);

        self::assertCount(2, $preview);
        self::assertSame('import', $preview[0]['verdict']);
        self::assertSame('skip', $preview[1]['verdict']);
        self::assertNull($this->products->findObservedByWcProduct(self::WC_READY));
        self::assertSame([], $this->intake->runs());
    }

    /** Without the capability nothing is written and the refusal is named. */
    public function testWithoutTheCapabilityNothingIsImported(): void
    {
        $service = $this->service(new FakeCapabilityChecker(self::MANAGER, []));

        $batch = $service->importBatch('intake-1', [self::WC_READY]);

        self::assertFalse($batch['ok']);
        self::assertSame('forbidden', $batch['code']);
        self::assertNull($this->products->findObservedByWcProduct(self::WC_READY));
    }

    // ===================================== §3 acceptance names what it risks

    /**
     * Accepting management is refused while a field this marketplace cannot
     * preserve is unacknowledged — and the keys are named.
     */
    public function testAcceptanceIsRefusedUntilTheRisksAreAcknowledged(): void
    {
        $this->reader->risks[self::WC_READY] = [
            'manage_stock' => 'مدیریت موجودی خاموش است',
            'backorders' => 'سفارش پس‌افت فعال است',
        ];
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);
        $transfer = $this->transfer();

        $refused = $transfer->take((int) $product?->id);
        self::assertFalse($refused->ok);
        self::assertSame('ownership_risks_unacknowledged', $refused->code);
        self::assertSame(['manage_stock', 'backorders'], $refused->context['risks']);
        self::assertSame(
            LinkOwnership::Observed,
            $this->products->linkOwnership((int) $product->id),
            'and the ownership did not move'
        );

        // Half the keys is still a refusal: the tick has to carry what the
        // page printed.
        self::assertFalse($transfer->take((int) $product->id, ['manage_stock'])->ok);

        $taken = $transfer->take((int) $product->id, ['manage_stock', 'backorders']);
        self::assertTrue($taken->ok, $taken->code);
        self::assertSame(LinkOwnership::Marketplace, $this->products->linkOwnership((int) $product->id));
    }

    /** With no risks at all, acceptance needs no acknowledgement. */
    public function testAcceptanceWithNoRisksNeedsNoAcknowledgement(): void
    {
        $this->intake->importBatch('intake-1', [self::WC_READY]);
        $product = $this->products->findObservedByWcProduct(self::WC_READY);

        self::assertTrue($this->transfer()->take((int) $product?->id)->ok);
    }

    private function service(?FakeCapabilityChecker $capabilities = null): ImportExistingProducts
    {
        return new ImportExistingProducts(
            $this->reader,
            new CatalogueInventory($this->reader),
            $this->products,
            $this->vendors,
            $this->options,
            $this->audit(),
            new SystemClock(),
            $capabilities ?? new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            $this->revisions
        );
    }

    private function transfer(): TransferOwnership
    {
        return new TransferOwnership(
            $this->products,
            new FakeCatalogProjector(),
            $this->audit(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR]),
            null,
            $this->reader
        );
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), new SystemClock());
    }
}
