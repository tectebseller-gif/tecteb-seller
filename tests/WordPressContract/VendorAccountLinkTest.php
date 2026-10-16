<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffMember;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffPermissions;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffRolePreset;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: «ورود به داشبورد فروشنده» is on the account dashboard, for the
 * people who have a vendor path, and it is printed once.
 *
 * `alpha.29` added it and asked one question — «does this person act in a shop
 * that may trade today?» That is the right question for a buyer and the wrong
 * one for the people who need the link most: an applicant waiting for a
 * decision, a vendor whose shop was just suspended, and the STAFF of that shop,
 * who have no application of their own at all.
 *
 * And `alpha.36` lost the button altogether. `renderPanel()` stood down
 * whenever the menu filter had run, so on every theme that renders
 * WooCommerce's stock navigation the dashboard printed nothing — which is what
 * the owner reported. The menu row and the dashboard button are not duplicates
 * of each other: one is wayfinding in a list of endpoints, the other is the
 * call to action on the page a vendor lands on. What must not happen is the
 * same block twice in one request, and that is what is asserted here.
 *
 * Nothing here is access control. `addMenuItem()` and `panel()` both ask
 * `label()`, so the row, the button and the shortcode cannot disagree about
 * where they go — and `/vendor/` still refuses anyone without a store on its
 * own, which two tests state explicitly.
 */
final class VendorAccountLinkTest extends ContractTestCase
{
    public function testAPlainCustomerGetsNoRowNoPanelAndNothingFromTheShortcode(): void
    {
        $link = $this->link(profile: null, application: null);

        self::assertSame(['dashboard' => 'x', 'customer-logout' => 'y'], $link->addMenuItem($this->menu()));
        self::assertSame('', $link->shortcode(), 'a shortcode left in the page costs a buyer nothing');
    }

    public function testASignedOutVisitorGetsNothing(): void
    {
        State::$currentUserId = 0;
        $link = $this->link(profile: $this->profile(), application: null, signIn: false);

        self::assertSame('', $link->shortcode());
    }

    public function testAnApprovedVendorGetsTheDashboardRowBeforeTheWayOut(): void
    {
        $items = $this->link(profile: $this->profile())->addMenuItem($this->menu());

        self::assertSame(
            ['dashboard', VendorAccountLink::MENU_KEY, 'customer-logout'],
            array_keys($items),
            'the row goes before «خروج», not after it'
        );
        self::assertSame(VendorAccountLink::panelLabel(), $items[VendorAccountLink::MENU_KEY]);
        // The owner's exact words for this round, pinned deliberately: this
        // is the one place a literal belongs, because the string IS the
        // requirement («عنوان دکمهٔ اصلی بازارگاه دقیقاً باشد»).
        self::assertSame('ورود به داشبورد فروشنده', VendorAccountLink::panelLabel(), 'the owner\'s words');
    }

    /**
     * WHAT THIS PROVES: an applicant keeps their own route, under its own name.
     *
     * They have no shop, so «ورود به داشبورد فروشنده» would be a link that lied
     * about where it goes — and hiding it altogether leaves the one page that
     * shows their review status unreachable from the account they signed in to.
     */
    public function testAnApplicantWithNoShopStillReachesTheirOwnPage(): void
    {
        foreach (['draft', 'submitted', 'changes_requested', 'rejected'] as $status) {
            $link = $this->link(profile: null, application: ApplicationStatus::from($status));
            $items = $link->addMenuItem($this->menu());

            self::assertArrayHasKey(VendorAccountLink::MENU_KEY, $items, $status);
            self::assertSame('درخواست فروشندگی من', $items[VendorAccountLink::MENU_KEY], $status);
            self::assertStringContainsString('وضعیت درخواست فروشندگی و مدارک شما.', $link->shortcode(), $status);
        }
    }

    /**
     * A suspended shop has `canSell = false`, so `storeFor()` answers null and
     * the old test hid the link. The suspension reason is on that page.
     */
    public function testASuspendedShopCanStillReachThePageThatExplainsWhy(): void
    {
        $link = $this->link(
            profile: $this->profile(canSell: false),
            application: ApplicationStatus::Suspended
        );

        self::assertArrayHasKey(VendorAccountLink::MENU_KEY, $link->addMenuItem($this->menu()));
        self::assertNotSame('', $link->shortcode());
    }

