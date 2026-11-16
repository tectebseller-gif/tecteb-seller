<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The vendor area's addresses. Built once by the router, which knows whether
 * the site has pretty permalinks, so no view ever has to guess.
 */
final class VendorUrls
{
    /**
     * WHICH PAGE A VENDOR-AREA LIST IS SHOWING — and deliberately NOT
     * `paged`.
     *
     * `paged` is one of WordPress's own public query vars, so on
     * `/vendor/products/?paged=2` it lands in the MAIN query of a request
     * whose rewrite rule names no post. Core then does two things, both
     * before this plugin's `template_redirect` handler runs:
     * `handle_404()` 404s it («We will 404 for paged queries, as no posts
     * were found.» — wp-includes/class-wp.php), and `redirect_canonical()`
     * 301s it to `/vendor/products/page/2/`, which no vendor rewrite rule
     * matches. That was the owner's 404 on page two of a 184-product shop.
     *
     * Its own var, so it survives a rewrite — the same decision
     * `StorePage::PAGE_VAR` already made for the public store page. One
     * constant, read by the views that print the pager AND the areas that
     * read the page number, so the two can never drift apart.
     */
    public const PAGE_PARAM = 'tmc_page';

    public function __construct(
        private readonly string $dashboard,
        private readonly string $application,
        private readonly string $store = '',
        private readonly string $staff = '',
        private readonly string $invite = '',
        private readonly string $products = ''
    ) {
    }

    public function dashboard(): string
    {
        return $this->dashboard;
    }

    public function application(): string
    {
        return $this->application;
    }

    public function store(): string
    {
        return $this->store;
    }

    public function staff(): string
    {
        return $this->staff;
    }

    public function products(): string
    {
        return $this->products;
    }

    /** One product's form. `0` is the "new product" form. */
    public function product(int $productId): string
    {
        return $productId > 0
            ? add_query_arg('product', $productId, $this->products)
            : add_query_arg('product', 'new', $this->products);
    }

    public function productsInStatus(string $status): string
    {
        return $status === '' ? $this->products : add_query_arg('status', $status, $this->products);
    }

    /**
     * The products list as a vendor is looking at it: the status tab they are
     * on, the search they typed, and which page.
     *
     * Built in one place because the three travel together. A pager that
     * carried only the status — which is what `alpha.106` printed — dropped
     * the search term, so page two of «چسب تزریق» silently showed the
     * unsearched list.
     *
     * Page one carries no page parameter at all, so the first page has ONE
     * address for a bookmark, the back button and the search form alike.
     */
    public function productsList(string $status, string $search, int $page): string
    {
        $url = $this->productsInStatus($status);
        if ($search !== '') {
            $url = add_query_arg('q', $search, $url);
        }
        return self::withPage($url, $page);
    }

    /**
     * Any vendor-area list URL, at a given page.
     *
     * Static because the orders list is handed a plain base URL rather than
     * this object, and «page one is the bare address» has to be the same rule
     * in both places or the two lists disagree about what page one looks
     * like.
     */
    public static function withPage(string $url, int $page): string
    {
        return $page > 1 ? add_query_arg(self::PAGE_PARAM, (string) $page, $url) : $url;
    }

    /** The link a vendor hands to a colleague; the token is the credential. */
    public function invite(string $token): string
    {
        return add_query_arg('token', $token, $this->invite);
    }

    public function forRoute(?string $route): string
    {
        return match ($route) {
            'application', 'submit' => $this->application,
            default => $this->dashboard,
        };
    }

    /** Download links are nonced per document: a stale or copied link dies. */
    public function document(int $documentId): string
    {
        return wp_nonce_url(add_query_arg(['tmc_doc' => $documentId], $this->dashboard), 'tmc_vendor_doc_' . $documentId);
    }

    public function withNotice(string $url, string $code): string
    {
        return add_query_arg('tmc_notice', rawurlencode($code), $url);
    }
}
