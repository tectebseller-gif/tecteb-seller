<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * A Dokan store URL that people have bookmarked keeps working: `301` to the
 * same shop's page here.
 *
 * A migration that moved the catalogue and left `/store/daroukhane/` returning
 * 404 would throw away whatever that URL had earned — every link, every search
 * result, every message somebody sent a friend. `301` is the one answer that
 * tells a browser, a crawler and a person the same thing.
 *
 * **A Dokan store URL stops resolving in three different ways, and only one
 * of them looks like a 404.** All three were measured on a real Dokan Lite:
 *
 * | state                                   | `is_404()` | what the URL answered |
 * |-----------------------------------------|-----------|------------------------|
 * | Dokan active, shop moved off it          | `false`   | `404` from its template |
 * | Dokan deactivated, rules still cached    | `false`   | **`200`, the home page** |
 * | Dokan gone and rewrites flushed          | `true`    | `404`                   |
 *
 * The middle row is the nastiest and the least obvious. Deactivating a plugin
 * does not flush the rewrite rules it registered: six `store/…` rules were
 * still in the `rewrite_rules` option right after
 * `wp plugin deactivate dokan-lite`, so the URL still matched — while `store`
 * was no longer a registered query var, so WordPress dropped it and served the
 * front page with `200`. The site's home page at every old shop address is
 * duplicate content on dozens of URLs, and a bookmark lands somewhere
 * plausible and wrong.
 *
 * The first row is the one an actual migration hits first, because a
 * marketplace does not move every shop on one night: Dokan stays installed for
 * the shops that have not moved, owns the rewrite, and decides only later — on
 * `template_include` priority 99, by asking `dokan_is_user_seller()`.
 *
 * So the question this file asks first is **«is Dokan running»**, not «is this
 * a 404». Running means Dokan owns the URL and its own predicate settles it.
 * Not running means nobody else will answer, and the slug is read from the
 * path — which is the only place it survives, since its query var is
 * unregistered in both of the not-running rows.
 *
 * **Four deliberate narrowings, because a redirect that is too eager breaks a
 * site nobody asked us to touch:**
 *
 * 1. A shop Dokan WILL serve is never touched. That is `dokan_is_user_seller()`
 *    — Dokan's own published function, the same one its template branches on,
 *    so we agree with it by construction rather than by copying its rules and
 *    drifting. When the function does not exist, Dokan is gone and the
 *    `is_404()` path covers it.
 * 2. The base segment is read from Dokan's own setting, not assumed to be
 *    «store». A shop that renamed it to `/forushgah/` has that in
 *    `dokan_general['custom_store_url']`, and guessing would redirect the
 *    wrong URLs or none.
 * 3. Only the bare store URL. `/store/x/toc/`, a section and a paged listing
 *    are pages this marketplace has no equivalent of, and sending them to the
 *    shop's front page would be a lie about where that content went.
 * 4. Only an APPROVED vendor of this marketplace is redirected to. An
 *    unapproved or suspended one has no page, and sending somebody to a 404
 *    through a 301 is worse than the 404 they already had — the 301 is cached
 *    by their browser.
 */
final class DokanUrlRedirects
{
    public static function register(ContainerInterface $container): void
    {
        // Priority 20 on `template_redirect`: after `StorePage` has had its
        // chance to serve our own URL, and before anything renders.
        add_action('template_redirect', static function () use ($container): void {
            self::maybeRedirect($container);
        }, 20);
    }

    /** The store base Dokan is configured with, e.g. «store». */
    public static function storeBase(): string
    {
        $settings = get_option('dokan_general', []);
        $base = is_array($settings) ? (string) ($settings['custom_store_url'] ?? '') : '';
        return $base !== '' ? trim($base, '/') : 'store';
    }

    private static function maybeRedirect(ContainerInterface $container): void
    {
        if (is_admin()) {
            return;
        }
        $nicename = self::slugNobodyElseWillServe();
        if ($nicename === '') {
            return;
        }
        $user = get_user_by('slug', $nicename);
        if (!$user || !isset($user->ID)) {
            return;
        }
        $vendorUserId = (int) $user->ID;
        $application = $container->get(VendorRepositoryInterface::class)->findApplicationByUser($vendorUserId);
        if ($application === null || $application->status !== ApplicationStatus::Approved) {
            // No page to send them to. Leave the 404 alone rather than making
            // it a permanently cached redirect to another 404.
            return;
        }
        wp_safe_redirect(StorePage::url($vendorUserId), 301);
        exit;
    }

