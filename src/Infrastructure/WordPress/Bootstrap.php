<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\FlashStoreInterface;
use Tecteb\Marketplace\Contracts\GuardedOptionStoreInterface;
use Tecteb\Marketplace\Contracts\DependencyProbeInterface;
use Tecteb\Marketplace\Contracts\EnvironmentProbeInterface;
use Tecteb\Marketplace\Contracts\LockStoreInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Environment\EnvironmentResolver;
use Tecteb\Marketplace\Core\Events\EventBus;
use Tecteb\Marketplace\Core\Kernel;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Migration\UpgradeGate;
use Tecteb\Marketplace\Core\Modules\LoadReport;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\Otp\NullOtpProvider;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Activator;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\CapabilityInstaller;
use Tecteb\Marketplace\Infrastructure\WordPress\Lifecycle\Deactivator;
use Tecteb\Marketplace\Modules\Admin\AdminModule;
use Tecteb\Marketplace\Modules\Health\HealthModule;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\LegacyPrivateDocuments;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\PrivateUploadStorage;
use Tecteb\Marketplace\Modules\Finance\FinanceModule;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Application\TrialUnlock;
use Tecteb\Marketplace\Modules\Order\OrderModule;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables;
use Tecteb\Marketplace\Modules\Order\Application\ManageReturns;
use Tecteb\Marketplace\Modules\Order\Application\RefundRecorderInterface;
use Tecteb\Marketplace\Modules\Order\Application\BuyerVerifierInterface;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce\NullBuyerVerifier;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce\WcBuyerVerifier;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WooCommerce\WcRefundRecorder;
use Tecteb\Marketplace\Modules\Order\Application\RefundScope;
use Tecteb\Marketplace\Modules\Order\Application\ReturnTerms;
use Tecteb\Marketplace\Modules\Order\Application\ShipItems;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStateMachine;
use Tecteb\Marketplace\Modules\Product\Application\CatalogProjectorInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbShipmentRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0008ShipmentsAndReturns;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Marketplace\MarketplaceModule;
use Tecteb\Marketplace\Modules\Migration\MigrationModule;
use Tecteb\Marketplace\Modules\Operations\OperationsModule;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0009EngagementTables;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0011AttachmentsAndNotices;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\Migrations\M0012VendorRatings;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox;
use Tecteb\Marketplace\Core\Migration\Migrations\M0014RowVersionAndImportRun;
use Tecteb\Marketplace\Core\Migration\Migrations\M0015DokanOrderHistory;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\JobRunner;
use Tecteb\Marketplace\Infrastructure\Jobs\DbJobRepository;
use Tecteb\Marketplace\Modules\Product\ProductModule;
use Tecteb\Marketplace\Modules\Vendor\VendorModule;

/**
 * The only place that wires WordPress into the kernel. Everything WordPress-
 * specific enters through the interfaces bound here.
 */
final class Bootstrap
{
    public const TEXT_DOMAIN = 'tecteb-marketplace-core';

