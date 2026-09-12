<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Vendor\Application\ConfigureDocumentTypes;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\SaveApplicationDraft;
use Tecteb\Marketplace\Modules\Vendor\Application\SubmitApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\UploadApplicationDocument;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorCapabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspaceFactory;
use Tecteb\Marketplace\Modules\Vendor\Application\MobileVerification;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadPolicy;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadRejection;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbDocumentRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbDocumentTypeRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\PrivateUploadStorage;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;

/**
 * The whole vendor path on a real MariaDB, in the order a real applicant and
 * a real manager would walk it:
 *
 *   draft → (documents undefined: submission refused) → manager defines a
 *   document → upload refused, then accepted → submit → manager asks for
 *   changes → applicant edits and resubmits → manager approves → the vendor
 *   dashboard shows the result.
 *
 * Every step asserts the database, not just the return value, because the
 * point of this suite is that the SQL is real.
 */
final class VendorFlowTest extends DatabaseTestCase
{
    private const APPLICANT = 41;
    private const MANAGER = 7;

    private DbVendorRepository $applications;
    private DbDocumentTypeRepository $types;
    private DbDocumentRepository $documents;
    private FakeCapabilityChecker $capabilities;
    private AuditLogger $auditLogger;
    private PrivateUploadStorage $storage;
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach (M0002CreateVendorTables::TABLES as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        (new M0002CreateVendorTables())->up($db);

        $clock = new SystemClock();
        $this->applications = new DbVendorRepository($db, $clock);
        $this->types = new DbDocumentTypeRepository($db, new WpOptionStore(), $clock);
        $this->documents = new DbDocumentRepository($db, $clock);
        $this->capabilities = new FakeCapabilityChecker(self::APPLICANT, [VendorCapabilities::APPLY]);
        $this->auditLogger = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);
        $this->storageDir = sys_get_temp_dir() . '/tmc-vendor-test-' . bin2hex(random_bytes(4));
        $this->storage = new PrivateUploadStorage($this->storageDir);
        (new \Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable())->up($db);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            exec('rm -rf ' . escapeshellarg($this->storageDir));
        }
        parent::tearDown();
    }

    public function testTheWholePathFromDraftToApprovedVendor(): void
    {
        $details = new ApplicantDetails('داروخانه نمونه', 'شرکت الف', 'a@example.test', '09120000000', 'تهران، خیابان یک', true);

        // 1. draft --------------------------------------------------------
        $draft = $this->saveDraft()->handle(self::APPLICANT, $details);
        self::assertTrue($draft->ok, 'a draft saves even before any document rule exists');
        $application = $this->applications->findApplicationByUser(self::APPLICANT);
        self::assertNotNull($application);
        self::assertSame(ApplicationStatus::Draft, $application->status);
        self::assertSame('داروخانه نمونه', $application->details->storeName);

        // 2. submission refused while the requirement set is undefined -----
        $blocked = $this->submit()->handle(self::APPLICANT);
        self::assertFalse($blocked->ok);
        self::assertSame('requirements_undefined', $blocked->code);
        self::assertSame(ApplicationStatus::Draft, $this->applications->findApplicationByUser(self::APPLICANT)->status, 'nothing moved');

        // 3. the manager defines one required document ---------------------
        $this->capabilities->become(self::MANAGER, [VendorCapabilities::MANAGE_DOCUMENTS, VendorCapabilities::REVIEW]);
        $added = $this->configure()->add('پروانه کسب', true, ['application/pdf'], 2 * 1048576, 'اسکن خوانا');
        self::assertTrue($added->ok);
        $slug = $this->types->requirementSet()->types()[0]->slug;

        // 4. an upload that breaks the manager's own rule is refused -------
        $this->capabilities->become(self::APPLICANT, [VendorCapabilities::APPLY]);
        $tooBig = $this->upload()->handle(self::APPLICANT, $slug, $this->tempFile('big.pdf', 'application/pdf', 3 * 1048576));
        self::assertFalse($tooBig->ok);
        self::assertSame(UploadRejection::TooLarge->value, $tooBig->code);
        self::assertSame([], $this->documents->forApplication($application->id), 'a refused upload stores nothing');

        $wrongType = $this->upload()->handle(self::APPLICANT, $slug, $this->tempFile('note.txt', 'text/plain', 10));
        self::assertSame(UploadRejection::MimeNotAllowed->value, $wrongType->code);

        // 5. a good upload is stored privately -----------------------------
        $ok = $this->upload()->handle(self::APPLICANT, $slug, $this->tempFile('licence.pdf', 'application/pdf', 1024));
        self::assertTrue($ok->ok, $ok->code);
        $stored = $this->documents->forApplication($application->id);
        self::assertCount(1, $stored);
        self::assertTrue($this->storage->exists($stored[0]->storedPath));
        self::assertStringNotContainsString('licence', $stored[0]->storedPath, 'the stored name is random, not the sent one');

        // 6. submit --------------------------------------------------------
        $submitted = $this->submit()->handle(self::APPLICANT);
        self::assertTrue($submitted->ok, $submitted->code);
        self::assertSame(ApplicationStatus::Submitted, $this->applications->findApplicationByUser(self::APPLICANT)->status);
        self::assertNotNull($this->applications->findApplicationByUser(self::APPLICANT)->submittedAt);

        // 7. the manager asks for changes ----------------------------------
        $this->capabilities->become(self::MANAGER, [VendorCapabilities::REVIEW]);
        self::assertFalse($this->review()->requestChanges($application->id, '')->ok, 'a note is required');
        $changes = $this->review()->requestChanges($application->id, 'اسکن پروانه ناخواناست.');
        self::assertTrue($changes->ok);
        $afterChanges = $this->applications->findApplication($application->id);
        self::assertSame(ApplicationStatus::ChangesRequested, $afterChanges->status);
        self::assertSame('اسکن پروانه ناخواناست.', $afterChanges->reviewNote);
        self::assertSame(self::MANAGER, $afterChanges->reviewedBy);

        // 8. the applicant fixes it and resubmits ---------------------------
        $this->capabilities->become(self::APPLICANT, [VendorCapabilities::APPLY]);
        self::assertTrue($this->upload()->handle(self::APPLICANT, $slug, $this->tempFile('better.pdf', 'application/pdf', 2048))->ok);
        self::assertCount(1, $this->documents->forApplication($application->id), 'replacing keeps one document per type');
        self::assertTrue($this->submit()->handle(self::APPLICANT)->ok);

        // 9. approval creates the vendor, with selling but not direct publishing
        $this->capabilities->become(self::MANAGER, [VendorCapabilities::REVIEW]);
        self::assertTrue($this->review()->approve($application->id)->ok);
        $profile = $this->applications->findProfileByUser(self::APPLICANT);
        self::assertNotNull($profile);
        self::assertTrue($profile->canSell);
        self::assertFalse($profile->canPublishDirectly, 'direct publishing is a separate grant');
        self::assertSame(ApplicationStatus::Approved, $this->applications->findApplication($application->id)->status);

        // 10. what the vendor now sees --------------------------------------
        $workspace = $this->workspace()->forUser(self::APPLICANT);
        self::assertTrue($workspace->isApprovedVendor());
        self::assertSame(ApplicationStatus::Approved, $workspace->status());
        self::assertFalse($workspace->mobile->isVerified(), 'no provider, so the number stays unverified');

        // 11. the decisions are in the audit log ----------------------------
        $events = array_column(
            $this->wpdb->get_results("SELECT event_type FROM `{$this->auditTable()}` ORDER BY id", ARRAY_A),
            'event_type'
        );
        self::assertContains(AuditEventCatalog::VENDOR_REQUIREMENTS_CHANGED, $events);
        self::assertContains(AuditEventCatalog::VENDOR_DOCUMENT_REJECTED, $events);
        self::assertContains(AuditEventCatalog::VENDOR_DOCUMENT_UPLOADED, $events);
        self::assertContains(AuditEventCatalog::VENDOR_APPLICATION_SUBMITTED, $events);
        self::assertContains(AuditEventCatalog::VENDOR_APPLICATION_REVIEWED, $events);
    }

    public function testAnExplicitNoDocumentsDecisionLetsAnApplicationThroughAndUndefinedDoesNot(): void
    {
        $details = new ApplicantDetails('فروشگاه دو', 'شرکت ب', 'b@example.test', '09121111111', 'تهران', true);
        $this->saveDraft()->handle(self::APPLICANT, $details);
        self::assertSame('requirements_undefined', $this->submit()->handle(self::APPLICANT)->code);

        $this->capabilities->become(self::MANAGER, [VendorCapabilities::MANAGE_DOCUMENTS]);
        self::assertTrue($this->configure()->declareNoDocumentsNeeded(true)->ok);

        $this->capabilities->become(self::APPLICANT, [VendorCapabilities::APPLY]);
        self::assertTrue($this->submit()->handle(self::APPLICANT)->ok, 'an explicit "none needed" is a decision, unlike silence');
    }

    public function testAnApplicantWithoutTheCapabilityChangesNothing(): void
    {
        $this->capabilities->become(999, []);
        $result = $this->saveDraft()->handle(999, new ApplicantDetails('x', 'y', 'z@example.test', '0912', 'jaa', true));
        self::assertFalse($result->ok);
        self::assertSame('forbidden', $result->code);
        self::assertNull($this->applications->findApplicationByUser(999));
    }

    public function testASecondApprovalDoesNotCreateASecondVendor(): void
    {
        $this->applications->saveDraft(self::APPLICANT, new ApplicantDetails('فروشگاه سه', 'ج', 'c@example.test', '09122222222', 'تهران', true));
        $id = $this->applications->findApplicationByUser(self::APPLICANT)->id;
        $this->applications->updateStatus($id, ApplicationStatus::Submitted);

        $this->capabilities->become(self::MANAGER, [VendorCapabilities::REVIEW]);
        self::assertTrue($this->review()->approve($id)->ok);
        // Approving again is not a legal transition, and even if it were the
        // profile write is an upsert: one vendor per user, always.
        self::assertFalse($this->review()->approve($id)->ok);
        $count = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->wpdb->prefix}" . M0002CreateVendorTables::PROFILES . "` WHERE user_id = " . self::APPLICANT
        );
        self::assertSame(1, $count);
    }

    // ---- wiring -------------------------------------------------------------

    private function saveDraft(): SaveApplicationDraft
    {
        return new SaveApplicationDraft($this->applications, $this->capabilities);
    }

    private function submit(): SubmitApplication
    {
        return new SubmitApplication($this->applications, $this->types, $this->documents, new ApplicationStateMachine(), $this->auditLogger, $this->capabilities);
    }

    private function upload(): UploadApplicationDocument
    {
        return new UploadApplicationDocument($this->applications, $this->types, $this->documents, $this->storage, new UploadPolicy(), $this->auditLogger, $this->capabilities);
    }

    private function review(): ReviewApplication
    {
        return new ReviewApplication($this->applications, new ApplicationStateMachine(), $this->auditLogger, $this->capabilities);
    }

    private function configure(): ConfigureDocumentTypes
    {
        return new ConfigureDocumentTypes($this->types, $this->auditLogger, $this->capabilities);
    }

    private function workspace(): VendorWorkspaceFactory
    {
        return new VendorWorkspaceFactory($this->applications, $this->types, $this->documents, new MobileVerification(new NullOtpProvider()));
    }

    /** A real file on disk, because the storage really moves bytes. */
    private function tempFile(string $name, string $mime, int $size): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tmc-up');
        file_put_contents($path, str_repeat('x', max(1, $size)));
        return new UploadedFile($name, $path, $size, $mime, 0);
    }
}