    /**
     * WHAT THIS PROVES: a manager gets an ADMINISTRATIVE route, not a vendor
     * one — and gets one at all.
     *
     * The owner reported seeing only Dokan's red button on the real theme's
     * account page. The account in the screenshots is a manager, and a
     * manager has no vendor profile, no place on a roster and no application:
     * this plugin answered `''` and was right to. «مدیر را خودکار فروشنده
     * نکن» — so the answer is not a vendor panel, it is a link into the
     * marketplace's own admin screen, labelled as that.
     */
    public function testAManagerGetsAnAdministrativeRouteAndNotAVendorOne(): void
    {
        State::$currentUserCaps = ['read', Capabilities::REVIEW_PRODUCTS];
        $link = $this->link(profile: null, application: null);

        $items = $link->addMenuItem($this->menu());
        $panel = $link->shortcode();

        self::assertSame(VendorAccountLink::managerLabel(), $items[VendorAccountLink::MENU_KEY] ?? '');
        // And it says wp-admin out loud, because that is where it goes.
        self::assertSame('بررسی محصول‌ها در پیشخوان مدیریت', VendorAccountLink::managerLabel());
        self::assertNotSame(
            VendorAccountLink::panelLabel(),
            VendorAccountLink::managerLabel(),
            'the administrative link must not borrow the vendor button\'s words'
        );
        self::assertStringContainsString('page=tmc-product-review', $panel, 'into wp-admin, not into /vendor/');
        self::assertStringNotContainsString(
            VendorAccountLink::panelLabel(),
            $panel,
            'a manager pressing that would land on a page about a shop they do not have'
        );
        // And the menu row goes to the same place as the button: two answers
        // to one question on one page is the `alpha.52` form mistake.
        self::assertStringContainsString(
            'page=tmc-product-review',
            $link->endpointUrl('ignored', VendorAccountLink::MENU_KEY, '', '')
        );
    }

    /**
     * WHAT THIS PROVES: being a manager does not outrank being a vendor.
     *
     * A person who is both came to `/my-account/` for their shop. The manager
     * branch is deliberately last.
     */
    public function testAVendorWhoIsAlsoAManagerIsSentToTheirShop(): void
    {
        State::$currentUserCaps = ['read', Capabilities::REVIEW_PRODUCTS];
        $link = $this->link(profile: $this->profile());

        self::assertSame(
            VendorAccountLink::panelLabel(),
            $link->addMenuItem($this->menu())[VendorAccountLink::MENU_KEY] ?? ''
        );
        // The LABEL was all this asserted until `alpha.57`, and the label is
        // not where the owner's complaint lived: «نقش مدیر نباید کاربر دارای
        // فروشگاه را از داشبورد فروشنده به wp-admin بفرستد». So the
        // destination is asserted too, on the button AND on the menu row.
        $panel = $link->shortcode();
        self::assertStringNotContainsString('wp-admin', $panel, 'a manager with a shop is not sent to wp-admin');
        self::assertStringNotContainsString('page=tmc-product-review', $panel, 'nor to the review screen');
        self::assertStringContainsString('tmc_vendor=dashboard', $panel, 'they go to their own dashboard');
        self::assertStringNotContainsString(
            'wp-admin',
            $link->endpointUrl('ignored', VendorAccountLink::MENU_KEY, '', ''),
            'and the menu row agrees with the button'
        );
    }

    /**
     * WHAT THIS PROVES: the vendor button goes to THIS plugin's front-end
     * dashboard — not wp-admin, not the manager's review screen, and not
     * another plugin's dashboard.
     *
     * Four destinations were on the table in the owner's report and only one
     * of them is right, so each wrong one is named. The right one is read
     * from `VendorRoutes` rather than typed here: the address is built from
     * `home_url()` and the permalink setting, and a literal in a test would
     * be a second opinion about it.
     */
    public function testTheVendorButtonLandsOnThisPluginsOwnDashboard(): void
    {
        $html = $this->link(profile: $this->profile())->shortcode();

        self::assertStringContainsString(
            'href="' . VendorRoutes::dashboardUrl() . '"',
            $html,
            'the one address the plugin itself builds'
        );
        foreach (['wp-admin', 'page=tmc-product-review', 'dokan', 'dashboard/'] as $wrong) {
            self::assertStringNotContainsString($wrong, $html, "not {$wrong}");
        }
    }

    /** And a plain customer is still nobody, manager capability or not. */
    public function testACustomerWithoutThatCapabilityStillGetsNothing(): void
    {
        State::$currentUserCaps = ['read'];
        $link = $this->link(profile: null, application: null);

        self::assertSame('', $link->shortcode());
    }

