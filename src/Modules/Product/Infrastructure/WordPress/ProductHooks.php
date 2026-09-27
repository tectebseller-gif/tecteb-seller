<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
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
                // Submissions THIS manager has not looked at yet — «اعلان قرمز
                // بر اساس دیده‌نشده». Until `alpha.35` it was the size of the
                // queue, which is the same number for everybody and only a
                // decision could change: a manager who had read every one of
                // them still had a red badge, and a second manager reading them
                // changed nothing for the first.
                //
                // Counted per PRODUCT, out of the same `WHERE` the review list
                // uses, so the badge and the list cannot say two different
                // things — and one product that is both submitted and carrying
                // a proposal is still one thing to look at.
                //
                // `get_current_user_id()` here rather than inside `ReviewSeen`:
                // «who is asking» is a WordPress question, and the Application
                // layer does not call WordPress.
                'bubble' => static fn (): int
                    => $container->get(ReviewSeen::class)->unseenCount(get_current_user_id()),
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
