<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Modules\Vendor\Domain\StoreSettings;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StorePageView;

/**
 * The shop's page, rendered INSIDE the site's own theme — header, menu,
 * footer and all — instead of as a document of its own.
 *
 * **Why both modes exist.** A standalone document always renders: it depends
 * on no theme, so it cannot be broken by one. That is why the page started
 * out that way, and why it is still the fallback. But a shop page that does
 * not carry the site's header is visibly a different website, and the brief
 * is «هویت بصری یکپارچه» — one identity. So when the theme can wrap it, it
 * wraps it.
 *
 * **«Can wrap it» is asked, not assumed.** `get_header()` loads `header.php`.
 * A block theme usually has none — its header is a template part assembled by
 * the block renderer — so calling it there produces a page with our content
 * and no site chrome at all: worse than either mode on purpose. `supported()`
 * therefore requires a real `header.php` AND a real `footer.php`, and a block
 * theme without them falls back to standalone rather than to half a page.
 *
 * The manager can force either mode; `auto` is the default and decides per
 * install. Whatever the mode, `StorePageView::head()` is the same string in
 * both, so the title, canonical, Open Graph card and Schema.org block never
 * differ between them.
 */
final class StoreThemeRenderer
{
    public const OPTION = 'tmc_store_page_theme';

    public const AUTO = 'auto';
    public const THEME = 'theme';
    public const STANDALONE = 'standalone';

    /** Which mode this install will actually use — never `auto`. */
    public static function mode(): string
    {
        $chosen = (string) get_option(self::OPTION, self::AUTO);
        if ($chosen === self::THEME || $chosen === self::STANDALONE) {
            return $chosen;
        }
        return self::supported() ? self::THEME : self::STANDALONE;
    }

    /** True when the active theme has the two files `get_header()`/`get_footer()` load. */
    public static function supported(): bool
    {
        if (!function_exists('locate_template')) {
            return false;
        }
        return locate_template('header.php') !== '' && locate_template('footer.php') !== '';
    }

    /**
     * Renders the shop inside the theme and ends the request.
     *
     * @param list<object>        $products
     * @param array<string,mixed> $standing
     */
    public static function render(
        int $vendorUserId,
        StoreSettings $store,
        array $products,
        array $standing,
        string $canonical,
        int $page,
        int $pages,
        string $body
    ): void {
        // Hand the values over BEFORE deciding whether to print them, so that
        // standing down never means losing them. An SEO plugin that has never
        // heard of this route would otherwise canonicalise the page to
        // whatever WordPress thinks the current URL is — which on a query-var
        // route is the home page.
        $values = StorePageView::seoValues($store, $standing, $canonical);
        SeoHandover::describe(
            (string) $values['canonical'],
            (string) $values['title'],
            (string) $values['description'],
            (string) $values['image']
        );
        SeoHandover::describeSchema((array) $values['schema']);

        $ownsHead = SeoHandover::ownsHead();
        $head = StorePageView::head($store, $standing, $canonical, $ownsHead);
        $name = StorePageView::name($store);

        // The theme's header prints the document title from this filter, so
        // the shop's own title has to come from here rather than from the
        // `<title>` inside `head()` — two titles in one document is a bug a
        // crawler reports and a person never sees.
        add_filter('pre_get_document_title', static fn (): string => $name . ' — ' . get_bloginfo('name'), 99);
        add_action('wp_head', static function () use ($head): void {
            // phpcs:ignore WordPress.Security.EscapeOutput -- the view escapes.
            echo self::withoutTitle($head);
        }, 1);
        // Without this a theme styles the shop as the blog index, because
        // `?tmc_store=…` on the front page is still, to WordPress, the home
        // query. The class is added so a theme or a child theme has something
        // to hook onto.
        add_filter('body_class', static function (array $classes): array {
            $classes = array_values(array_diff($classes, ['home', 'blog']));
            $classes[] = 'tmc-store';
            return $classes;
        }, 20);

        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_home = false;
            $wp_query->is_404 = false;
        }

        get_header();
        // phpcs:ignore WordPress.Security.EscapeOutput -- the view escapes.
        echo $body;
        get_footer();
        // A stale description would belong to another page; the request is
        // over either way, and forgetting here keeps that true under any test
        // harness that does not exit.
        SeoHandover::forget();
        exit;
    }

    /**
     * The head block minus its `<title>`.
     *
     * `head()` carries one because the standalone document needs it. Here the
     * theme's own `wp_head` already prints a title from
     * `pre_get_document_title`, and a second `<title>` element is invalid —
     * browsers keep the first, so which one wins depends on hook order.
     */
    private static function withoutTitle(string $head): string
    {
        return (string) preg_replace('#<title>.*?</title>#s', '', $head, 1);
    }
}
