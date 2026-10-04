<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * «ورود به پنل فروشنده» where a vendor actually goes looking for it: /my-account/.
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
 * ### Who sees it (`alpha.33`, corrected in `alpha.37`)
 *
 * «یک مشتری عادی فروشنده نیست» — and an applicant is not a customer either.
 * Until `alpha.33` the test was `storeFor() !== null`, which is «acts in a shop
 * that may trade today»: it hid the link from the two people who need it most,
 * the applicant waiting for a decision and the vendor whose shop was just
 * suspended. Both have exactly one page that tells them what happened, and
 * neither could find it.
 *
 * `alpha.33` answered that with «a store, or an application on file», which
 * still left one person out: the STAFF of a suspended shop. They act in no
 * shop (`storeFor()` is null, correctly) and they have no application of their
 * own, so they got nothing at all. The question is membership, not permission,
 * and `StaffAccess::membershipFor()` is the method that answers it without
 * granting anything (`alpha.34`). So the test is now, in order:
 *
 *  1. a vendor profile of their own — the owner, whatever the shop's standing;
 *  2. a place on some shop's roster — staff, whatever their own standing;
 *  3. an application on file — an applicant, or somebody who was refused;
 *  4. otherwise nothing, which is «یک مشتری عادی فروشنده نیست».
 *
 * **None of this is access control, and it must not be read as any.** It
 * decides whether a link is drawn. `/vendor/` refuses anyone without a store
 * on its own, a suspended shop's staff still «may do nothing» there
 * (`alpha.34`), and every gate still asks `storeFor()` / `can()`. A link is
 * not a right.
 *
 * ### Why the button came back (`alpha.37`)
 *
 * The owner reported «دکمهٔ ورود به پنل فروشنده از صفحهٔ حساب کاربری حذف شده
 * است», and the cause was this file: `renderPanel()` returned early whenever
 * the menu filter had run, so on ANY theme that renders WooCommerce's stock
 * account navigation the button in the dashboard was never printed. The
 * guard was written for a real defect — the same label appearing twice on
 * `/my-account/` — but it treated the two as one thing, and they are not:
 *
 *  - the MENU ROW is wayfinding. It sits in a list of endpoints with «سفارش‌ها»
 *    and «خروج», and it is read by somebody who already knows where they are
 *    going.
 *  - the DASHBOARD BUTTON is the call to action. It is the first thing on the
 *    page a vendor lands on after logging in, and it is what the owner means
 *    by «دکمهٔ روشن».
 *
 * One of each is not a duplicate. What must not happen is the same block
 * printed twice in one request, and that is what `$printed` is for — a flag
 * about this plugin's own output, not about somebody else's menu.
 */
final class VendorAccountLink
{
    /** The key WooCommerce uses to identify our row in its own menu array. */
    public const MENU_KEY = 'tmc-vendor-dashboard';

    /**
     * Has this plugin already printed the panel on this page?
     *
     * The ONE duplicate rule: the same block twice in one request. It is not a
     * question about WooCommerce's menu — a row in the navigation and a button
     * in the dashboard are different things in different places, and
     * `alpha.36` suppressed the button because it conflated them.
     *
     * Both the dashboard hook and the shortcode consult it, so a site that has
     * the hook AND a pasted `[tmc_vendor_dashboard]` gets exactly one button,
     * whichever comes first in the page.
     *
     * An INSTANCE flag, not a static: `register()` hooks every callback onto
     * one object, so per-request is exactly per-instance — and a static would
     * leak from one test to the next, which is a bug that only shows up in the
     * second test written.
     */
    private bool $printed = false;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /** The shortcode for an account page this plugin cannot hook into. */
    public const SHORTCODE = 'tmc_vendor_dashboard';

    /**
     * The owner's words, and a method rather than a `const` because `__()`
     * cannot run at class-definition time.
     *
     * «دکمهٔ ورود به پنل فروشنده» — one string, used by the menu row, the
     * dashboard button and the shortcode, so the three cannot disagree about
     * where they go.
     */
    public static function panelLabel(): string
    {
        return __('ورود به پنل فروشنده', 'tecteb-marketplace-core');
    }

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
     * The button on the account dashboard — on every theme, menu or no menu.
     *
     * Not a fallback for themes that replace the navigation: this is the
     * primary entry point, and the menu row beside it is a second, smaller
     * one. `alpha.36` made this conditional on the menu not having run and so
     * removed the button from every stock theme; see the class docblock.
     */
    public function renderPanel(): void
    {
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
        // Not gated on the MENU — somebody who pastes the shortcode has asked
        // for the block to be in that spot, and a shortcode that renders
        // nothing because a menu elsewhere has a row is a shortcode that looks
        // broken. It is gated on the same `$printed` flag as the hook, so the
        // two cannot both print on one page.
        return $this->panel();
    }

    /**
     * The block, once per request.
     *
     * The flag is set only when something is actually returned: a buyer, for
     * whom this is '', must not use up the one print and silence a later
     * caller that would also have printed nothing.
     *
     * `class="button"` is WooCommerce's own — `.woocommerce a.button` is in
     * `woocommerce.css` on every store, so the button looks like the store's
     * other buttons without this plugin putting a stylesheet on a page it has
     * no business on.
     */
    private function panel(): string
    {
        if ($this->printed) {
            return '';
        }
        $label = $this->label();
        if ($label === '') {
            return '';
        }
        $this->printed = true;
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
            $vendors = $this->container->get(VendorRepositoryInterface::class);
            // The owner of a shop, whatever its standing. `findProfileByUser()`
            // rather than `storeFor()`: the second is «may act today», and a
            // vendor whose shop was suspended an hour ago needs this link more
            // than anyone.
            if ($vendors->findProfileByUser($userId) !== null) {
                return self::panelLabel();
            }
            // On some shop's roster. A question about membership, answered by
            // the method that cannot grant anything — staff of a suspended
            // shop, and staff whose own place is paused, both have a page that
            // says so and had no way to reach it.
            if ($this->container->get(StaffAccess::class)->membershipFor($userId) !== null) {
                return self::panelLabel();
            }
            // Neither, but with an application on file: an applicant in
            // review, or somebody who was refused. The LABEL differs because
            // sending them to «ورود به پنل فروشنده» and showing them a review
            // status is a link that lied about where it went.
            return $vendors->findApplicationByUser($userId) === null
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
        return $label === self::panelLabel()
            ? __('مدیریت محصول‌ها، سفارش‌ها و تنظیمات فروشگاه شما.', 'tecteb-marketplace-core')
            : __('وضعیت درخواست فروشندگی و مدارک شما.', 'tecteb-marketplace-core');
    }
}
