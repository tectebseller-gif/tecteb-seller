<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicantDetails;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Domain\VendorApplication;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\DokanUrlRedirects;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StorePageCache;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\StoreSitemapProvider;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use TmcWpStubs\RedirectedException;
use TmcWpStubs\State;

/**
 * The three things the public store page gained beyond its own HTML: a cache,
 * a redirect for the URLs a Dokan shop already had, and a line in the sitemap.
 *
 * Every test here was written after a live probe on the disposable site, and
 * the first two exist because that probe failed.
 */
final class StorePublicSurfaceTest extends ContractTestCase
{
    private const VENDOR_ID = 4242;

    /**
     * Core's own routing regex, copied verbatim from
     * `wp-includes/sitemaps/class-wp-sitemaps.php`. If a future core release
     * widens it this test keeps passing; what it forbids is US drifting.
     */
    private const CORE_SITEMAP_ROUTE = '#^wp-sitemap-([a-z]+?)-(\d+?)\.xml$#';

    public function testTheSitemapNameIsOneCoreCanActuallyRoute(): void
    {
        $advertised = 'wp-sitemap-' . StoreSitemapProvider::NAME . '-1.xml';

        // The bug this pins: `tmc-stores` built a perfectly good-looking URL
        // in the index and then fell through to the theme, answering
        // `200 text/html` — because `([a-z]+?)` matches no hyphen, so core
        // read it as provider «tmc» with subtype «stores», which nobody
        // registered. Measured on the disposable site before the fix.
        self::assertSame(
            1,
            preg_match(self::CORE_SITEMAP_ROUTE, $advertised, $m),
            $advertised . ' is advertised in the sitemap index but core cannot route it'
        );
        self::assertSame(StoreSitemapProvider::NAME, $m[1]);
        self::assertTrue(StoreSitemapProvider::isRoutableName(StoreSitemapProvider::NAME));
    }

    public function testAnUnroutableNameIsRejectedRatherThanAdvertised(): void
    {
        foreach (['tmc-stores', 'tmc_stores', 'tmcStores', 'tmc2', ''] as $bad) {
            self::assertFalse(StoreSitemapProvider::isRoutableName($bad), $bad . ' should be refused');
        }
    }

    public function testTheCacheReturnsWhatItStoredAndTheVersionBumpMakesItUnreachable(): void
    {
        StorePageCache::put(7, 1, '<html>page one</html>');
        StorePageCache::put(7, 3, '<html>page three</html>');
        self::assertSame('<html>page one</html>', StorePageCache::get(7, 1));
        self::assertSame('<html>page three</html>', StorePageCache::get(7, 3));

        // One write, and EVERY page this shop ever cached is unreachable —
        // including page 3, which a key sweep would have to enumerate to
        // find. That is the whole reason invalidation is a counter.
        StorePageCache::forget(7);
        self::assertNull(StorePageCache::get(7, 1));
        self::assertNull(StorePageCache::get(7, 3));
    }

    public function testForgettingOneShopLeavesAnotherShopsCacheAlone(): void
    {
        StorePageCache::put(7, 1, 'seven');
        StorePageCache::put(8, 1, 'eight');
        StorePageCache::forget(7);
        self::assertNull(StorePageCache::get(7, 1));
        self::assertSame('eight', StorePageCache::get(8, 1));
    }

    public function testAManagerCanTurnTheCacheOffEntirely(): void
    {
        update_option('tmc_store_page_cache', '0');
        StorePageCache::put(7, 1, 'body');
        self::assertNull(StorePageCache::get(7, 1), 'nothing may be served from a cache switched off');
    }

    public function testTheStoreBaseComesFromDokansOwnSettingNotFromAGuess(): void
    {
        self::assertSame('store', DokanUrlRedirects::storeBase(), 'the default when Dokan has no setting');

        update_option('dokan_general', ['custom_store_url' => 'forushgah']);
        self::assertSame('forushgah', DokanUrlRedirects::storeBase());

        update_option('dokan_general', ['custom_store_url' => '/forushgah/']);
        self::assertSame('forushgah', DokanUrlRedirects::storeBase(), 'slashes are not part of the base');
    }

    public function testADokanStoreUrlRedirectsPermanentlyToTheSameShopHere(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        $this->request404('/store/daroukhane/');

        self::assertCount(1, State::$redirects);
        self::assertSame(301, State::$redirects[0]['status']);
        self::assertStringContainsString('tmc_store=' . self::VENDOR_ID, State::$redirects[0]['location']);
    }

    public function testAShopDokanWillActuallyServeIsNeverTakenOver(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        State::$dokanSellers = [self::VENDOR_ID];       // Dokan is going to render its page

        $this->requestDokanClaimed('daroukhane');
        self::assertSame([], State::$redirects, 'a live Dokan store page is not ours to take');
    }

    /**
     * The case the live probe found, and the one a real migration hits first.
     *
     * Dokan stays installed for the shops that have not moved yet, so it owns
     * the `store/…` rewrite and `is_404()` is FALSE at `template_redirect`
     * even for a shop it is about to refuse — it decides later, on
     * `template_include`. Measured on the disposable site: `/store/tmcvendor/`
     * answered `404` while `is_404()` said `no`.
     */
    public function testAShopDokanHasStoppedServingIsRedirected(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        State::$dokanSellers = [];                      // no longer sells through Dokan

        $this->requestDokanClaimed('daroukhane');
        self::assertCount(1, State::$redirects);
        self::assertSame(301, State::$redirects[0]['status']);
        self::assertStringContainsString('tmc_store=' . self::VENDOR_ID, State::$redirects[0]['location']);
    }

