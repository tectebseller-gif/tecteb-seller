<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorDashboard;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorStaffStanding;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspace;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\MobileIdentity;
use Tecteb\Marketplace\Modules\Vendor\Domain\RequirementSet;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;
use Tecteb\Marketplace\Modules\Vendor\Presentation\DashboardView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * WHAT THIS PROVES: the approved shop's first screen is about the shop's work,
 * and the five other vendor states still get the screen they had.
 *
 * The owner's complaint was not a bug in the usual sense — every number on the
 * old page was correct. It was that the largest thing on it was a list of
 * registration steps finished months ago, and the approval was announced twice.
 * A rendered page is the only place that is visible, so the assertions here are
 * about what the page CONTAINS and what it does not: an approval said once, a
 * counter that links to the list holding it, a task section that wears the
 * warning look only when there is a task, and a «مشاهده فروشگاه» button that is
 * absent when there is no page to open.
 */
final class VendorDashboardTest extends ContractTestCase
{
    private const PRODUCTS = 'https://example.test/vendor/products/';

    public function testAnApprovedShopOpensOnItsNameItsPermissionAndItsTwoActions(): void
    {
        $html = $this->render($this->approved());

        self::assertStringContainsString('فروشگاه نمونه', $html, 'the shop is the first thing named');
        self::assertStringContainsString('افزودن محصول', $html);
        self::assertStringContainsString('https://example.test/?tmc_store=7', $html, 'the storefront button');
        // Said once. The old page printed «وضعیت درخواست شما» AND a «نتیجه
        // بررسی» task about the same decision.
        self::assertSame(1, substr_count($html, 'تأییدشده'), 'the approval is announced exactly once');
        self::assertStringNotContainsString('وضعیت درخواست شما', $html);
        self::assertStringNotContainsString('کارهای شما', $html, 'the finished registration is not the page');
    }

    public function testTheStorefrontButtonIsAbsentWhenThereIsNoPageToOpen(): void
    {
        $html = $this->render($this->approved(storefrontUrl: ''));

        self::assertStringNotContainsString('مشاهده فروشگاه', $html);
        self::assertStringContainsString('افزودن محصول', $html, 'the other action is unaffected');
    }

    public function testEachCounterLinksToTheListFilteredToExactlyThatStatus(): void
    {
        $html = $this->render($this->approved(counts: [
            'published' => 12,
            'submitted' => 3,
            'changes_requested' => 0,
            'draft' => 5,
        ]));

        foreach (['published', 'submitted', 'changes_requested', 'draft'] as $status) {
            self::assertStringContainsString(
                self::PRODUCTS . '?status=' . $status,
                $html,
                $status . ' has no way into the list'
            );
        }
        self::assertStringContainsString('۱۲', $html, 'counts are in Persian digits');
        // Dim, and still a link: «کارت صفر کم‌رنگ ولی قابل کلیک».
        self::assertStringContainsString('tv-number is-empty', $html);
    }

    public function testANotReadCounterSaysSoInsteadOfShowingFourZeros(): void
    {
        $html = $this->render($this->approved(counts: null));

        self::assertStringContainsString('شمارش محصول‌ها خوانده نشد', $html);
        self::assertStringNotContainsString('tv-number__value', $html, 'no invented numbers');
        self::assertStringContainsString('رفتن به فهرست محصولات', $html, 'and a way on');
    }

    public function testAProductSentBackCarriesTheManagersOwnWordsAndAWayToFixIt(): void
    {
        $html = $this->render($this->approved(needsWork: [
            ['id' => 41, 'title' => 'آمبوبگ سیلیکونی', 'note' => 'عکس دوم تار است'],
        ]));

        self::assertStringContainsString('نیازمند اقدام', $html);
        self::assertStringContainsString('آمبوبگ سیلیکونی', $html);
        self::assertStringContainsString('عکس دوم تار است', $html);
        self::assertStringContainsString(self::PRODUCTS . '?product=41', $html);
        self::assertStringContainsString('tv-todo has-work', $html, 'the warning look is earned');
    }

