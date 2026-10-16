<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
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
 *
 * ### The manager, and why they saw nothing (`alpha.56`)
 *
 * The owner reported that on the real theme's account page they could see
 * only Dokan's red button. The first thing to establish is which of two
 * different things that is, because the fix for each is the opposite of the
 * fix for the other:
 *
 *  1. **the hooks did not run.** A theme that renders its own account page,
 *     or overrides `myaccount/dashboard.php` without
 *     `do_action('woocommerce_account_dashboard')`, never calls us — the same
 *     class of problem `alpha.41` found on `woocommerce_single_product_summary`.
 *     Nothing in this file can fix that; the shortcode is the answer, and
 *     `lastSeen()` is how the owner finds out that this is the case.
 *  2. **the hooks ran and we drew nothing, correctly.** The account in the
 *     screenshots is a MANAGER. A manager has no vendor profile, no place on
 *     any shop's roster and no application, so `label()` answered `''` — and
 *     it was right to: «مدیر را خودکار فروشنده نکن». What was missing was not
 *     a vendor link, it was an ADMINISTRATIVE one.
 *
 * So from `alpha.56` there is a fourth answer: a manager with
 * `tmc_review_products` gets a link into the marketplace's own admin screen,
 * labelled as what it is. It grants nothing — the admin page checks its own
 * capability — and it does not make the manager a vendor: no profile is
 * created, no roster touched, no role changed.
 *
 * ### The measurement, because a theme cannot be read from here
 *
 * `register()` records, per request that reaches an account page, WHICH of
 * the two hooks fired and whether the block was printed. The health screen
 * reads it. That is deliberately a measurement and not a claim:
 * «بدون مشاهدهٔ قالب واقعی، سازگاری با آن را «تأییدشده» اعلام نکن» — this
 * plugin cannot see the owner's theme, so it asks the owner's own site.
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
     * «ورود به داشبورد فروشنده» — the owner's exact words, one string, used
     * by the menu row, the dashboard button and the shortcode, so the three
     * cannot disagree about where they go. It was «ورود به پنل فروشنده»
     * until `alpha.57`; the destination did not change with the wording, and
     * the test that pins the destination is what says so.
     */
    public static function panelLabel(): string
    {
        return __('ورود به داشبورد فروشنده', 'tecteb-marketplace-core');
    }

    /**
     * The manager's label, and it says where it goes.
     *
     * Not «ورود به داشبورد فروشنده», and the wording now says wp-admin out
     * loud. A manager who has no shop pressing a button called «داشبورد
     * فروشنده» would land on the page that invites them to APPLY to be a
     * vendor — the shape of mistake `alpha.33` paid for when a shop's staff
     * were shown «شروع درخواست فروشندگی». `alpha.56` labelled it «مدیریت
     * بازارگاه», which the owner read as this plugin's vendor button and is
     * the report this round answers: the vendor button is the one above, and
     * this is a different link to a different place.
     *
     * **What is NOT decided here**: whether a manager should be able to open
     * a particular vendor's dashboard. There is no mechanism for it —
     * `VendorRoutes::renderPage()` builds every page from the SIGNED-IN user
     * id and takes no shop parameter — and building one means deciding who
     * may read whose shop, which is the owner's call. So nothing is invented:
     * no shop is created, no vendor is picked, no capability is granted.
     */
    public static function managerLabel(): string
    {
        return __('بررسی محصول‌ها در پیشخوان مدیریت', 'tecteb-marketplace-core');
    }

    /**
     * Where the last account page's hooks got to, per site.
     *
     * An option and not a transient: the question «does this theme call
     * WooCommerce's account hooks» is answered once and stays true until the
     * theme changes, and a transient that expired would read as «never ran».
     */
    public const SEEN_OPTION = 'tmc_account_hooks_seen';

    public static function register(ContainerInterface $container): void
    {
        $link = new self($container);
        add_filter('woocommerce_account_menu_items', [$link, 'addMenuItem'], 20);
        add_filter('woocommerce_get_endpoint_url', [$link, 'endpointUrl'], 20, 4);
        add_action('woocommerce_account_dashboard', [$link, 'renderPanel'], 5);
        add_shortcode(self::SHORTCODE, [$link, 'shortcode']);
    }

    /**
     * What the last account-page render actually did, for the health screen.
     *
     * Four facts, and each sends the reader somewhere different: the menu
     * filter ran, the dashboard hook ran, the shortcode ran, and our block was
     * printed. «menu yes / dashboard no» is a theme that renders WooCommerce's
     * navigation and its own dashboard; «both no» is a theme that renders
     * neither, where the shortcode is the only way; «ran but printed nothing»
     * is this plugin answering correctly about somebody who is not a vendor.
     *
     * `kind` separates the two destinations, which is the question this
     * round was asked: «vendor» is the front-end dashboard and «admin» is
     * wp-admin. A site reporting `printed=yes kind=admin` has an account with
     * no shop in this marketplace — not a broken button.
     *
     * @return array{menu:string, dashboard:string, shortcode:string, printed:string, label:string, kind:string}
     */
    public static function lastSeen(): array
    {
        $raw = get_option(self::SEEN_OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        return [
            'menu' => (string) ($raw['menu'] ?? ''),
            'dashboard' => (string) ($raw['dashboard'] ?? ''),
            'shortcode' => (string) ($raw['shortcode'] ?? ''),
            'printed' => (string) ($raw['printed'] ?? ''),
            'label' => (string) ($raw['label'] ?? ''),
            'kind' => (string) ($raw['kind'] ?? ''),
        ];
    }

    /**
     * Note that one of them ran, with the time.
     *
     * Written only when the value CHANGES — the same page is loaded all day
     * and a write per view would be a row rewritten for nothing. The time is
     * the site's own, through `current_time()`, because this is read by a
     * person on a screen.
     */
    private function note(string $key, string $value): void
    {
        $seen = self::lastSeen();
        if (($seen[$key] ?? '') === $value) {
            return;
        }
        $seen[$key] = $value;
        $seen['at'] = (string) current_time('mysql');
        update_option(self::SEEN_OPTION, $seen, false);
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function addMenuItem(array $items): array
    {
        $this->note('menu', 'yes');
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
        // The SAME destination the button uses, from the same decision: a
        // menu row that went to `/vendor/` while the button beside it went to
        // wp-admin would be two answers to one question on one page.
        return $endpoint === self::MENU_KEY ? $this->destinationFor($this->label()) : $url;
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
        $this->note('dashboard', 'yes');
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
        $this->note('shortcode', 'yes');
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
            $this->note('printed', 'no');
            return '';
        }
        $this->printed = true;
        // Which block was drawn, for the health screen. «ran and printed
        // nothing» and «never ran» are different problems with opposite
        // fixes, and only the site can tell them apart.
        $this->note('printed', 'yes');
        $this->note('label', $label);
        $this->note('kind', $label === self::managerLabel() ? 'admin' : 'vendor');
        // `class="button"` is WooCommerce's own so the button matches the
        // store's; `tmc-account-vendor__link` is ours, and the few rules
        // below are the only thing this plugin ever puts on a storefront
        // page. They exist because `.woocommerce a.button` inherits the
        // theme's font size: on a 390px screen the owner's theme rendered it
        // at a size that wrapped mid-word. Printed INSIDE the block, so a
        // page that draws no button gets no stylesheet either.
        return '<style>.tmc-account-vendor{margin:0 0 1.5em}'
            . '.tmc-account-vendor__link{display:inline-block;font-size:1rem;line-height:1.6;'
            . 'padding:.7em 1.4em;max-width:100%;white-space:normal;text-align:center}'
            . '.tmc-account-vendor__note{display:block;margin-top:.6em;font-size:.9rem;opacity:.85}'
            . '</style>'
            . '<p class="tmc-account-vendor"><a class="button tmc-account-vendor__link" href="'
            . esc_url($this->destinationFor($label)) . '">' . esc_html($label) . '</a>'
            . '<span class="tmc-account-vendor__note">' . esc_html(self::blurb($label)) . '</span>'
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
     * Where this label's button goes.
     *
     * Two destinations, because there are two kinds of reader: a vendor, an
     * applicant or a shop's staff go to `/vendor/`, and a manager goes to
     * wp-admin. One destination for both would be a button that lied about
     * where it went for one of them.
     *
     * The admin address is built from `admin_url()`, so it is right on any
     * domain, and the page behind it checks its own capability: this decides
     * what is drawn and nothing else.
     */
    private function destinationFor(string $label): string
    {
        return $label === self::managerLabel()
            ? admin_url('admin.php?page=tmc-product-review')
            : $this->dashboardUrl();
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
            if ($vendors->findApplicationByUser($userId) !== null) {
                return __('درخواست فروشندگی من', 'tecteb-marketplace-core');
            }
            // And a MANAGER. Fourth and last, deliberately: a person who is
            // both a vendor and a manager is sent to their shop, because that
            // is the thing they came to `/my-account/` for. Nothing here
            // makes a manager a vendor — no profile is created, no roster
            // touched, no role changed — and the capability it reads is the
            // one the admin screen checks for itself, so this is wayfinding
            // and not access.
            return current_user_can(Capabilities::REVIEW_PRODUCTS)
                ? self::managerLabel()
                : '';
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
        return match ($label) {
            self::panelLabel() => __('مدیریت محصول‌ها، سفارش‌ها و تنظیمات فروشگاه شما.', 'tecteb-marketplace-core'),
            // Says where it goes, because it does not go to a dashboard.
            // A manager with a shop never sees this line: the vendor branch
            // is first, so their button is the one above.
            self::managerLabel() => __('این حساب فروشگاهی در بازارگاه ندارد؛ این پیوند به پیشخوان مدیریت وردپرس می‌رود.', 'tecteb-marketplace-core'),
            default => __('وضعیت درخواست فروشندگی و مدارک شما.', 'tecteb-marketplace-core'),
        };
    }
}
