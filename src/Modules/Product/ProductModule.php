<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Events\EventBus;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductHooks;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\StorefrontPage;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullCatalogProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\CartGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\PurchaseGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * The vendor's catalogue: the four-step form, the manager's review queue, the
 * category templates and CSV (Master Spec §§5–6, gate 4).
 *
 * Declares `finance` as a dependency because step 4 shows the vendor's
 * estimated share, and that number must come from the one calculator the
 * ledger uses. It does NOT require WooCommerce: a product here is the
 * marketplace's own record, and the WooCommerce projection belongs to the
 * order stage, which is where WooCommerce actually becomes the source of
 * truth.
 */
final class ProductModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'product',
            $this->version(),
            'محصولات',
            ModuleKind::Operational,
            ['core', 'environment-guard', 'admin', 'vendor', 'finance'],
            false,
            'ثبت و ویرایش محصول، تأیید انتشار، موجودی، تصویر، فیلدهای پویای دسته و CSV'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(ProductStateMachine::class, static fn () => new ProductStateMachine());
        $c->bind(ProductImagePolicy::class, static fn () => new ProductImagePolicy());
        $c->bind(ProductImageLibraryInterface::class, static fn (ContainerInterface $c) => new WpProductImages(
            $c->get(ProductImagePolicy::class)
        ));
        $c->bind(ProductPublishPolicy::class, static fn (ContainerInterface $c) => new ProductPublishPolicy(
            $c->get(OptionStoreInterface::class)
        ));
        $c->bind(ProductRepositoryInterface::class, static fn (ContainerInterface $c) => new DbProductRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(SpecTemplateRepositoryInterface::class, static fn (ContainerInterface $c) => new DbSpecTemplateRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ProductRevisionRepositoryInterface::class, static fn (ContainerInterface $c) => new DbProductRevisionRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(VariationRepositoryInterface::class, static fn (ContainerInterface $c) => new DbVariationRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ProductReadiness::class, static fn (ContainerInterface $c) => new ProductReadiness(
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(VariationRepositoryInterface::class)
        ));
        $c->bind(ManageVariations::class, static fn (ContainerInterface $c) => new ManageVariations(
            $c->get(ProductRepositoryInterface::class),
            $c->get(VariationRepositoryInterface::class),
            $c->get(ProductImageLibraryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class)
        ));
        // Which projector is bound is decided by whether WooCommerce is
        // actually running, not by a setting: a marketplace that thinks it
        // has a storefront and has not is worse than one that says so.
        $c->bind(CatalogProjectorInterface::class, static function (ContainerInterface $c) {
            $probe = $c->get(DependencyProbeInterface::class);
            return $probe->woocommerceAvailable()
                ? new WooCommerceProjector($c->get(SpecTemplateRepositoryInterface::class))
                : new NullCatalogProjector();
        });
        $c->bind(StorefrontSwitch::class, static fn (ContainerInterface $c) => new StorefrontSwitch(
            $c->get(OptionStoreInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(PurchasePolicy::class, static fn (ContainerInterface $c) => new PurchasePolicy(
            $c->get(ProductRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(OrderOperationsGate::class),
            $c->get(StorefrontSwitch::class)
        ));
        $c->bind(SyncCatalog::class, static fn (ContainerInterface $c) => new SyncCatalog(
            $c->get(ProductRepositoryInterface::class),
            $c->get(VariationRepositoryInterface::class),
            $c->get(CatalogProjectorInterface::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(StorefrontStop::class, static fn (ContainerInterface $c) => new StorefrontStop(
            $c->get(ProductRepositoryInterface::class),
            $c->get(SyncCatalog::class),
            $c->get(ProductReadiness::class),
            $c->get(StaffAccess::class),
            $c->get(StorefrontSwitch::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(ManageProducts::class, static fn (ContainerInterface $c) => new ManageProducts(
            $c->get(ProductRepositoryInterface::class),
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(ProductRevisionRepositoryInterface::class),
            $c->get(ProductReadiness::class),
            $c->get(SyncCatalog::class),
            $c->get(ProductImageLibraryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(ProductPublishPolicy::class),
            $c->get(ProductStateMachine::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(ReviewProducts::class, static fn (ContainerInterface $c) => new ReviewProducts(
            $c->get(ProductRepositoryInterface::class),
            $c->get(ProductRevisionRepositoryInterface::class),
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(ProductReadiness::class),
            $c->get(SyncCatalog::class),
            $c->get(ProductPublishPolicy::class),
            $c->get(ProductStateMachine::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ConfigureSpecTemplates::class, static fn (ContainerInterface $c) => new ConfigureSpecTemplates(
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ProductCsv::class, static fn (ContainerInterface $c) => new ProductCsv(
            $c->get(ProductRepositoryInterface::class),
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(ManageProducts::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(EstimateVendorShare::class, static fn (ContainerInterface $c) => new EstimateVendorShare(
            $c->get(ResolveCommissionRate::class),
            $c->get(CommissionCalculator::class)
        ));
    }

    public function boot(ContainerInterface $container): void
    {
        ProductHooks::register($container);
        $storefront = new StorefrontPage($container);
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($storefront): array {
            $pages[] = [
                'slug' => StorefrontPage::SLUG,
                'page_title' => StorefrontPage::menuLabel(),
                'menu_label' => StorefrontPage::menuLabel(),
                'capability' => StorefrontPage::CAPABILITY,
                'render' => [$storefront, 'render'],
            ];
            return $pages;
        });
        $this->followVendorStatus($container);
        // Registered whatever the order module's own state is: "the
        // marketplace may not sell" has to be enforceable exactly when the
        // order module is not there to enforce it.
        if ($container->get(DependencyProbeInterface::class)->woocommerceAvailable()) {
            PurchaseGuard::register($container);
            // The other three doors into a purchase: a basket filled before
            // the stop, the block checkout's Store API, and the pay link of
            // an order that was never paid.
            CartGuard::register($container);
        }
    }

    /**
     * Suspending a shop takes its products out of the storefront, and
     * reinstating it puts the live ones back.
     *
     * Through the bus rather than a direct call, because the vendor module
     * must not learn what a product is — and because the rule then holds
     * wherever the suspension happens: the admin screen, WP-CLI, or a test.
     */
    private function followVendorStatus(ContainerInterface $container): void
    {
        $events = $container->get(EventBus::class);
        $events->on(EventBus::VENDOR_SUSPENDED, static function (array $payload) use ($container): void {
            $vendorUserId = (int) ($payload['vendor_user_id'] ?? 0);
            if ($vendorUserId > 0) {
                $container->get(SyncCatalog::class)->withdrawVendor($vendorUserId, (string) ($payload['reason'] ?? ''));
            }
        });
        $events->on(EventBus::VENDOR_REINSTATED, static function (array $payload) use ($container): void {
            $vendorUserId = (int) ($payload['vendor_user_id'] ?? 0);
            if ($vendorUserId <= 0) {
                return;
            }
            // Reinstating a shop is permission to trade, not a blanket
            // republish: each product still has to qualify on its own, and a
            // marketplace whose selling is stopped republishes nothing.
            $container->get(SyncCatalog::class)->republishVendor(
                $vendorUserId,
                $container->get(StorefrontStop::class)
            );
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