    /**
     * WHAT THIS PROVES: a long list of corrections does not become the page.
     *
     * Measured on a shop with seven of them: twenty named rows pushed «منتظر
     * تصمیم مدیر» and every quick link below two screens of repeated cards —
     * the complaint this round exists to fix, appearing in a new place. Five
     * are named; the rest are one line that says how many and where they are.
     */
    public function testMoreCorrectionsThanFitAreCountedRatherThanListed(): void
    {
        $named = [];
        foreach ([41, 42, 43, 44, 45] as $id) {
            $named[] = ['id' => $id, 'title' => 'کالای ' . $id, 'note' => ''];
        }
        $html = $this->render($this->approved(
            counts: ['published' => 1, 'submitted' => 0, 'changes_requested' => 7, 'draft' => 0],
            needsWork: $named
        ));

        self::assertSame(5, substr_count($html, 'اصلاح این محصول'), 'five are named');
        self::assertStringContainsString('و ۲ محصول دیگر که مدیر برای اصلاح برگردانده است', $html);
        self::assertStringContainsString(self::PRODUCTS . '?status=changes_requested', $html);
    }

    public function testNothingIsAddedWhenEveryCorrectionIsAlreadyNamed(): void
    {
        $html = $this->render($this->approved(
            counts: ['published' => 1, 'submitted' => 0, 'changes_requested' => 1, 'draft' => 0],
            needsWork: [['id' => 41, 'title' => 'کالای ۴۱', 'note' => '']]
        ));

        self::assertStringNotContainsString('محصول دیگر که مدیر', $html);
    }

    public function testWaitingOnTheManagerIsNotATaskAndCarriesNoButton(): void
    {
        $html = $this->render($this->approved(counts: [
            'published' => 1,
            'submitted' => 3,
            'changes_requested' => 0,
            'draft' => 0,
        ]));

        self::assertStringContainsString('منتظر تصمیم مدیر', $html);
        self::assertStringContainsString('۳ محصول در انتظار بررسی مدیر است.', $html);
        // With nothing of the vendor's own to do, the task card says so plainly
        // and does not wear the warning edge.
        self::assertStringContainsString('در حال حاضر کاری از طرف شما لازم نیست.', $html);
        self::assertStringNotContainsString('tv-todo has-work', $html);
    }

    public function testTheRegistrationIsFoldedAwayWithTheFileAsALinkNotAButton(): void
    {
        $html = $this->render($this->approved());

        self::assertStringContainsString('<details class="tv-card tv-fold">', $html);
        self::assertStringContainsString('اطلاعات و مدارک فروشگاه', $html);
        self::assertStringContainsString('پروندهٔ فروشندگی و مدارک', $html);
        // The old page put this behind a full-width primary button.
        self::assertStringNotContainsString('مشاهده اطلاعات ثبت‌شده', $html);
        self::assertStringNotContainsString(
            '<a class="tv-btn tv-btn--primary" href="https://example.test/vendor/application/"',
            $html
        );
    }

    /**
     * A staff member sees the cards their rights cover — and no counters at
     * all when they may not read products, rather than counters showing zero
     * about a catalogue they are not entitled to.
     */
    public function testStaffWithoutProductRightsGetNoCountersAndNoPaperwork(): void
    {
        $html = $this->render($this->approved(
            counts: null,
            isOwner: false,
            mayViewProducts: false,
            mayEditProducts: false,
            ownsTheShop: false
        ));

        self::assertStringNotContainsString('tv-numbers__grid', $html);
        self::assertStringNotContainsString('افزودن محصول', $html);
        // «محصولات شما» is a SUBSTRING of «محصولات شما پس از تأیید مدیر
        // منتشر می‌شوند.», so a text needle here answered «the counters are
        // present» about a sentence. Measured on the installed package; the
        // assertion above is on the grid, and this one is on the sentence.
        self::assertStringNotContainsString('پس از تأیید مدیر منتشر می‌شوند', $html,
            'a publishing rule is not news to somebody who may not save a product');
        self::assertStringNotContainsString('اطلاعات و مدارک فروشگاه', $html, "the owner's file is not theirs");
        self::assertStringContainsString('فروشگاه نمونه', $html, 'they still know which shop they are in');
    }

