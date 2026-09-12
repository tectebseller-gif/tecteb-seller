<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Vendor;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Contracts\Files\UploadedFile;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\DocumentType;
use Tecteb\Marketplace\Modules\Vendor\Domain\MobileIdentity;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementMode;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadPolicy;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadRejection;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorDocument;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorDomainException;

/** The vendor rules that must hold with no database and no WordPress. */
final class VendorDomainTest extends TestCase
{
    private function type(bool $required = true, int $maxBytes = 1024, array $mime = ['application/pdf']): DocumentType
    {
        return new DocumentType(1, 'licence', 'پروانه', $required, $mime, $maxBytes);
    }

    private function document(string $slug = 'licence'): VendorDocument
    {
        return new VendorDocument(1, 1, $slug, 'a.pdf', 'application-1/' . str_repeat('a', 32) . '.pdf', 'application/pdf', 10, '2026-09-12 00:00:00');
    }

    private function file(int $size = 100, string $mime = 'application/pdf', int $error = 0): UploadedFile
    {
        return new UploadedFile('a.pdf', '/tmp/x', $size, $mime, $error);
    }

    // ---- state machine ------------------------------------------------------

    public function testAnApplicationCannotJumpStraightToApproved(): void
    {
        $states = new ApplicationStateMachine();
        self::assertFalse($states->canTransition(ApplicationStatus::Draft, ApplicationStatus::Approved));
        self::assertTrue($states->canTransition(ApplicationStatus::Draft, ApplicationStatus::Submitted));
        self::assertTrue($states->canTransition(ApplicationStatus::Submitted, ApplicationStatus::Approved));

        $this->expectException(VendorDomainException::class);
        $states->assertTransition(ApplicationStatus::Draft, ApplicationStatus::Approved);
    }

    public function testChangesRequestedGoesBackToSubmittedAndApprovedOnlyToSuspended(): void
    {
        $states = new ApplicationStateMachine();
        self::assertTrue($states->canTransition(ApplicationStatus::ChangesRequested, ApplicationStatus::Submitted));
        self::assertSame([ApplicationStatus::Suspended], $states->nextStates(ApplicationStatus::Approved));
    }

    public function testOnlyDraftAndChangesRequestedAreEditableByTheApplicant(): void
    {
        self::assertTrue(ApplicationStatus::Draft->isEditableByApplicant());
        self::assertTrue(ApplicationStatus::ChangesRequested->isEditableByApplicant());
        foreach ([ApplicationStatus::Submitted, ApplicationStatus::InReview, ApplicationStatus::Approved, ApplicationStatus::Rejected, ApplicationStatus::Suspended] as $status) {
            self::assertFalse($status->isEditableByApplicant(), $status->value);
        }
    }

    // ---- requirement modes --------------------------------------------------

    public function testAnEmptyListIsUndefinedAndBlocksSubmissionUntilSomeoneDecides(): void
    {
        $undefined = new RequirementSet([], false);
        self::assertSame(RequirementMode::Undefined, $undefined->mode());
        self::assertTrue($undefined->blocksSubmission([]), 'undefined must never be read as "nothing needed"');
    }

    public function testAnExplicitNoneUnblocksSubmissionAndIsADifferentStateFromUndefined(): void
    {
        $none = new RequirementSet([], true);
        self::assertSame(RequirementMode::ExplicitlyNone, $none->mode());
        self::assertFalse($none->blocksSubmission([]));
    }

    public function testConfiguredBlocksUntilEveryRequiredTypeHasAFile(): void
    {
        $set = new RequirementSet([$this->type(), new DocumentType(2, 'extra', 'اختیاری', false, ['application/pdf'], 1024)], false);
        self::assertSame(RequirementMode::Configured, $set->mode());
        self::assertTrue($set->blocksSubmission([]));
        self::assertSame(['پروانه'], array_map(static fn ($t) => $t->label, $set->missingRequired([])));
        self::assertFalse($set->blocksSubmission([$this->document()]), 'the optional type is not required');
    }

    // ---- upload policy ------------------------------------------------------

    public function testUploadIsAcceptedOnlyWhenTypeSizeAndDetectedMimeAllAgree(): void
    {
        $policy = new UploadPolicy();
        $set = new RequirementSet([$this->type(true, 1024, ['application/pdf'])], false);

        self::assertTrue($policy->decide($set, 'licence', $this->file(100))->accepted);

        self::assertSame(UploadRejection::UnknownType, $policy->decide($set, 'nope', $this->file())->reason);
        self::assertSame(UploadRejection::TooLarge, $policy->decide($set, 'licence', $this->file(2048))->reason);
        self::assertSame(UploadRejection::MimeNotAllowed, $policy->decide($set, 'licence', $this->file(100, 'text/html'))->reason);
        self::assertSame(UploadRejection::Empty, $policy->decide($set, 'licence', $this->file(0))->reason);
        self::assertSame(UploadRejection::TransferFailed, $policy->decide($set, 'licence', $this->file(100, 'application/pdf', UPLOAD_ERR_PARTIAL))->reason);
    }

    /**
     * PHP's own limit refuses the file before our rule sees a single byte.
     * Seen on the real site: a 3 MB file against upload_max_filesize=2M.
     * Reporting that as a transfer problem sends people hunting a network
     * fault; it is a size refusal and must read as one.
     */
    public function testPhpsOwnSizeLimitIsReportedAsTooLargeNotAsATransferFailure(): void
    {
        $policy = new UploadPolicy();
        $set = new RequirementSet([$this->type(true, 8 * 1024 * 1024, ['application/pdf'])], false);
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $code) {
            $decision = $policy->decide($set, 'licence', new UploadedFile('big.pdf', '/tmp/x', 0, '', $code));
            self::assertSame(UploadRejection::TooLarge, $decision->reason, 'error code ' . $code);
        }
    }

    public function testAFileRenamedToPdfIsStillJudgedByItsBytes(): void
    {
        // The detected mime is what the policy sees; the name says .pdf and
        // is ignored, which is the whole point of detecting server-side.
        $policy = new UploadPolicy();
        $set = new RequirementSet([$this->type(true, 4096, ['application/pdf'])], false);
        $disguised = new UploadedFile('safe.pdf', '/tmp/x', 200, 'application/x-php');
        self::assertSame(UploadRejection::MimeNotAllowed, $policy->decide($set, 'licence', $disguised)->reason);
    }

    // ---- identity -----------------------------------------------------------

    public function testAStoredNumberIsNeverVerified(): void
    {
        $identity = MobileIdentity::registered('09120000000');
        self::assertFalse($identity->isVerified());
        self::assertNull($identity->verifiedAt);
    }

    public function testVerificationNeedsBothATimestampAndTheProviderThatIssuedIt(): void
    {
        $ok = MobileIdentity::verifiedByProvider('09120000000', '2026-09-12T00:00:00Z', 'some-gateway');
        self::assertTrue($ok->isVerified());

        $this->expectException(VendorDomainException::class);
        MobileIdentity::verifiedByProvider('09120000000', '2026-09-12T00:00:00Z', '');
    }
}