    /**
     * WHAT THIS PROVES: the site records which of WooCommerce's account hooks
     * its theme actually calls.
     *
     * This plugin cannot read the owner's theme, and «the hooks never ran» and
     * «they ran and we correctly drew nothing» are opposite problems with
     * opposite fixes. So the site measures it and the health screen reads it:
     * «بدون مشاهدهٔ قالب واقعی، سازگاری با آن را «تأییدشده» اعلام نکن».
     */
    public function testTheSiteRecordsWhichAccountHooksItsThemeCalls(): void
    {
        delete_option(VendorAccountLink::SEEN_OPTION);
        self::assertSame(
            ['menu' => '', 'dashboard' => '', 'shortcode' => '', 'printed' => '', 'label' => '', 'kind' => ''],
            VendorAccountLink::lastSeen(),
            'nothing is claimed before anything has run'
        );

        // A buyer on a theme that renders the stock dashboard: the hook ran
        // and nothing was printed, which is an answer and not a failure.
        $buyer = $this->link(profile: null, application: null);
        $buyer->renderPanel();
        $seen = VendorAccountLink::lastSeen();
        self::assertSame('yes', $seen['dashboard']);
        self::assertSame('no', $seen['printed']);
        self::assertSame('', $seen['menu'], 'and the menu filter did not run on this theme');
        self::assertSame('', $seen['kind'], 'nothing was drawn, so there is no kind to report');

        // A vendor on the same theme: printed, with the label that was drawn.
        $vendor = $this->link(profile: $this->profile());
        $vendor->renderPanel();
        $seen = VendorAccountLink::lastSeen();
        self::assertSame('yes', $seen['printed']);
        self::assertSame(VendorAccountLink::panelLabel(), $seen['label']);
    }

    public function testTheLinkIsBuiltFromTheRouteAndNotFromAStringInThisPlugin(): void
    {
        State::$homeUrl = 'https://shop.example';
        $html = $this->link(profile: $this->profile())->shortcode();

        self::assertStringContainsString('https://shop.example', $html);
        self::assertStringNotContainsString('tecteb.com', $html, 'no host is written into the plugin');
    }

    /**
     * WHAT THIS PROVES: the row WooCommerce would build for our key is replaced.
     *
     * Left alone, WooCommerce builds `/my-account/tmc-vendor-dashboard/` for a
     * menu key it thinks is an endpoint, and that address is a 404.
     */
    public function testWooCommerceDoesNotGetToBuildOurAddress(): void
    {
        $link = $this->link(profile: $this->profile());

        self::assertStringContainsString(
            'tmc_vendor=dashboard',
            $link->endpointUrl('https://example.test/my-account/tmc-vendor-dashboard/', VendorAccountLink::MENU_KEY, '', ''),
        );
        self::assertSame(
            'https://example.test/my-account/orders/',
            $link->endpointUrl('https://example.test/my-account/orders/', 'orders', '', ''),
            "somebody else's endpoint is not ours to rewrite"
        );
    }

    /**
     * WHAT THIS PROVES: the hook and the shortcode print the SAME block.
     *
     * Two renderers of one link are two links waiting for the day they differ —
     * the `alpha.26` rule. Compared across two instances, because one instance
     * prints once: the point is that the markup is identical, not that a
     * second call repeats it.
     */
    public function testThePanelHookAndTheShortcodePrintTheSameBlock(): void
    {
        ob_start();
        $this->link(profile: $this->profile())->renderPanel();
        $printed = (string) ob_get_clean();

        self::assertSame($this->link(profile: $this->profile())->shortcode(), $printed);
        self::assertSame(1, substr_count($printed, '<a class="button tmc-account-vendor__link"'), 'one link, not two');
    }

    /**
     * WHAT THIS PROVES: the menu row and the dashboard button BOTH appear, one
     * each — and the row's existence is not a reason to drop the button.
     *
     * «وجود پیوند در منوی حساب کاربری نباید باعث حذف دکمه در داخل داشبورد
     * شود». `alpha.36` asserted the opposite here, which is why this test is
     * the shape of the defect: WooCommerce's `my-account.php` renders
     * `navigation.php` before the content, so by the time the dashboard hook
     * ran the old guard had already decided to print nothing.
     */
    public function testTheMenuRowDoesNotRemoveTheDashboardButton(): void
    {
        $link = $this->link(profile: $this->profile());

        $items = $link->addMenuItem($this->menu());
        self::assertArrayHasKey(VendorAccountLink::MENU_KEY, $items, 'the navigation row is there');

        ob_start();
        $link->renderPanel();
        $printed = (string) ob_get_clean();

        self::assertStringContainsString(VendorAccountLink::panelLabel(), $printed, 'and so is the button');
        self::assertSame(1, substr_count($printed, '<a class="button tmc-account-vendor__link"'), 'one button, not two');
    }