    /**
     * `paged` defaults to the INTEGER 0, and 0 here means «no page asked
     * for», not «page zero». Reading it as «set» stood the whole redirect
     * down on every bare store URL, and the symptom was a fix that looked
     * completely inert.
     */
    public function testPageZeroMeansNoPageWasAskedFor(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        foreach ([0, '0', '', false, null] as $absent) {
            State::$redirects = [];
            $this->requestDokanClaimed('daroukhane', ['paged' => $absent]);
            self::assertCount(1, State::$redirects, var_export($absent, true) . ' means no page was requested');
        }
    }

    /** @return list<array{0:string,1:mixed}> */
    public static function deeperDokanViews(): array
    {
        return [['toc', '1'], ['term', 7], ['term_section', 'true'], ['paged', 2]];
    }

    /** @dataProvider deeperDokanViews */
    public function testADeeperDokanViewIsLeftAloneEvenWhenDokanRefusesIt(string $var, mixed $value): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        State::$dokanSellers = [];

        $this->requestDokanClaimed('daroukhane', [$var => $value]);
        self::assertSame([], State::$redirects, $var . ' names a page we have no equivalent of');
    }

    /** @return list<array{0:ApplicationStatus}> */
    public static function unservedStatuses(): array
    {
        return [
            [ApplicationStatus::Draft],
            [ApplicationStatus::Submitted],
            [ApplicationStatus::InReview],
            [ApplicationStatus::ChangesRequested],
            [ApplicationStatus::Rejected],
            [ApplicationStatus::Suspended],
        ];
    }

    /** @dataProvider unservedStatuses */
    public function testAShopWithNoPageHereKeepsItsOwn404(ApplicationStatus $status): void
    {
        $this->bootWithVendor($status, 'daroukhane');
        $this->request404('/store/daroukhane/');

        // A 301 to a page that also 404s is worse than the 404 they had: the
        // browser caches the redirect, so the mistake outlives the fix.
        self::assertSame([], State::$redirects, $status->value . ' has no page to send anybody to');
    }

    public function testADeeperDokanUrlIsNotRedirectedToTheShopFrontPage(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        $this->request404('/store/daroukhane/toc/');
        self::assertSame([], State::$redirects, 'we have no equivalent of that page; saying we do would be a lie');
    }

    public function testTheBaseSegmentMustMatchTheConfiguredOne(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        update_option('dokan_general', ['custom_store_url' => 'forushgah']);

        $this->request404('/store/daroukhane/');
        self::assertSame([], State::$redirects, 'store/ is not the base this install uses');

        $this->request404('/forushgah/daroukhane/');
        self::assertCount(1, State::$redirects);
    }

    public function testAnUnknownSlugIsNotEvenLookedUp(): void
    {
        $this->bootWithVendor(ApplicationStatus::Approved, 'daroukhane');
        $this->request404('/store/somebody-elses-shop/');
        self::assertSame([], State::$redirects);
    }

    /** Boots the plugin with exactly one vendor, in the status given. */
    private function bootWithVendor(ApplicationStatus $status, string $nicename): void
    {
        $this->bootPlugin(false);

        $application = new VendorApplication(1, self::VENDOR_ID, $status, new ApplicantDetails('داروخانه'));
        $vendors = $this->createStub(VendorRepositoryInterface::class);
        $vendors->method('findApplicationByUser')->willReturnCallback(
            static fn (int $userId): ?VendorApplication => $userId === self::VENDOR_ID ? $application : null
        );
        Bootstrap::container()->bind(VendorRepositoryInterface::class, static fn () => $vendors);

        State::$users[self::VENDOR_ID] = ['user_nicename' => $nicename];
    }

    private function request404(string $path): void
    {
        $this->fireTemplateRedirect($path, true);
    }

    /**
     * A request Dokan matched: its rewrite filled the store query var, so
     * `is_404()` is false however Dokan later answers.
     *
     * @param array<string,mixed> $extraVars
     */
    private function requestDokanClaimed(string $slug, array $extraVars = []): void
    {
        State::$queryVars = array_merge(
            ['store' => $slug, 'toc' => '', 'term' => 0, 'term_section' => '', 'paged' => 0],
            $extraVars
        );
        $this->fireTemplateRedirect('/store/' . $slug . '/', false);
        State::$queryVars = [];
    }

    /**
     * Fires the hook the way a real request does — including the fact that a
     * redirect ENDS it. `RedirectedException` stands in for the `exit`, and
     * catching it here is the test saying «and nothing after this ran».
     */
    private function fireTemplateRedirect(string $path, bool $is404): void
    {
        State::$redirects = [];
        State::$isAdmin = false;
        State::$is404 = $is404;
        State::$requestUri = $path;
        State::$throwOnRedirect = true;
        try {
            do_action('template_redirect');
        } catch (RedirectedException) {
            // The request stopped, which is the outcome under test.
        }
    }
}
