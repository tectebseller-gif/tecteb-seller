<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\SelfGatedModuleInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress\OrderHooks;
use Tecteb\Marketplace\Modules\Order\Infrastructure\OrderServices;

/**
 * Orders — present as a module, and operating only once money can be recorded.
 *
 * While the gate says no, this module registers NOTHING: no hooks on
 * WooCommerce, no status writes, no screens that could take an action. That
 * is the difference the owner asked for between "the numbers are hidden" and
 * "the module does not operate" — there is no code path that could accept an
 * order and leave its money unrecorded, because there is no code path that
 * accepts an order at all.
 *
 * On a disposable site the owner may switch on trial mode, which lets the
 * whole path run with sample rules so it can be measured. The reason the
 * modules screen then shows says «آزمایشی», never «آماده».
 *
 * The modules page shows it as blocked, and the reason it shows is the one
 * the gate computed — a missing rate, an unwritable ledger, or the named open
 * decisions — never a vague «فعلاً نیست».
 */
final class OrderModule implements ModuleInterface, SelfGatedModuleInterface
{
    /**
     * Blocked, and honest about which answer is missing.
     *
     * The gate answers with codes rather than a boolean, and they are what
     * the modules screen prints: a missing rate, an unwritable ledger, the
     * named open decisions, or a trial switch this environment refuses — all
     * of them, so clearing one does not reveal the next as a surprise.
     */
    public function blockedReason(ContainerInterface $container): ?string
    {
        $gate = $container->get(OrderOperationsGate::class);
        $blockers = $gate->blockers();
        if ($blockers === []) {
            return null;
        }
        // Every reason, joined: a manager who clears one should already know
        // what else is in the way rather than discovering it one at a time.
        return implode(' | ', $blockers);
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'order',
            $this->version(),
            'سفارش‌ها و ارسال',
            ModuleKind::Operational,
            ['core', 'vendor', 'finance'],
            true,
            'دسترسی فروشنده به سفارش‌های خودش، وضعیت‌ها و ارسال — تا ثبت سهم مالی و دفترکل عملیاتی نمی‌شود'
        );
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }

    public function register(ContainerInterface $c): void
    {
        // The gate itself is bound in Bootstrap, because the catalogue has to
        // be able to ask it even when THIS module is blocked and never
        // registers. Everything below only exists once the gate said yes.
        OrderServices::register($c);
    }

    public function boot(ContainerInterface $c): void
    {
        // Reached only when the gate is ready (or on an explicit trial), so
        // there is no path here that can accept an order it cannot record.
        OrderHooks::register($c);
    }
}