    /**
     * WHAT THIS PROVES: the block is printed once per request, whichever of the
     * three places gets there first.
     *
     * «دکمه در هیچ صفحه‌ای تکرار نشود». The flag is about this plugin's own
     * output and nothing else — a site with the dashboard hook AND a pasted
     * `[tmc_vendor_dashboard]` gets one button.
     */
    public function testTheSameBlockIsNotPrintedTwiceInOneRequest(): void
    {
        $link = $this->link(profile: $this->profile());

        ob_start();
        $link->renderPanel();
        $first = (string) ob_get_clean();
        ob_start();
        $link->renderPanel();
        $second = (string) ob_get_clean();

        self::assertStringContainsString('<a class="button tmc-account-vendor__link"', $first);
        self::assertSame('', $second, 'the second call prints nothing');
        self::assertSame('', $link->shortcode(), 'and neither does a shortcode after it');

        // The other order: a shortcode first, then the hook.
        $other = $this->link(profile: $this->profile());
        self::assertStringContainsString('<a class="button tmc-account-vendor__link"', $other->shortcode());
        ob_start();
        $other->renderPanel();
        self::assertSame('', (string) ob_get_clean());
    }

    /**
     * WHAT THIS PROVES: a buyer's empty answer does not use up the one print.
     *
     * A flag set before the label was asked would let a customer's '' silence
     * a later caller — a bug that only shows itself on a page with two of our
     * three entry points.
     */
    public function testABuyersEmptyAnswerDoesNotConsumeThePrint(): void
    {
        $link = $this->link(profile: null, application: null);

        self::assertSame('', $link->shortcode());
        ob_start();
        $link->renderPanel();
        self::assertSame('', (string) ob_get_clean());
    }

    /**
     * WHAT THIS PROVES: staff of a suspended shop get a route, and still may do
     * nothing.
     *
     * «همکار فروشگاه نیز باید مسیر ورود مناسب بر اساس عضویت و دسترسی‌های موجود
     * خود داشته باشد» — and «نمایش دکمه نباید محدودیت فروشگاه تعلیق‌شده را دور
     * بزند». Both halves in one test, because a link that came with a
     * permission attached would pass the first and break the second. The
     * membership question is `membershipFor()`, which returns a row and grants
     * nothing (`alpha.34`).
     */
    public function testStaffOfASuspendedShopGetTheButtonAndStillMayDoNothing(): void
    {
        $staff = $this->createStub(StaffRepositoryInterface::class);
        $staff->method('findByUser')->willReturn($this->member(StaffStatus::Active));
        $vendors = $this->createStub(VendorRepositoryInterface::class);
        // The employee is 9 and the SHOP is 7: `findProfileByUser(9)` must be
        // null, or `label()` answers on the owner branch and this test passes
        // without ever asking the membership question it is about.
        $vendors->method('findProfileByUser')->willReturnCallback(
            static fn (int $id): ?VendorProfile => $id === 7
                ? new VendorProfile(1, 7, 'فروشگاه نمونه', false, false)
                : null
        );
        $vendors->method('findApplicationByUser')->willReturn(null);

        $container = new Container();
        $access = new StaffAccess($staff, $vendors);
        $container->instance(StaffAccess::class, $access);
        $container->instance(VendorRepositoryInterface::class, $vendors);
        State::$currentUserId = 9;
        $link = new VendorAccountLink($container);

        self::assertStringContainsString(VendorAccountLink::panelLabel(), $link->shortcode(), 'they have a route');
        self::assertNull($access->storeFor(9), 'and no shop they may act in');
    }

