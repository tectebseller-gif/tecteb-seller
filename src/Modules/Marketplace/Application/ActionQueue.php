<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Marketplace\Domain\WholesaleStatus;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * «صف اقدام» — the one list both dashboards are built from.
 *
 * UX §6 gives the vendor «سفارش جدید | محصول نیازمند اصلاح | موجودی کم | تیکت»
 * and §13 gives the manager «فروشنده در انتظار | محصول در انتظار | تسویه |
 * اختلاف | تیکت فوری». They are the same idea twice, so they are the same
 * code twice: one row shape, counted from whoever is asking's own scope.
 *
 * Three rules hold for every row:
 *
 *  - **A zero is not a row.** A queue that lists «۰ سفارش جدید» teaches people
 *    to stop reading it, so an empty bucket is omitted entirely.
 *  - **Nothing here is a number a person cannot act on.** Every row has a URL
 *    that opens the thing it counts.
 *  - **No row carries its own words.** A key, a count, a tone and a URL; the
 *    Persian sentence belongs to whichever screen is showing it.
 *  - **Every count is scoped by the caller's own permissions**, because these
 *    two dashboards are the places where a vendor is most likely to see a
 *    number that is not theirs.
 *
 * A module that is not loaded contributes nothing rather than throwing: the
 * order module is self-gated, and a dashboard that fataled on a day the gate
 * was shut would take the whole admin down with it (F-15).
 */
final class ActionQueue
{
    /** Low stock is a warning, not a rule: the threshold is a display choice. */
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * The shop's own queue.
     *
     * @return list<array{key:string, count:int, tone:string, url:string}>
     */
    public function forVendor(int $vendorUserId, string $baseUrl): array
    {
        $rows = [];
        $orderItems = $this->service(OrderItemRepositoryInterface::class);
        if ($orderItems !== null) {
            $counts = $orderItems->countsByStatus($vendorUserId);
            $this->push($rows, 'new_orders',
                (int) ($counts[OrderItemStatus::Placed->value] ?? 0), 'warning', $baseUrl . 'orders/');
            $this->push($rows, 'partly_shipped',
                (int) ($counts[OrderItemStatus::PartiallyShipped->value] ?? 0), 'warning', $baseUrl . 'orders/');
        }
        $products = $this->service(ProductRepositoryInterface::class);
        if ($products !== null) {
            $needsWork = 0;
            $lowStock = 0;
            foreach ($products->allForVendor($vendorUserId) as $product) {
                if ($product->status === ProductStatus::ChangesRequested) {
                    $needsWork++;
                }
                if ($product->status === ProductStatus::Published
                    && $product->details->stock <= self::LOW_STOCK_THRESHOLD) {
                    $lowStock++;
                }
            }
            $this->push($rows, 'products_need_work',
                $needsWork, 'warning', $baseUrl . 'products/');
            $this->push($rows, 'low_stock',
                $lowStock, 'warning', $baseUrl . 'products/');
        }
        $returns = $this->service(ShipmentRepositoryInterface::class);
        if ($returns !== null) {
            $open = 0;
            foreach ($returns->returnsForVendor($vendorUserId, null, 200) as $request) {
                if (!$request->status->isFinished()) {
                    $open++;
                }
            }
            $this->push($rows, 'open_returns',
                $open, 'error', $baseUrl . 'orders/');
        }
        $engagement = $this->service(EngagementRepositoryInterface::class);
        if ($engagement !== null) {
            $this->push($rows, 'answered_tickets',
                $engagement->countTickets(Ticket::ANSWERED, $vendorUserId), 'info', $baseUrl . 'support/');
        }
        $balance = $this->service(VendorBalance::class);
        if ($balance !== null) {
            $of = $balance->of($vendorUserId);
            $this->push($rows, 'unrecorded_share',
                (int) $of['unrecorded'], 'error', $baseUrl . 'finance/');
        }
        return $rows;
    }

    /**
     * The marketplace's own queue.
     *
     * @return list<array{key:string, count:int, tone:string, url:string}>
     */
    public function forManager(string $adminUrl): array
    {
        $rows = [];
        $vendors = $this->service(VendorRepositoryInterface::class);
        if ($vendors !== null) {
            $this->push($rows, 'vendor_applications',
                count($vendors->listApplications(ApplicationStatus::Submitted, 200)),
                'warning', $adminUrl . 'admin.php?page=tmc-vendor-applications');
        }
        $products = $this->service(ProductRepositoryInterface::class);
        if ($products !== null) {
            $this->push($rows, 'products_in_review',
                $products->countInStatus(ProductStatus::Submitted),
                'warning', $adminUrl . 'admin.php?page=tmc-product-review');
        }
        $returns = $this->service(ShipmentRepositoryInterface::class);
        if ($returns !== null) {
            $waiting = count($returns->allReturns(ReturnStatus::Requested, 200))
                + count($returns->allReturns(ReturnStatus::Received, 200));
            $this->push($rows, 'returns_waiting',
                $waiting, 'error', $adminUrl . 'admin.php?page=tmc-returns');
        }
        $engagement = $this->service(EngagementRepositoryInterface::class);
        if ($engagement !== null) {
            $this->push($rows, 'tickets_waiting',
                $engagement->countTickets(Ticket::OPEN), 'error', $adminUrl . 'admin.php?page=tmc-tickets');
            $this->push($rows, 'wholesale_waiting',
                count($engagement->wholesaleAccounts(WholesaleStatus::Requested, 200)),
                'info', $adminUrl . 'admin.php?page=tmc-wholesale');
        }
        return $rows;
    }

    /**
     * A row, or nothing.
     *
     * No label: this is the Application layer, and a Persian sentence here
     * would be a WordPress call (`__()`) in a place the architecture test
     * forbids one — rightly, because a queue that carries its own translated
     * text cannot be reused by a REST response or a report. The key is what
     * the two views look up (ActionQueueMessages).
     *
     * @param list<array<string,mixed>> $rows
     */
    private function push(array &$rows, string $key, int $count, string $tone, string $url): void
    {
        if ($count <= 0) {
            return;
        }
        $rows[] = ['key' => $key, 'count' => $count, 'tone' => $tone, 'url' => $url];
    }

    /**
     * A service, or null when its module never registered.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    private function service(string $id): ?object
    {
        try {
            return $this->container->get($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