    /**
     * WHAT THIS PROVES: folding the paperwork away does not hide a real gap.
     *
     * «شکاف‌های قابل اقدام پنهان نشوند» — an approved shop with a field still
     * empty gets the row in «نیازمند اقدام», with the button, not a line inside
     * a collapsed `<details>` nobody opens.
     */
    public function testAnApprovedShopWithMissingPaperworkStillGetsTheActionableRow(): void
    {
        $html = $this->render($this->approved(paperworkComplete: false));

        self::assertStringContainsString('tv-todo has-work', $html);
        self::assertStringContainsString('اطلاعات فروشگاه و تماس', $html);
        self::assertStringContainsString(
            '<a class="tv-btn tv-btn--primary" href="https://example.test/vendor/application/">',
            $html,
            'a gap with no way to close it is not a task'
        );
    }

    /**
     * WHAT THIS PROVES: «منتظر تصمیم مدیر» is about the manager.
     *
     * The mobile number is `Waiting` too and waits on an SMS adapter that does
     * not exist. Listed under a heading naming the manager, it sends the vendor
     * to ask somebody about something nobody can do anything about — so it is
     * in the paperwork fold, and it is there exactly once.
     */
    public function testTheUnverifiedMobileIsNotFiledUnderWaitingForTheManager(): void
    {
        $html = $this->render($this->approved(counts: [
            'published' => 1,
            'submitted' => 0,
            'changes_requested' => 0,
            'draft' => 0,
        ]));

        self::assertStringNotContainsString('منتظر تصمیم مدیر', $html, 'nothing is waiting on the manager');
        self::assertSame(1, substr_count($html, 'شماره موبایل'), 'the number is mentioned once, in the fold');
    }

    /**
     * WHAT THIS PROVES: a staff member gets the SHOP's screen, not an
     * invitation to apply for the shop they already work in.
     *
     * Measured on the installed package: `alpha.33`'s first build branched on
     * `$workspace->isApprovedVendor()`, which asks about the VIEWER. A staff
     * member has no application, so they were shown «وضعیت درخواست شما:
     * پیش‌نویس — هنوز درخواستی ثبت نکرده‌اید» and a «شروع درخواست فروشندگی»
     * button, above that shop's real action queue.
     */
    public function testStaffOfAnApprovedShopGetTheShopsScreenAndNotAnApplication(): void
    {
        $html = $this->render($this->approved(isOwner: false, mayEditProducts: false, ownsTheShop: false));

        self::assertStringNotContainsString('وضعیت درخواست شما', $html);
        self::assertStringNotContainsString('شروع درخواست فروشندگی', $html);
        self::assertStringContainsString('فروشگاه نمونه', $html);
        self::assertStringContainsString('تأییدشده', $html, "the SHOP's state, not theirs");
        self::assertStringContainsString('tv-numbers__grid', $html, 'their product rights still apply');
    }

    public function testAVendorWithoutDirectPublishingIsToldOnceAndPlainly(): void
    {
        self::assertStringContainsString(
            'محصولات شما پس از تأیید مدیر منتشر می‌شوند.',
            $this->render($this->approved(canPublishDirectly: false))
        );
        self::assertStringNotContainsString(
            'محصولات شما پس از تأیید مدیر منتشر می‌شوند.',
            $this->render($this->approved(canPublishDirectly: true))
        );
    }

    /**
     * WHAT THIS PROVES: the five non-approved paths still reach their own page.
     *
     * «مسیرهای فروشندهٔ جدید، در انتظار، نیازمند اصلاح، رد شده و تعلیق‌شده نباید
     * بشکنند» — and for each of them the application IS the job, so the status
     * comes first exactly as before.
     */
    public function testEveryPathThatIsNotAnApprovedShopStillOpensOnItsApplication(): void
    {
        foreach ([null, 'draft', 'submitted', 'changes_requested', 'rejected', 'suspended'] as $status) {
            $html = $this->render($this->applicant($status));
            $label = $status ?? 'no application at all';
            self::assertStringContainsString('وضعیت درخواست شما', $html, $label);
            self::assertStringContainsString('کارهای شما', $html, $label);
            self::assertStringNotContainsString('tv-numbers__grid', $html, $label . ' must not get a shop header');
        }
    }