    /**
     * The shop slug of a store URL that nobody else is going to answer — or
     * '' when somebody is.
     *
     * **Deactivating Dokan does not remove its rewrite rules.** They live in
     * the cached `rewrite_rules` option and stay there until something
     * flushes it. Measured on the disposable site immediately after
     * `wp plugin deactivate dokan-lite`: **six `store/…` rules still in the
     * option**, so `is_404()` was FALSE — while `store` was no longer a
     * registered query var, so WordPress dropped it and answered the home
     * page with `200`.
     *
     * That is the worst of the three outcomes. A `404` at least tells a
     * crawler the page is gone; the site's front page served at every old
     * shop URL is duplicate content on dozens of addresses, and a customer
     * following a bookmark lands somewhere plausible and wrong.
     *
     * So the question asked first is «is Dokan running», not «is this a 404»:
     *
     * - **Dokan gone** — whether its rules were flushed or not, nobody else
     *   will answer, and the slug has to come from the PATH because its query
     *   var is unregistered either way.
     * - **Dokan running** — it owns the URL; its own predicate says whether it
     *   is going to serve this shop, and its query var carries the slug.
     */
    private static function slugNobodyElseWillServe(): string
    {
        // Is Dokan actually running? Its own predicate is the honest test —
        // and the answer decides WHERE the slug can be read from, not just
        // whether to redirect.
        if (!self::dokanIsRunning()) {
            return self::sellerSlugFromRequest();
        }
        return self::slugDokanClaimedButWillRefuse();
    }

    /**
     * Is Dokan running on this site?
     *
     * The presence of its own published function is the test — a plugin that
     * is deactivated has not loaded, so the symbol is gone even while its
     * rewrite rules linger in the options table.
     *
     * The filter is not a test hook. It is for the site that has Dokan
     * installed but wants this marketplace to own the store URLs anyway (or
     * the reverse), which is a decision about that site and not one this
     * plugin should make for it. Returning `false` puts every `/<base>/<slug>/`
     * under the rule below; returning `true` hands them all back to Dokan.
     *
     * @param bool $running whether Dokan's own function is loaded
     */
    private static function dokanIsRunning(): bool
    {
        return (bool) apply_filters('tmc_dokan_is_running', function_exists('dokan_is_user_seller'));
    }

    /**
     * The slug Dokan matched and is about to answer `404` for.
     *
     * Everything here is a read. Dokan's rows, options and users are never
     * written — the standing rule is «هیچ افزونه موجودی (از جمله دکان)
     * حذف/غیرفعال/ویرایش نمی‌شود» — and the only thing asked of Dokan is a
     * question it already answers for itself.
     */
    private static function slugDokanClaimedButWillRefuse(): string
    {
        if (!function_exists('dokan_is_user_seller')) {
            return '';                          // the filter said «running»; Dokan disagrees
        }
        // Dokan registers its query var under the store base and its rewrite
        // fills it, so a non-empty value means Dokan matched this URL.
        $claimed = (string) get_query_var(self::storeBase());
        if ($claimed === '') {
            return '';
        }
        // Narrowing 3: only the bare store URL. The deeper Dokan views set a
        // query var of their own, and each is a page we have no equivalent of.
        //
        // `paged` defaults to the INTEGER 0, not to '' — so a plain `!== ''`
        // test reads every bare store URL as «page zero was requested» and
        // stands down on all of them. Measured: the fix looked completely
        // inert until this line was the one that changed.
        foreach (['toc', 'term', 'term_section', 'paged'] as $deeper) {
            $value = get_query_var($deeper);
            if ($value !== '' && $value !== 0 && $value !== '0' && $value !== false && $value !== null) {
                return '';
            }
        }
        $user = get_user_by('slug', $claimed);
        if (!$user || !isset($user->ID)) {
            return '';
        }
        // Narrowing 1: Dokan is going to serve this shop. Hands off.
        if (dokan_is_user_seller((int) $user->ID)) {
            return '';
        }
        return sanitize_title($claimed);
    }

    /**
     * «/store/daroukhane/» → «daroukhane», and '' for anything else.
     *
     * The URL comes from `home_url(add_query_arg([]))` — the idiom the vendor
     * router already uses — rather than from the request superglobal. Only
     * `Request` reads those, and an architecture test enforces it by looking
     * for the name anywhere in a shipped file, comments included;
     * this is also the version that behaves when WordPress lives in a
     * subdirectory, because the home path is then stripped rather than
     * mistaken for the store base.
     */
    private static function sellerSlugFromRequest(): string
    {
        $path = (string) wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH);
        $homePath = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($homePath !== '' && $homePath !== '/' && str_starts_with($path, $homePath)) {
            $path = substr($path, strlen($homePath));
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
        $base = self::storeBase();
        // Exactly «<base>/<slug>», and not «<base>/<slug>/toc» — a deeper
        // Dokan URL is a page this marketplace has no equivalent of, and
        // redirecting it to the shop's front page would be a lie about where
        // that content went.
        if (count($segments) !== 2 || $segments[0] !== $base) {
            return '';
        }
        return sanitize_title($segments[1]);
    }
}
