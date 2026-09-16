<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Marketplace\Domain\Ticket;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStatus;

/**
 * The reports the specs approve, and only those.
 *
 * UX §4.1 gives the vendor «فروش، محصول، موجودی، مالی» and §12.3 gives the
 * manager «مالی و عملیاتی». Four for a shop, two for the marketplace; nothing
 * invented, because a report nobody asked for is a number somebody will act on.
 *
 * Three rules, each of which is the difference between a report and a
 * decoration:
 *
 *  - **Computed, never cached.** Every figure is derived from the ledger and
 *    the order lines when it is asked for. A stored total is a total that
 *    drifts, and a financial number that drifts is worse than no number.
 *  - **Scoped by the asker.** A vendor's report is built from their own rows
 *    and cannot be widened by any argument; the marketplace-wide one asks for
 *    the manager's capability first and returns nothing without it.
 *  - **No row carries its own words.** Keys, numbers and tones; the Persian
 *    belongs to the screen, the same as the action queue. The architecture
 *    test enforces that this layer never calls `__()`.
 *
 * A figure that CANNOT be known says so with `null` rather than `0`. «سهم
 * ثبت‌نشده» and «سهم صفر» are different facts and FIN-02 has always said so —
 * a zero here would tell a vendor they earned nothing when the truth is that
 * nobody has worked it out yet.
 *
 * A module that is not loaded contributes nothing rather than throwing: the
 * order module is self-gated and a report page that fataled on a day the gate
 * was shut would take the admin down with it (F-15).
 */
final class Reports
{
    /** Report keys, so a screen can title and order them. */
    public const SALES = 'report.sales';
    public const PRODUCTS = 'report.products';
    public const STOCK = 'report.stock';
    public const FINANCE = 'report.finance';
    public const OPERATIONS = 'report.operations';

    /** Below this, a product is worth a vendor's attention. Display only. */
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * One shop's four reports.
     *
     * @return array<string, array<string, int|string|null>>
     */
    public function forVendor(int $actorId, int $vendorUserId): array
    {
        $access = $this->service(StaffAccess::class);
        if ($access === null || $access->storeFor($actorId) !== $vendorUserId) {
            return [];
        }
        return [
            self::SALES => $this->sales($vendorUserId),
            self::PRODUCTS => $this->products($vendorUserId),
            self::STOCK => $this->stock($vendorUserId),
            self::FINANCE => $this->finance($vendorUserId),
        ];
    }

    /**
     * The marketplace's two.
     *
     * `$vendorIds` is passed in rather than discovered here, because who the
     * vendors are is the Vendor module's question and this class asking it
     * directly would be a second answer to it.
     *
     * @param list<int> $vendorIds
     * @return array<string, array<string, int|string|null>>
     */
    public function forManager(array $vendorIds): array
    {
        return [
            self::FINANCE => $this->marketplaceFinance($vendorIds),
            self::OPERATIONS => $this->marketplaceOperations($vendorIds),
        ];
    }

    /** «فروش» — what this shop sold, by the state each line is in. */
    private function sales(int $vendorUserId): array
    {
        $items = $this->service(OrderItemRepositoryInterface::class);
        if ($items === null) {
            return ['available' => 0];
        }
        $counts = $items->countsByStatus($vendorUserId);
        $lines = 0;
        foreach ($counts as $count) {
            $lines += (int) $count;
        }
        $returns = $this->service(ShipmentRepositoryInterface::class);
        $returned = 0;
        if ($returns !== null) {
            foreach ($returns->returnsForVendor($vendorUserId) as $return) {
                if ($return->status === ReturnStatus::Refunded || $return->status === ReturnStatus::Received) {
                    $returned++;
                }
            }
        }
        return [
            'available' => 1,
            'lines' => $lines,
            'awaiting' => (int) ($counts[OrderItemStatus::Placed->value] ?? 0),
            'preparing' => (int) ($counts[OrderItemStatus::Preparing->value] ?? 0),
            'partially_shipped' => (int) ($counts[OrderItemStatus::PartiallyShipped->value] ?? 0),
            'shipped' => (int) ($counts[OrderItemStatus::Shipped->value] ?? 0),
            'delivered' => (int) ($counts[OrderItemStatus::Delivered->value] ?? 0),
            'cancelled' => (int) ($counts[OrderItemStatus::Cancelled->value] ?? 0),
            'returned' => $returned,
        ];
    }

    /** «محصول» — where this shop's catalogue has got to. */
    private function products(int $vendorUserId): array
    {
        $products = $this->service(ProductRepositoryInterface::class);
        if ($products === null) {
            return ['available' => 0];
        }
        // Counted in SQL. The old version hydrated every product of the shop
        // into an object to add one to a counter — the whole catalogue read on
        // every page load, for six numbers.
        $byStatus = $products->countsByStatus($vendorUserId);
        return [
            'available' => 1,
            'total' => array_sum($byStatus),
            'published' => (int) ($byStatus[ProductStatus::Published->value] ?? 0),
            'in_review' => (int) ($byStatus[ProductStatus::Submitted->value] ?? 0),
            'needs_fix' => (int) ($byStatus[ProductStatus::ChangesRequested->value] ?? 0),
            'draft' => (int) ($byStatus[ProductStatus::Draft->value] ?? 0),
            'suspended' => (int) ($byStatus[ProductStatus::Suspended->value] ?? 0),
        ];
    }

