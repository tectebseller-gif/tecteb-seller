<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\OrderLedgerFilter;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderDisposition;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderLifecycle;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * Every recorded order line, one row at a time, with what is true of it.
 *
 * ⛔ **The owner's §7, and the complaint it answers.** The reports page shows
 * totals, and «گزارش فعلی فقط جمع کل است و در مقیاس بزرگ قابل استفاده نیست».
 * A manager who wants to know what happened to ONE product in ONE order had no
 * screen to ask on. The aggregates stay exactly where they are — this is
 * another reader over the same table, not a replacement.
 *
 * **Scoped by the ASKER, and a filter cannot widen it.** A vendor's own staff
 * see one shop and that shop is decided here, from `StaffAccess`, never from
 * the request: `OrderLedgerFilter::forVendor()` overwrites whatever `vendor`
 * was typed. The marketplace-wide read asks for the manager's capability first
 * and answers `forbidden` without it — the same posture as `Reports`, for the
 * same reason, and `alpha.86`'s rule that widening a list's scope widens what
 * it may show.
 *
 * ⛔ **It cannot leak a customer, because it reads a table that has none.**
 * «هیچ اطلاعات محرمانه مشتری نباید بی‌دلیل در گزارش یا خروجی CSV فروشنده
 * افشا شود» — `tmc_order_items` holds no name, no address, no telephone and no
 * e-mail, and this service reads nothing else. That is a property of the data
 * rather than a rule kept by a screen, and
 * `OrderLedgerReportHasNoCustomerDataTest` reads the file to say so.
 *
 * **Three amounts that must not be added together** (§7, and §3-3 behind it):
 *
 *   `historic`   every line this filter matched, cancelled ones included —
 *                what was sold, for ever.
 *   `valid`      the lines whose obligation still stands.
 *   `pending`    valid, and nobody has called it complete yet, so it is not
 *                money anybody may ask for.
 *
 * They are returned as three figures with the unit beside them rather than one,
 * because a single «total» has to be one of the three and would be read as the
 * other two.
 */
