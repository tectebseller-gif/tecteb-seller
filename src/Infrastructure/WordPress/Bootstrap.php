<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\EnvironmentProbeInterface;
use Tecteb\Marketplace\Contracts\LockStoreInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Kernel;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\UpgradeGate;
use Tecteb\Marketplace\Core\Modules\LoadReport;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Modules\Admin\AdminModule;
use Tecteb\Marketplace\Modules\Health\HealthModule;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\VendorModule;

/**
 * The only place that wires WordPress into the kernel. Everything WordPress-
 * specific enters through the interfaces bound here.
 */
final class Bootstrap
{
    public const TEXT_DOMAIN = 'tecteb-marketplace-core';

    private static ?Kernel $kernel = null;
    private static bool $loaded = false;
    private static string $mainFile = '';
    private static string $version = '0.0.0';

    public static function init(string $mainFile, string $version): void
    {
        self::$mainFile = $mainFile;
        self::$version = $version;

        register_activation_hook($mainFile, [Activator::class, 'activate']);
        register_deactivation_hook($mainFile, [Deactivator::class, 'deactivate']);
        HposDeclaration::register($mainFile);
        add_action('plugins_loaded', [self::class, 'onPluginsLoaded'], 10);
    }

    public static function onPluginsLoaded(): void
    {
        if (self::$loaded) {
            return; // plugins_loaded fires once per request; guard anyway so hooks never double up
        }
        self::$loaded = true;
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(self::$mainFile)) . '/languages');
        $container = self::container();
        /** @var DependencyProbeInterface $deps */
        $deps = $container->get(DependencyProbeInterface::class);
        $wooCommerceAvailable = $deps->woocommerceAvailable();
        if (!$wooCommerceAvailable) {
            Notices::registerWooCommerceMissing();
        }
        Notices::registerActivationResult();
        self::kernel()->load($wooCommerceAvailable);

        // Updating a plugin's files does NOT re-fire its activation hook, so
        // migrations cannot depend on activation alone. Check on admin
        // requests, where a failure is visible and a long-running step is not
        // in a visitor's way. Front-end requests never migrate.
        add_action('admin_init', [self::class, 'runPendingMigrations'], 5);
    }

    /**
     * Applies a pending schema upgrade outside activation. Guarded by
     * UpgradeGate (cooldown after failure, never migrates a schema that is
     * ahead of this build) and by the owner-scoped migration lock, so
     * concurrent admin requests cannot run it twice.
     */
    public static function runPendingMigrations(): void
    {
        try {
            /** @var UpgradeGate $gate */
            $gate = self::container()->get(UpgradeGate::class);
            $gate->runIfNeeded();
        } catch (\Throwable) {
            // Never break wp-admin because of a migration attempt; the
            // outcome (including a recorded failure) is on the health page.
        }
    }

    public static function mainFile(): string
    {
        return self::$mainFile;
    }

    public static function pluginVersion(): string
    {
        return self::$version;
    }

    public static function pluginDirUrl(): string
    {
        return plugin_dir_url(self::$mainFile);
    }

    public static function pluginDirPath(): string
    {
        return plugin_dir_path(self::$mainFile);
    }

    public static function report(): ?LoadReport
    {
        return self::$kernel?->report();
    }

    public static function container(): ContainerInterface
    {
        return self::kernel()->container();
    }

    /** Test hook: forget the kernel so a fresh one is wired on the next call. */
    public static function reset(): void
    {
        self::$kernel = null;
        self::$loaded = false;
    }

    public static function kernel(): Kernel
    {
        if (self::$kernel !== null) {
            return self::$kernel;
        }
        $kernel = new Kernel();
        self::bindServices($kernel->container());
        self::registerModules($kernel);
        self::$kernel = $kernel;
        return $kernel;
    }

    private static function bindServices(ContainerInterface $c): void
    {
        $c->instance('tmc.version', new \ArrayObject(['version' => self::$version]));
        $c->bind(ClockInterface::class, static fn () => new SystemClock());
        $c->bind(OptionStoreInterface::class, static fn () => new WpOptionStore());
        $c->bind(LockStoreInterface::class, static fn () => new WpLockStore($GLOBALS['wpdb']));
        // Same adapter: the guarded writes act on the same options table and
        // must see the same lock row as the lock itself.
        $c->bind(GuardedOptionStoreInterface::class, static fn (ContainerInterface $c) => $c->get(LockStoreInterface::class));
        $c->bind(DatabaseInterface::class, static fn () => new WpDatabase($GLOBALS['wpdb']));
        $c->bind(AuditRepositoryInterface::class, static fn () => new WpAuditRepository($GLOBALS['wpdb']));
        $c->bind(CapabilityCheckerInterface::class, static fn () => new WpCapabilities());
        $c->bind(DependencyProbeInterface::class, static fn () => new WpDependencyProbe());
        $c->bind(SettingsService::class, static fn (ContainerInterface $c) => new SettingsService($c->get(OptionStoreInterface::class)));
        $c->bind(EnvironmentProbeInterface::class, static fn (ContainerInterface $c) => new WpEnvironmentProbe($c->get(SettingsService::class)));
        $c->bind(EnvironmentResolver::class, static fn (ContainerInterface $c) => new EnvironmentResolver($c->get(EnvironmentProbeInterface::class)));
        $c->bind(AuditLogger::class, static fn (ContainerInterface $c) => new AuditLogger(
            $c->get(AuditRepositoryInterface::class),
            new AuditEventSanitizer(),
            $c->get(ClockInterface::class)
        ));
        $c->bind(MigrationRunner::class, static fn (ContainerInterface $c) => new MigrationRunner(
            $c->get(DatabaseInterface::class),
            $c->get(OptionStoreInterface::class),
            $c->get(GuardedOptionStoreInterface::class),
            new MigrationLock($c->get(LockStoreInterface::class), $c->get(ClockInterface::class), MigrationLock::generateOwnerToken()),
            [new M0001CreateAuditTable(), new M0002CreateVendorTables()],
            $c->get(ClockInterface::class)
        ));
        $c->bind(UpgradeGate::class, static fn (ContainerInterface $c) => new UpgradeGate(
            $c->get(MigrationRunner::class),
            $c->get(OptionStoreInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(OtpProviderInterface::class, static fn () => new NullOtpProvider());
    }

    private static function registerModules(Kernel $kernel): void
    {
        $registry = $kernel->registry();
        $registry->add(new CoreModule());
        $registry->add(new EnvironmentGuardModule());
        $registry->add(new AdminModule());
        $registry->add(new HealthModule());
        $registry->add(new VendorModule());

        // Roadmap only (CORE-02): no code, no hooks, no activation button.
        $planned = [
            ['product', 'محصولات و فرم پزشکی', true],
            ['order', 'سفارش‌ها و ارسال', true],
            ['commission', 'کمیسیون و دفترکل', true],
            ['settlement', 'تسویه و برداشت', true],
            ['migration', 'مهاجرت از دکان', true],
        ];
        foreach ($planned as [$id, $label, $needsWc]) {
            $registry->addPlanned(new ModuleManifest($id, '0.0.0', $label, ModuleKind::Planned, ['core', 'environment-guard'], $needsWc));
        }
    }
}
