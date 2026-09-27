<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Vendor\Application\TaskState;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\MobileIdentity;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;
use Tecteb\Marketplace\Modules\Vendor\Presentation\ApplicationView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * WHAT THIS PROVES: the sentences on the vendor's own pages say what is true of
 * THIS vendor, and saying it changes nothing about what they may do.
 *
 * Two of them were wrong in the same way — one condition standing in for five
 * states. «این درخواست در حال بررسی است» printed for every status the applicant
 * cannot edit, so an approved shop was told for months that its own file was
 * still under review; and the mobile row said «تأیید نشده است», which reads as
 * a step the vendor skipped, when in fact there is no SMS adapter and nothing
 * for them to do.
 *
 * A text test is not decoration here: these two sentences are the only thing on
 * the page that tells the vendor whether they are waiting for somebody or
 * somebody is waiting for them.
 */
final class VendorApplicationTextsTest extends ContractTestCase
{
    // ------------------------------------------------ the SMS sentence

    public function testWithNoSmsAdapterTheRowSaysVerificationIsUnavailableAndNoActionIsNeeded(): void
    {
        $text = VendorMessages::task($this->mobileTask(available: false));

        self::assertStringContainsString('تأیید پیامکی فعلاً در دسترس نیست؛ اقدامی لازم نیست.', $text['detail']);
        self::assertSame('', $text['action'], 'there is no button, because there is nothing to press');
    }

    public function testWithAnAdapterTheRowSaysTheNumberIsNotVerifiedYet(): void
    {
        $text = VendorMessages::task($this->mobileTask(available: true));

        self::assertStringContainsString('هنوز تأیید نشده است', $text['detail']);
        self::assertStringNotContainsString('در دسترس نیست', $text['detail']);
    }

    /**
     * WHAT THIS PROVES: the new sentence does not make the number verified.
     *
     * The row's STATE is read from `MobileIdentity::isVerified()` and from
     * nothing else, and that answers to a provider's timestamp — which no
     * provider in this build returns. A kinder sentence must not become a
     * quieter claim.
     */
    public function testSayingVerificationIsUnavailableDoesNotMarkTheNumberVerified(): void
    {
        foreach ([true, false] as $available) {
            $workspace = $this->workspace(ApplicationStatus::Approved, mobileAvailable: $available);
            self::assertFalse($workspace->mobile->isVerified());
            foreach ($workspace->tasks() as $task) {
                if ($task->key === 'mobile') {
                    self::assertSame(TaskState::Waiting, $task->state, 'still unverified either way');
                }
            }
        }
    }

    public function testTheFormsMobileHintSaysTheSameThingAsTheRow(): void
    {
        self::assertStringContainsString(
            'تأیید پیامکی فعلاً در دسترس نیست؛ اقدامی لازم نیست.',
            $this->render(ApplicationStatus::Draft, mobileAvailable: false)
        );
        self::assertStringNotContainsString(
            'در دسترس نیست',
            $this->render(ApplicationStatus::Draft, mobileAvailable: true)
        );
    }

    // ------------------------------------- the locked-file sentence

    public function testAnApprovedFileIsNotDescribedAsBeingUnderReview(): void
    {
        $html = $this->render(ApplicationStatus::Approved);

        self::assertStringNotContainsString('در نوبت بررسی است', $html);
        self::assertStringContainsString('تأیید شده است', $html);
        // And it names the page that DOES hold the shop's live values, which is
        // the question an approved vendor arrives here with.
        self::assertStringContainsString('تنظیمات فروشگاه', $html);
    }

    public function testASubmittedFileStillSaysItIsInTheQueue(): void
    {
        foreach ([ApplicationStatus::Submitted, ApplicationStatus::InReview] as $status) {
            self::assertStringContainsString('در نوبت بررسی است', $this->render($status), $status->value);
        }
    }

    public function testARejectionAndASuspensionAreWarningsAndTheRestAreNot(): void
    {
        foreach ([ApplicationStatus::Rejected, ApplicationStatus::Suspended] as $status) {
            self::assertStringContainsString('tv-notice--warning', $this->render($status), $status->value);
        }
        foreach ([ApplicationStatus::Submitted, ApplicationStatus::Approved] as $status) {
            self::assertStringContainsString('tv-notice--info', $this->render($status), $status->value);
            self::assertStringNotContainsString('tv-notice--warning', $this->render($status), $status->value);
        }
    }

