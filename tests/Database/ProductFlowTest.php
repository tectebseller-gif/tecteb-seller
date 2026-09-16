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
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RateScope;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbCommissionRuleRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

/**
 * The product stage end to end, on real MariaDB.
 *
 * What these tests are for: every rule the owner asked to be visible in the
 * delivery — vendor ownership, staff access, publish approval, inventory,
 * images, category fields and CSV — is asserted here against the real tables
 * rather than described in a document.
 */
final class ProductFlowTest extends DatabaseTestCase
{
    private const VENDOR = 31;
    private const OTHER_VENDOR = 32;
    private const STAFF = 33;
    private const MANAGER = 9;

    private DbProductRepository $products;
    private DbSpecTemplateRepository $templates;
    private DbProductRevisionRepository $revisions;
    private DbVariationRepository $variations;
    private DbVendorRepository $vendors;
    private DbStaffRepository $staff;
    private DbCommissionRuleRepository $rules;
    private ManageProducts $manage;
    private ReviewProducts $review;
    private ConfigureSpecTemplates $configure;
    private ProductCsv $csv;
    private ProductPublishPolicy $publishing;
    private FakeProductImages $images;
    private FakeCatalogProjector $projector;
    private FakeCapabilityChecker $capabilities;

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
        $this->templates = new DbSpecTemplateRepository($db, $clock);
        $this->revisions = new DbProductRevisionRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->staff = new DbStaffRepository($db, $clock);
        $this->rules = new DbCommissionRuleRepository($db, $clock);
        $this->images = new FakeProductImages();
        $this->publishing = new ProductPublishPolicy($options);
        $this->capabilities = new FakeCapabilityChecker(self::MANAGER, [
            Capabilities::REVIEW_PRODUCTS,
            Capabilities::MANAGE_SPEC_TEMPLATES,
        ]);
        $access = new StaffAccess($this->staff, $this->vendors);
        $states = new ProductStateMachine();
        $this->variations = new DbVariationRepository($db, $clock);
        $readiness = new ProductReadiness($this->templates, $this->variations);
        $this->projector = new FakeCatalogProjector();
        $catalog = new SyncCatalog($this->products, $this->variations, $this->projector, $audit);

