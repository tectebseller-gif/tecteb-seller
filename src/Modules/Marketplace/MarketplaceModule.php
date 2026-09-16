<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Marketplace\Application\ActionQueue;
use Tecteb\Marketplace\Modules\Marketplace\Application\EngagementRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageCoupons;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageReviews;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageTickets;
use Tecteb\Marketplace\Modules\Marketplace\Application\ManageWholesale;
use Tecteb\Marketplace\Modules\Marketplace\Application\NotificationRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\ProductReviewGatewayInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\RatingRepositoryInterface;
use Tecteb\Marketplace\Modules\Marketplace\Application\Notify;
use Tecteb\Marketplace\Modules\Marketplace\Application\Reports;
use Tecteb\Marketplace\Modules\Marketplace\Application\AttachTicketFile;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\DbEngagementRepository;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\DbNotificationRepository;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\DbRatingRepository;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\CartPricing;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\NullProductReviewGateway;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\PurchaseOnlyReviews;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\RatingStorefront;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\WcProductReviewGateway;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\WholesaleStorefront;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress\NoticeArea;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress\ReviewArea;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress\SupportArea;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WordPress\TicketFileRoute;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin\ReportsPage;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin\ReviewsPage;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin\TicketsPage;
use Tecteb\Marketplace\Modules\Marketplace\Presentation\Admin\WholesalePage;
use Tecteb\Marketplace\Modules\Order\Application\BuyerVerifierInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaExtensions;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaOutcome;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorAreaView;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The phase-7 items: a shop's own discount codes, wholesale buyers and their
 * ladders, the vendor↔marketplace ticket, and the action queue both dashboards
 * are built from.
 *
 * Not self-gated, unlike the order module, and the difference is worth stating:
 * nothing here can accept money or move stock. A coupon that cannot be applied
 * because the order module is shut is simply a code sitting in a table, and a
 * ticket is a conversation. So these screens stay available on a day the
 * financial decisions are still open — which is most days, so far.
 */