    public function testTheManagersReasonForSendingAnApplicationBackIsOnTheApplicantsPage(): void
    {
        $html = $this->render($this->applicant('changes_requested', 'کد اقتصادی ناخواناست'));

        self::assertStringContainsString('یادداشت مدیر بازارگاه', $html);
        self::assertStringContainsString('کد اقتصادی ناخواناست', $html);
    }

    // ------------------------------------------------------------- fixtures

    // ------------------------------------- staff of a shop that cannot trade

    /**
     * WHAT THIS PROVES: the defect the owner named — «همکار فروشگاه تعلیق‌شده
     * نباید متقاضی جدید فرض شود یا دعوت به ثبت‌نام ببیند».
     *
     * `alpha.33` fixed the APPROVED shop's screen for staff and left this one:
     * `storeFor()` answers `null` for a suspended shop, the route read that as
     * «belongs to no shop», and the only page for somebody who belongs to no shop
     * invites them to register. So the employee of a suspended shop was offered a
     * vendor application for the shop they work in.
     */
    public function testStaffOfASuspendedShopSeeTheShopAndNoInvitationToRegister(): void
    {
        $html = $this->render($this->staffOf(ApplicationStatus::Suspended));

        self::assertStringNotContainsString('شروع درخواست فروشندگی', $html, 'the button that was the defect');
        self::assertStringNotContainsString('هنوز درخواستی ثبت نکرده‌اید', $html);
        self::assertStringNotContainsString('وضعیت درخواست شما', $html, 'they have no application');
        self::assertStringNotContainsString('کارهای شما', $html, 'nor any registration steps');

        self::assertStringContainsString('فروشگاه نمونه', $html, 'the shop they work in is named');
        // The needle is the half that belongs to the SHOP's sentence alone.
        // «این فروشگاه تعلیق شده است» is a substring of «دسترسی شما در این
        // فروشگاه تعلیق شده است», so it would answer about either one — the same
        // collision «محصولات شما» caused in `alpha.33`.
        self::assertStringContainsString('دسترسی همکاران آن هم موقتاً بسته است', $html, 'and why nothing works');
    }

    /**
     * WHAT THIS PROVES: nothing on that page belongs to the shop's owner or to
     * another shop.
     *
     * The review note is the manager writing to the OWNER about their
     * application. It is the one field on the applicant screen that is somebody
     * else's, so it is asserted absent by its own text and by its heading.
     */
    public function testStaffOfASuspendedShopAreShownNothingPrivateToTheOwner(): void
    {
        $html = $this->render($this->staffOf(ApplicationStatus::Suspended, note: 'مدارک مالکیت جعلی بود'));

        self::assertStringNotContainsString('مدارک مالکیت جعلی بود', $html, "the manager's note is the owner's");
        self::assertStringNotContainsString('یادداشت مدیر بازارگاه', $html);
        self::assertStringNotContainsString('پروندهٔ فروشندگی و مدارک', $html, 'nor the shop file');
        self::assertStringNotContainsString('tv-number__value', $html, 'and no figures');
    }

    /**
     * WHAT THIS PROVES: «مجوزهای پرسنل را درست نمایش دهد» — their own access is
     * stated, area by area, and stated as CLOSED.
     *
     * Every area appears, «بدون دسترسی» included: a list that drops the empty
     * rows cannot answer «do I have order access?», because a missing row reads
     * as a page that forgot rather than as a no.
     */
    public function testStaffSeeWhatTheirOwnAccessCoversAndThatItIsClosed(): void
    {
        $html = $this->render($this->staffOf(ApplicationStatus::Suspended));

        self::assertStringContainsString('دسترسی‌های شما در این فروشگاه', $html);
        self::assertStringContainsString('فعلاً بسته', $html, 'closed, not simply absent');
        foreach (['محصول', 'موجودی', 'سفارش', 'گزارش', 'مالی'] as $area) {
            self::assertStringContainsString($area, $html, $area . ' is missing from the access list');
        }
        self::assertStringContainsString('ویرایش', $html, 'the level they were granted');
        self::assertStringContainsString('بدون دسترسی', $html, 'and the ones they were not');
    }

