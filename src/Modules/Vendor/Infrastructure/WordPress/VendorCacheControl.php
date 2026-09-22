<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;

/**
 * «Never cache the vendor panel» — said early enough, and in every dialect.
 *
 * What the owner measured: `/vendor/products/` showed three drafts and one
 * awaiting review; coming back to the same address showed zero of everything;
 * adding `?tmc_check=2241` brought all four back. A counter that changes when
 * you add a meaningless query parameter is not a counter that came from the
 * database on that request.
 *
 * Two facts from the code say the same thing from the other side.
 * `countsByStatus()` is `SELECT status, COUNT(*) … GROUP BY status` with no
 * transient, no object cache and no memo — so the plugin has nothing stale to
 * hand back. And a signed-out visitor is redirected to the login form rather
 * than shown an empty panel. Neither path can produce what was on screen.
 *
 * So the HTML came from a cache that keys on the URL, outside the plugin.
 *
 * `alpha.25` defined `DONOTCACHEPAGE` on `template_redirect`, which was two
 * steps short:
 *
 *   1. **Too late to be sure.** A page cache decides what to do with a
 *      response before the theme runs. `plugins_loaded` is the earliest a
 *      plugin can speak, and the request URI is already there — read
 *      through `Request`, the one file allowed to touch a superglobal.
 *   2. **The wrong half of the problem.** `DONOTCACHEPAGE` stops a response
 *      being WRITTEN to the cache. It cannot stop one being SERVED: WP
 *      Rocket, W3 Total Cache and WP Super Cache all serve from
 *      `advanced-cache.php`, which runs before any plugin is loaded. Stopping
 *      the serve means getting the path into the caching plugin's OWN config,
 *      which is what the reject-URI filters below do.
 *
 * Neither of those can reach an entry that is already stored. A cache written
 * before this code existed keeps being served until somebody purges it once —
 * that step is the owner's, and it is written down rather than assumed
 * (`docs/vendor-panel-cache-fa.md`).
 */
final class VendorCacheControl
{
    /** The path the rewrite rules own: `/vendor/` and `/vendor/<view>/`. */
    public const BASE = 'vendor';

    public static function register(): void
    {
        // Priority 1 on the earliest hook a plugin has. `init` would still be
        // before the response, but after WooCommerce and the theme have had a
        // chance to prime caches of their own.
        add_action('plugins_loaded', [self::class, 'refuseIfVendorRequest'], 1);

        // Registered ALWAYS, not only on vendor requests: a caching plugin
        // reads these when it rebuilds its config, which happens on a save or
        // a purge — not on the request being rejected.
        add_filter('rocket_cache_reject_uri', [self::class, 'addRejectedUris']);
        add_filter('rocket_cache_reject_uri_prefixes', [self::class, 'addRejectedUris']);
        add_filter('wp_super_cache_rejected_uris', [self::class, 'addRejectedUris']);
        add_filter('w3tc_can_cache', [self::class, 'notOnVendorRequests'], 10, 1);
    }

    /**
     * Is this request inside the vendor panel?
     *
     * Deliberately reads the raw URI rather than `get_query_var()`: at
     * `plugins_loaded` the rewrite rules have not run, so the query var does
     * not exist yet. Both shapes are covered — the pretty path, and the
     * `?tmc_vendor=` fallback the plugin uses when permalinks are plain.
     */
    public static function isVendorRequest(string $uri): bool
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        if (preg_match('~(^|/)' . preg_quote(self::BASE, '~') . '(/|$)~', $path) === 1) {
            return true;
        }
        parse_str($query, $args);
        return isset($args[VendorRoutes::QUERY_VAR]) && (string) $args[VendorRoutes::QUERY_VAR] !== '';
    }

    public static function refuseIfVendorRequest(): void
    {
        if (!self::isVendorRequest(Request::uri())) {
            return;
        }
        foreach (['DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY'] as $flag) {
            if (!defined($flag)) {
                define($flag, true);
            }
        }
        // LiteSpeed listens for an action rather than a constant.
        do_action('litespeed_control_set_nocache', 'tecteb vendor panel is per-user');

        if (!headers_sent()) {
            // `no-store` and not merely `no-cache`: a shared proxy may keep a
            // `no-cache` response and revalidate, and there is nothing here
            // worth revalidating — every byte belongs to one signed-in person.
            header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
            header('Vary: Cookie', false);
        }
    }

    /**
     * @param mixed $uris
     * @return mixed the same shape, with the vendor paths added
     */
    public static function addRejectedUris($uris)
    {
        if (!is_array($uris)) {
            return $uris;
        }
        foreach (['/' . self::BASE . '/(.*)', '/' . self::BASE . '/?$'] as $pattern) {
            if (!in_array($pattern, $uris, true)) {
                $uris[] = $pattern;
            }
        }
        return $uris;
    }

    /** @param mixed $can */
    public static function notOnVendorRequests($can): bool
    {
        if (!$can) {
            return false;
        }
        return !self::isVendorRequest(Request::uri());
    }
}
