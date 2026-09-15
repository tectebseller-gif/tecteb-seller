<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Infrastructure;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Order\Application\CaptureOrder;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Infrastructure\WordPress\WcOrderReader;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

/**
 * Everything the order module binds — in one file, so the module class can say
 * what it means (blocked or operating) without a wall of wiring.
 */
final class OrderServices
{
    public static function register(ContainerInterface $c): void
    {
        // OrderItemRepositoryInterface is deliberately NOT bound here: it is
        // bound in Bootstrap, because reading what was already recorded is not
        // "operating orders" and the finance screens have to keep working
        // while this module is blocked. Found by opening a vendor's finance
        // page with the gate closed — it fatalled on an unknown service.
        $c->bind(OrderItemStateMachine::class, static fn () => new OrderItemStateMachine());
        $c->bind(WcOrderReader::class, static fn () => new WcOrderReader());
        $c->bind(CaptureOrder::class, static fn (ContainerInterface $c) => new CaptureOrder(
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(ProductRepositoryInterface::class),
            $c->get(RecordCommission::class),
            $c->get(SyncCatalog::class),
            $c->get(OrderOperationsGate::class),
            $c->get(AuditLogger::class)
        ));
        $c->bind(ManageOrderItems::class, static fn (ContainerInterface $c) => new ManageOrderItems(
            $c->get(OrderItemRepositoryInterface::class),
            $c->get(StaffAccess::class),
            $c->get(AuditLogger::class),
            $c->get(ClockInterface::class),
            $c->get(OrderItemStateMachine::class),
            // The manager's capability checker, for the one action on this
            // service that is a manager's and not a vendor's: recording that
            // a sale is complete for settlement (ORDER-01).
            $c->get(CapabilityCheckerInterface::class)
        ));
    }
}