    /**
     * WHAT THIS PROVES: reinstatement needs nothing but the shop's own standing.
     *
     * The same membership, the same permissions, the shop approved and able to
     * sell again: the shop's working screen comes back, with the sections the
     * person's rights cover. Suspension and its reversal are one field.
     */
    public function testReinstatingTheShopGivesTheSameStaffMemberTheWorkingScreen(): void
    {
        $html = $this->render($this->staffOf(ApplicationStatus::Approved, canSell: true));

        self::assertStringNotContainsString('دسترسی همکاران آن هم موقتاً بسته است', $html);
        self::assertStringNotContainsString('فعلاً بسته', $html);
        self::assertStringNotContainsString('شروع درخواست فروشندگی', $html);
        self::assertStringContainsString('فروشگاه نمونه', $html);
        self::assertStringContainsString('افزودن محصول', $html, 'their edit right is live again');
        self::assertStringContainsString('tv-number__value', $html, 'and the figures with it');
    }

    /**
     * WHAT THIS PROVES: the two halves are separate, and the screen says which
     * one is shut.
     *
     * A suspended MEMBER of a working shop is not in the same situation as a
     * live member of a suspended shop, and only one of them has anything to wait
     * for. Without this the page showed a working shop with every section
     * silently missing, which reads as a broken site.
     */
    public function testASuspendedMemberOfAWorkingShopIsToldItIsTheirOwnAccess(): void
    {
        $html = $this->render($this->staffOf(ApplicationStatus::Approved, canSell: true, staffStatus: StaffStatus::Suspended));

        self::assertStringContainsString('دسترسی شما در این فروشگاه تعلیق شده است', $html);
        self::assertStringNotContainsString('دسترسی همکاران آن هم موقتاً بسته است', $html, 'the shop is open');
        self::assertStringNotContainsString('شروع درخواست فروشندگی', $html);
    }

    /**
     * WHAT THIS PROVES: an invitation that was never activated is its own
     * sentence, not a suspension and not a registration form.
     */
    public function testAnUnactivatedInvitationSaysThatRatherThanSuspended(): void
    {
        $html = $this->render($this->staffOf(
            ApplicationStatus::Approved,
            canSell: true,
            staffStatus: StaffStatus::Invited
        ));

        self::assertStringContainsString('دعوت شما هنوز فعال نشده است', $html);
        self::assertStringNotContainsString('تعلیق', $html, 'an unused invitation is not a suspension');
    }

    /**
     * WHAT THIS PROVES: somebody who really is a new applicant still gets the
     * registration page.
     *
     * The fix must not swallow the case it is next to. Nothing on the staff path
     * is reachable without a membership, and the assertion is the button that
     * SHOULD be there.
     */
    public function testSomebodyWithNoMembershipStillGetsTheApplicationPage(): void
    {
        $html = $this->render($this->applicant(null));

        self::assertStringContainsString('شروع درخواست فروشندگی', $html);
        self::assertStringContainsString('وضعیت درخواست شما', $html);
        self::assertStringNotContainsString('دسترسی‌های شما در این فروشگاه', $html);
    }

    private function render(VendorDashboard $dashboard): string
    {
        return DashboardView::render($dashboard, $this->urls());
    }

