<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * NO FRONT-END SCREEN OF THIS PLUGIN BORROWS ONE OF WORDPRESS'S OWN PUBLIC
 * QUERY VARS.
 *
 * The vendor panel and the public store page are rendered on a request whose
 * rewrite rule names no post, so the main query is the blog's. A public query
 * var in such a URL is therefore read by core, not only by us — and
 * `handle_404()` 404s a paged query that found no posts while
 * `redirect_canonical()` moves the page number into the path. That pair made
 * page two of a 184-product shop unreachable for four releases, and nothing
 * in this plugin could have prevented it: both run before
 * `template_redirect`.
 *
 * The list below is WordPress's own `WP::$public_query_vars`, copied from
 * `wp-includes/class-wp.php` at 6.8. It is pinned rather than derived because
 * this environment has no WordPress to ask — stated, not hidden.
 *
 * wp-admin pages are NOT in scope and must not be: `handle_404()`'s first
 * branch is `is_admin()`, so a manager's screen may use `paged` freely, and
 * five of them do.
 */
final class VendorAreaOwnsItsQueryVarsTest extends TestCase
{
    /** @var list<string> WP::$public_query_vars, wp-includes/class-wp.php, 6.8 */
    private const CORE_PUBLIC_QUERY_VARS = [
        'm', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments', 's', 'search', 'exact',
        'sentence', 'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby',
        'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'name', 'category_name', 'tag',
        'feed', 'author_name', 'pagename', 'page_id', 'error', 'attachment', 'attachment_id',
        'subpost', 'subpost_id', 'preview', 'robots', 'favicon', 'taxonomy', 'term', 'cpage',
        'post_type', 'embed',
    ];

    /** @return list<string> every front-end file: Presentation outside Admin/, plus WordPress infra */
    private static function frontEndFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Modules';
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/Presentation/Admin/')) {
                continue;                           // wp-admin: core never 404s it
            }
            if (str_contains($path, '/Presentation/') || str_contains($path, '/Infrastructure/WordPress/')) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    public function testNoFrontEndScreenWritesOrReadsACorePublicQueryVar(): void
    {
        $files = self::frontEndFiles();
        self::assertGreaterThan(40, count($files), 'the scan found the front-end files');

        $offences = [];
        foreach ($files as $path) {
            $body = (string) file_get_contents($path);
            // Dokan's own deeper-URL segments are READ to decide whether a
            // legacy store URL is a bare one; no URL of ours is built from
            // them. Named, with its reason, rather than silently skipped.
            if (str_ends_with($path, '/DokanUrlRedirects.php')) {
                continue;
            }
            foreach (self::CORE_PUBLIC_QUERY_VARS as $var) {
                foreach (["add_query_arg('{$var}'", "queryInt('{$var}')", "queryKey('{$var}')", "queryText('{$var}')"] as $needle) {
                    if (str_contains($body, $needle)) {
                        $offences[] = basename($path) . ': ' . $needle;
                    }
                }
                if (preg_match('#<input[^>]*name="' . preg_quote($var, '#') . '"#', $body) === 1) {
                    $offences[] = basename($path) . ': <input name="' . $var . '">';
                }
            }
        }
        self::assertSame([], $offences, "a front-end screen borrowed a core query var:\n" . implode("\n", $offences));
    }

    /**
     * WHAT THIS PROVES: the replacement is not itself a core var, and both
     * vendor-area lists reach for the SAME one. Two constants would be two
     * rules, and the pair that drifts is the pair nobody measures.
     */
    public function testBothVendorListsUseTheOnePageParameter(): void
    {
        self::assertNotContains(VendorUrls::PAGE_PARAM, self::CORE_PUBLIC_QUERY_VARS);
        self::assertStringStartsWith('tmc_', VendorUrls::PAGE_PARAM);

        $root = dirname(__DIR__, 2) . '/src/Modules';
        foreach ([
            '/Product/Infrastructure/WordPress/ProductArea.php',
            '/Order/Infrastructure/WordPress/OrderArea.php',
        ] as $reader) {
            $body = (string) file_get_contents($root . $reader);
            self::assertStringContainsString(
                'queryInt(VendorUrls::PAGE_PARAM)',
                $body,
                $reader . ' reads the page number from the shared constant'
            );
        }
        foreach ([
            '/Product/Presentation/ProductListView.php' => '$urls->productsList(',
            '/Order/Presentation/VendorOrdersView.php' => 'VendorUrls::withPage(',
        ] as $writer => $call) {
            $body = (string) file_get_contents($root . $writer);
            self::assertStringContainsString($call, $body, $writer . ' builds its pager link through VendorUrls');
        }
    }
}
