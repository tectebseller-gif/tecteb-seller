<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Core\Container;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorProfile;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorAccountLink;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the way into the panel from `/my-account/` exists for the
 * people who have a vendor path, says where it goes, and is one link.
 *
 * `alpha.29` added it and asked one question — «does this person act in a shop
 * that may trade today?» That is the right question for a buyer and the wrong
 * one for the two people who need the link most: an applicant waiting for a
 * decision, and a vendor whose shop was just suspended. `storeFor()` answers
 * null for both, so neither could find the page that explains their own state.
 *
 * The other half of this is that there must not be a SECOND link. The menu row,
 * the dashboard panel and the shortcode all ask `label()`, so they cannot
 * disagree about who is a vendor or about where the link goes.
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
        self::assertSame('داشبورد فروشنده', $items[VendorAccountLink::MENU_KEY]);
    }

    /**
     * WHAT THIS PROVES: an applicant keeps their own route, under its own name.
     *
     * They have no shop, so «داشبورد فروشنده» would be a link that lied about
     * where it goes — and hiding it altogether leaves the one page that shows
     * their review status unreachable from the account they signed in to.
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
     * WHAT THIS PROVES: there is one link, registered in three places, and the
     * panel hook and the shortcode print the SAME block.
     *
     * Two renderers of one link are two links waiting for the day they differ —
     * the `alpha.26` rule, applied to the thing the owner explicitly asked not
     * to be duplicated: «ابتدا پیاده‌سازی فعلی را بررسی کن تا لینک تکراری ساخته
     * نشود».
     */
    public function testThePanelHookAndTheShortcodePrintTheSameBlock(): void
    {
        $link = $this->link(profile: $this->profile());

        ob_start();
        $link->renderPanel();
        $printed = (string) ob_get_clean();

        self::assertSame($link->shortcode(), $printed);
        self::assertSame(1, substr_count($printed, '<a class="button"'), 'one link, not two');
    }

    /**
     * WHAT THIS PROVES: the menu row and the panel never both appear.
     *
     * Measured on the demo install: with a stock theme both fired and
     * `/my-account/` carried «داشبورد فروشنده» twice. WooCommerce renders its
     * navigation before the content area, so the panel can tell — and stands
     * down. On a theme that renders no navigation the menu filter never runs
     * and the panel is the only copy, which is the case it exists for.
     */
    public function testThePanelStandsDownWhenTheMenuAlreadyCarriesTheRow(): void
    {
        $link = $this->link(profile: $this->profile());
        $link->addMenuItem($this->menu());

        ob_start();
        $link->renderPanel();
        self::assertSame('', (string) ob_get_clean(), 'the navigation already has it');

        self::assertNotSame('', $link->shortcode(), 'a pasted shortcode is an explicit request');
    }

    public function testWithoutTheNavigationThePanelIsTheOnlyCopy(): void
    {
        $link = $this->link(profile: $this->profile());

        ob_start();
        $link->renderPanel();
        self::assertStringContainsString('داشبورد فروشنده', (string) ob_get_clean());
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
