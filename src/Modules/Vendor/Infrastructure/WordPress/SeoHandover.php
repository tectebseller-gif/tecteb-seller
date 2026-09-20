<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;

/**
 * Who owns the `<head>` of a shop page when an SEO plugin is installed — and
 * how our values get there without a second copy of everything.
 *
 * ### The failure this prevents
 *
 * In theme mode the shop page prints its head on `wp_head`, which is the same
 * hook Rank Math, Yoast, SEOPress and All in One SEO print theirs on. With one
 * of them active the page ends up with **two** canonicals, two descriptions,
 * two Open Graph cards and two Schema blocks. A crawler seeing two canonicals
 * does not average them; it picks, and which one it picks is not ours to
 * decide. «خروجی تکراری» was the owner's word for it.
 *
 * ### The rule
 *
 * When a head owner exists, this plugin **stands down and hands over**: it
 * prints no canonical, no description, no Open Graph and no JSON-LD, and
 * instead feeds those same values to the owner through the owner's own
 * filters. One canonical, and it is ours.
 *
 * Standing down without handing over would be worse than the duplicate: an
 * SEO plugin that has never heard of `?tmc_store=4` will canonicalise the page
 * to whatever WordPress thinks the current URL is, and on a query-var route
 * that is the home page. That is not a duplicate, it is a wrong one.
 *
 * ### What is NOT handed over
 *
 * The title. `StoreThemeRenderer` already removes it from the injected head
 * because the theme prints its own from `document_title_parts`, and that
 * filter is the one every SEO plugin also respects — so the title arrives
 * correctly by a path that predates all of this.
 */
final class SeoHandover
{
    /** Values a shop page wants in the head, whoever ends up printing them. */
    public const FILTER_OWNS_HEAD = 'tmc_seo_plugin_owns_head';

    /** @var array<string,mixed>|null the current request’s shop values, if any */
    private static ?array $current = null;

    /**
     * Whether something else is already printing canonical and friends.
     *
     * Detected by each plugin's own public marker rather than by its folder
     * name: a renamed directory is common and a constant is not. Filterable,
     * because a site may have an SEO plugin this list has never heard of — and
     * the honest answer to «is somebody else doing this» is one a site owner
     * can correct.
     */
    public static function ownsHead(): bool
    {
        $owned = class_exists('\\RankMath')                    // Rank Math
            || defined('WPSEO_VERSION')                        // Yoast SEO
            || defined('SEOPRESS_VERSION')                     // SEOPress
            || defined('AIOSEO_VERSION');                      // All in One SEO
        return (bool) apply_filters(self::FILTER_OWNS_HEAD, $owned);
    }

    /** True when the owner is specifically Rank Math, which we can feed. */
    public static function rankMathIsActive(): bool
    {
        return (bool) apply_filters('tmc_rank_math_is_active', class_exists('\\RankMath'));
    }

    /**
     * Remember this request's shop values so the filters below can answer.
     *
     * Called by the store page as it renders. Held in a static rather than
     * passed through the filters, because WordPress filters arrive with the
     * plugin's arguments and not ours.
     */
    public static function describe(string $canonical, string $title, string $description, string $imageUrl = ''): void
    {
        self::$current = [
            'canonical' => $canonical,
            'title' => $title,
            'description' => $description,
            'image' => $imageUrl,
        ];
    }

    /** Forgotten at the end of the request; a stale value would describe another page. */
    public static function forget(): void
    {
        self::$current = null;
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Hand our values to Rank Math through its documented filters.
     *
     * Each one answers only while a shop page is being rendered — `current()`
     * is null everywhere else, so an ordinary post's canonical is untouched.
     * That guard is the whole safety of hooking global filters.
     */
    public static function register(ContainerInterface $container): void
    {
        unset($container);
        if (!self::rankMathIsActive()) {
            return;
        }

        add_filter('rank_math/frontend/canonical', static function ($canonical) {
            return self::$current['canonical'] ?? $canonical;
        }, 20);
        add_filter('rank_math/frontend/description', static function ($description) {
            return self::$current['description'] ?? $description;
        }, 20);
        add_filter('rank_math/opengraph/url', static function ($url) {
            return self::$current['canonical'] ?? $url;
        }, 20);
        add_filter('rank_math/opengraph/facebook/og_title', static function ($title) {
            return self::$current['title'] ?? $title;
        }, 20);
        add_filter('rank_math/opengraph/facebook/og_description', static function ($description) {
            return self::$current['description'] ?? $description;
        }, 20);
        add_filter('rank_math/opengraph/facebook/og_image', static function ($image) {
            $ours = self::$current['image'] ?? '';
            return $ours !== '' ? $ours : $image;
        }, 20);

        // The Schema block. Rank Math prints ONE `application/ld+json` for the
        // page and this adds our `Store` node to it, rather than printing a
        // second script tag beside it — which is the duplicate, in the shape
        // search engines complain about loudest.
        add_filter('rank_math/json_ld', static function ($data, $jsonld = null) {
            unset($jsonld);
            $store = self::$current['schema'] ?? null;
            if (is_array($data) && is_array($store)) {
                $data['tmcStore'] = $store;
            }
            return $data;
        }, 20, 2);
    }

    /**
     * The `Store` node, kept beside the rest so one call describes the page.
     *
     * @param array<string,mixed> $schema
     */
    public static function describeSchema(array $schema): void
    {
        if (self::$current !== null) {
            self::$current['schema'] = $schema;
        }
    }
}
