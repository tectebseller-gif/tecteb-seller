<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;
use Tecteb\Marketplace\Modules\Vendor\Presentation\StorePageView;

/**
 * The shop's PUBLIC page — Master §7 («صفحه فروشگاه شامل هویت، معرفی، محصولات،
 * سیاست‌ها، امتیاز/نظر در صورت فعال بودن و اطلاعات تماس عمومی انتخاب‌شده») and
 * A.5 («صفحه عمومی فروشگاه سئوپذیر است و اطلاعات حساس/نشانی خصوصی را نمایش
 * نمی‌دهد»).
 *
 * **What is NOT on it is the specification.** A shop's rows hold things a
 * shopper must never see: the origin warehouse, the bank details, the
 * applicant's own address, e-mail and mobile, the documents. None of them is
 * read here — not filtered out at the end, but never fetched at all, because a
 * field that is never loaded cannot be leaked by a later template edit.
 * `StorePageView::PUBLIC_FIELDS` is the whole list of what may appear, and the
 * evidence asserts the private ones are absent from the rendered HTML.
 *
 * **Discoverable, and nothing more clever than that.** A query var on the
 * front controller, rendered server-side with a real `<title>`, a description
 * and Schema.org `Store` JSON-LD, so a crawler reads it the way a person does.
 * Deliberately NO rewrite rule: one would flush permalinks on activation and
 * leave 404s behind on deactivation, which is the rule every other screen in
 * this plugin keeps.
 *
 * **Only an APPROVED shop gets a page.** A pending applicant, a rejected one
 * and a suspended one all get the theme's own 404 — the same answer a made-up
 * id gets, because «این فروشگاه تعلیق است» on a public URL is nobody's
 * business but the marketplace's.
 */
final class StorePage
{
    /** `?tmc_store=<vendor-user-id>` — no rewrite rule, so nothing to flush. */
    public const QUERY_VAR = 'tmc_store';

    /** Which page of the catalogue. Its own var, so it survives a rewrite. */
    public const PAGE_VAR = 'tmc_store_page';

    /**
     * Products per page. Small enough that the page stays quick on a phone
     * connection, large enough that a shop of fifty is two pages rather than
     * nine.
     */
    public const PER_PAGE = 24;

    public static function register(ContainerInterface $container): void
    {
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            $vars[] = self::PAGE_VAR;
            return $vars;
        });
        add_action('template_redirect', static function () use ($container): void {
            self::render($container);
        });
    }

    /** The public URL of one shop's page. */
    public static function url(int $vendorUserId, int $page = 1): string
    {
        $url = add_query_arg(self::QUERY_VAR, (string) $vendorUserId, home_url('/'));
        return $page > 1 ? add_query_arg(self::PAGE_VAR, (string) $page, $url) : $url;
    }

    private static function render(ContainerInterface $container): void
    {
        $vendorUserId = (int) get_query_var(self::QUERY_VAR);
        if ($vendorUserId <= 0) {
            return;
        }
        $application = $container->get(VendorRepositoryInterface::class)
            ->findApplicationByUser($vendorUserId);
        if ($application === null || $application->status !== ApplicationStatus::Approved) {
            self::notFound();
            return;
        }
        $store = $container->get(StoreRepositoryInterface::class)->find($vendorUserId);
        if ($store === null) {
            self::notFound();
            return;
        }
        // Paged in SQL, not filtered in PHP. Reading a whole catalogue into
        // memory to show twenty-four of it is how a public page — the one a
        // search engine crawls repeatedly and a customer opens on a phone —
        // becomes the slowest thing on the site.
        $repository = $container->get(ProductRepositoryInterface::class);
        $page = max(1, (int) get_query_var(self::PAGE_VAR, 1));
        $total = $repository->countForVendor($vendorUserId, ProductStatus::Published);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            // A page beyond the end is a 404, not an empty shelf: an infinite
            // supply of empty pages is an infinite supply of thin URLs for a
            // crawler to index.
            self::notFound();
            return;
        }
        $products = [];
        foreach ($repository->forVendor($vendorUserId, ProductStatus::Published, self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $product) {
            // Projected too: a product with no WooCommerce id has no page to
            // link to, and a link to nothing is worse than an omission. The
            // count above may therefore exceed what is listed; the pager
            // follows the count, because an unprojected product is a transient
            // state that the next projection fixes.
            if ((int) ($product->wcProductId ?? 0) > 0) {
                $products[] = $product;
            }
        }

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        // phpcs:ignore WordPress.Security.EscapeOutput -- the view escapes.
        echo StorePageView::html(
            $vendorUserId,
            $store,
            $products,
            $container->get(ManageReviews::class)->standing($vendorUserId),
            // The canonical is THIS page, not page one. Pointing every page at
            // the first would tell a crawler that pages two and three are
            // duplicates of it, and their products would drop out of the index.
            self::url($vendorUserId, $page),
            $page,
            $pages
        );
        exit;
    }

    private static function notFound(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();
        $template = get_404_template();
        if (is_string($template) && $template !== '') {
            include $template;
            exit;
        }
    }
}