final class MarketplaceModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'marketplace',
            $this->version(),
            'کوپن، عمده‌فروشی و پشتیبانی',
            ModuleKind::Operational,
            ['core', 'vendor', 'product'],
            // Not WooCommerce's: a discount code, a wholesale approval and a
            // support thread are all rows and conversations. They stay usable
            // on a site where WooCommerce is not running.
            false,
            'کد تخفیف فروشنده، خریدار عمده و قیمت پلکانی، تیکت فروشنده–مدیریت و صف اقدام مشترک — کد تخفیف سراسری تا DEC-04 ساخته نمی‌شود'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(EngagementRepositoryInterface::class, static fn (ContainerInterface $c) => new DbEngagementRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ManageCoupons::class, static fn (ContainerInterface $c) => new ManageCoupons(
            $c->get(EngagementRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ManageWholesale::class, static fn (ContainerInterface $c) => new ManageWholesale(
            $c->get(EngagementRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ManageTickets::class, static fn (ContainerInterface $c) => new ManageTickets(
            $c->get(EngagementRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(CapabilityCheckerInterface::class),
            $c->get(Notify::class)
        ));
        $c->bind(ActionQueue::class, static fn (ContainerInterface $c) => new ActionQueue($c));
        $c->bind(Reports::class, static fn (ContainerInterface $c) => new Reports($c));
        $c->bind(NotificationRepositoryInterface::class, static fn (ContainerInterface $c) => new DbNotificationRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(Notify::class, static fn (ContainerInterface $c) => new Notify(
            $c->get(NotificationRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(RatingRepositoryInterface::class, static fn (ContainerInterface $c) => new DbRatingRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        // Which gateway is decided ONCE, here, by whether WooCommerce is
        // running — not by a `function_exists()` inside each caller. A screen
        // asks `isAvailable()` and gets an honest «خوانده نشد» instead of a
        // zero it would otherwise print as a fact.
        $c->bind(ProductReviewGatewayInterface::class, static fn (ContainerInterface $c) =>
            (class_exists('WooCommerce', false) || function_exists('WC'))
                ? new WcProductReviewGateway($c->get(ProductRepositoryInterface::class))
                : new NullProductReviewGateway());
        $c->bind(ManageReviews::class, static fn (ContainerInterface $c) => new ManageReviews(
            $c->get(RatingRepositoryInterface::class),
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(BuyerVerifierInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(CapabilityCheckerInterface::class),
            $c->get(ProductReviewGatewayInterface::class),
            $c->get(Notify::class)
        ));
        $c->bind(AttachTicketFile::class, static fn (ContainerInterface $c) => new AttachTicketFile(
            $c->get(NotificationRepositoryInterface::class),
            $c->get(EngagementRepositoryInterface::class),
            $c->get(ManageTickets::class),
            $c->get(PrivateFileStorageInterface::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class)
        ));
    }

    public function boot(ContainerInterface $c): void
    {
        $tickets = new TicketsPage($c);
        $wholesale = new WholesalePage($c);
        $reports = new ReportsPage($c);
        $reviewsPage = new ReviewsPage($c);
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($tickets, $wholesale, $reports, $reviewsPage): array {
            $pages[] = [
                'slug' => TicketsPage::SLUG,
                'page_title' => TicketsPage::menuLabel(),
                'menu_label' => TicketsPage::menuLabel(),
                'capability' => TicketsPage::CAPABILITY,
                'render' => [$tickets, 'render'],
            ];
            $pages[] = [
                'slug' => ReportsPage::SLUG,
                'page_title' => ReportsPage::menuLabel(),
                'menu_label' => ReportsPage::menuLabel(),
                'capability' => ReportsPage::CAPABILITY,
                'render' => [$reports, 'render'],
            ];
            $pages[] = [
                'slug' => ReviewsPage::SLUG,
                'page_title' => ReviewsPage::menuLabel(),
                'menu_label' => ReviewsPage::menuLabel(),
                'capability' => ReviewsPage::CAPABILITY,
                'render' => [$reviewsPage, 'render'],
            ];
            $pages[] = [
                'slug' => WholesalePage::SLUG,
                'page_title' => WholesalePage::menuLabel(),
                'menu_label' => WholesalePage::menuLabel(),
                'capability' => WholesalePage::CAPABILITY,
                'render' => [$wholesale, 'render'],
            ];
            return $pages;
        });

        // The one thing that turns a coupon row and a price ladder into money
        // in a real basket. Registered only when WooCommerce is actually
        // running: without a cart there is nothing to price.
        // The one door a ticket attachment leaves the server through. Not
        // behind the WooCommerce check below: a support conversation is not a
        // purchase and works on a site where WooCommerce is not running.
        TicketFileRoute::register($c);

        if (class_exists('WooCommerce', false) || function_exists('WC')) {
            CartPricing::register($c);
            // …and the two screens a BUYER needs for the same two features:
            // where to ask to buy wholesale, and where to see the ladder once
            // the manager has said yes.
            WholesaleStorefront::register($c);
            // Reviews: the rule about who may write one, and the two places a
            // shopper meets a shop's standing. Both inside the WooCommerce
            // check, because a product review IS a WooCommerce review and a
            // purchase cannot be proved without orders.
            PurchaseOnlyReviews::register($c);
            RatingStorefront::register($c);
        }

        $area = new SupportArea($c);
        $notices = new NoticeArea($c);
        $reviewArea = new ReviewArea($c);
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($reviewArea): array {
            $views[] = [
                'slug' => ReviewArea::SLUG,
                'label' => __('نظرات', 'tecteb-marketplace-core'),
                'title' => __('نظرها و امتیازها', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $reviewArea->render($view),
                'actions' => ReviewArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $reviewArea->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $reviewArea->reviewsUrl(),
                'nav' => true,
            ];
            return $views;
        });
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($notices): array {
            $views[] = [
                'slug' => NoticeArea::SLUG,
                'label' => __('اطلاعیه و گزارش', 'tecteb-marketplace-core'),
                'title' => __('اطلاعیه‌ها و گزارش‌ها', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $notices->render($view),
                'actions' => NoticeArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $notices->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $notices->noticesUrl(),
                'nav' => true,
            ];
            return $views;
        });
        add_filter(VendorAreaExtensions::FILTER, static function (array $views) use ($area): array {
            $views[] = [
                'slug' => SupportArea::SLUG,
                'label' => __('پشتیبانی', 'tecteb-marketplace-core'),
                'title' => __('پشتیبانی و کد تخفیف', 'tecteb-marketplace-core'),
                'requires_vendor' => true,
                'render' => static fn (VendorAreaView $view): string => $area->render($view),
                'actions' => SupportArea::ACTIONS,
                'handle' => static fn (string $action, Request $request, int $userId, VendorUrls $urls): ?VendorAreaOutcome
                    => $area->handle($action, $request, $userId, $urls),
                'url' => static fn (VendorUrls $urls): string => $area->supportUrl(),
                'nav' => true,
            ];
            return $views;
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
