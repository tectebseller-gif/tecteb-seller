<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * The vendor area's addresses. Built once by the router, which knows whether
 * the site has pretty permalinks, so no view ever has to guess.
 */
final class VendorUrls
{
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
