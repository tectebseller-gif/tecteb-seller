<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\LineReconciliation;
use Tecteb\Marketplace\Modules\Order\Domain\OrderInconsistency;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;

/**
 * Does every order have its line, and every line its document? — read only.
 *
 * ⛔ **WHY THIS EXISTS, in the owner's words.** «در Hookهای ثبت سفارش برخی
 * exceptionها گرفته می‌شوند تا checkout مشتری نشکند. تعیین کن اگر سفارش
 * ووکامرس ایجاد شد ولی ثبت قلم یا سند مالی بازارگاه شکست خورد، چگونه سیستم
 * آن را کشف می‌کند.» Until this release the answer was: it does not. The
 * swallow is correct — a marketplace defect must not stop a customer paying —
 * but a swallow with no reader is a silence, and §5 asks for the reader.
 *
 * **Nothing here writes.** Not a row, not a repair, not an audit entry: «هیچ
 * اصلاح مالی خودکاری روی داده واقعی انجام نده». The class is given a reading
 * repository and a reading ledger and has no transaction, no clock and no
 * logger — so «read only» is the absence of a collaborator rather than a
 * promise, which is the only form of that claim this project accepts
 * (`alpha.9`, `alpha.55`, `alpha.86`).
 *
 * **Fail-closed, in both directions.**
 *   - A window that could not be counted answers `readable: false`, and the
 *     screen says «could not check» — never «nothing found» (`alpha.97`).
 *   - WooCommerce absent means class one is `unchecked` BY NAME and the
 *     summary says a check was skipped, so a clean list is never read as a
 *     clean marketplace.
 *
 * **Bounded and batched.** One window per request, keyset by id, and a fixed
 * number of queries whichever size the window is: the lines, the accrual
 * keys, the reversal keys, the event balances, the duplicate keys and the
 * vendor mismatches. Six, plus one count — not one per row (`alpha.96`).
 */
final class ReconcileOrders
{
    /** How many recorded lines one pass examines. */
    public const WINDOW = 200;

