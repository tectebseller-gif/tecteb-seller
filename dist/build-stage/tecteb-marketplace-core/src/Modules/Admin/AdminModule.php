<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin;

use Tecteb\Marketplace\Contracts\AuditRepositoryInterface;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Admin\Application\SettingsSubmission;
use Tecteb\Marketplace\Modules\Admin\Infrastructure\AdminHooks;

/**
 * Admin module (CORE-05): four Persian pages under «بازارگاه تک‌طب».
 * Does not require WooCommerce so that health, settings and modules stay
 * reachable in limited mode (correction 6). Does not depend on the health
 * module: the health page degrades gracefully when that module is not Active.
 */
final class AdminModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'admin',
            $this->version(),
            'مدیریت',
            ModuleKind::Operational,
            ['core', 'environment-guard'],
            false,
            'پیشخوان، سلامت، تنظیمات، ماژول‌ها'
        );
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind(SettingsSubmission::class, static fn (ContainerInterface $c) => new SettingsSubmission(
            $c->get(SettingsService::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
    }

    public function boot(ContainerInterface $container): void
    {
        AdminHooks::register($container);
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
