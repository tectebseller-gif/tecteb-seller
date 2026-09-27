<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\ProductReviewPage;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\SpecTemplatesPage;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/** Everything the product module hangs on WordPress, in one readable place. */
final class ProductHooks
{
    public static function register(ContainerInterface $container): void
    {
        $area = new ProductArea($container);
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($area): array {
            $views[] = [
                'slug' => ProductArea::SLUG,
                'label' => __('محصولات', 'tecteb-marketplace-core'),
                'title' => __('محصولات فروشگاه', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $area->render($view),
                'actions' => ProductArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $area->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $urls->products(),
                'nav' => true,
            ];
            return $views;
        });

        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($container): array {
            $review = new ProductReviewPage($container);
            $templates = new SpecTemplatesPage($container);
            $pages[] = [
                'slug' => ProductReviewPage::SLUG,
                'page_title' => ProductReviewPage::menuLabel(),
                'menu_label' => ProductReviewPage::menuLabel(),
                'capability' => ProductReviewPage::CAPABILITY,
                'render' => [$review, 'render'],
                'nav' => true,
                // Products waiting for a decision — submitted, or live with an
                // unanswered proposal. Counted from the same rows the review
                // list is built from, so the badge and the list cannot say two
                // different things, and counted per PRODUCT so one product
                // that is both is one thing to look at.
                'bubble' => static fn (): int
                    => $container->get(ProductRepositoryInterface::class)->countAwaitingReview(),
            ];
            $pages[] = [
                'slug' => SpecTemplatesPage::SLUG,
                'page_title' => SpecTemplatesPage::menuLabel(),
                'menu_label' => SpecTemplatesPage::menuLabel(),
                'capability' => SpecTemplatesPage::CAPABILITY,
                'render' => [$templates, 'render'],
                'nav' => true,
            ];
            return $pages;
        });
    }
}
