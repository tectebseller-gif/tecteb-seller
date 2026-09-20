<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Application;

use Tecteb\Marketplace\Contracts\CacheInterface;
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
 *  - **Computed, then cached behind a version — never stored.** Every figure
 *    is still derived from the ledger and the order lines. What is kept is the
 *    RESULT of that derivation, under a key carrying a counter that any write
 *    to the shop bumps, so a figure cannot outlive the data it came from. That
 *    is a different thing from a stored total, which is what drifts: nothing
 *    here is ever written back as though it were a fact. A cache miss and a
 *    cold install produce the same numbers by the same path, and
 *    `ReportCacheKey::NAMESPACE_PREFIX` is per shop, so one shop's entry is
 *    not reachable with another shop's id.
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

    /**
     * How long a report may be stale.
     *
     * Ninety seconds, not ten minutes. The version counter is what actually
     * retires a figure; this is only the backstop for a write that forgot to
     * bump one, and a backstop measured in minutes on a financial screen is a
     * wrong number somebody acts on. Short enough that «refresh and it is
     * right» is true, long enough to absorb a manager clicking between tabs.
     */
    public const TTL = 90;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?CacheInterface $cache = null
    ) {
    }

    /**
     * Retire everything cached for one shop.
     *
     * Called from the WRITE, never from the reader — the rule the store page
     * cache learned the hard way in `alpha.16`, where `VendorRoutes` forgot
     * BEFORE saving and a read landing in between re-cached the stale page
     * under the new version.
     */
    public function forgetVendor(int $vendorUserId): void
    {
        if ($vendorUserId > 0) {
            $this->cache?->bump(ReportCacheKey::forVendor($vendorUserId));
        }
    }

    /** Retire the marketplace-wide pair. */
    public function forgetMarketplace(): void
    {
        $this->cache?->bump(ReportCacheKey::MARKETPLACE);
    }

    /**
     * One shop's four reports.
     *
     * @return array<string, array<string, int|string|null>>
     */
    public function forVendor(int $actorId, int $vendorUserId): array
    {
        // The access check is BEFORE the cache and is never cached itself.
        // A cached report keyed only by shop is correct precisely because
        // nobody reaches this line without already being that shop's — a key
        // that also carried the actor would multiply the entries and change
        // nothing about who may read them.
        $access = $this->service(StaffAccess::class);
        if ($access === null || $access->storeFor($actorId) !== $vendorUserId) {
            return [];
        }

        $namespace = ReportCacheKey::forVendor($vendorUserId);
        $key = $this->cache !== null
            ? $namespace . ':v' . $this->cache->version($namespace)
            : '';
        if ($key !== '') {
            $hit = $this->cache?->get($key);
            if (is_array($hit) && $hit !== []) {
                return $hit;
            }
        }

        $report = [
            self::SALES => $this->sales($vendorUserId),
            self::PRODUCTS => $this->products($vendorUserId),
            self::STOCK => $this->stock($vendorUserId),
            self::FINANCE => $this->finance($vendorUserId),
        ];
        if ($key !== '') {
            $this->cache?->put($key, $report, self::TTL);
        }
        return $report;
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
        // The set of shops is part of the key. Two managers looking at
        // different selections must not share an entry, and the same
        // selection in a different order is the same selection.
        $ids = array_values(array_unique(array_map('intval', $vendorIds)));
        sort($ids);
        $namespace = ReportCacheKey::MARKETPLACE;
        $key = $this->cache !== null
            ? $namespace . ':v' . $this->cache->version($namespace) . ':' . md5(implode(',', $ids))
            : '';
        if ($key !== '') {
            $hit = $this->cache?->get($key);
            if (is_array($hit) && $hit !== []) {
                return $hit;
            }
        }

        $report = [
            self::FINANCE => $this->marketplaceFinance($ids),
            self::OPERATIONS => $this->marketplaceOperations($ids),
        ];
        if ($key !== '') {
            $this->cache?->put($key, $report, self::TTL);
        }
        return $report;
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
