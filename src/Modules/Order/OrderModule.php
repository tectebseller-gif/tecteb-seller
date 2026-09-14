<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\SelfGatedModuleInterface;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;

/**
 * Orders — present as a module, deliberately not operating.
 *
 * It registers its gate and nothing else: no hooks on WooCommerce, no status
 * writes, no screens that could take an action. That is the difference the
 * owner asked for between "the numbers are hidden" and "the module does not
 * operate": there is no code path here that could accept an order and leave
 * its money unrecorded, because there is no code path here that accepts an
 * order at all.
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
     * The open decisions are checked FIRST and without touching the database:
     * while DEC-02 and DEC-04 are unanswered the module cannot operate
     * whatever the rates say, so asking the ledger would cost a query on
     * every request to learn something already known.
     */
    public function blockedReason(ContainerInterface $container): ?string
    {
        if (OrderOperationsGate::OPEN_DECISIONS !== []) {
            return OrderOperationsGate::DECISIONS_OPEN . ':' . implode(',', OrderOperationsGate::OPEN_DECISIONS);
        }
        $gate = new OrderOperationsGate(
            $container->get(ResolveCommissionRate::class),
            $container->get(LedgerRepositoryInterface::class)
        );
        $check = $gate->check();
        return $check['ready'] ? null : $check['reason'];
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'order',
            '0.0.0',
            'سفارش‌ها و ارسال',
            ModuleKind::Operational,
            ['core', 'vendor', 'finance'],
            true,
            'دسترسی فروشنده به سفارش‌های خودش، وضعیت‌ها و ارسال — تا ثبت سهم مالی و دفترکل عملیاتی نمی‌شود'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(OrderOperationsGate::class, static fn (ContainerInterface $c) => new OrderOperationsGate(
            $c->get(ResolveCommissionRate::class),
            $c->get(LedgerRepositoryInterface::class)
        ));
    }

    public function boot(ContainerInterface $c): void
    {
        // Nothing. Booting an order module that cannot record money is the
        // failure this whole arrangement exists to prevent.
    }
}
