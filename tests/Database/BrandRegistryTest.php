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
use Tecteb\Marketplace\Modules\Product\Application\ManageBrands;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbBrandProposalRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FakeBrandDirectory;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeCatalogProjector;
use Tecteb\Marketplace\Tests\Support\FakeProductImages;

/**
 * Brands: picked from the manager's list, or asked for — §4, on real MariaDB.
 *
 * What is asserted here is the whole of §4 as behaviour rather than as prose:
 * free text cannot become an official brand, a name that differs only in a
 * space or an Arabic yeh is the SAME brand, two requests for one name cannot
 * both be open, an incomplete approval is not reported as a success, and a
 * legacy free-text brand is neither deleted nor declared approved.
 */
final class BrandRegistryTest extends DatabaseTestCase
{
    private const VENDOR = 601;
    private const OTHER_VENDOR = 602;
    private const MANAGER = 609;

    private DbProductRepository $products;
    private DbBrandProposalRepository $proposals;
    private DbProductRevisionRepository $revisions;
    private ManageProducts $manage;
    private ManageBrands $brands;
    private ReviewProducts $review;
    private FakeBrandDirectory $directory;
    private FakeCatalogProjector $projector;
    private FakeCapabilityChecker $capabilities;
    private FakeProductImages $images;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);

        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->products = new DbProductRepository($db, $clock);
        $this->proposals = new DbBrandProposalRepository($db, $clock);
        $this->revisions = new DbProductRevisionRepository($db, $clock);
        $templates = new DbSpecTemplateRepository($db, $clock);
        $variations = new DbVariationRepository($db, $clock);
        $vendors = new DbVendorRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $access = new StaffAccess($staff, $vendors);
        $this->projector = new FakeCatalogProjector();
        $catalog = new SyncCatalog($this->products, $variations, $this->projector, $audit);
        $this->directory = new FakeBrandDirectory('آریا طب', 'مدلاین');
        $this->capabilities = new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_PRODUCTS]);
        $this->images = new FakeProductImages();

        $this->brands = new ManageBrands(
            $this->directory,
            $this->proposals,
            $this->products,
            $catalog,
            $access,
            $this->capabilities,
            $audit
        );
        $readiness = new ProductReadiness($templates, $variations);
        $this->manage = new ManageProducts(
            $this->products,
            $templates,
            $this->revisions,
            $readiness,
            $catalog,
            $this->images,
            $access,
            new ProductPublishPolicy(new WpOptionStore()),
            new ProductStateMachine(),
            $audit,
            null,
            $this->brands
        );
        $this->review = new ReviewProducts(
            $this->products,
            $this->revisions,
            $templates,
            $readiness,
            $catalog,
            new ProductPublishPolicy(new WpOptionStore()),
            new ProductStateMachine(),
            $audit,
            $this->capabilities,
            null,
            null,
            $this->brands
        );

        $vendors->upsertProfile(self::VENDOR, 'داروخانه یک', true, false);
        $vendors->upsertProfile(self::OTHER_VENDOR, 'داروخانه دو', true, false);
    }

    // ----------------------------------------------------------- the gate

    public function testFreeTextCannotBecomeABrand(): void
    {
        $refused = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            0,
            $this->details('دستکش لاتکس', 'برندی که کسی تأیید نکرده')
        );
        self::assertFalse($refused->ok);
        self::assertSame('brand_not_approved', $refused->code);
        self::assertSame('برندی که کسی تأیید نکرده', $refused->context['brand']);
        self::assertSame([], $this->products->forVendor(self::VENDOR), 'and nothing was created');
    }

    public function testABrandFromTheListIsAcceptedAndItsIdIsRemembered(): void
    {
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, $this->details('دستکش لاتکس', 'آریا طب'));
        self::assertTrue($created->ok, $created->code);
        $id = (int) $created->context['product_id'];
        self::assertSame('آریا طب', $this->products->find($id)?->details->brand);
        $termId = $this->products->brandTermIdOf($id);
        self::assertNotNull($termId);
        self::assertSame('آریا طب', $this->directory->find($termId)?->name);
    }

    /** A space, a ZWNJ and an Arabic yeh are not a different brand. */
    public function testANameThatOnlySpellsTheBrandDifferentlyIsAccepted(): void
    {
        $this->directory->terms[700] = 'آمبو بگ';
        $created = $this->manage->save(self::VENDOR, self::VENDOR, 0, $this->details('آمبوبگ آزمایشی', 'آمبوبگ'));
        self::assertTrue($created->ok, $created->code);
        // Stored as the vendor spelled it — the name is theirs to write — and
        // POINTING AT the approved brand, which is what the id is for.
        self::assertSame(700, $this->products->brandTermIdOf((int) $created->context['product_id']));
    }

    /**
     * The owner's shop is full of these, and a picker that refused them would
     * make «save» impossible on every product that has one.
     */
    public function testALegacyFreeTextBrandKeepsSavingAndIsNeverDeclaredApproved(): void
    {
        $id = $this->legacyProduct('برند قدیمی دست‌نویس');
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('عنوان تازه', 'برند قدیمی دست‌نویس'),
            [],
            [],
            0,
            $this->products->find($id)?->rowVersion ?? ''
        );
        self::assertTrue($saved->ok, $saved->code);
        self::assertSame('برند قدیمی دست‌نویس', $this->products->find($id)?->details->brand);
        self::assertNull(
            $this->products->brandTermIdOf($id),
            'nothing claims this brand is approved — the owner asked for exactly that restraint'
        );
    }

    public function testWithNoListThereIsNothingToCheckAgainstAndTheChangeIsRefused(): void
    {
        $this->directory->available = false;
        $refused = $this->manage->save(self::VENDOR, self::VENDOR, 0, $this->details('دستکش', 'هر نامی'));
        self::assertFalse($refused->ok);
        self::assertSame('brand_list_unavailable', $refused->code);
    }

    // ------------------------------------------------------- the request

    public function testAVendorAsksAndTheProductsBrandDoesNotMove(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        $asked = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب');
        self::assertTrue($asked->ok, $asked->code);
        self::assertSame('brand_requested', $asked->code);
        self::assertSame('برند قدیمی', $this->products->find($id)?->details->brand, 'until approval the brand stays');

        $open = $this->proposals->openFor($id);
        self::assertNotNull($open);
        self::assertSame('سینا طب', $open->name);
        self::assertSame('decision', $open->outstanding());
        self::assertFalse($open->isRegistered());
    }

    public function testAskingForAnExistingBrandIsRefusedWithItsRealName(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        // Spelled with an Arabic yeh and no space — the same brand.
        $refused = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'آريا طب');
        self::assertFalse($refused->ok);
        self::assertSame('brand_already_approved', $refused->code);
        self::assertSame('آریا طب', $refused->context['brand']);
        self::assertNull($this->proposals->openFor($id), 'and no request was recorded');
    }

    public function testTwoShopsCannotBothHaveTheSameNameOpen(): void
    {
        $mine = $this->legacyProduct('الف');
        $theirs = $this->legacyProduct('ب', self::OTHER_VENDOR);

        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $mine, 'آمبو بگ')->ok);
        // The SAME brand, spelled without the space. The unique index on the
        // normalised key is what refuses it — not a read followed by a write.
        $second = $this->brands->propose(self::OTHER_VENDOR, self::OTHER_VENDOR, $theirs, 'آمبوبگ');
        self::assertFalse($second->ok);
        self::assertSame('brand_request_elsewhere', $second->code);
    }

    public function testAVendorCannotAskForAnotherShopsProduct(): void
    {
        $theirs = $this->legacyProduct('ب', self::OTHER_VENDOR);
        $refused = $this->brands->propose(self::VENDOR, self::VENDOR, $theirs, 'سینا طب');
        self::assertFalse($refused->ok);
        self::assertSame('not_found', $refused->code);
    }

    public function testOnlyTheManagerDecides(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $withoutCapability = new FakeCapabilityChecker(self::VENDOR, []);
        $vendorsBrands = $this->brandsWith($withoutCapability);
        self::assertSame('forbidden', $vendorsBrands->approve($proposalId)->code);
        self::assertSame('forbidden', $vendorsBrands->reject($proposalId, 'نه')->code);
        self::assertSame(BrandProposal::PENDING, $this->proposals->find($proposalId)?->status);
    }

    // ------------------------------------------------------ the decision

    public function testApprovingAddsTheBrandToTheListAndWritesItOnTheProduct(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $approved = $this->brands->approve($proposalId);
        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('brand_approved', $approved->code);
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
        self::assertNotNull($this->products->brandTermIdOf($id));
        self::assertNotNull($this->directory->findByKey('سیناطب'), 'and it is in the picker for every shop');
        self::assertSame(BrandProposal::APPROVED, $this->proposals->find($proposalId)?->status);
        self::assertNull($this->proposals->openFor($id), 'nothing is left open');
    }

    public function testRejectingLeavesTheBrandAloneAndKeepsTheReason(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        self::assertSame('note_required', $this->brands->reject($proposalId, '  ')->code);
        $rejected = $this->brands->reject($proposalId, 'این نام برند نیست، نام مدل است.');
        self::assertTrue($rejected->ok, $rejected->code);
        self::assertSame('برند قدیمی', $this->products->find($id)?->details->brand);
        self::assertNull($this->directory->findByKey('سیناطب'), 'and it never reached the list');
        self::assertSame('این نام برند نیست، نام مدل است.', $this->proposals->find($proposalId)?->note);
    }

    /** A name rejected once can be asked for again: the index frees it. */
    public function testARejectedNameCanBeAskedForAgain(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $first = $this->proposals->openFor($id)?->id ?? 0;
        self::assertTrue($this->brands->reject($first, 'نه')->ok);

        $again = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب');
        self::assertTrue($again->ok, $again->code);
        self::assertNotSame($first, $this->proposals->openFor($id)?->id);
    }

    public function testARefusedRegistrationRecordsNothingAndStaysRetryable(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $this->directory->registerFails = true;
        $failed = $this->brands->approve($proposalId);
        self::assertFalse($failed->ok);
        self::assertSame('brand_not_registered', $failed->code);
        self::assertSame(BrandProposal::PENDING, $this->proposals->find($proposalId)?->status);
        self::assertSame('برند قدیمی', $this->products->find($id)?->details->brand);

        $this->directory->registerFails = false;
        self::assertTrue($this->brands->approve($proposalId)->ok, 'and the second attempt finishes it');
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
    }

    /**
     * The two halves, told apart — and the second one retried on its own.
     *
     * «ثبت برند و اعمال آن روی محصول باید قابل تفکیک و تلاش دوباره باشد.» The
     * sync is made to fail, so registering has happened and applying has not;
     * the request must say which, and `finish()` must complete it without
     * registering a second time.
     */
    public function testAnIncompleteApprovalIsNotReportedAsSuccessAndIsRetryable(): void
    {
        $id = $this->publishedProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $this->projector->refusesToProject[] = $id;
        $half = $this->brands->approve($proposalId);
        self::assertFalse($half->ok);
        self::assertSame('brand_not_synced', $half->code);
        $open = $this->proposals->find($proposalId);
        self::assertSame(BrandProposal::REGISTERED, $open?->status);
        self::assertSame('apply_to_product', $open?->outstanding());
        self::assertTrue($open?->isRegistered(), 'the brand IS in the list — that half happened');
        self::assertNotNull($this->directory->findByKey('سیناطب'));
        $termsBefore = count($this->directory->terms);

        $this->projector->refusesToProject = [];
        $finished = $this->brands->finish($proposalId);
        self::assertTrue($finished->ok, $finished->code);
        self::assertSame(BrandProposal::APPROVED, $this->proposals->find($proposalId)?->status);
        self::assertCount($termsBefore, $this->directory->terms, 'and no second term was made');
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
    }

    public function testFinishingSomethingThatIsNotHalfDoneDoesNothing(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;
        self::assertSame('nothing_to_finish', $this->brands->finish($proposalId)->code);
    }

    // ----------------------------------------- a live product's brand change

    /**
     * Picking an EXISTING brand on a published product still goes through the
     * approval cycle — §4 says so in as many words.
     */
    public function testChangingToAnApprovedBrandOnALiveProductIsStillAProposal(): void
    {
        $id = $this->publishedProduct('آریا طب');
        $saved = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('دستکش لاتکس', 'مدلاین'),
            [],
            $this->products->find($id)?->imageIds ?? [],
            $this->products->find($id)?->mainImageId ?? 0,
            $this->products->find($id)?->rowVersion ?? ''
        );
        self::assertSame('revision_requested', $saved->code);
        self::assertSame('آریا طب', $this->products->find($id)?->details->brand, 'the live brand did not move');

        $revisionId = $this->revisions->pendingFor($id)?->id ?? 0;
        self::assertTrue($this->review->approveRevision($revisionId)->ok);
        self::assertSame('مدلاین', $this->products->find($id)?->details->brand);
        self::assertSame(
            $this->directory->findByKey('مدلاین')?->termId,
            $this->products->brandTermIdOf($id),
            'and the approval records which approved brand it is'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function brandsWith(FakeCapabilityChecker $capabilities): ManageBrands
    {
        $db = new WpDatabase($this->wpdb);
        $clock = new SystemClock();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $vendors = new DbVendorRepository($db, $clock);
        return new ManageBrands(
            $this->directory,
            $this->proposals,
            $this->products,
            new SyncCatalog($this->products, new DbVariationRepository($db, $clock), $this->projector, $audit),
            new StaffAccess(new DbStaffRepository($db, $clock), $vendors),
            $capabilities,
            $audit
        );
    }

    private function details(string $title, string $brand): ProductDetails
    {
        return new ProductDetails(
            title: $title,
            type: 'simple',
            categoryKey: 'gloves',
            brand: $brand,
            shortDescription: 'یک جمله',
            priceMinor: 200000,
            sku: 'SKU-' . substr(md5($title . $brand), 0, 8),
            stock: 5
        );
    }

    /**
     * A product whose brand is free text and whose `brand_term_id` is NULL —
     * the shape every product on the owner's shop has today.
     *
     * Written straight through the repository, because the SERVICE would refuse
     * it: that refusal is the feature, and a fixture that went around it is
     * the only way to have a legacy row to test against.
     */
    private function legacyProduct(string $brand, int $vendor = self::VENDOR): int
    {
        return $this->products->create($vendor, $this->details('دستکش لاتکس', $brand), ProductStatus::Draft);
    }

    private function publishedProduct(string $brand): int
    {
        $id = $this->products->create(self::VENDOR, $this->details('دستکش لاتکس', $brand), ProductStatus::Published);
        // An image, because `ProductReadiness` requires one and an approval
        // writes the proposal and then asks — a fixture without one reports
        // `missing_image` about a build that is working.
        $image = 4100 + $id;
        $this->images->give($image, self::VENDOR);
        $this->products->saveImages($id, [$image], $image);
        $this->products->link($id, 9000 + $id);
        $this->projector->links[$id] = 9000 + $id;
        $this->projector->stock[9000 + $id] = 5;
        return $id;
    }
}
