<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Migration\Application\DokanImportJob;
use Tecteb\Marketplace\Modules\Migration\Application\CategoryMap;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\OrderHistoryRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbOrderHistoryRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress\WpDokanReader;
use Tecteb\Marketplace\Modules\Migration\Presentation\Admin\MigrationPage;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;

/**
 * Migration from Dokan — read-only until a person presses a button, and
 * undoable after they do.
 *
 * It adds no tables of its own: what a trial import creates is ordinary
 * marketplace rows, and the manifest of each run lives in one option so a
 * rollback knows exactly what to remove. A migration with its own schema
 * would be a second place for the truth to live.
 */
final class MigrationModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'migration',
            $this->version(),
            'مهاجرت از دکان',
            ModuleKind::Operational,
            ['core', 'vendor', 'product'],
            false,
            'اجرای آزمایشی و تطبیق سطربه‌سطر داده‌های دکان، ورود برگشت‌پذیر، و هیچ نوشتنی روی دادهٔ دکان'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(DokanReaderInterface::class, static fn (ContainerInterface $c) => new WpDokanReader(
            $c->get(DatabaseInterface::class)
        ));
        $c->bind(TransferOwnership::class, static fn (ContainerInterface $c) => new TransferOwnership(
            $c->get(ProductRepositoryInterface::class),
            $c->get(CatalogProjectorInterface::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class),
            // So a transfer drops the remembered «is this ours» answer. The
            // product module owns the policy; if it is not loaded there is no
            // answer to forget either.
            $c->has(\Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class)
                ? $c->get(\Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class)
                : null
        ));
        $c->bind(OrderHistoryRepositoryInterface::class, static fn (ContainerInterface $c) => new DbOrderHistoryRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ShopRecordRepositoryInterface::class, static fn (ContainerInterface $c) => new DbShopRecordRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ImportFromDokan::class, static fn (ContainerInterface $c) => new ImportFromDokan(
            $c->get(DokanReaderInterface::class),
            $c->get(VendorRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(OptionStoreInterface::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(CapabilityCheckerInterface::class),
            $c->get(OrderHistoryRepositoryInterface::class),
            $c->get(ShopRecordRepositoryInterface::class),
            $c->get(LedgerRepositoryInterface::class),
            $c->get(CategoryMap::class),
            $c->has(\Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface::class)
                ? $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface::class)
                : null
        ));
        $c->bind(CategoryMap::class, static fn (ContainerInterface $c) => new CategoryMap(
            $c->get(OptionStoreInterface::class)
        ));
    }

    public function boot(ContainerInterface $c): void
    {
        // Registered on the ONE runner in the container, at module load, so the
        // cron tick later in this same request finds a handler for the type it
        // claims. A runner built at tick time would know nothing.
        $c->get(JobRunner::class)->register(new DokanImportJob($c->get(ImportFromDokan::class)));

        $page = new MigrationPage($c);
        add_filter(AdminExtensions::FILTER, static function (array $pages) use ($page): array {
            $pages[] = [
                'slug' => MigrationPage::SLUG,
                'page_title' => MigrationPage::menuLabel(),
                'menu_label' => MigrationPage::menuLabel(),
                'capability' => MigrationPage::CAPABILITY,
                'render' => [$page, 'render'],
            ];
            return $pages;
        });
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
