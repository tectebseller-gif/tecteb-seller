<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Presentation\VendorOrdersView;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductListView;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorRoutes;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;
use TmcWpStubs\State;

/**
 * THE VENDOR AREA'S PAGER ADDRESS.
 *
 * The owner's report: the first page of `/vendor/products/` renders, and
 * «صفحه بعد» answers 404 — on the shop with 184 products, so 164 of them had
 * no reachable address at all.
 *
 * The cause is not in this plugin's routing. It is that the pager borrowed
 * `paged`, which is one of WordPress's own PUBLIC query vars, and therefore
 * lands in the MAIN query of a request whose rewrite rule
 * (`^vendor/([a-z\-]+)/?$` → `index.php?tmc_vendor=…`) names no post. Two
 * pieces of core then act, both before this plugin's `template_redirect`
 * handler ever runs — so no amount of care inside `VendorRoutes` can undo
 * them:
 *
 *  1. `wp-includes/class-wp.php::handle_404()`, read at 6.8. `$set_404`
 *     starts TRUE and only three branches clear it: admin/robots/favicon,
 *     «posts were found», and `! is_paged()` (which is where `is_home()`
 *     lives). A paged home query that found nothing matches none of them, and
 *     core says so in its own comment: «We will 404 for paged queries, as no
 *     posts were found.» Page one works for exactly one reason — it is not
 *     paged, so the `is_home()` branch is reached.
 *
 *  2. `wp-includes/canonical.php::redirect_canonical()`. INSIDE the
 *     `if ( is_404() )` block sits `elseif ( get_query_var('paged') || … )`,
 *     which strips `paged` from the query string and appends
 *     `<pagination_base>/<paged>` to the PATH. So the 404 is followed by a
 *     301 to `/vendor/products/page/2/` — an address no vendor rewrite rule
 *     matches, which is the theme's 404 page the owner actually saw.
 *
 * This file therefore models those two rules once, asserts the model in BOTH
 * directions so it cannot quietly decay into «always 200», and then measures
 * the rendered pager against it. The fix is to stop borrowing the core var:
 * `VendorUrls::PAGE_PARAM`, the same decision `StorePage::PAGE_VAR` already
 * made for the public store page («Its own var, so it survives a rewrite»).
 *
 * ⛔ Not run: a real WordPress. This environment has none — `wordpress.org`
 * answers 403 — so what is measured here is core's RULE, ported with the file
 * and function named above, not core itself.
 */
final class VendorPagerAddressTest extends ContractTestCase
{
    private function urls(): VendorUrls
    {
        return new VendorUrls(
            'https://example.test/vendor/',
            'https://example.test/vendor/application/',
            'https://example.test/vendor/store/',
            'https://example.test/vendor/staff/',
            'https://example.test/vendor/invite/',
            'https://example.test/vendor/products/'
        );
    }