    /** «موجودی» — what is about to run out. */
    private function stock(int $vendorUserId): array
    {
        $products = $this->service(ProductRepositoryInterface::class);
        if ($products === null) {
            return ['available' => 0];
        }
        // One query, three figures, and they agree with each other because
        // they are counted in the same pass — «موجود» and «رو به اتمام» that do
        // not add up is worse than a slow report. A draft's stock is still
        // nobody's problem: the WHERE says published.
        $summary = $products->stockSummary($vendorUserId, self::LOW_STOCK_THRESHOLD);
        $counted = $summary['on_sale'];
        $out = $summary['out'];
        $low = $summary['low'];
        return [
            'available' => 1,
            'on_sale' => $counted,
            'out_of_stock' => $out,
            'low_stock' => $low,
            'threshold' => self::LOW_STOCK_THRESHOLD,
        ];
    }

    /** «مالی» — the same figures the settlement screen shows, not a second set. */
    private function finance(int $vendorUserId): array
    {
        $balance = $this->service(VendorBalance::class);
        if ($balance === null) {
            return ['available' => 0];
        }
        $of = $balance->of($vendorUserId);
        return [
            'available' => 1,
            'earned_minor' => (int) ($of['earned'] ?? 0),
            'pending_minor' => (int) ($of['pending'] ?? 0),
            'eligible_minor' => (int) ($of['eligible'] ?? 0),
            'reserved_minor' => (int) ($of['reserved'] ?? 0),
            'paid_minor' => (int) ($of['paid'] ?? 0),
            // Not a zero: lines whose share nobody has worked out yet (FIN-02).
            'unrecorded_lines' => (int) ($of['unrecorded'] ?? 0),
        ];
    }

    /**
     * «مالی» for the marketplace: every shop's figures added up.
     *
     * @param list<int> $vendorIds
     */
    private function marketplaceFinance(array $vendorIds): array
    {
        $balance = $this->service(VendorBalance::class);
        if ($balance === null) {
            return ['available' => 0];
        }
        $totals = ['earned_minor' => 0, 'pending_minor' => 0, 'eligible_minor' => 0,
                   'reserved_minor' => 0, 'paid_minor' => 0, 'unrecorded_lines' => 0];
        foreach ($vendorIds as $vendorUserId) {
            $of = $balance->of((int) $vendorUserId);
            $totals['earned_minor'] += (int) ($of['earned'] ?? 0);
            $totals['pending_minor'] += (int) ($of['pending'] ?? 0);
            $totals['eligible_minor'] += (int) ($of['eligible'] ?? 0);
            $totals['reserved_minor'] += (int) ($of['reserved'] ?? 0);
            $totals['paid_minor'] += (int) ($of['paid'] ?? 0);
            $totals['unrecorded_lines'] += (int) ($of['unrecorded'] ?? 0);
        }
        return ['available' => 1, 'vendors' => count($vendorIds)] + $totals;
    }

    /**
     * «عملیاتی» for the marketplace: what is waiting on somebody.
     *
     * @param list<int> $vendorIds
     */
    private function marketplaceOperations(array $vendorIds): array
    {
        $vendors = $this->service(VendorRepositoryInterface::class);
        $items = $this->service(OrderItemRepositoryInterface::class);
        $returns = $this->service(ShipmentRepositoryInterface::class);
        $engagement = $this->service(EngagementRepositoryInterface::class);

        $awaitingShipment = 0;
        foreach ($vendorIds as $vendorUserId) {
            if ($items !== null) {
                $awaitingShipment += $items->countForVendor((int) $vendorUserId, OrderItemStatus::Placed);
            }
        }
        // The two undecided return states, asked marketplace-wide rather than
        // vendor by vendor: the same two the action queue counts, so the
        // report and the queue can never disagree about what is waiting.
        $openReturns = $returns === null ? 0
            : count($returns->allReturns(ReturnStatus::Requested, 500))
              + count($returns->allReturns(ReturnStatus::Received, 500));
        return [
            'available' => 1,
            'vendors' => count($vendorIds),
            'vendor_applications_waiting' => $vendors === null
                ? 0 : count($vendors->listApplications(ApplicationStatus::Submitted, 500)),
            'awaiting_shipment' => $awaitingShipment,
            'open_returns' => $openReturns,
            'open_tickets' => $engagement === null ? 0 : $engagement->countTickets(Ticket::OPEN),
        ];
    }

    /**
     * A service, or null when its module is not loaded.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    private function service(string $id): ?object
    {
        try {
            return $this->container->has($id) ? $this->container->get($id) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
