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

    public static function register(ContainerInterface $container): void
    {
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('template_redirect', static function () use ($container): void {
            self::render($container);
        });
    }

    /** The public URL of one shop's page. */
    public static function url(int $vendorUserId): string
    {
        return add_query_arg(self::QUERY_VAR, (string) $vendorUserId, home_url('/'));
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
        $products = [];
        foreach ($container->get(ProductRepositoryInterface::class)->allForVendor($vendorUserId) as $product) {
            // Published AND projected: a product with no WooCommerce id has no
            // page to link to, and a link to nothing is worse than an omission.
            if ($product->status === ProductStatus::Published && (int) ($product->wcProductId ?? 0) > 0) {
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
            self::url($vendorUserId)
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