    public function testThePageSaysWhichDataItIsShowing(): void
    {
        self::assertStringContainsString('این صفحه درخواست فروشندگی شماست', $this->render(ApplicationStatus::Draft));
        self::assertStringContainsString('این پروندهٔ فروشندگی شماست', $this->render(ApplicationStatus::Approved));
    }

    // --------------------------- the manager's reason, on the existing path

    public function testTheManagersReasonIsShownForAllThreeDecisionsThatCarryOne(): void
    {
        foreach ([
            'changes_requested' => 'چه چیزی باید اصلاح شود',
            'rejected' => 'دلیل رد درخواست',
            'suspended' => 'دلیل تعلیق فروشگاه',
        ] as $status => $heading) {
            $html = $this->render(ApplicationStatus::from($status), note: 'کد اقتصادی ناخواناست');
            self::assertStringContainsString($heading, $html, $status);
            self::assertStringContainsString('کد اقتصادی ناخواناست', $html, $status);
        }
    }

    public function testAnApprovalCarriesNoCorrectionHeadingEvenWhenTheColumnHasText(): void
    {
        $html = $this->render(ApplicationStatus::Approved, note: 'یادداشت قدیمی');

        self::assertStringNotContainsString('چه چیزی باید اصلاح شود', $html);
        self::assertStringNotContainsString('یادداشت قدیمی', $html, 'a stale column is not a message');
    }

    /**
     * WHAT THIS PROVES: clearer text opened no editing.
     *
     * `isEditableByApplicant()` is still the only gate, and it is still exactly
     * `draft` and `changes_requested`. The save button is the visible half of
     * that; the `readonly` attributes are the other half.
     */
    public function testTheRewrittenTextsChangedNobodysAbilityToEdit(): void
    {
        foreach (['draft', 'changes_requested'] as $status) {
            $html = $this->render(ApplicationStatus::from($status));
            self::assertStringContainsString('ذخیره پیش‌نویس', $html, $status);
            self::assertStringNotContainsString('readonly', $html, $status);
        }
        foreach (['submitted', 'in_review', 'approved', 'rejected', 'suspended'] as $status) {
            $html = $this->render(ApplicationStatus::from($status));
            self::assertStringNotContainsString('ذخیره پیش‌نویس', $html, $status);
            self::assertStringContainsString('readonly', $html, $status);
        }
    }

    // ------------------------------------------------------------- fixtures

    private function mobileTask(bool $available): \Tecteb\Marketplace\Modules\Vendor\Application\WorkspaceTask
    {
        foreach ($this->workspace(ApplicationStatus::Approved, mobileAvailable: $available)->tasks() as $task) {
            if ($task->key === 'mobile') {
                return $task;
            }
        }
        self::fail('the workspace built no mobile row');
    }

    private function render(ApplicationStatus $status, string $note = '', bool $mobileAvailable = false): string
    {
        return ApplicationView::render(
            $this->workspace($status, $note, $mobileAvailable),
            new VendorUrls('https://example.test/vendor/', 'https://example.test/vendor/application/'),
            '<input type="hidden" name="n" value="1">'
        );
    }

    private function workspace(
        ApplicationStatus $status,
        string $note = '',
        bool $mobileAvailable = false
    ): VendorWorkspace {
        $details = new ApplicantDetails(
            storeName: 'فروشگاه نمونه',
            legalName: 'شرکت نمونه',
            contactEmail: 'shop@example.test',
            contactMobile: '09120000000',
            address: 'تهران',
            termsAccepted: true
        );
        return new VendorWorkspace(
            new VendorApplication(1, 7, $status, $details, $note === '' ? null : $note),
            $status === ApplicationStatus::Approved ? new VendorProfile(1, 7, 'فروشگاه نمونه', true, false) : null,
            new RequirementSet([], true),
            [],
            MobileIdentity::registered('09120000000'),
            $mobileAvailable
        );
    }
}
