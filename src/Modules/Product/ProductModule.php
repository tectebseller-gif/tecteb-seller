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
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Product\Application\ConfigureSpecTemplates;
use Tecteb\Marketplace\Modules\Product\Application\EstimateVendorShare;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductReadiness;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStateMachine;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRevisionRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbSpecTemplateRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress\ProductHooks;
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
        $c->bind(ProductReadiness::class, static fn (ContainerInterface $c) => new ProductReadiness(
            $c->get(SpecTemplateRepositoryInterface::class)
        ));
        $c->bind(ManageProducts::class, static fn (ContainerInterface $c) => new ManageProducts(
            $c->get(ProductRepositoryInterface::class),
            $c->get(SpecTemplateRepositoryInterface::class),
            $c->get(ProductRevisionRepositoryInterface::class),
            $c->get(ProductReadiness::class),
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
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