final class OrderLedgerReport
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly StaffAccess $access,
        private readonly ?CapabilityCheckerInterface $capabilities = null
    ) {
    }

    /**
     * One page, for somebody who may see one shop.
     *
     * @return array{allowed:bool, rows:list<array<string,mixed>>, total:?int, filter:OrderLedgerFilter}
     */
    public function forVendor(int $actorId, int $vendorUserId, OrderLedgerFilter $filter): array
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Order, StaffLevel::View)) {
            return ['allowed' => false, 'rows' => [], 'total' => null, 'filter' => $filter];
        }
        // ⛔ The scope is overwritten, not validated. A vendor's request that
        // carried another shop's id does not earn an error message — it reads
        // their own shop, which is the only thing they were ever able to read.
        return $this->page($filter->forVendor($vendorUserId));
    }

    /**
     * One page, marketplace-wide, for a manager.
     *
     * @return array{allowed:bool, rows:list<array<string,mixed>>, total:?int, filter:OrderLedgerFilter}
     */
    public function forManager(OrderLedgerFilter $filter): array
    {
        if ($this->capabilities === null || !$this->capabilities->can(Capabilities::VIEW_VENDOR_FINANCE)) {
            // The finance capability rather than the list one, and
            // deliberately: this report prints commission and share per line,
            // which `alpha.86` separated from «which shops exist» for exactly
            // this reason.
            return ['allowed' => false, 'rows' => [], 'total' => null, 'filter' => $filter];
        }
        return $this->page($filter);
    }

    /**
     * @return array{allowed:bool, rows:list<array<string,mixed>>, total:?int, filter:OrderLedgerFilter}
     */
    private function page(OrderLedgerFilter $filter): array
    {
        $answer = $this->items->detailed($filter);
        $rows = $answer['rows'];

        // THE REVERSING DOCUMENTS, IN ONE QUERY for the whole page. Asked per
        // row this would be a query per row — the shape that makes a per-line
        // report unusable at volume (`alpha.96`).
        $voidKeys = [];
        foreach ($rows as $row) {
            if ((string) $row['ledger_event'] !== '') {
                $voidKeys[] = SyncOrderLifecycle::voidEventKey(
                    (int) $row['wc_order_id'],
                    (int) $row['wc_order_item_id']
                );
            }
        }
        $present = [];
        foreach ($this->ledger->eventsPresent($voidKeys) as $key) {
            $present[$key] = true;
        }

        return [
            'allowed' => true,
            'rows' => array_map(fn (array $row): array => $this->describe($row, $present), $rows),
            'total' => $answer['total'],
            'filter' => $filter,
        ];
    }

    /**
     * One row, with the facts a reader needs derived once.
     *
     * Derived HERE rather than on the screen, because the CSV and the table
     * both print them and two derivations of «is this settleable» are two
     * answers (`alpha.78`).
     *
     * @param array<string,mixed> $row
     * @param array<string,bool> $voided
     * @return array<string,mixed>
     */
    private function describe(array $row, array $voided): array
    {
        $status = OrderItemStatus::tryFrom((string) $row['status']) ?? OrderItemStatus::Placed;
        $event = (string) $row['ledger_event'];
        $voidKey = SyncOrderLifecycle::voidEventKey((int) $row['wc_order_id'], (int) $row['wc_order_item_id']);
        $wcStatus = $row['wc_order_status'];
        $disposition = $wcStatus === null
            // ⛔ «Nobody has told us» is not a status, so it is not read as
            // one. `WcOrderLifecycle::of('')` answers `Unknown`, which is the
            // same word for a different reason — and both print as «نامعلوم»,
            // which is the honest answer either way.
            ? WcOrderDisposition::Unknown
            : WcOrderLifecycle::of((string) $wcStatus);

        return [
            ...$row,
            'wc_disposition' => $disposition->value,
            'item_status' => $status->value,
            'has_shipped' => $status->hasShipped(),
            'is_cancelled' => $status === OrderItemStatus::Cancelled,
            // THE FINANCIAL EVENT AND ITS REVERSAL, as three answers rather
            // than a boolean: a line with no accrual at all is not a line
            // whose accrual stands (FIN-02), and a reversal nobody could
            // confirm is not a reversal that is absent.
            'financial_state' => match (true) {
                $event === '' => 'unrecorded',
                isset($voided[$voidKey]) => 'reversed',
                default => 'recorded',
            },
            'reversal_event' => isset($voided[$voidKey]) ? $voidKey : '',
            // THE COMMISSION SHAPE, read off the two columns that hold it —
            // the same derivation the filter uses, so a row the filter
            // matched prints the kind it was matched on.
            'commission_kind' => match (true) {
                $row['commission_minor'] === null => 'unrecorded',
                (int) $row['commission_minor'] === 0 => 'none',
                ((int) ($row['rate_bp'] ?? 0)) > 0 => 'percent',
                default => 'fixed',
            },
            // SETTLEABILITY, in the words the finance screens already use.
            // Not recomputed from the delay here: `VendorBalance` owns that
            // rule and owns the cutoff it used, and a second computation of
            // «has the waiting period passed» is a second rule that agrees
            // until one of them is a millisecond later (`alpha.39`).
            'settlement_state' => match (true) {
                $row['vendor_share_minor'] === null => 'unrecorded',
                $status === OrderItemStatus::Cancelled => 'void',
                $row['withdrawal_id'] !== null => 'reserved',
                $row['settlement_completed_at'] === null => 'awaiting_completion',
                default => 'complete',
            },
        ];
    }

    /**
     * The three figures of the filtered set — in ONE unit, which it names.
     *
     * Summed over the page's rows rather than in SQL, because a vendor's lines
     * can legitimately sit at two exponents of one currency (`alpha.67`) and
     * a bare `SUM()` across those is wrong by a factor of ten thousand. Two
     * CURRENCIES are not combined at all: `mixed_currencies` with no figure,
     * because the honest answer to «what is the total» across two kinds of
     * money is that there is not one.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   historic:int|null, valid:int|null, pending:int|null,
     *   currency:string, exponent:int|null, refused:string, lines:int
     * }
     */
    public static function totalsOf(array $rows): array
    {
        $currency = '';
        $exponent = null;
        foreach ($rows as $row) {
            $rowCurrency = $row['currency'] === null ? '' : (string) $row['currency'];
            if ($rowCurrency === '') {
                continue;
            }
            if ($currency === '') {
                $currency = $rowCurrency;
            } elseif ($currency !== $rowCurrency) {
                return [
                    'historic' => null, 'valid' => null, 'pending' => null,
                    'currency' => '', 'exponent' => null,
                    'refused' => 'mixed_currencies', 'lines' => count($rows),
                ];
            }
            $rowExponent = $row['exponent'] === null ? null : (int) $row['exponent'];
            if ($rowExponent !== null && ($exponent === null || $rowExponent > $exponent)) {
                $exponent = $rowExponent;      // the finest present
            }
        }

        $historic = 0;
        $valid = 0;
        $pending = 0;
        foreach ($rows as $row) {
            $share = $row['vendor_share_minor'];
            if ($share === null) {
                continue;                       // unset is not zero (FIN-02)
            }
            $rowExponent = $row['exponent'] === null ? $exponent : (int) $row['exponent'];
            $scaled = self::rescale((int) $share, $rowExponent, $exponent);
            if ($scaled === null) {
                return [
                    'historic' => null, 'valid' => null, 'pending' => null,
                    'currency' => $currency, 'exponent' => $exponent,
                    'refused' => 'scale_exceeds_integer_range', 'lines' => count($rows),
                ];
            }
            $historic += $scaled;
            if ((string) $row['item_status'] === OrderItemStatus::Cancelled->value) {
                continue;                       // sold once, owed by nobody
            }
            $valid += $scaled;
            if ((string) $row['settlement_state'] === 'awaiting_completion') {
                $pending += $scaled;
            }
        }
        return [
            'historic' => $historic,
            'valid' => $valid,
            'pending' => $pending,
            'currency' => $currency,
            'exponent' => $exponent,
            'refused' => '',
            'lines' => count($rows),
        ];
    }

    /** Exactly, by a power of ten — or `null` when it would leave the range. */
    private static function rescale(int $minor, ?int $from, ?int $to): ?int
    {
        if ($from === null || $to === null || $from === $to) {
            return $minor;
        }
        if ($from > $to) {
            // Finer than the unit being summed in, which cannot happen while
            // that unit is the finest present — counted as unanswerable rather
            // than divided, because dividing would round.
            return null;
        }
        $factor = 10 ** ($to - $from);
        if ($minor !== 0 && abs($minor) > intdiv(PHP_INT_MAX, $factor)) {
            return null;
        }
        return $minor * $factor;
    }
}
