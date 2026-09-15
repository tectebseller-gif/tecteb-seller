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
use Tecteb\Marketplace\Modules\Admin\Presentation\AdminExtensions;
use Tecteb\Marketplace\Modules\Migration\Application\DokanReaderInterface;
use Tecteb\Marketplace\Modules\Migration\Application\ImportFromDokan;
use Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership;
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
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ImportFromDokan::class, static fn (ContainerInterface $c) => new ImportFromDokan(
            $c->get(DokanReaderInterface::class),
            $c->get(VendorRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(OptionStoreInterface::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
    }

    public function boot(ContainerInterface $c): void
    {
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