    /** Hash of the capability list this site was last brought up to. */
    public const CAPABILITY_SIGNATURE_OPTION = 'tmc_capability_signature';

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
        // AFTER the modules, because each of them registers its handlers on
        // the runner as it loads. Registering the tick first would bind a
        // runner that knows no handlers and quietly drains nothing.
        WpJobScheduler::register(static fn (): JobRunner => $container->get(JobRunner::class));
        // After the modules, because it asks one of them a question. Two
        // option reads on an admin request, and only for somebody who could
        // act on the answer.
        if (is_admin()) {
            Notices::registerStuckStorefront($container);
        }

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
        self::ensureCapabilities();
        self::relocateLegacyPrivateDocuments();
    }

    /**
     * Adds capabilities introduced by a NEWER build to the administrator role.
     *
     * Activation grants them, but replacing a plugin's files does not fire the
     * activation hook — so without this, upgrading in place would leave the
     * administrator unable to open a page the new build just added. The stored
     * signature makes it one option read per admin request once it has run,
     * and a changed list is what makes it run again.
     */
    public static function ensureCapabilities(): void
    {
        try {
            /** @var OptionStoreInterface $options */
            $options = self::container()->get(OptionStoreInterface::class);
            $signature = md5(implode(',', Capabilities::all()));
            if ((string) $options->get(self::CAPABILITY_SIGNATURE_OPTION, '') === $signature) {
                return;
            }
            if (CapabilityInstaller::install()) {
                $options->set(self::CAPABILITY_SIGNATURE_OPTION, $signature);
            }
        } catch (\Throwable) {
            // Same rule as the migration above: never break wp-admin. The
            // next admin request tries again.
        }
    }

    /**
     * Documents written by the first build sit in a directory the web server
     * can serve. Activation moves them, but replacing a plugin's files does
     * NOT fire the activation hook — the same hole UpgradeGate exists to
     * close — so the move is attempted here too, once, and then never again:
     * the option read below is the whole cost on every later request.
     */
    public static function relocateLegacyPrivateDocuments(): void
    {
        try {
            /** @var OptionStoreInterface $options */
            $options = self::container()->get(OptionStoreInterface::class);
            if ((bool) $options->get(LegacyPrivateDocuments::DONE_OPTION, false)) {
                return;
            }
            $result = LegacyPrivateDocuments::relocate(new PrivateUploadStorage());
            if ($result['skipped'] && $result['reason'] === 'no_safe_directory') {
                return;    // nothing safe to move INTO yet; try again next time
            }
            if ($result['failed'] === 0) {
                $options->set(LegacyPrivateDocuments::DONE_OPTION, true);
            }
            if ($result['moved'] > 0) {
                /** @var AuditLogger $audit */
                $audit = self::container()->get(AuditLogger::class);
                $audit->log(
                    AuditEventCatalog::VENDOR_DOCUMENTS_RELOCATED,
                    (new WpCapabilities())->currentUserId(),
                    'plugin',
                    'tecteb-marketplace-core',
                    ['moved' => $result['moved'], 'failed' => $result['failed']]
                );
            }
        } catch (\Throwable) {
            // Same rule as above: wp-admin must load even if the filesystem
            // refuses. The documents stay where they are and the vendor
            // documents screen says so.
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
        // One bus for the whole request: the container caches objects, so
        // every module subscribes to and emits on the same instance.
        $c->bind(EventBus::class, static fn () => new EventBus());
        $c->bind(OptionStoreInterface::class, static fn () => new WpOptionStore());
        $c->bind(FlashStoreInterface::class, static fn () => new TransientFlashStore());
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
            self::migrations(),
            $c->get(ClockInterface::class)
        ));
        $c->bind(UpgradeGate::class, static fn (ContainerInterface $c) => new UpgradeGate(
            $c->get(MigrationRunner::class),
            $c->get(OptionStoreInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(JobRepositoryInterface::class, static fn (ContainerInterface $c) => new DbJobRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        // One runner per request, because modules register their handlers on
        // it as they load and the cron tick — which fires later in the same
        // request — has to see all of them. A second instance would see none.
        $c->bind(JobRunner::class, static fn (ContainerInterface $c) => new JobRunner(
            $c->get(JobRepositoryInterface::class),
            static fn (): string => bin2hex(random_bytes(16))
        ));
        $c->bind(OtpProviderInterface::class, static fn () => new NullOtpProvider());
        // The order gate is wired HERE rather than inside the order module,
        // because the module is skipped entirely while it is blocked — and
        // the catalogue still has to ask "may the marketplace sell?" on every
        // add-to-cart, precisely when the answer is no.
        $c->bind(TrialUnlock::class, static fn (ContainerInterface $c) => new TrialUnlock(
            $c->get(OptionStoreInterface::class),
            $c->get(EnvironmentResolver::class)
        ));
        // The order LINES, bound here for the same reason the gate is: money
        // that was already recorded has to stay readable — by the vendor's
        // finance page, by settlement, by a report — while the order module
        // itself is blocked and never registers. Reading is not operating.
        $c->bind(OrderItemRepositoryInterface::class, static fn (ContainerInterface $c) => new DbOrderItemRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        // The parcels and the returns, bound here for the same reason: a
        // manager reviewing a return, and a vendor reading what was sent,
        // must not depend on the order module having been allowed to load.
        $c->bind(ShipmentRepositoryInterface::class, static fn (ContainerInterface $c) => new DbShipmentRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ReturnStateMachine::class, static fn () => new ReturnStateMachine());
        $c->bind(ReturnTerms::class, static fn () => new ReturnTerms());
        $c->bind(RefundScope::class, static fn () => new RefundScope());
        // Shipping what was already sold, and deciding a return that was
        // already opened, are both "finishing what the ledger recorded" rather
        // than "accepting new orders". They are bound here for the same reason
        // the order LINES are (F-15): a manager must be able to close an open
        // return, and a vendor to post the second parcel, on a day when the
        // order gate is shut again.
        $c->bind(ShipItems::class, static fn (ContainerInterface $c) => new ShipItems(
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(ShipmentRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(ManageReturns::class, static fn (ContainerInterface $c) => new ManageReturns(
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(ShipmentRepositoryInterface::class),
            $c->get(LedgerRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(ReturnStateMachine::class),
            $c->get(ReturnTerms::class),
            $c->get(RefundScope::class),
            $c->get(RefundRecorderInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(CatalogProjectorInterface::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(RefundRecorderInterface::class, static fn () => new WcRefundRecorder());
        // «Did this person buy this?» — decided once, here, by whether there is
        // a WooCommerce to ask. The null one answers «could not check», which
        // the callers are careful not to report as «did not buy».
        $c->bind(BuyerVerifierInterface::class, static fn (ContainerInterface $c) =>
            function_exists('wc_get_order')
                ? new WcBuyerVerifier($c->get(OrderItemRepositoryInterface::class))
                : new NullBuyerVerifier());
        $c->bind(OrderOperationsGate::class, static fn (ContainerInterface $c) => new OrderOperationsGate(
            $c->get(ResolveCommissionRate::class),
            $c->get(LedgerRepositoryInterface::class),
            $c->get(TrialUnlock::class)
        ));
    }

    /**
     * Every migration, in order — the ONE list.
     *
     * It is a named method rather than an inline array because the database
     * test fixtures need exactly this list too, and each of them used to carry
     * a hand-written copy. Adding migration 14 broke sixty-one tests at once:
     * the fixtures built their tables from a list that stopped at 10, so a
     * column the code now writes to did not exist. That is the same staleness
     * that bit the test administrator's capability list one release earlier —
     * a copy of a list is a list that goes wrong later.
     *
     * @return list<MigrationInterface>
     */
    public static function migrations(): array
    {
        return [
            new M0001CreateAuditTable(),
            new M0002CreateVendorTables(),
            new M0003CreateStoreAndStaffTables(),
            new M0004CreateFinanceTables(),
            new M0005CreateProductTables(),
            new M0006CatalogAndOrders(),
            new M0007SettlementTables(),
            new M0008ShipmentsAndReturns(),
            new M0009EngagementTables(),
            new M0010LinkOwnership(),
            new M0011AttachmentsAndNotices(),
            new M0012VendorRatings(),
            new M0013JobsAndOutbox(),
            new M0014RowVersionAndImportRun(),
            new M0015DokanOrderHistory(),
        ];
    }

    private static function registerModules(Kernel $kernel): void
    {
        $registry = $kernel->registry();
        $registry->add(new CoreModule());
        $registry->add(new EnvironmentGuardModule());
        $registry->add(new AdminModule());
        $registry->add(new HealthModule());
        $registry->add(new OperationsModule());
        $registry->add(new VendorModule());
        $registry->add(new FinanceModule());
        $registry->add(new ProductModule());
        $registry->add(new MarketplaceModule());
        $registry->add(new MigrationModule());
        $registry->add(new OrderModule());

        // Roadmap only (CORE-02): no code, no hooks, no activation button.
        // «migration» left this list when the real module arrived — a planned
        // entry beside a real one of the same id would show the roadmap twice.
        $planned = [
            ['settlement', 'تسویه و برداشت', true],
        ];
        foreach ($planned as [$id, $label, $needsWc]) {
            $registry->addPlanned(new ModuleManifest($id, '0.0.0', $label, ModuleKind::Planned, ['core', 'environment-guard'], $needsWc));
        }
    }
}
