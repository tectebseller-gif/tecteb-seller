<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * What a reader asked the per-line order report for.
 *
 * Pure, and the only place a filter is normalised: a page builds one of these
 * from a request, a CSV export builds one from the SAME request, and the two
 * cannot narrow differently — «خروجی CSV مطابق فیلترهای فعال». The `alpha.78`
 * rule, on a report: two halves that answer one question must read one list.
 *
 * **Every field is optional and an unparseable one is simply absent.** A
 * filter built from a fallback answers about rows nobody asked for, and on a
 * financial report that is worse than no filter at all.
 *
 * **It carries no SQL and no vendor scope.** The scope is the ASKER's, decided
 * by `OrderLedgerReport` from a capability, so a filter cannot widen itself:
 * `vendorUserId` here is a manager narrowing to one shop, never a vendor
 * choosing which shop to read.
 */
final class OrderLedgerFilter
{
    /** The page sizes offered. Anything else becomes the first. */
    public const PER_PAGE = [25, 50, 100];

    /** What a column may be sorted by — the indexed ones, plus the amounts. */
    public const SORTS = ['recent', 'oldest', 'amount_high', 'amount_low', 'order', 'share_high'];

    /**
     * The derived commission shapes a row can have.
     *
     * Derived rather than stored: `RecordCommission` keeps `rate_bp` at the
     * percentage's basis points and writes `0` for a fixed rule, so the four
     * cases are a reading of two columns and need no fifth.
     */
    public const COMMISSION_KINDS = ['percent', 'fixed', 'none', 'unrecorded'];

    /** How the WooCommerce order's own status may be narrowed. */
    public const PAYMENT_STATES = ['paid', 'unpaid', 'void', 'refunded', 'unknown'];

    private function __construct(
        public readonly ?int $vendorUserId,
        public readonly ?int $wcOrderId,
        public readonly ?int $wcProductId,
        public readonly ?int $variationId,
        public readonly string $title,
        public readonly string $sku,
        public readonly ?OrderItemStatus $shipping,
        public readonly string $payment,
        public readonly string $commissionKind,
        public readonly string $fromLocalDate,
        public readonly string $toLocalDate,
        public readonly ?int $minAmountMinor,
        public readonly ?int $maxAmountMinor,
        public readonly string $sort,
        public readonly int $perPage,
        public readonly int $page
    ) {
    }

    /**
     * @param array<string,string|int> $input the request's own values, raw
     */
    public static function fromInput(array $input): self
    {
        $text = static function (string $key) use ($input): string {
            $value = trim((string) ($input[$key] ?? ''));
            return $value === '' ? '' : mb_substr($value, 0, 190);
        };
        $id = static function (string $key) use ($input): ?int {
            $raw = trim((string) ($input[$key] ?? ''));
            // «Absent» and «zero» are different questions and nought is not an
            // id: a `0` typed into «شناسهٔ سفارش» must not narrow to nothing.
            return $raw === '' || !ctype_digit($raw) || (int) $raw <= 0 ? null : (int) $raw;
        };
        $money = static function (string $key) use ($input): ?int {
            $raw = trim((string) ($input[$key] ?? ''));
            if ($raw === '') {
                return null;
            }
            // Persian digits and the thousands separator a person types, both
            // removed before the shape is judged — `alpha.66`'s rule: the
            // boundary translates, the layer below does not guess.
            $raw = strtr($raw, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٬' => '', ',' => '', ' ' => '', "\u{200c}" => '',
            ]);
            return ctype_digit($raw) ? (int) $raw : null;
        };
        $oneOf = static function (string $key, array $allowed) use ($input): string {
            $value = strtolower(trim((string) ($input[$key] ?? '')));
            return in_array($value, $allowed, true) ? $value : '';
        };
        $date = static function (string $key) use ($input): string {
            $value = trim((string) ($input[$key] ?? ''));
            // The shape an `<input type="date">` sends. Anything else is left
            // off rather than interpreted; `SiteTime` refuses it too, and one
            // of the two has to be the place a reader can see why.
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
        };