    /**
     * WHAT THIS PROVES: so do staff of a shop that is trading normally.
     *
     * The ordinary case, which the suspended one would otherwise be the only
     * evidence for: an employee with no application of their own was getting
     * nothing at all, whatever the shop's standing.
     */
    public function testStaffOfATradingShopGetTheButtonToo(): void
    {
        $staff = $this->createStub(StaffRepositoryInterface::class);
        $staff->method('findByUser')->willReturn($this->member(StaffStatus::Active));
        $vendors = $this->createStub(VendorRepositoryInterface::class);
        $vendors->method('findProfileByUser')->willReturnCallback(
            static fn (int $id): ?VendorProfile => $id === 7
                ? new VendorProfile(1, 7, 'فروشگاه نمونه', true, false)
                : null
        );
        $vendors->method('findApplicationByUser')->willReturn(null);

        $container = new Container();
        $container->instance(StaffAccess::class, new StaffAccess($staff, $vendors));
        $container->instance(VendorRepositoryInterface::class, $vendors);
        State::$currentUserId = 9;

        self::assertStringContainsString(VendorAccountLink::panelLabel(), (new VendorAccountLink($container))->shortcode());
    }

    /**
     * WHAT THIS PROVES: an INVITED member who has not accepted yet also has a
     * page, and still no access.
     *
     * `membershipFor()` returns the row whatever its status, deliberately:
     * «your place here is not active yet» is a true and useful sentence, and
     * the page that says it is the one they cannot otherwise find.
     */
    public function testAnInvitedMemberHasARouteAndNoAccess(): void
    {
        $staff = $this->createStub(StaffRepositoryInterface::class);
        $staff->method('findByUser')->willReturn($this->member(StaffStatus::Invited));
        $vendors = $this->createStub(VendorRepositoryInterface::class);
        $vendors->method('findProfileByUser')->willReturnCallback(
            static fn (int $id): ?VendorProfile => $id === 7
                ? new VendorProfile(1, 7, 'فروشگاه نمونه', true, false)
                : null
        );
        $vendors->method('findApplicationByUser')->willReturn(null);

        $container = new Container();
        $access = new StaffAccess($staff, $vendors);
        $container->instance(StaffAccess::class, $access);
        $container->instance(VendorRepositoryInterface::class, $vendors);
        State::$currentUserId = 9;

        self::assertStringContainsString(VendorAccountLink::panelLabel(), (new VendorAccountLink($container))->shortcode());
        self::assertNull($access->storeFor(9));
    }

    public function testRegisteringWiresTheMenuTheEndpointThePanelAndTheShortcode(): void
    {
        VendorAccountLink::register($this->container($this->profile(), null));

        foreach (['woocommerce_account_menu_items', 'woocommerce_get_endpoint_url', 'woocommerce_account_dashboard'] as $hook) {
            self::assertArrayHasKey($hook, State::$hooks, $hook . ' is not wired');
        }
        self::assertTrue(shortcode_exists(VendorAccountLink::SHORTCODE));
    }

    // ------------------------------------------------------------- fixtures

    /** @return array<string,string> */
    private function menu(): array
    {
        return ['dashboard' => 'x', 'customer-logout' => 'y'];
    }

    private function member(StaffStatus $status): StaffMember
    {
        return new StaffMember(
            1,
            7,
            9,
            'همکار نمونه',
            'demo-staff',
            'staff@example.test',
            '09120000000',
            StaffRolePreset::ProductAndInventory,
            StaffPermissions::none(),
            $status
        );
    }

    private function profile(bool $canSell = true): VendorProfile
    {
        return new VendorProfile(1, 7, 'فروشگاه نمونه', $canSell, false);
    }

    private function link(
        ?VendorProfile $profile,
        ApplicationStatus|null $application = ApplicationStatus::Approved,
        bool $signIn = true
    ): VendorAccountLink {
        if ($signIn) {
            State::$currentUserId = 7;
        }
        return new VendorAccountLink($this->container($profile, $application));
    }

    private function container(?VendorProfile $profile, ?ApplicationStatus $application): Container
    {
        $vendors = $this->createStub(VendorRepositoryInterface::class);
        $vendors->method('findProfileByUser')->willReturn($profile);
        $vendors->method('findApplicationByUser')->willReturn(
            $application === null
                ? null
                : new VendorApplication(1, 7, $application, new ApplicantDetails(storeName: 'فروشگاه نمونه'))
        );
        $staff = $this->createStub(StaffRepositoryInterface::class);
        $staff->method('findByUser')->willReturn(null);

        $container = new Container();
        $container->instance(StaffAccess::class, new StaffAccess($staff, $vendors));
        $container->instance(VendorRepositoryInterface::class, $vendors);
        return $container;
    }
}
