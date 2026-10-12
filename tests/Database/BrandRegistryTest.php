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
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
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

    /**
     * Gives the tables back empty — the `alpha.36` rule, and it earned itself
     * here within the hour.
     *
     * The CONTRACT suite's stub `wpdb` is a real PDO on this same disposable
     * database, and from `alpha.48` an open BRAND REQUEST puts a product in the
     * «needs review» set. So a request left behind by the last test of this
     * class put a red bubble on the marketplace menu and failed
     * `AdminMenuAndAssetsTest`, which is about capabilities and was perfectly
     * correct.
     */
    protected function tearDown(): void
    {
        $this->resetSchema(new WpDatabase($this->wpdb));
        parent::tearDown();
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

    /**
     * PRESSING SAVE TWICE DOES NOT ASK TWICE — and writes nothing the second
     * time.
     *
     * The owner's own words: «ذخیرهٔ تکراری، دوبار کلیک یا تلاش مجدد پس از
     * خطا نباید درخواست یا برند تکراری بسازد». Until `alpha.51` the second
     * call answered `brand_request_open` as a FAILURE, so an ordinary re-save
     * of step 1 painted the page red about a save that had worked.
     *
     * Asserted on the row count, not just on `openFor()`: a second row with a
     * superseded first would satisfy «one open request» and would still be a
     * duplicate.
     */
    public function testAskingTwiceForTheSameNameKeepsOneRequestAndWritesNothing(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertSame('brand_requested', $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->code);
        $first = $this->proposals->openFor($id)?->id ?? 0;
        $rows = $this->countProposals();

        // The same name, spelled with an Arabic yeh and no space — the same
        // brand, so the same request.
        $again = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سيناطب');

        self::assertTrue($again->ok, 'a re-save is not an error: ' . $again->code);
        self::assertSame('brand_request_unchanged', $again->code);
        self::assertSame($first, (int) $again->context['proposal_id']);
        self::assertSame($rows, $this->countProposals(), 'and no second row was written');
        self::assertSame('سینا طب', $this->proposals->openFor($id)?->name, 'the first spelling stands');
    }

    /**
     * RETYPING A DIFFERENT NAME REPLACES THE REQUEST, as one unit of work.
     *
     * With one field and one button, the vendor changing their mind IS the
     * save, and the latest typing is what they asked for. The old request is
     * superseded rather than deleted — a manager may have the queue open — and
     * exactly one is left to decide.
     */
    public function testRetypingADifferentNameReplacesTheOpenRequest(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $first = $this->proposals->openFor($id)?->id ?? 0;

        $second = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'آمبو بگ');

        self::assertTrue($second->ok, $second->code);
        self::assertSame('brand_request_replaced', $second->code);
        $open = $this->proposals->openFor($id);
        self::assertNotNull($open);
        self::assertSame('آمبو بگ', $open->name);
        self::assertNotSame($first, $open->id);
        self::assertSame(BrandProposal::SUPERSEDED, $this->proposals->find($first)?->status);
        self::assertSame(
            [$open->id],
            array_map(static fn (BrandProposal $p): int => $p->id, $this->proposals->pending()),
            'the manager has exactly one to decide'
        );
        self::assertSame('برند قدیمی', $this->products->find($id)?->details->brand, 'and the brand still has not moved');
    }

    /**
     * A SUPERSEDED REQUEST FREES ITS NAME, so the vendor can go back to it.
     *
     * `open_key` is what makes «one open request per name» an index rather
     * than a check, and superseding has to release it or a vendor who typed
     * «سینا طب», changed their mind and changed it back would be told their
     * own earlier ask belongs to somebody else.
     */
    public function testASupersededNameCanBeAskedForAgain(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'آمبو بگ')->ok);

        $back = $this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب');

        self::assertTrue($back->ok, $back->code);
        self::assertSame('سینا طب', $this->proposals->openFor($id)?->name);
        self::assertCount(1, $this->proposals->pending());
    }

    /**
     * NO UNIT OF WORK, SO NOTHING IS ATTEMPTED — `alpha.49`, at this table.
     *
     * The first half alone is the worst outcome: the vendor's open request
     * destroyed and the save reported as a failure. So a build that cannot
     * open a transaction refuses, and the earlier request is still theirs.
     */
    public function testAReplacementThatCannotBeAtomicKeepsTheOldRequest(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $first = $this->proposals->openFor($id)?->id ?? 0;
        $rows = $this->countProposals();

        $failing = new \Tecteb\Marketplace\Tests\Support\FailingDatabase(new WpDatabase($this->wpdb));
        $failing->refuseTransactions();
        $outcome = (new DbBrandProposalRepository($failing, new SystemClock()))
            ->replaceOpen($id, self::VENDOR, 'آمبو بگ', 'آمبوبگ', $first);

        self::assertSame('not_atomic', $outcome['code']);
        self::assertSame($first, $this->proposals->openFor($id)?->id, 'the vendor still has their request');
        self::assertSame(BrandProposal::PENDING, $this->proposals->find($first)?->status);
        self::assertSame($rows, $this->countProposals(), 'and no second row was inserted either');
    }

    /**
     * CLEARING THE BRAND CLEARS WHICH BRAND IT WAS.
     *
     * Found by reviewing the changed path rather than by a report, and it is
     * reachable from the one field: `rememberBrandTerm()` returns early on an
     * empty name, so the product ends with no brand and a `brand_term_id`
     * still pointing at the brand it used to have. The form reads that id to
     * survive a rename (below), so a stale one would show the removed brand in
     * the box — and the next save would put it back.
     */
    public function testClearingTheBrandAlsoClearsTheRecordedTerm(): void
    {
        $id = $this->legacyProduct('');
        $this->saveBrand($id, 'آریا طب');
        self::assertNotNull($this->products->brandTermIdOf($id), 'the fixture must start with a term');

        $this->saveBrand($id, '');

        self::assertSame('', $this->products->find($id)?->details->brand);
        self::assertNull(
            $this->products->brandTermIdOf($id),
            'a product with no brand is not a product whose brand is term 500'
        );
    }

    /**
     * A BRAND THE MANAGER RENAMED IN WOOCOMMERCE IS STILL THE SAME BRAND.
     *
     * The term id is identity; the name is a label. When a manager renames
     * term 500 from «آریا طب» to «آریا طب تجهیزات», the product row still
     * holds the old label — which is no longer in the list. Resolved by name
     * alone, the vendor's next save would read their own unchanged brand as an
     * unapproved name and open a REQUEST for a brand that already exists
     * under its new spelling.
     *
     * So the field resolves through the stored id first, and the save carries
     * the row up to the rename.
     */
    public function testARenamedBrandIsStillTheSameBrandAndNotANewRequest(): void
    {
        $id = $this->legacyProduct('');
        $this->saveBrand($id, 'آریا طب');
        $termId = $this->products->brandTermIdOf($id);
        self::assertNotNull($termId);

        // The manager renames it in WooCommerce's own screen.
        $this->directory->terms[$termId] = 'آریا طب تجهیزات';

        // What the form would now show in the box.
        self::assertSame('آریا طب تجهیزات', $this->directory->find($termId)?->name);

        // And saving that is an ordinary save, not a request.
        $saved = $this->saveBrand($id, 'آریا طب تجهیزات');
        self::assertSame('product_saved', $saved->code, 'a rename is not a new brand');
        self::assertSame('آریا طب تجهیزات', $this->products->find($id)?->details->brand);
        self::assertSame($termId, $this->products->brandTermIdOf($id), 'and it is the same brand');
        self::assertNull($this->proposals->openFor($id), 'nothing was asked for');
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

    /**
     * THE MANAGER ATTACHES THE REQUEST TO A BRAND THAT ALREADY EXISTS.
     *
     * §2(الف). The vendor typed something the list did not have; the manager
     * recognises it as a brand that IS there under another spelling, puts that
     * name in the box and approves. No second term is created — which is the
     * whole point, because the duplicate official brand is what the approval
     * exists to prevent.
     */
    public function testTheManagerCanAttachTheRequestToAnExistingBrand(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'آریا تب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;
        $terms = count($this->directory->terms);

        // «آریا طب» is already in the list; the vendor's «آریا تب» is not.
        $approved = $this->brands->approve($proposalId, 'آریا طب');

        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('brand_approved', $approved->code);
        self::assertSame('1', $approved->context['attached'], 'attached, not registered');
        self::assertSame('آریا تب', $approved->context['asked'], 'and what was asked is still on the record');
        self::assertSame('آریا طب', $this->products->find($id)?->details->brand);
        // The key comes from `BrandName`, not from a hand-written string. The
        // first version of this test wrote «آریاطب» and the real key is
        // «اریاطب» — the alef-with-hamza folds — so it reported a failure
        // about a build that was perfectly right. Ask the function.
        self::assertSame(
            $this->directory->findByKey(BrandName::key('آریا طب'))?->termId,
            $this->products->brandTermIdOf($id)
        );
        self::assertCount($terms, $this->directory->terms, 'no new brand was created');
        self::assertNull($this->proposals->openFor($id));
    }

    /**
     * THE MANAGER CORRECTS THE SPELLING AND APPROVES IT AS A NEW BRAND.
     *
     * §2(ب). The brand really is new, the vendor's spelling is not the one the
     * catalogue should carry, and the name that goes in the list is the
     * manager's — on the product too, because the two must not disagree.
     */
    public function testTheManagerCanCorrectTheNameAndApproveItAsANewBrand(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب تجهیز')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $approved = $this->brands->approve($proposalId, 'سینا طب');

        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('سینا طب', $approved->context['brand']);
        self::assertSame('', $approved->context['attached'], 'registered, not attached');
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
        self::assertNotNull($this->directory->findByKey(BrandName::key('سینا طب')), 'the manager\'s spelling is in the list');
        self::assertNull($this->directory->findByKey(BrandName::key('سینا طب تجهیز')), 'and the vendor\'s is not');
    }

    /**
     * AN EMPTY BOX MEANS «THE NAME AS ASKED», so the common case is one click.
     *
     * The field is pre-filled, but a manager who clears it has not asked for a
     * brand with no name — and reading it that way would refuse every approval
     * made from a page whose box was emptied by a browser restoring the form.
     */
    public function testAnEmptyBoxApprovesTheNameTheVendorAskedFor(): void
    {
        $id = $this->legacyProduct('برند قدیمی');
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;

        $approved = $this->brands->approve($proposalId, '');

        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
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

    // ------------------------------------- two decisions about one product

    /**
     * THE FINDING of `alpha.52`, in the owner's own order.
     *
     * A stock proposal carries the whole payload forward, brand and all; a
     * brand request is approved on its own table; and approving the stock then
     * replayed the payload's brand over it. Three places went back — the row,
     * the term id and the shop — and the result said «approved».
     */
    public function testApprovingAProposalDoesNotRevertABrandDecidedAfterIt(): void
    {
        $id = $this->publishedProduct('آریا طب');
        // A stock proposal: the vendor says 2, the shop holds 5.
        self::assertSame('stock_revision_requested', $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $id,
            2,
            $this->products->find($id)?->details->sku ?? '',
            1,
            20,
            1
        )->code);
        // …and a brand request for a name the list does not hold.
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        $proposalId = $this->proposals->openFor($id)?->id ?? 0;
        self::assertTrue($this->brands->approve($proposalId)->ok);
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
        $termB = $this->directory->findByKey('سیناطب')?->termId;
        self::assertSame($termB, $this->products->brandTermIdOf($id));

        $revision = $this->revisions->pendingFor($id);
        self::assertSame('آریا طب', (string) ($revision->payload['details']['brand'] ?? ''), 'the snapshot');
        self::assertSame('آریا طب', (string) ($revision->payload[ProductRevision::BASELINE]['brand'] ?? ''));

        $approved = $this->review->approveRevision($revision->id ?? 0);
        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand, 'the brand is not reverted');
        self::assertSame($termB, $this->products->brandTermIdOf($id), 'nor is its identity');
        self::assertSame('سینا طب', $approved->context['brand_kept'] ?? '', 'and the result says so');
        self::assertSame(2, $this->products->find($id)?->details->stock, 'the stock still moved');
    }

    /**
     * A REAL conflict: two vendor requests about the brand, one approved on
     * each path. Named and refused rather than settled by whichever ran last.
     */
    public function testTwoDecisionsAboutTheBrandAreRefusedByName(): void
    {
        $id = $this->publishedProduct('آریا طب');
        // A revision asking for a brand that IS in the list.
        self::assertSame('revision_requested', $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('دستکش لاتکس', 'مدلاین'),
            [],
            $this->products->find($id)?->imageIds ?? [],
            $this->products->find($id)?->mainImageId ?? 0,
            $this->products->find($id)?->rowVersion ?? ''
        )->code);
        // …and a request for one that is not, approved first.
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        self::assertTrue($this->brands->approve($this->proposals->openFor($id)?->id ?? 0)->ok);

        $revisionId = $this->revisions->pendingFor($id)?->id ?? 0;
        $refused = $this->review->approveRevision($revisionId);
        self::assertFalse($refused->ok);
        self::assertSame('brand_moved', $refused->code);
        self::assertSame('آریا طب', $refused->context['baseline_brand'] ?? '');
        self::assertSame('سینا طب', $refused->context['current_brand'] ?? '');
        self::assertSame('مدلاین', $refused->context['proposed_brand'] ?? '');
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand, 'nothing was written');
        self::assertTrue($this->revisions->pendingFor($id) !== null, 'and the proposal is still open');

        // THE OVERRIDE IS FOR THE BRAND THAT WAS SHOWN — `alpha.49`'s rule.
        $blind = $this->review->approveRevision($revisionId, [], false, null, true);
        self::assertFalse($blind->ok);
        self::assertSame('brand_moved_again', $blind->code);
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);

        $forced = $this->review->approveRevision($revisionId, [], false, null, true, 'سینا طب');
        self::assertTrue($forced->ok, $forced->code);
        self::assertSame('مدلاین', $this->products->find($id)?->details->brand);
    }

    /**
     * A proposal written before the baseline existed — every pending row on
     * the owner's site today. «I do not know» is a question, not a default.
     */
    public function testAProposalWithNoRecordedBaselineIsAQuestionNotAGuess(): void
    {
        $id = $this->publishedProduct('آریا طب');
        self::assertSame('stock_revision_requested', $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $id,
            2,
            $this->products->find($id)?->details->sku ?? '',
            1,
            20,
            1
        )->code);
        $revisionId = $this->revisions->pendingFor($id)?->id ?? 0;
        // The shape `alpha.51` wrote: the baseline map taken back out.
        $payload = $this->revisions->pendingFor($id)?->payload ?? [];
        unset($payload[ProductRevision::BASELINE]);
        $db = new WpDatabase($this->wpdb);
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_product_revisions` SET payload = %s WHERE id = %d',
            [(string) json_encode($payload, JSON_UNESCAPED_UNICODE), $revisionId]
        );
        self::assertTrue($this->brands->propose(self::VENDOR, self::VENDOR, $id, 'سینا طب')->ok);
        self::assertTrue($this->brands->approve($this->proposals->openFor($id)?->id ?? 0)->ok);

        $refused = $this->review->approveRevision($revisionId);
        self::assertSame('brand_moved', $refused->code);
        self::assertSame('__unknown__', $refused->context['baseline_brand'] ?? '');
        self::assertSame('سینا طب', $this->products->find($id)?->details->brand);
    }

    /**
     * AND THE ORDINARY CASE IS NOT A QUESTION. A proposal whose brand equals
     * the product's — which is every proposal about anything else — goes
     * through with no baseline recorded at all.
     */
    public function testAProposalWithNoBaselineAndAnUnchangedBrandIsNotAQuestion(): void
    {
        $id = $this->publishedProduct('آریا طب');
        self::assertSame('stock_revision_requested', $this->manage->updateInventory(
            self::VENDOR,
            self::VENDOR,
            $id,
            2,
            $this->products->find($id)?->details->sku ?? '',
            1,
            20,
            1
        )->code);
        $revisionId = $this->revisions->pendingFor($id)?->id ?? 0;
        $payload = $this->revisions->pendingFor($id)?->payload ?? [];
        unset($payload[ProductRevision::BASELINE]);
        $db = new WpDatabase($this->wpdb);
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_product_revisions` SET payload = %s WHERE id = %d',
            [(string) json_encode($payload, JSON_UNESCAPED_UNICODE), $revisionId]
        );
        $approved = $this->review->approveRevision($revisionId);
        self::assertTrue($approved->ok, $approved->code);
        self::assertSame('آریا طب', $this->products->find($id)?->details->brand);
        self::assertSame(2, $this->products->find($id)?->details->stock);
    }

    /**
     * A re-save that asks for what the pending proposal already asks for is
     * still «unchanged» AFTER the baseline map was added to the payload.
     *
     * Without `samePayload()` ignoring it, the first save after the upgrade
     * would supersede every pending `alpha.51` proposal and bring back a badge
     * for a product nobody had touched.
     */
    public function testTheBaselineMapDoesNotMakeAnUnchangedSaveLookLikeAChange(): void
    {
        $id = $this->publishedProduct('آریا طب');
        self::assertSame('revision_requested', $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('دستکش نیتریل', 'آریا طب'),
            [],
            $this->products->find($id)?->imageIds ?? [],
            $this->products->find($id)?->mainImageId ?? 0,
            $this->products->find($id)?->rowVersion ?? ''
        )->code);
        $first = $this->revisions->pendingFor($id);
        // The `alpha.51` shape: no baseline map at all.
        $payload = $first?->payload ?? [];
        unset($payload[ProductRevision::BASELINE]);
        $db = new WpDatabase($this->wpdb);
        $db->execute(
            'UPDATE `' . $db->prefix() . 'tmc_product_revisions` SET payload = %s WHERE id = %d',
            [(string) json_encode($payload, JSON_UNESCAPED_UNICODE), $first?->id ?? 0]
        );
        $again = $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details('دستکش نیتریل', 'آریا طب'),
            [],
            $this->products->find($id)?->imageIds ?? [],
            $this->products->find($id)?->mainImageId ?? 0,
            $this->products->find($id)?->rowVersion ?? ''
        );
        self::assertSame('revision_unchanged', $again->code);
        self::assertSame($first?->id, $this->revisions->pendingFor($id)?->id, 'the same proposal, not a new one');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every row in the requests table, open or not.
     *
     * Asked rather than `openFor()` in the idempotency tests: «one open
     * request» is satisfied by a second row with the first superseded, and
     * that is still a duplicate.
     */
    private function countProposals(): int
    {
        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_brand_proposals`'
        );
    }

    /** One save of a draft that changes only the brand. */
    private function saveBrand(int $id, string $brand): \Tecteb\Marketplace\Modules\Vendor\Application\OperationResult
    {
        $product = $this->products->find($id);
        self::assertNotNull($product);
        return $this->manage->save(
            self::VENDOR,
            self::VENDOR,
            $id,
            $this->details($product->details->title, $brand),
            [],
            $product->imageIds,
            $product->mainImageId,
            $product->rowVersion
        );
    }

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