        $perPage = (int) ($input['per_page'] ?? 0);
        $min = $money('min_amount');
        $max = $money('max_amount');
        // A reversed range is a typo, not an empty result: swapped rather than
        // answered with nothing, because «from 500 to 100» has one reading.
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return new self(
            $id('vendor'),
            $id('order'),
            $id('product'),
            $id('variation'),
            $text('title'),
            $text('sku'),
            OrderItemStatus::tryFrom(strtolower(trim((string) ($input['shipping'] ?? '')))),
            $oneOf('payment', self::PAYMENT_STATES),
            $oneOf('commission', self::COMMISSION_KINDS),
            $date('from'),
            $date('to'),
            $min,
            $max,
            $oneOf('sort', self::SORTS) === '' ? 'recent' : $oneOf('sort', self::SORTS),
            in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0],
            max(1, (int) ($input['paged'] ?? 1))
        );
    }

    /** The same filter, scoped to one shop and unable to be widened again. */
    public function forVendor(int $vendorUserId): self
    {
        return $this->with(['vendorUserId' => $vendorUserId]);
    }

    /** The same filter on another page of the same rows. */
    public function onPage(int $page): self
    {
        return $this->with(['page' => max(1, $page)]);
    }

    /**
     * The same rows, read in batches of a size the CALLER picks.
     *
     * For a sweep, where the batch size is a memory decision rather than a
     * reader's choice — so it deliberately does not go through
     * `self::PER_PAGE`, which is the list of sizes a person may ASK for.
     */
    public function atPage(int $page, int $perPage): self
    {
        return $this->with(['page' => max(1, $page), 'perPage' => max(1, $perPage)]);
    }

    /**
     * A copy with some fields replaced.
     *
     * One place, so adding a field to this object cannot leave a copier behind
     * that quietly drops it — which is how a «next page» link ends up on a
     * different set of rows from the page it was printed on.
     *
     * @param array<string,mixed> $changes
     */
    private function with(array $changes): self
    {
        $value = fn (string $key, mixed $current): mixed
            => array_key_exists($key, $changes) ? $changes[$key] : $current;
        return new self(
            $value('vendorUserId', $this->vendorUserId),
            $value('wcOrderId', $this->wcOrderId),
            $value('wcProductId', $this->wcProductId),
            $value('variationId', $this->variationId),
            $value('title', $this->title),
            $value('sku', $this->sku),
            $value('shipping', $this->shipping),
            $value('payment', $this->payment),
            $value('commissionKind', $this->commissionKind),
            $value('fromLocalDate', $this->fromLocalDate),
            $value('toLocalDate', $this->toLocalDate),
            $value('minAmountMinor', $this->minAmountMinor),
            $value('maxAmountMinor', $this->maxAmountMinor),
            $value('sort', $this->sort),
            $value('perPage', $this->perPage),
            $value('page', $this->page)
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function isNarrowed(): bool
    {
        return $this->wcOrderId !== null || $this->wcProductId !== null
            || $this->variationId !== null || $this->title !== '' || $this->sku !== ''
            || $this->shipping !== null || $this->payment !== '' || $this->commissionKind !== ''
            || $this->fromLocalDate !== '' || $this->toLocalDate !== ''
            || $this->minAmountMinor !== null || $this->maxAmountMinor !== null;
    }

    /**
     * The filter as query arguments, for a link or a CSV URL.
     *
     * Empty values are left out so the first page of an unfiltered list has
     * ONE address — the `alpha.96` rule, which is what makes a bookmarked
     * report and a shared one the same report.
     *
     * @return array<string,string>
     */
    public function toQuery(): array
    {
        $out = [
            'vendor' => $this->vendorUserId === null ? '' : (string) $this->vendorUserId,
            'order' => $this->wcOrderId === null ? '' : (string) $this->wcOrderId,
            'product' => $this->wcProductId === null ? '' : (string) $this->wcProductId,
            'variation' => $this->variationId === null ? '' : (string) $this->variationId,
            'title' => $this->title,
            'sku' => $this->sku,
            'shipping' => $this->shipping?->value ?? '',
            'payment' => $this->payment,
            'commission' => $this->commissionKind,
            'from' => $this->fromLocalDate,
            'to' => $this->toLocalDate,
            'min_amount' => $this->minAmountMinor === null ? '' : (string) $this->minAmountMinor,
            'max_amount' => $this->maxAmountMinor === null ? '' : (string) $this->maxAmountMinor,
            'sort' => $this->sort === 'recent' ? '' : $this->sort,
            'per_page' => $this->perPage === self::PER_PAGE[0] ? '' : (string) $this->perPage,
            'paged' => $this->page === 1 ? '' : (string) $this->page,
        ];
        return array_filter($out, static fn (string $v): bool => $v !== '');
    }
}
