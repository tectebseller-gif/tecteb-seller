<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * «داشبورد فروشنده» where a vendor actually goes looking for it: /my-account/.
 *
 * Until now the panel had no entry point at all from the shop side. A vendor
 * who had not bookmarked `/vendor/` had to be told the address, and the owner
 * had to paste a staging URL into a message — which is exactly how a hardcoded
 * host ends up in a plugin.
 *
 * Three things this deliberately does NOT do:
 *
 *  - **It does not build the address.** It asks `VendorRoutes` for it, which
 *    builds from `home_url()` and already knows whether the site has pretty
 *    permalinks. So the link is right on any domain, including the day the
 *    site moves.
 *  - **It does not guard anything.** Hiding a link is not access control:
 *    `/vendor/` itself refuses anyone without a store, and that refusal is
 *    what this can be tested against. The visibility test here exists so the
 *    menu is not cluttered for buyers, and for no other reason.
 *  - **It does not require WooCommerce's stock template.** The menu filter
 *    covers themes that render the standard navigation; the dashboard hook
 *    covers a custom account page that does not; and `[tmc_vendor_dashboard]`
 *    covers a page that renders neither — pasted into the page's own content,
 *    so the owner never edits a theme file or another plugin's template. A site
 *    using none of the three still has the address, because the address is not
 *    the access.
 *
 * ### Who sees it (`alpha.33`)
 *
 * «یک مشتری عادی فروشنده نیست» — and an applicant is not a customer either.
 * Until this round the test was `storeFor() !== null`, which is «acts in a shop
 * that may trade today»: it hid the link from the two people who need it most,
 * the applicant waiting for a decision and the vendor whose shop was just
 * suspended. Both have exactly one page that tells them what happened, and
 * neither could find it. So the test is «has a vendor path at all» — a store,
 * or an application on file — and the LABEL says which, because sending
 * somebody to «داشبورد فروشنده» and showing them a review status is a link that
 * lied about where it went.
 */
final class VendorAccountLink
{
    /** The key WooCommerce uses to identify our row in its own menu array. */
    public const MENU_KEY = 'tmc-vendor-dashboard';

    /**
     * Did the theme render WooCommerce's own account navigation this request?
     *
     * Measured on the demo install: with a stock theme BOTH places fired and
     * `/my-account/` carried «داشبورد فروشنده» twice — «ابتدا پیاده‌سازی فعلی
     * را بررسی کن تا لینک تکراری ساخته نشود», and a duplicate we print
     * ourselves is the same defect as a duplicate we add beside somebody
     * else's. WooCommerce's `my-account.php` renders `navigation.php` before
     * the content area, so by the time the dashboard hook runs the answer is
     * already known: the menu got it, and the panel stands down.
     *
     * An INSTANCE flag, not a static: `register()` hooks both callbacks onto
     * one object, so per-request is exactly per-instance — and a static would
     * leak from one test to the next, which is a bug that only shows up in the
     * second test written.
     */
    private bool $menuCarriedIt = false;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /** The shortcode for an account page this plugin cannot hook into. */
    public const SHORTCODE = 'tmc_vendor_dashboard';

    public static function register(ContainerInterface $container): void
    {
        $link = new self($container);
        add_filter('woocommerce_account_menu_items', [$link, 'addMenuItem'], 20);
        add_filter('woocommerce_get_endpoint_url', [$link, 'endpointUrl'], 20, 4);
        add_action('woocommerce_account_dashboard', [$link, 'renderPanel'], 5);
        add_shortcode(self::SHORTCODE, [$link, 'shortcode']);
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function addMenuItem(array $items): array
    {
        $label = $this->label();
        if ($label === '') {
            return $items;
        }
        // Inserted before «خروج», because a menu whose last item is the way
        // out is a menu people stop reading one line early.
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);
        $items[self::MENU_KEY] = $label;
        $this->menuCarriedIt = true;
        if ($logout !== null) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    /**
     * WooCommerce would otherwise build `/my-account/tmc-vendor-dashboard/`
     * for our row, which is not an endpoint and would 404.
     */
    public function endpointUrl(string $url, string $endpoint, string $value, string $permalink): string
    {
        return $endpoint === self::MENU_KEY ? $this->dashboardUrl() : $url;
    }

    /**
     * A panel on the account dashboard, for the themes that replace the menu.
     *
     * The owner's site has a custom account page; a link that only exists
     * inside WooCommerce's stock navigation would be a link that only exists
     * on a site nobody has.
     */
    public function renderPanel(): void
    {
        if ($this->menuCarriedIt) {
            // The navigation already has it. A second copy in the content area
            // is not a fallback, it is a duplicate.
            return;
        }
        echo $this->panel();
    }

    /**
     * The same block, for a page that renders neither WooCommerce's menu nor
     * its dashboard hook. Paste `[tmc_vendor_dashboard]` into the account page.
     *
     * A shortcode and not a widget or a template override: the owner's account
     * page belongs to their theme, and this plugin does not edit files it does
     * not own. It returns '' for a buyer, so leaving it in the page costs a
     * customer nothing.
     */
    public function shortcode(): string
    {
        // Deliberately NOT gated on the menu: somebody who pastes the
        // shortcode has asked for the block to be in that spot, and a
        // shortcode that renders nothing because a menu elsewhere has a row
        // is a shortcode that looks broken.
        return $this->panel();
    }

    private function panel(): string
    {
        $label = $this->label();
        if ($label === '') {
            return '';
        }
        return '<p class="tmc-account-vendor"><a class="button" href="'
            . esc_url($this->dashboardUrl()) . '">' . esc_html($label) . '</a> '
            . esc_html(self::blurb($label))
            . '</p>';
    }

    /**
     * Built from the plugin's own route, never from a string in this file.
     *
     * `VendorRoutes::dashboardUrl()` is the single place that knows both the
     * path and whether this site uses pretty permalinks.
     */
    private function dashboardUrl(): string
    {
        return VendorRoutes::dashboardUrl();
    }

    /**
     * The link's text — and '' when this person has no vendor path at all.
     *
     * One question answered once, so the menu row, the dashboard panel and the
     * shortcode cannot disagree about who is a vendor. It is a visibility test
     * and nothing more: `/vendor/` refuses anyone without a store on its own,
     * and that refusal — not this method — is the access control.
     *
     * Wrapped whole: an account page must not go blank because a repository is
     * unavailable, and a missing link is a missing link, not a broken site.
     */
    private function label(): string
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return '';
        }
        try {
            if ($this->container->get(StaffAccess::class)->storeFor($userId) !== null) {
                return __('داشبورد فروشنده', 'tecteb-marketplace-core');
            }
            // No shop that may trade today. An application on file still means
            // a vendor path: an applicant in review, a rejected one, or a
            // suspended shop whose reason is on that page and nowhere else.
            $application = $this->container->get(VendorRepositoryInterface::class)
                ->findApplicationByUser($userId);
            return $application === null
                ? ''
                : __('درخواست فروشندگی من', 'tecteb-marketplace-core');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The one line under the button, matched to where the button goes.
     *
     * Takes the label rather than asking again: `label()` reads two
     * repositories, and a paragraph is not worth a second pair of queries.
     */
    private static function blurb(string $label): string
    {
        return $label === __('داشبورد فروشنده', 'tecteb-marketplace-core')
            ? __('مدیریت محصول‌ها، سفارش‌ها و تنظیمات فروشگاه شما.', 'tecteb-marketplace-core')
            : __('وضعیت درخواست فروشندگی و مدارک شما.', 'tecteb-marketplace-core');
    }
}
