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
        private readonly string $application
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