        $this->manage = new ManageProducts(
            $this->products,
            $this->templates,
            $this->revisions,
            $readiness,
            $catalog,
            $this->images,
            $access,
            $this->publishing,
            $states,
            $audit
        );
        $this->review = new ReviewProducts(
            $this->products,
            $this->revisions,
            $this->templates,
            $readiness,
            $catalog,
            $this->publishing,
            $states,
            $audit,
            $this->capabilities
        );
        $this->configure = new ConfigureSpecTemplates($this->templates, $audit, $this->capabilities);
        $this->csv = new ProductCsv($this->products, $this->templates, $this->manage, $access, $audit);

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', true, false);
        $this->vendors->upsertProfile(self::OTHER_VENDOR, 'داروخانه دو', true, false);
    }

    // ------------------------------------------------------------- ownership

    public function testAVendorNeverSeesOrEditsAnotherShopsProduct(): void
    {
        $mine = $this->createProduct(self::VENDOR, 'دستکش لاتکس');
        $theirs = $this->createProduct(self::OTHER_VENDOR, 'ماسک سه‌لایه');

        self::assertNotNull($this->products->findOwned($mine, self::VENDOR));
        self::assertNull(
            $this->products->findOwned($theirs, self::VENDOR),
            'AC-PRIV: changing the id in the URL must not reach shop B'
        );

        $stolen = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $theirs,
            new ProductDetails(title: 'دزدیده‌شده', categoryKey: 'gloves', priceMinor: 1), [], [], 0, $this->stampOf($theirs));
        self::assertFalse($stolen->ok);
        self::assertSame('not_found', $stolen->code);
        self::assertSame('ماسک سه‌لایه', $this->products->find($theirs)?->details->title);

        $listed = $this->products->forVendor(self::VENDOR);
        self::assertCount(1, $listed);
        self::assertSame($mine, $listed[0]->id);
    }

    public function testAnImageBelongingToAnotherShopIsDroppedNotAttached(): void
    {
        $productId = $this->createProduct(self::VENDOR, 'باند کشی');
        $this->images->give(900, self::OTHER_VENDOR);
        $this->images->give(901, self::VENDOR);

        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('باند کشی'),
            [],
            [900, 901],
            900,
            $this->stampOf($productId)
        );
        $product = $this->products->find($productId);
        self::assertSame([901], $product?->imageIds, 'only this shop\'s attachment survives');
        self::assertSame(901, $product?->mainImageId, 'the main image falls back to one the gallery has');
    }

    // ------------------------------------------------------------ staff access

    public function testStaffActInTheShopOnlyWithinTheirOwnPermissions(): void
    {
        // Product and inventory editor: may do both.
        $staffId = $this->addStaff(StaffRolePreset::ProductAndInventory);
        $this->staff->activate($staffId);

        $created = $this->manage->save(self::STAFF, self::VENDOR, 0, $this->details('چسب زخم'));
        self::assertTrue($created->ok, $created->code);

        // Support: no product rights at all.
        $this->staff->updateRole(
            $staffId,
            StaffRolePreset::CustomerSupport,
            StaffRolePreset::CustomerSupport->permissions()
        );
        $refused = $this->manage->save(self::STAFF, self::VENDOR, 0, $this->details('نخ بخیه'));
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
    }

    public function testSuspendingTheShopStopsItsStaffImmediately(): void
    {
        $staffId = $this->addStaff(StaffRolePreset::ProductAndInventory);
        $this->staff->activate($staffId);
        self::assertTrue($this->manage->save(self::STAFF, self::VENDOR, 0, $this->details('گاز استریل'))->ok);

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانه یک', false, false);

        $afterSuspension = $this->manage->save(self::STAFF, self::VENDOR, 0, $this->details('سرنگ'));
        self::assertFalse($afterSuspension->ok);
        self::assertSame('forbidden', $afterSuspension->code, 'suspension cuts staff off, not just the owner');
        self::assertCount(1, $this->products->forVendor(self::VENDOR), 'history is untouched');
    }

    // --------------------------------------------------------- publish approval

    public function testSubmittingQueuesTheProductAndOnlyTheManagerPublishesIt(): void
    {
        $productId = $this->readyProduct(self::VENDOR);

        $submitted = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertTrue($submitted->ok, $submitted->code);
        self::assertSame('product_submitted', $submitted->code);
        self::assertSame(ProductStatus::Submitted, $this->products->find($productId)?->status);

        $changes = $this->review->requestChanges($productId, 'تصویر واضح‌تری لازم است.');
        self::assertTrue($changes->ok, $changes->code);
        $product = $this->products->find($productId);
        self::assertSame(ProductStatus::ChangesRequested, $product?->status);
        self::assertSame('تصویر واضح‌تری لازم است.', $product?->reviewNote);

        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);
        self::assertSame(ProductStatus::Published, $this->products->find($productId)?->status);
    }

    public function testDirectPublishingIsASeparateGrantAndIsRevocable(): void
    {
        $productId = $this->readyProduct(self::VENDOR);
        self::assertTrue($this->review->setDirectPublishing(self::VENDOR, true)->ok);

        $submitted = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertSame('product_published', $submitted->code);
        self::assertSame(ProductStatus::Published, $this->products->find($productId)?->status);

        self::assertTrue($this->review->setDirectPublishing(self::VENDOR, false)->ok);
        $second = $this->readyProduct(self::VENDOR, 'محصول دوم', 'SKU-2');
        self::assertSame('product_submitted', $this->manage->submit(self::VENDOR, self::VENDOR, $second)->code);
    }

    public function testAReviewerWithoutTheCapabilityDecidesNothing(): void
    {
        $productId = $this->readyProduct(self::VENDOR);
        $this->manage->submit(self::VENDOR, self::VENDOR, $productId);

        $this->capabilities->become(self::MANAGER, []);
        $refused = $this->review->approve($productId);
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
        self::assertSame(ProductStatus::Submitted, $this->products->find($productId)?->status);
    }

    public function testSubmissionRefusesAnIncompleteProductAndSaysWhat(): void
    {
        $productId = $this->createProduct(self::VENDOR, '');
        $verdict = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($verdict->ok);
        self::assertSame('incomplete_product', $verdict->code);
        self::assertStringContainsString('title', (string) $verdict->context['fields']);

        // Complete, but no picture: UX §5.2 makes the image mandatory.
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $this->details('ترمومتر'), [], [], 0, $this->stampOf($productId));
        self::assertSame('missing_image', $this->manage->submit(self::VENDOR, self::VENDOR, $productId)->code);
    }

    public function testAManagerCannotPublishAProductThatTheVendorCouldNotSubmit(): void
    {
        // No picture, and the category demands a material.
        $templateId = $this->template();
        $this->configure->addField($templateId, 'material', 'جنس', 'text', true);
        $productId = $this->createProduct(self::VENDOR, 'دستکش بدون تصویر');

        $refused = $this->review->approve($productId);
        self::assertFalse($refused->ok, 'approval is not a way around the marketplace\'s own rules');
        self::assertSame('missing_image', $refused->code);
        self::assertSame(ProductStatus::Draft, $this->products->find($productId)?->status);
    }

    public function testARevisionThatWouldBreakTheLiveProductIsRefusedAndNothingChanges(): void
    {
        $templateId = $this->template();
        $this->configure->addField($templateId, 'material', 'جنس', 'text', true);

        $productId = $this->readyProduct(self::VENDOR);
        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details(),
            ['material' => 'لاتکس'],
            $this->galleryOf($productId),
            $this->mainOf($productId), $this->stampOf($productId));
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);

        // The proposal empties the required medical field.
        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش نیتریل'),
            ['material' => ''],
            $this->galleryOf($productId),
            $this->mainOf($productId), $this->stampOf($productId));
        $revisionId = $this->revisions->pendingFor($productId)?->id ?? 0;

        $refused = $this->review->approveRevision($revisionId);
        self::assertFalse($refused->ok);
        self::assertSame('missing_specs', $refused->code);

        $live = $this->products->find($productId);
        self::assertSame('دستکش لاتکس', $live?->details->title, 'the live product is put back exactly as it was');
        self::assertSame('لاتکس', $live?->specs['material'] ?? null);
        self::assertSame(ProductRevision::PENDING, $this->revisions->find($revisionId)?->status);
    }

    // ------------------------------------------------- live product and revision

    public function testASensitiveChangeToALiveProductBecomesARevisionAndStockStillMoves(): void
    {
        $productId = $this->publish(self::VENDOR);

        $changed = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('عنوان تازه', priceMinor: 250000, stock: 3),
            [],
            $this->products->find($productId)?->imageIds ?? [],
            $this->products->find($productId)?->mainImageId ?? 0, $this->stampOf($productId));
        self::assertSame('revision_requested', $changed->code);

        $live = $this->products->find($productId);
        self::assertSame('دستکش لاتکس', $live?->details->title, 'the live product is untouched');
        self::assertSame(200000, $live?->details->priceMinor);
        self::assertSame(3, $live?->details->stock, 'inventory applies at once (A.1)');
        self::assertSame(ProductStatus::Published, $live?->status);

        $pending = $this->revisions->pendingFor($productId);
        self::assertNotNull($pending);
        self::assertSame('عنوان تازه', $pending->payload['details']['title']);
    }

    public function testRejectingARevisionLeavesThePublishedProductOnTheSite(): void
    {
        $productId = $this->publish(self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $this->details('عنوان تازه'), [], $this->galleryOf($productId), $this->mainOf($productId), $this->stampOf($productId));
        $revisionId = $this->revisions->pendingFor($productId)?->id ?? 0;

        $rejected = $this->review->rejectRevision($revisionId, 'عنوان تازه گمراه‌کننده است.');
        self::assertTrue($rejected->ok, $rejected->code);

        $live = $this->products->find($productId);
        self::assertSame(ProductStatus::Published, $live?->status, 'UX §14.2: rejecting a change never unpublishes');
        self::assertSame('دستکش لاتکس', $live?->details->title);
        self::assertSame(ProductRevision::REJECTED, $this->revisions->find($revisionId)?->status);
        self::assertNull($this->revisions->pendingFor($productId));
    }

    public function testApprovingARevisionWritesTheProposalButNotItsStaleStock(): void
    {
        $productId = $this->publish(self::VENDOR);
        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش نیتریل', priceMinor: 260000, stock: 9),
            [],
            $this->galleryOf($productId),
            $this->mainOf($productId), $this->stampOf($productId));
        $revisionId = $this->revisions->pendingFor($productId)?->id ?? 0;

        // Days pass and the shop sells: stock is now 2, not the 9 in the payload.
        $this->manage->updateInventory(self::VENDOR, self::VENDOR, $productId, 2, 'SKU-1', 1, null);

        self::assertTrue($this->review->approveRevision($revisionId)->ok);
        $live = $this->products->find($productId);
        self::assertSame('دستکش نیتریل', $live?->details->title);
        self::assertSame(260000, $live?->details->priceMinor);
        self::assertSame(2, $live?->details->stock, 'approval must not roll inventory back');
    }

    public function testASecondProposalSupersedesTheFirstRatherThanQueueingBehindIt(): void
    {
        $productId = $this->publish(self::VENDOR);
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $this->details('اولی'), [], $this->galleryOf($productId), $this->mainOf($productId), $this->stampOf($productId));
        $first = $this->revisions->pendingFor($productId)?->id ?? 0;
        $this->manage->save(self::VENDOR, self::VENDOR, $productId, $this->details('دومی'), [], $this->galleryOf($productId), $this->mainOf($productId), $this->stampOf($productId));
        $second = $this->revisions->pendingFor($productId)?->id ?? 0;

        self::assertNotSame($first, $second);
        self::assertSame(ProductRevision::SUPERSEDED, $this->revisions->find($first)?->status);
        self::assertSame(1, $this->revisions->countPending());
    }

    public function testEditingAProductThatIsInTheQueueIsRefused(): void
    {
        $productId = $this->readyProduct(self::VENDOR);
        $this->manage->submit(self::VENDOR, self::VENDOR, $productId);

        $refused = $this->manage->save(self::VENDOR, self::VENDOR, $productId, $this->details('عنوان تازه'), [], [], 0, $this->stampOf($productId));
        self::assertFalse($refused->ok);
        self::assertSame('in_review', $refused->code);

        // Inventory is the exception, at every status.
        $stock = $this->manage->updateInventory(self::VENDOR, self::VENDOR, $productId, 7, 'SKU-1', 1, null);
        self::assertTrue($stock->ok, $stock->code);
        self::assertSame(7, $this->products->find($productId)?->details->stock);
    }

    // ------------------------------------------------------------- inventory

    public function testInventoryRefusesNegativeStockAndADuplicateSku(): void
    {
        $first = $this->createProduct(self::VENDOR, 'محصول یک', 'SKU-A');
        $this->createProduct(self::VENDOR, 'محصول دو', 'SKU-B');

        self::assertSame('bad_stock', $this->manage->updateInventory(self::VENDOR, self::VENDOR, $first, -1, 'SKU-A', 1, null)->code);
        self::assertSame('bad_quantity', $this->manage->updateInventory(self::VENDOR, self::VENDOR, $first, 1, 'SKU-A', 3, 2)->code);

        $clash = $this->manage->updateInventory(self::VENDOR, self::VENDOR, $first, 1, 'SKU-B', 1, null);
        self::assertFalse($clash->ok);
        self::assertSame('sku_taken', $clash->code);

        // The same SKU in ANOTHER shop is nobody else's business.
        $theirs = $this->createProduct(self::OTHER_VENDOR, 'محصول آن‌ها', 'SKU-A');
        self::assertTrue($this->manage->updateInventory(self::OTHER_VENDOR, self::OTHER_VENDOR, $theirs, 4, 'SKU-A', 1, null)->ok);
    }

    // ------------------------------------------------- category dynamic fields

    public function testARetiredFieldStopsBeingAskedAndKeepsItsAnswers(): void
    {
        $templateId = $this->template();
        $this->configure->addField($templateId, 'material', 'جنس', 'text', true);
        $this->configure->addField($templateId, 'old_code', 'کد قدیمی', 'text', true);

        $productId = $this->createProduct(self::VENDOR, 'دستکش لاتکس');
        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش لاتکس'),
            ['material' => 'لاتکس', 'old_code' => 'X-1'], [], 0, $this->stampOf($productId));
        $versionThen = $this->templates->find($templateId)?->schemaVersion ?? 0;
        self::assertSame($versionThen, $this->products->find($productId)?->specSchemaVersion);

        $fieldId = 0;
        foreach ($this->templates->find($templateId)?->allFields() ?? [] as $field) {
            if ($field->key === 'old_code') {
                $fieldId = $field->id;
            }
        }
        self::assertTrue($this->configure->deprecateField($templateId, $fieldId)->ok);

        $template = $this->templates->find($templateId);
        self::assertCount(1, $template?->askedFields() ?? []);
        self::assertCount(2, $template?->allFields() ?? []);
        self::assertGreaterThan($versionThen, $template?->schemaVersion ?? 0, 'the shape changed, so the version moved');
        self::assertSame('X-1', $this->products->specs($productId)['old_code'] ?? null, 'MED-01: the answer survives');
    }

    public function testASpecValueThatTheFieldCannotAcceptIsRefusedOnTheWayIn(): void
    {
        $templateId = $this->template();
        $this->configure->addField($templateId, 'size_mm', 'اندازه', 'number', false);
        $productId = $this->createProduct(self::VENDOR, 'دستکش لاتکس');

        $refused = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش لاتکس'),
            ['size_mm' => 'بزرگ'], [], 0, $this->stampOf($productId));
        self::assertFalse($refused->ok);
        self::assertSame('invalid_specs', $refused->code);
        self::assertSame([], $this->products->specs($productId));
    }

    public function testARequiredCategoryFieldBlocksSubmissionButNotSaving(): void
    {
        $templateId = $this->template();
        $this->configure->addField($templateId, 'material', 'جنس', 'text', true);

        $productId = $this->readyProduct(self::VENDOR);
        $blocked = $this->manage->submit(self::VENDOR, self::VENDOR, $productId);
        self::assertFalse($blocked->ok);
        self::assertSame('missing_specs', $blocked->code);
        self::assertSame('جنس', $blocked->context['fields']);

        $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $productId,
            $this->details('دستکش لاتکس'),
            ['material' => 'لاتکس'],
            $this->galleryOf($productId),
            $this->mainOf($productId), $this->stampOf($productId));
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);
    }

    public function testAFieldKeyIsNeverReusedWithinATemplate(): void
    {
        $templateId = $this->template();
        self::assertTrue($this->configure->addField($templateId, 'material', 'جنس', 'text', false)->ok);
        $again = $this->configure->addField($templateId, 'material', 'جنس دیگر', 'text', false);
        self::assertFalse($again->ok);
        self::assertSame('field_key_taken', $again->code);
    }

    public function testAChoiceFieldWithoutOptionsIsRefused(): void
    {
        $templateId = $this->template();
        $refused = $this->configure->addField($templateId, 'grade', 'رده', 'choice', false);
        self::assertFalse($refused->ok);
        self::assertSame('choice_needs_options', $refused->code);
    }

    // ------------------------------------------- search, ordering and SEO

    public function testTheListSearchesTitleBrandAndSkuWithinTheShopOnly(): void
    {
        $this->createProduct(self::VENDOR, 'دستکش لاتکس پودری', 'GLV-100');
        $this->createProduct(self::VENDOR, 'ماسک سه‌لایه', 'MSK-050');
        $this->createProduct(self::OTHER_VENDOR, 'دستکش فروشگاه دیگر', 'GLV-999');

        $byTitle = $this->products->forVendor(self::VENDOR, null, 20, 0, 'دستکش');
        self::assertCount(1, $byTitle);
        self::assertSame('دستکش لاتکس پودری', $byTitle[0]->details->title);
        self::assertSame(1, $this->products->countForVendor(self::VENDOR, null, 'دستکش'), 'the count matches the rows');

        $bySku = $this->products->forVendor(self::VENDOR, null, 20, 0, 'MSK');
        self::assertCount(1, $bySku);

        // A wildcard the vendor typed is a character they are looking for.
        self::assertSame([], $this->products->forVendor(self::VENDOR, null, 20, 0, '%'));
        // And the other shop's matching product is not in the answer.
        self::assertSame(0, $this->products->countForVendor(self::VENDOR, null, 'GLV-999'));
    }

    public function testSeoIsTheManagersAloneAndReachesTheStorefront(): void
    {
        $productId = $this->publish(self::VENDOR);

        $saved = $this->review->setSeo($productId, ' دستکش لاتکس ', 'دستکش لاتکس پزشکی', 'بستهٔ ۱۰۰ عددی');
        self::assertTrue($saved->ok, $saved->code);
        $product = $this->products->find($productId);
        self::assertSame('دستکش-لاتکس', $product?->seo->slug, 'the slug is normalised, and Persian survives');
        self::assertSame('دستکش لاتکس پزشکی', $product?->seo->title);

        // Saving SEO re-projects, so the public page carries it at once.
        $wcId = (int) $product?->wcProductId;
        self::assertGreaterThan(1, $this->projector->writes[$wcId] ?? 0);

        // Without the reviewer capability there is no way in at all.
        $this->capabilities->become(self::MANAGER, []);
        $refused = $this->review->setSeo($productId, 'other', '', '');
        self::assertFalse($refused->ok);
        self::assertSame('forbidden', $refused->code);
        self::assertSame('دستکش-لاتکس', $this->products->find($productId)?->seo->slug);
    }

    // ------------------------------------------------------------------- CSV

    public function testExportCarriesOnlyThisShopAndNeutralisesFormulas(): void
    {
        $this->createProduct(self::VENDOR, '=SUM(A1:A9)', 'SKU-A');
        $this->createProduct(self::OTHER_VENDOR, 'محصول آن‌ها', 'SKU-Z');

        $export = $this->csv->export(self::VENDOR, self::VENDOR);
        self::assertTrue($export['ok']);
        self::assertSame(1, $export['rows']);
        self::assertStringContainsString('"\'=SUM(A1:A9)"', $export['csv'], 'a formula is defused with a quote');
        self::assertStringNotContainsString('SKU-Z', $export['csv'], 'another shop never appears in the file');
    }

    public function testImportPreviewsBeforeItWritesAndThenWritesWhatItPreviewed(): void
    {
        $csv = "sku,title,price,stock\nSKU-NEW,محصول واردشده,150000,4\n";

        $preview = $this->csv->import(self::VENDOR, self::VENDOR, $csv, false);
        self::assertTrue($preview['ok']);
        self::assertSame(1, $preview['created']);
        self::assertSame('create', $preview['rows'][0]['action']);
        self::assertSame(0, $this->products->countForVendor(self::VENDOR), 'a preview writes nothing');

        $applied = $this->csv->import(self::VENDOR, self::VENDOR, $csv, true);
        self::assertSame(1, $applied['created']);
        $products = $this->products->forVendor(self::VENDOR);
        self::assertCount(1, $products);
        self::assertSame('محصول واردشده', $products[0]->details->title);
        self::assertSame(150000, $products[0]->details->priceMinor);
        self::assertSame(ProductStatus::Draft, $products[0]->status, 'a CSV never publishes');
    }

    public function testImportUpdatesBySkuWithinTheShopAndCannotReachAnother(): void
    {
        $mine = $this->createProduct(self::VENDOR, 'قدیمی', 'SKU-A');
        $theirs = $this->createProduct(self::OTHER_VENDOR, 'محصول آن‌ها', 'SKU-A');

        $report = $this->csv->import(self::VENDOR, self::VENDOR, "sku,title,price\nSKU-A,عنوان تازه,99000\n", true);
        self::assertSame(1, $report['updated']);
        self::assertSame('عنوان تازه', $this->products->find($mine)?->details->title);
        self::assertSame('محصول آن‌ها', $this->products->find($theirs)?->details->title, 'the same SKU elsewhere is untouched');
    }

    public function testImportOfALiveProductProposesAChangeInsteadOfEditingIt(): void
    {
        $productId = $this->publish(self::VENDOR);

        $report = $this->csv->import(self::VENDOR, self::VENDOR, "sku,title,price,stock\nSKU-1,عنوان از فایل,300000,6\n", true);
        self::assertSame(1, $report['updated']);
        self::assertSame('revision', $report['rows'][0]['action']);

        $live = $this->products->find($productId);
        self::assertSame('دستکش لاتکس', $live?->details->title);
        self::assertSame(6, $live?->details->stock, 'the inventory column still applies at once');
        self::assertNotNull($this->revisions->pendingFor($productId));
    }

    public function testImportReportsABadRowWithoutStoppingTheGoodOnes(): void
    {
        $csv = "sku,title,price,stock\nSKU-OK,محصول درست,120000,2\nSKU-BAD,محصول غلط,120000,-5\n";
        $report = $this->csv->import(self::VENDOR, self::VENDOR, $csv, true);

        self::assertSame(1, $report['created']);
        self::assertSame(1, $report['skipped']);
        self::assertSame('bad_stock', $report['rows'][1]['code']);
        self::assertSame(3, $report['rows'][1]['line'], 'the line number is the one in the file');
        self::assertCount(1, $this->products->forVendor(self::VENDOR));
    }

    public function testAStaffMemberWithoutProductRightsCannotExport(): void
    {
        $export = $this->csv->export(self::STAFF, self::VENDOR);
        self::assertFalse($export['ok']);
        self::assertSame('forbidden', $export['code']);
        self::assertSame('', $export['csv']);
    }

    // ------------------------------------------------------- estimated share

    public function testTheEstimatedShareIsUnsetRatherThanZeroWhenNoRateExists(): void
    {
        $productId = $this->publish(self::VENDOR);
        $estimator = new EstimateVendorShare(new ResolveCommissionRate($this->rules), new CommissionCalculator());

        $unset = $estimator->forProduct($this->products->find($productId), '2026-09-14');
        self::assertSame(CommissionOutcome::NEEDS_CONFIGURATION, $unset->state);
        self::assertSame('rate_unset', $unset->reason, 'FIN-02: no rate is not a zero rate');

        $this->rules->setRate(RateScope::General, 'general', CommissionRate::ofBasisPoints(1000));
        $calculated = $estimator->forProduct($this->products->find($productId), '2026-09-14');
        self::assertTrue($calculated->isCalculated());
        self::assertSame(200000, $calculated->base?->minor);
        self::assertSame(20000, $calculated->commission?->minor);
        self::assertSame(180000, $calculated->vendorShare?->minor);
    }

    // ------------------------------------------------------------- helpers

    private function details(
        string $title = 'دستکش لاتکس',
        int $priceMinor = 200000,
        int $stock = 5,
        string $sku = 'SKU-1'
    ): ProductDetails {
        return new ProductDetails(
            title: $title,
            categoryKey: 'gloves',
            priceMinor: $priceMinor,
            sku: $sku,
            stock: $stock
        );
    }


    /**
     * And the refusal reaches the vendor as something they can act on.
     *
     * A `false` from the repository is not a user experience. The application
     * turns it into a named code that carries the row's CURRENT counter, which
     * is what lets the form come back stamped correctly with their own values
     * still in it.
     */
    public function testTheVendorIsToldWhatToDoAboutAFormWithNoStamp(): void
    {
        $id = $this->createProduct(self::VENDOR, 'روی سطر');

        $refused = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('چیزی که تایپ کرده'),
            [],
            [],
            0,
            ''
        );

        self::assertFalse($refused->ok);
        self::assertSame('revision_missing', $refused->code);
        self::assertSame(
            $this->products->rowVersion($id),
            $refused->context['current_revision'],
            'the answer carries the stamp the next attempt needs'
        );
        self::assertSame('روی سطر', $this->products->find($id)?->details->title, 'and the row is untouched');

        // Resubmitted with the stamp the refusal handed back: it goes through.
        $again = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('چیزی که تایپ کرده'),
            [],
            [],
            0,
            (string) $refused->context['current_revision']
        );
        self::assertTrue($again->ok, $again->code);
        self::assertSame('چیزی که تایپ کرده', $this->products->find($id)?->details->title);
    }

    // ------------------------------------------------------- bulk and preview

    public function testABulkActionRunsEveryRowAndNamesTheOnesItRefused(): void
    {
        $ready = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-B1');
        $bare = $this->createProduct(self::VENDOR, 'ماسک بدون تصویر', 'SKU-B2');

        $result = $this->manage->bulk(self::VENDOR, self::VENDOR, 'submit', [$ready, $bare]);

        self::assertTrue($result->ok, 'a batch that RAN is a success even when a row was refused');
        self::assertSame('bulk_done', $result->code);
        self::assertSame(1, $result->context['ok']);
        self::assertSame(1, $result->context['failed']);

        $byId = [];
        foreach ($result->context['rows'] as $row) {
            $byId[$row['product_id']] = $row;
        }
        self::assertTrue($byId[$ready]['ok']);
        self::assertSame('product_submitted', $byId[$ready]['code']);
        self::assertFalse($byId[$bare]['ok'], 'the incomplete row did not stop the complete one');
        self::assertSame('missing_image', $byId[$bare]['code'], 'and it is refused with its own reason');

        self::assertSame(ProductStatus::Submitted, $this->products->find($ready)?->status);
        self::assertSame(ProductStatus::Draft, $this->products->find($bare)?->status);
    }

    /**
     * The point of the preview: the vendor learns what would happen without
     * anything happening. Measured on the rows themselves and on the audit
     * trail, because «it returned the right answer» would still be true of an
     * implementation that had already submitted everything.
     */
    public function testAPreviewChangesNoRowAndWritesNoAuditLine(): void
    {
        $ready = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-P1');
        $bare = $this->createProduct(self::VENDOR, 'ماسک بدون تصویر', 'SKU-P2');
        $before = $this->auditCount();

        $preview = $this->manage->previewBulk(self::VENDOR, self::VENDOR, 'submit', [$ready, $bare]);

        self::assertTrue($preview->ok);
        self::assertSame('bulk_previewed', $preview->code);
        self::assertSame(1, $preview->context['ok']);
        self::assertSame(1, $preview->context['failed']);

        self::assertSame(ProductStatus::Draft, $this->products->find($ready)?->status, 'nothing moved');
        self::assertSame(ProductStatus::Draft, $this->products->find($bare)?->status);
        self::assertSame($before, $this->auditCount(), 'a preview leaves no trace, because it did nothing');
        self::assertSame([], $this->projector->writes, 'and it never reaches the storefront');
    }

    public function testThePreviewSaysExactlyWhatTheActionThenDoes(): void
    {
        $ready = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-A1');
        $bare = $this->createProduct(self::VENDOR, 'ماسک بدون تصویر', 'SKU-A2');
        $archived = $this->readyProduct(self::VENDOR, 'گاز استریل', 'SKU-A3');
        self::assertTrue($this->manage->archive(self::VENDOR, self::VENDOR, $archived)->ok);

        $selection = [$ready, $bare, $archived];
        $forecast = $this->manage->previewBulk(self::VENDOR, self::VENDOR, 'submit', $selection);
        $actual = $this->manage->bulk(self::VENDOR, self::VENDOR, 'submit', $selection);

        self::assertSame(
            $this->codesById($forecast->context['rows']),
            $this->codesById($actual->context['rows']),
            'the preview and the action are one decision, not two implementations'
        );
        self::assertSame($forecast->context['ok'], $actual->context['ok']);
        self::assertSame($forecast->context['failed'], $actual->context['failed']);
    }

    /**
     * The honest half of the promise. A preview is a forecast, and this test
     * is what stops us calling it anything stronger: the row that somebody
     * else changed in between is reported with its NEW answer, because the
     * action re-plans instead of trusting what was shown.
     */
    public function testARowChangedAfterThePreviewIsReportedWithItsNewAnswer(): void
    {
        $productId = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-R1');

        $forecast = $this->manage->previewBulk(self::VENDOR, self::VENDOR, 'submit', [$productId]);
        self::assertTrue($forecast->context['rows'][0]['ok']);

        // The same product goes out in another tab in the seconds in between.
        self::assertTrue($this->manage->submit(self::VENDOR, self::VENDOR, $productId)->ok);

        $actual = $this->manage->bulk(self::VENDOR, self::VENDOR, 'submit', [$productId]);
        self::assertFalse($actual->context['rows'][0]['ok']);
        self::assertSame('not_submittable', $actual->context['rows'][0]['code']);
        self::assertSame(0, $actual->context['ok']);
    }

    public function testThePreviewCarriesTheTitleAndBothEndsOfTheMove(): void
    {
        $productId = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-T1');

        $row = $this->manage->previewBulk(self::VENDOR, self::VENDOR, 'archive', [$productId])->context['rows'][0];

        // An id is what the vendor would have to go and look up, forty times.
        self::assertSame('دستکش لاتکس', $row['title']);
        self::assertSame('draft', $row['from']);
        self::assertSame('archived', $row['to']);
        self::assertTrue($row['ok']);
    }

    public function testThePreviewRefusesExactlyWhatTheActionRefuses(): void
    {
        $mine = $this->readyProduct(self::VENDOR, 'دستکش لاتکس', 'SKU-G1');

        foreach ([
            ['submit', [], 'nothing_selected'],
            ['dance', [$mine], 'unknown_bulk_action'],
            ['submit', range(1, ManageProducts::BULK_LIMIT + 1), 'bulk_too_large'],
        ] as [$action, $ids, $expected]) {
            $forecast = $this->manage->previewBulk(self::VENDOR, self::VENDOR, $action, $ids);
            $actual = $this->manage->bulk(self::VENDOR, self::VENDOR, $action, $ids);
            self::assertFalse($forecast->ok, $action);
            self::assertSame($expected, $forecast->code);
            self::assertSame($actual->code, $forecast->code, 'a selection one accepts and the other rejects is a bug');
        }
    }

    public function testAnotherShopsProductIsRefusedInThePreviewToo(): void
    {
        $theirs = $this->readyProduct(self::OTHER_VENDOR, 'ماسک سه‌لایه', 'SKU-X1');

        $row = $this->manage->previewBulk(self::VENDOR, self::VENDOR, 'submit', [$theirs])->context['rows'][0];

        self::assertFalse($row['ok']);
        // Not «forbidden» and not a list of what exists: the same answer an
        // id that never existed gets, so the preview cannot be used to probe.
        self::assertSame('not_found', $row['code']);
        self::assertSame('', $row['title'], 'and it does not leak the other shop\'s title');
        self::assertSame(ProductStatus::Draft, $this->products->find($theirs)?->status);
    }

    /** @param list<array{product_id:int,ok:bool,code:string}> $rows @return array<int,string> */
    private function codesById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['product_id']] = (string) $row['code'];
        }
        ksort($out);
        return $out;
    }

    private function auditCount(): int
    {
        return (int) $this->wpdb->get_var('SELECT COUNT(*) FROM `' . $this->auditTable() . '`');
    }

    private function createProduct(int $vendorUserId, string $title, string $sku = ''): int
    {
        $result = $this->manage->save(
            $vendorUserId,
            $vendorUserId,
            0,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 200000, sku: $sku, stock: 5)
        );
        self::assertTrue($result->ok, $result->code);
        return (int) $result->context['product_id'];
    }

    /** A product that would pass submit(): complete, with one owned picture. */
    private function readyProduct(int $vendorUserId, string $title = 'دستکش لاتکس', string $sku = 'SKU-1'): int
    {
        $productId = $this->createProduct($vendorUserId, $title, $sku);
        $mediaId = 700 + $productId;
        $this->images->give($mediaId, $vendorUserId);
        $saved = $this->manage->save(
            $vendorUserId,
            $vendorUserId,
            $productId,
            $this->details($title, sku: $sku),
            [],
            [$mediaId],
            $mediaId, $this->stampOf($productId));
        self::assertTrue($saved->ok, $saved->code);
        return $productId;
    }

    private function publish(int $vendorUserId): int
    {
        $productId = $this->readyProduct($vendorUserId);
        self::assertTrue($this->manage->submit($vendorUserId, $vendorUserId, $productId)->ok);
        self::assertTrue($this->review->approve($productId)->ok);
        return $productId;
    }

    private function addStaff(StaffRolePreset $preset): int
    {
        return $this->staff->add(
            self::VENDOR,
            self::STAFF,
            'همکار فروشگاه',
            'staffer',
            'staff@example.test',
            '09120000000',
            $preset,
            $preset->permissions(),
            hash('sha256', 'token'),
            '2030-01-01 00:00:00'
        );
    }

    private function template(): int
    {
        $result = $this->configure->createTemplate('gloves', 'دستکش');
        self::assertTrue($result->ok, $result->code);
        return (int) $result->context['template_id'];
    }

    /** @return list<int> */
    private function galleryOf(int $productId): array
    {
        return $this->products->find($productId)?->imageIds ?? [];
    }

    private function mainOf(int $productId): int
    {
        return $this->products->find($productId)?->mainImageId ?? 0;
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