    private function urls(): VendorUrls
    {
        return new VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            self::PRODUCTS
        );
    }

    /**
     * @param array<string,int>|null $counts
     * @param list<array{id:int,title:string,note:string}> $needsWork
     */
    private function approved(
        ?array $counts = ['published' => 2, 'submitted' => 0, 'changes_requested' => 0, 'draft' => 1],
        array $needsWork = [],
        string $storefrontUrl = 'https://example.test/?tmc_store=7',
        bool $isOwner = true,
        bool $mayViewProducts = true,
        bool $mayEditProducts = true,
        bool $canPublishDirectly = true,
        bool $canSell = true,
        bool $paperworkComplete = true,
        bool $ownsTheShop = true
    ): VendorDashboard {
        return new VendorDashboard(
            // `$ownsTheShop` false is a STAFF member: no application of their
            // own, so their workspace is empty and the shop's state comes from
            // the two fields beside it.
            $ownsTheShop
                ? $this->workspace(ApplicationStatus::Approved, canSell: $canSell, complete: $paperworkComplete)
                : new VendorWorkspace(null, null, new RequirementSet([], true), [], MobileIdentity::registered(''), false),
            'فروشگاه نمونه',
            $storefrontUrl,
            $counts,
            $needsWork,
            ApplicationStatus::Approved,
            $canSell,
            $isOwner,
            $mayViewProducts,
            $mayEditProducts,
            $canPublishDirectly
        );
    }

    private function applicant(?string $status, string $note = ''): VendorDashboard
    {
        $workspace = $status === null
            ? new VendorWorkspace(null, null, new RequirementSet([], true), [], MobileIdentity::registered(''), false)
            : $this->workspace(ApplicationStatus::from($status), profile: false, note: $note);

        return new VendorDashboard(
            $workspace,
            'فروشگاه نمونه',
            '',
            null,
            [],
            $status === null ? ApplicationStatus::Draft : ApplicationStatus::from($status),
            false,
            true,
            false,
            false,
            false
        );
    }

    /**
     * A shop seen by somebody on its staff roster: no application of their own,
     * and a standing that is theirs alone.
     *
     * `mayView`/`mayEdit` are derived here the way `StaffAccess::can()` derives
     * them — both halves must hold — rather than passed in, so a fixture cannot
     * describe a person with rights in a shop that may not trade. That
     * combination does not exist, and a test built on it would pass about
     * nothing.
     */
    private function staffOf(
        ApplicationStatus $shopStatus,
        bool $canSell = false,
        StaffStatus $staffStatus = StaffStatus::Active,
        string $note = ''
    ): VendorDashboard {
        // Derived from the status and never passed separately: a fixture that
        // took «can act» as its own argument let a test ask for `Invited` and
        // silently get `Suspended`, and then assert about the wrong sentence.
        $live = $staffStatus->canAct() && $shopStatus === ApplicationStatus::Approved && $canSell;
        $permissions = StaffPermissions::of([
            'product' => StaffLevel::Edit,
            'inventory' => StaffLevel::Edit,
            'order' => StaffLevel::View,
            'report' => StaffLevel::None,
            'finance' => StaffLevel::None,
        ]);

        return new VendorDashboard(
            // Their OWN workspace, which is what the route hands over: empty,
            // because a staff member has never applied for anything. The note
            // argument exists to prove the owner's note cannot reach this screen
            // even when there is one — so it goes nowhere near the workspace.
            new VendorWorkspace(null, null, new RequirementSet([], true), [], MobileIdentity::registered(''), false),
            'فروشگاه نمونه',
            $live ? 'https://example.test/?tmc_store=7' : '',
            $live ? ['published' => 4, 'submitted' => 1, 'changes_requested' => 0, 'draft' => 0] : null,
            [],
            $shopStatus,
            $canSell,
            false,
            $live,
            $live,
            true,
            '',
            new VendorStaffStanding($staffStatus, $permissions)
        );
    }

    private function workspace(
        ApplicationStatus $status,
        bool $profile = true,
        bool $canSell = true,
        string $note = '',
        bool $complete = false
    ): VendorWorkspace {
        $details = $complete
            ? new ApplicantDetails(
                storeName: 'فروشگاه نمونه',
                legalName: 'شرکت نمونه',
                contactEmail: 'shop@example.test',
                contactMobile: '09120000000',
                address: 'تهران، خیابان نمونه',
                termsAccepted: true
            )
            : new ApplicantDetails(storeName: 'فروشگاه نمونه');
        return new VendorWorkspace(
            new VendorApplication(1, 7, $status, $details, $note === '' ? null : $note),
            $profile ? new VendorProfile(1, 7, 'فروشگاه نمونه', $canSell, false) : null,
            new RequirementSet([], true),
            [],
            MobileIdentity::registered(''),
            false
        );
    }
}
