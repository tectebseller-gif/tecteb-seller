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
use Tecteb\Marketplace\Contracts\Html\HtmlSanitizerInterface;
use Tecteb\Marketplace\Core\Events\EventBus;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ManageVariations;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeen;
use Tecteb\Marketplace\Modules\Product\Application\ReviewSeenStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontStop;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontSwitch;
use Tecteb\Marketplace\Modules\Product\Application\UnpaidOrderGuardInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\VariationRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductDecisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbReviewSeenStore;
use Tecteb\Marketplace\Modules\Product\Infrastructure\TitleSortRepair;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\AliasingSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbVariationRepository;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\CategorySuggest;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductAutosave;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductHooks;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductDraftStore;
use Tecteb\Marketplace\Modules\Product\Presentation\Admin\StorefrontPage;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullCatalogProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullProductCategoryDirectory;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WpProductCategoryDirectory;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\CartGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\PurchaseGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\NullUnpaidOrderGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WcUnpaidOrderGuard;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceProjector;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceStorefrontFields;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\WpProductImages;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;

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
        $c->bind(ProductDraftStoreInterface::class, static fn (ContainerInterface $c) => new WpProductDraftStore(
            $c->get(ClockInterface::class)
        ));
        // Which submission each manager has already looked at. A row per
        // (manager, product) in `tmc_review_seen`, and the count is a single
        // bounded query — `alpha.36` kept the whole set in one user-meta array
        // and a 500-mark cap made a read notification come back, which is the
        // defect this round closes. See `DbReviewSeenStore`.
        $c->bind(ReviewSeenStoreInterface::class, static fn (ContainerInterface $c) => new DbReviewSeenStore(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ReviewSeen::class, static fn (ContainerInterface $c) => new ReviewSeen(
            $c->get(ReviewSeenStoreInterface::class)
        ));
        // The host's own upload ceiling, not ours: PHP enforces
        // `upload_max_filesize` before this plugin runs, so a policy that did
        // not know about it would promise a size the server refuses.
        $c->bind(ProductImagePolicy::class, static fn () => new ProductImagePolicy(
            function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0
        ));
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
        // Keeps the sort key honest after a build that did not maintain it —
        // which is what a rollback to `alpha.32` and back again leaves behind.
        $c->bind(TitleSortRepair::class, static fn (ContainerInterface $c) => new TitleSortRepair(
            $c->get(DatabaseInterface::class),
            $c->get(OptionStoreInterface::class)
        ));
        $c->bind(ProductDecisionRepositoryInterface::class, static fn (ContainerInterface $c) => new DbProductDecisionRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        // Wrapped, so a template keyed by the old word and a product carrying
        // the new term id still find each other. The directory is resolved
        // through a closure because it, in turn, wants to know which
        // categories have a template.
        $c->bind(SpecTemplateRepositoryInterface::class, static fn (ContainerInterface $c) => new AliasingSpecTemplateRepository(
            new DbSpecTemplateRepository(
                $c->get(DatabaseInterface::class),
                $c->get(ClockInterface::class)
            ),
            static fn (): ProductCategoryDirectoryInterface => $c->get(ProductCategoryDirectoryInterface::class)
        ));
        // Categories are WooCommerce's, so the directory only exists when
        // WooCommerce does. Off a storefront it answers «nothing», which is
        // honest: there is no taxonomy to read.
        $c->bind(ProductCategoryDirectoryInterface::class, static function (ContainerInterface $c) {
            return $c->get(DependencyProbeInterface::class)->woocommerceAvailable()
                ? new WpProductCategoryDirectory(new DbSpecTemplateRepository(
                    $c->get(DatabaseInterface::class),
                    $c->get(ClockInterface::class)
                ))
                : new NullProductCategoryDirectory();
        });
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
                ? new WooCommerceProjector(
                    $c->get(SpecTemplateRepositoryInterface::class),
                    $c->get(ProductCategoryDirectoryInterface::class)
                )
                : new NullCatalogProjector();
        });
        // The read side of the projection, and the two ways to settle a field
        // the manager and the vendor both wrote. Bound only when WooCommerce
        // is there: without it there is no storefront to disagree with, and a
        // null that answered «no difference» would be a lie by omission.
        $c->bind(StorefrontFieldsInterface::class, static function (ContainerInterface $c): ?StorefrontFieldsInterface {
            $projector = $c->get(CatalogProjectorInterface::class);
            return $projector instanceof WooCommerceProjector
                ? new WooCommerceStorefrontFields($projector)
                : null;
        });
        $c->bind(StorefrontSwitch::class, static fn (ContainerInterface $c) => new StorefrontSwitch(
            $c->get(OptionStoreInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(PurchasePolicy::class, static fn (ContainerInterface $c) => new PurchasePolicy(
            $c->get(ProductRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(OrderOperationsGate::class),
            $c->get(StorefrontSwitch::class),
            $c->get(StoreRepositoryInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(SyncCatalog::class, static fn (ContainerInterface $c) => new SyncCatalog(
            $c->get(ProductRepositoryInterface::class),
            $c->get(VariationRepositoryInterface::class),
            $c->get(CatalogProjectorInterface::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(UnpaidOrderGuardInterface::class, static function (ContainerInterface $c) {
            return $c->get(DependencyProbeInterface::class)->woocommerceAvailable()
                ? new WcUnpaidOrderGuard()
                : new NullUnpaidOrderGuard();
        });
        $c->bind(StorefrontStop::class, static fn (ContainerInterface $c) => new StorefrontStop(
            $c->get(ProductRepositoryInterface::class),
            $c->get(SyncCatalog::class),
            $c->get(ProductReadiness::class),
            $c->get(StaffAccess::class),
            $c->get(StorefrontSwitch::class),
            $c->get(AuditLogger::class),
            $c->get(UnpaidOrderGuardInterface::class)
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
            $c->get(AuditLogger::class),
            $c->get(ProductDecisionRepositoryInterface::class)
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
            $c->get(CapabilityCheckerInterface::class),
            $c->get(StorefrontFieldsInterface::class),
            $c->get(ProductDecisionRepositoryInterface::class)
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
            $c->get(AuditLogger::class),
            $c->get(HtmlSanitizerInterface::class)
        ));
        $c->bind(EstimateVendorShare::class, static fn (ContainerInterface $c) => new EstimateVendorShare(
            $c->get(ResolveCommissionRate::class),
            $c->get(CommissionCalculator::class)
        ));
    }

    public function boot(ContainerInterface $container): void
    {
        ProductHooks::register($container);
        // The autosave endpoint, so a closed tab does not cost somebody an
        // afternoon. `wp_ajax_` only — no `nopriv` variant — so a logged-out
        // caller is refused by WordPress before any of this runs.
        (new ProductAutosave($container))->register();
        // And the category suggestions the picker asks for while somebody
        // types. Same door, same rule, and it writes nothing at all.
        (new CategorySuggest($container))->register();
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
        $this->repairTitleSort($container);
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
     * One bounded pass of the sort-key repair per wp-admin request.
     *
     * Priority 20, so the upgrade gate at priority 5 has already had its turn:
     * on a site coming from `alpha.32` the column is created by the migration
     * in the same request, and asking about it first would answer «not there».
     *
     * `admin_init` and not `init`, because the only reader of `title_sort` is
     * the manager's product list in wp-admin — a shopper must not pay for this.
     * AJAX and the REST route are excluded for the same reason: `admin_init`
     * fires on `admin-ajax.php` too, and the autosave endpoint is on a path
     * somebody is waiting on keystroke by keystroke.
     */
    private function repairTitleSort(ContainerInterface $container): void
    {
        if (!is_admin()) {
            return;
        }
        add_action('admin_init', static function () use ($container): void {
            if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
                return;
            }
            try {
                $container->get(TitleSortRepair::class)->run();
            } catch (\Throwable) {
                // Same rule as the migration gate above it: wp-admin must load.
                // A key that stays stale puts a handful of rows in the wrong
                // place; a fatal here would take the whole screen.
            }
        }, 20);
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