    /** How many recent WooCommerce orders the seventh check may enumerate. */
    public const ORDER_WINDOW = 100;

    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly ?CapabilityCheckerInterface $capabilities = null,
        private readonly ?OrderLineReaderInterface $orders = null,
        private readonly ?ProductRepositoryInterface $products = null
    ) {
    }

    /**
     * One pass over a window of lines, plus the order-side check.
     *
     * @return array{
     *   allowed:bool, readable:bool, examined:int, cursor:int, finished:bool,
     *   findings:list<array<string,mixed>>, counts:array<string,int>,
     *   unchecked:list<string>, order_scan:array<string,mixed>
     * }
     */
    public function pass(int $afterId = 0, ?int $vendorUserId = null): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::VIEW_VENDOR_FINANCE)) {
            // The finance capability, for the reason `alpha.86` separated it:
            // this control prints which shop is owed what, and which document
            // is missing behind it.
            return self::refused();
        }

        $window = $this->items->linesToReconcile($afterId, self::WINDOW, $vendorUserId);
        if (!$window['readable']) {
            return [
                'allowed' => true, 'readable' => false, 'examined' => 0,
                'cursor' => $afterId, 'finished' => false,
                'findings' => [], 'counts' => self::emptyCounts(),
                'unchecked' => [OrderInconsistency::Unchecked->value],
                'order_scan' => ['checked' => false, 'reason' => 'lines_unreadable'],
            ];
        }
        $rows = $window['rows'];

        // ⛔ EVERY FACT FOR THE WHOLE WINDOW FIRST, in a fixed number of
        // queries. Asked per row this would be six per line.
        $accrualKeys = [];
        $voidKeys = [];
        $lineIds = [];
        foreach ($rows as $row) {
            $lineIds[] = (int) $row['id'];
            $event = (string) $row['ledger_event'];
            if ($event !== '') {
                $accrualKeys[] = $event;
            }
            $voidKeys[] = SyncOrderLifecycle::voidEventKey(
                (int) $row['wc_order_id'],
                (int) $row['wc_order_item_id']
            );
        }
        $present = self::keyed($this->ledger->eventsPresent([...$accrualKeys, ...$voidKeys]));
        $balances = $this->ledger->eventBalances($accrualKeys);
        $shared = self::keyed($this->items->eventsOnSeveralLines($accrualKeys));
        $mismatched = [];
        foreach ($this->items->vendorMismatchedLines($lineIds) as $id) {
            $mismatched[$id] = true;
        }

        $findings = [];
        $counts = self::emptyCounts();
        $cursor = $afterId;
        foreach ($rows as $row) {
            $cursor = max($cursor, (int) $row['id']);
            $event = (string) $row['ledger_event'];
            $voidKey = SyncOrderLifecycle::voidEventKey(
                (int) $row['wc_order_id'],
                (int) $row['wc_order_item_id']
            );
            $balance = $balances[$event] ?? null;
            $classes = LineReconciliation::of(
                $event,
                $event !== '' && isset($present[$event]),
                isset($present[$voidKey]),
                (string) $row['status'] === OrderItemStatus::Cancelled->value,
                (bool) $row['share_recorded'],
                // A document nobody could read is not a document that
                // balances: `null` from the sum is reported, never trusted.
                $balance !== null && $balance['balanced'],
                $balance !== null && $balance['single_unit'],
                isset($shared[$event]),
                !isset($mismatched[(int) $row['id']]),
                OrderItemStatus::tryFrom((string) $row['status']) !== null
            );
            foreach ($classes as $class) {
                $counts[$class->value]++;
                $findings[] = [
                    'line_id' => (int) $row['id'],
                    'wc_order_id' => (int) $row['wc_order_id'],
                    'wc_order_item_id' => (int) $row['wc_order_item_id'],
                    'vendor_user_id' => (int) $row['vendor_user_id'],
                    'title' => (string) $row['title'],
                    'status' => (string) $row['status'],
                    'class' => $class->value,
                    'touches_money' => $class->touchesMoney(),
                    'ledger_event' => $event,
                ];
            }
        }

        $scan = $this->scanOrders($vendorUserId);
        foreach ($scan['findings'] as $finding) {
            $counts[OrderInconsistency::OrderWithoutLine->value]++;
            $findings[] = $finding;
        }

        $unchecked = [];
        if (!$scan['checked']) {
            $unchecked[] = OrderInconsistency::OrderWithoutLine->value;
        }

        return [
            'allowed' => true,
            'readable' => true,
            'examined' => count($rows),
            'cursor' => $cursor,
            // A short window is the end of the set — the reading every cursor
            // in this plugin uses.
            'finished' => count($rows) < self::WINDOW,
            'findings' => $findings,
            'counts' => $counts,
            'unchecked' => $unchecked,
            'order_scan' => $scan,
        ];
    }

    /**
     * Class one: a WooCommerce order with a marketplace product and no line.
     *
     * @return array{checked:bool, reason:string, orders:int, findings:list<array<string,mixed>>}
     */
    private function scanOrders(?int $vendorUserId): array
    {
        if ($this->orders === null || $this->products === null) {
            return ['checked' => false, 'reason' => 'no_reader', 'orders' => 0, 'findings' => []];
        }
        $ids = $this->orders->recentOrderIds(self::ORDER_WINDOW);
        if ($ids === null) {
            return ['checked' => false, 'reason' => 'woocommerce_absent', 'orders' => 0, 'findings' => []];
        }
        $findings = [];
        $read = 0;
        foreach ($ids as $orderId) {
            $productIds = $this->orders->productIdsIn($orderId);
            if ($productIds === null) {
                // One unreadable order makes the whole scan unchecked: a
                // partial scan reported as a scan is the `alpha.97` mistake.
                return [
                    'checked' => false, 'reason' => 'order_unreadable',
                    'orders' => $read, 'findings' => [],
                ];
            }
            $read++;
            $ours = [];
            foreach ($productIds as $productId) {
                $row = $this->products->findByWcProduct((int) $productId);
                if ($row !== null && ($vendorUserId === null || $row->vendorUserId === $vendorUserId)) {
                    $ours[] = (int) $productId;
                }
            }
            if ($ours === []) {
                continue;       // nothing of ours in it; not our business
            }
            if ($this->items->forOrder($orderId) !== []) {
                continue;       // recorded, which is the normal case
            }
            $findings[] = [
                'line_id' => 0,
                'wc_order_id' => $orderId,
                'wc_order_item_id' => 0,
                'vendor_user_id' => 0,
                'title' => '',
                'status' => '',
                'class' => OrderInconsistency::OrderWithoutLine->value,
                'touches_money' => OrderInconsistency::OrderWithoutLine->touchesMoney(),
                'ledger_event' => '',
            ];
        }
        return ['checked' => true, 'reason' => '', 'orders' => $read, 'findings' => $findings];
    }

    /**
     * @param list<string> $keys
     * @return array<string,bool>
     */
    private static function keyed(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = true;
        }
        return $out;
    }

    /** @return array<string,int> */
    private static function emptyCounts(): array
    {
        $counts = [];
        foreach (OrderInconsistency::findings() as $class) {
            $counts[$class->value] = 0;
        }
        return $counts;
    }

    /**
     * @return array{
     *   allowed:bool, readable:bool, examined:int, cursor:int, finished:bool,
     *   findings:list<array<string,mixed>>, counts:array<string,int>,
     *   unchecked:list<string>, order_scan:array<string,mixed>
     * }
     */
    private static function refused(): array
    {
        return [
            'allowed' => false, 'readable' => false, 'examined' => 0,
            'cursor' => 0, 'finished' => false,
            'findings' => [], 'counts' => self::emptyCounts(),
            'unchecked' => [],
            'order_scan' => ['checked' => false, 'reason' => 'not_allowed', 'orders' => 0, 'findings' => []],
        ];
    }
}