    private function product(int $id = 5, string $title = 'دستکش لاتکس'): Product
    {
        return new Product(
            $id,
            21,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 200000, sku: 'SKU-1', stock: 4),
            ProductStatus::Draft,
            [],
            [],
            0,
            ''
        );
    }

    /**
     * WordPress's answer to a vendor-area URL, by the two rules above.
     *
     * The main query here is the blog's own, because the rewrite names no
     * post: so «were posts found?» is «does the blog have a page $paged», and
     * the site this round is about has one page of posts at most. That is the
     * conservative reading and the one that matches the owner's site.
     *
     * @param array<string,string> $query
     * @return array{status:int,redirect:?string}
     */
    private static function wordPressWouldAnswer(string $path, array $query): array
    {
        $paged = (int) ($query['paged'] ?? 0);
        $isPaged = $paged > 1;                       // WP_Query::parse_query
        $postsFound = !$isPaged;                     // nothing on page two

        // class-wp.php::handle_404()
        $set404 = true;
        if ($postsFound) {
            $set404 = false;                         // «posts were found»
        } elseif (!$isPaged) {
            $set404 = false;                         // is_home()
        }
        if (!$set404) {
            return ['status' => 200, 'redirect' => null];
        }

        // canonical.php::redirect_canonical(), the paged branch of is_404()
        if ($paged > 1) {
            return ['status' => 404, 'redirect' => rtrim($path, '/') . '/page/' . $paged . '/'];
        }
        return ['status' => 404, 'redirect' => null];
    }

    /** @return array{path:string,query:array<string,string>} */
    private static function addressOf(string $href): array
    {
        $href = html_entity_decode($href, ENT_QUOTES);
        $query = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string,string> $query */
        return ['path' => (string) parse_url($href, PHP_URL_PATH), 'query' => $query];
    }

    /** @return list<string> every `href` in the pager nav */
    private static function pagerLinks(string $html): array
    {
        if (preg_match('#<nav class="tv-pager".*?</nav>#s', $html, $nav) !== 1) {
            self::fail('the pager was not rendered');
        }
        preg_match_all('#href="([^"]+)"#', $nav[0], $found);
        return $found[1];
    }

    /**
     * WHAT THIS PROVES: the model is honest in both directions. Without this,
     * a model that returned 200 for everything would make every other
     * assertion here pass while the owner's 404 stayed exactly where it was.
     */
    public function testCorePagedIs404AndRedirectsWhileAnOwnParameterIsNot(): void
    {
        $borrowed = self::wordPressWouldAnswer('/vendor/products/', ['paged' => '2']);
        self::assertSame(404, $borrowed['status'], 'core 404s a paged query that found no posts');
        self::assertSame('/vendor/products/page/2/', $borrowed['redirect'], 'and canonical moves the page number into the path');

        $own = self::wordPressWouldAnswer('/vendor/products/', [VendorUrls::PAGE_PARAM => '2']);
        self::assertSame(200, $own['status'], 'a parameter core does not know is not in the main query');
        self::assertNull($own['redirect']);

        // Page one is not paged, which is the whole reason it worked.
        self::assertSame(200, self::wordPressWouldAnswer('/vendor/products/', ['paged' => '1'])['status']);
    }

    /**
     * WHAT THIS PROVES: the address canonical would redirect to matches none
     * of this plugin's rewrite rules — so the redirect lands on the theme's
     * 404 and the page is unreachable, not merely mis-statused.
     *
     * Read off the rules the plugin actually registers, not a copy of them.
     */
    public function testNoVendorRewriteRuleMatchesThePagedPathCanonicalWouldBuild(): void
    {
        VendorRoutes::addRewriteRules();
        $rules = array_keys(State::$rewriteRules);
        self::assertNotSame([], $rules, 'the vendor rewrite rules were registered');

        $matches = static function (array $rules, string $path): bool {
            foreach ($rules as $pattern) {
                if (preg_match('#' . $pattern . '#', $path) === 1) {
                    return true;
                }
            }
            return false;
        };
        self::assertTrue($matches($rules, 'vendor/products/'), 'the list itself is routed');
        self::assertFalse(
            $matches($rules, 'vendor/products/page/2/'),
            'nothing routes the path canonical builds, so the 301 ends at the theme 404'
        );
    }

    /**
     * WHAT THIS PROVES: every link the product pager prints is an address
     * WordPress answers with 200. RED on `alpha.106`, where the link carried
     * `paged`.
     */
    public function testEveryProductPagerLinkIsAnAddressWordPressAnswers(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product()],
            ['draft' => 60],
            '',
            2,
            60,
            $this->urls(),
            '<input type="hidden" name="tmc_vendor_nonce" value="n">'
        );
        $links = self::pagerLinks($html);
        self::assertCount(2, $links, 'page two of three offers a previous and a next page');
        foreach ($links as $href) {
            $address = self::addressOf($href);
            self::assertArrayNotHasKey('paged', $address['query'], 'the pager must not borrow core\'s var: ' . $href);
            $answer = self::wordPressWouldAnswer($address['path'], $address['query']);
            self::assertSame(200, $answer['status'], 'WordPress would 404 this pager link: ' . $href);
            self::assertNull($answer['redirect'], 'and would redirect it: ' . $href);
        }
    }

    /**
     * WHAT THIS PROVES: paging a search keeps the search, and paging a status
     * filter keeps the filter. RED on `alpha.106`: the link was built from
     * `productsInStatus($status)` alone, so page two of a Persian search
     * showed the unsearched list — «جست‌وجو، فیلترها و صفحه‌بندی در ترکیب با
     * یکدیگر».
     */
    public function testPagingKeepsTheSearchAndTheStatusFilter(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product()],
            ['draft' => 60],
            'draft',
            2,
            60,
            $this->urls(),
            '<input type="hidden" name="tmc_vendor_nonce" value="n">',
            null,
            true,
            false,
            'چسب تزریق'
        );
        foreach (self::pagerLinks($html) as $href) {
            $query = self::addressOf($href)['query'];
            self::assertSame('draft', $query['status'] ?? '', 'the status filter travels with the page: ' . $href);
            self::assertSame('چسب تزریق', $query['q'] ?? '', 'and so does the search term: ' . $href);
        }
    }

    /**
     * WHAT THIS PROVES: page one has one address — no page parameter at all —
     * so a bookmark, the back button and the search form all land on the same
     * URL (`alpha.32`'s rule, kept here).
     */
    public function testPageOneCarriesNoPageParameter(): void
    {
        $this->bootPlugin(false);
        $html = ProductListView::render(
            [$this->product()],
            ['draft' => 60],
            '',
            2,
            60,
            $this->urls(),
            '<input type="hidden" name="tmc_vendor_nonce" value="n">'
        );
        $previous = self::addressOf(self::pagerLinks($html)[0]);
        self::assertArrayNotHasKey(VendorUrls::PAGE_PARAM, $previous['query'], 'back to page one is the bare address');
        self::assertArrayNotHasKey('paged', $previous['query']);
    }

    /**
     * WHAT THIS PROVES: the orders list in the same panel had the same defect
     * and is fixed by the same parameter. It was never reported — the owner
     * found the products list first — and leaving it would have left a second
     * page of order lines unreachable.
     */
    public function testEveryOrdersPagerLinkIsAnAddressWordPressAnswers(): void
    {
        $this->bootPlugin(false);
        $item = new VendorOrderItem(1, 500, 7, 9, 21, 'قلم سفارش', 'SKU-1', 1, 1000, 1000);
        $html = VendorOrdersView::render(
            [$item],
            ['placed' => 60],
            '',
            2,
            60,
            [],
            [],
            [],
            'https://example.test/vendor/orders/',
            '<input type="hidden" name="tmc_vendor_nonce" value="n">'
        );
        $links = self::pagerLinks($html);
        self::assertCount(2, $links);
        foreach ($links as $href) {
            $address = self::addressOf($href);
            self::assertArrayNotHasKey('paged', $address['query'], 'orders pager link: ' . $href);
            self::assertSame(200, self::wordPressWouldAnswer($address['path'], $address['query'])['status'], $href);
        }
    }
}
