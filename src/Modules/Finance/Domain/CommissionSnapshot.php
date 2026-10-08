<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * Everything needed to explain a commission line a year later (FIN-03).
 *
 * A stored result that says only «۹۰٬۰۰۰» is unauditable: it cannot be
 * checked without knowing the rate, where that rate came from, which
 * rounding rule applied, and in what currency. All of that is captured at
 * the moment of calculation, because every one of those inputs can change
 * afterwards.
 */
final class CommissionSnapshot
{
    public function __construct(
        public readonly int $rateBasisPoints,
        public readonly string $rateSource,
        public readonly string $roundingPolicy,
        public readonly string $currency,
        public readonly int $exponent,
        public readonly int $vendorUserId,
        public readonly int $baseMinor,
        public readonly int $commissionMinor,
        public readonly int $vendorShareMinor,
        public readonly string $discountAllocation = ''
    ) {
    }

    /**
     * The same row, read back.
     *
     * `toArray()` always said «for storage beside the ledger row» and until
     * `alpha.39` nothing stored it — `DbLedgerRepository::record()` wrote an
     * empty string into the `snapshot` column. That is why a retry after a
     * successful ledger write could not rebuild the rate: the figures were on
     * disk and the rate that produced them was not. Now it is written, and
     * this reads it.
     *
     * Returns `null` for anything that is not a complete snapshot, so an event
     * recorded by an older build (empty column) is reported as «figures yes,
     * rate unknown» rather than as a zero rate.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        foreach (['rate_bp', 'rate_source', 'currency', 'exponent', 'base_minor', 'commission_minor', 'vendor_minor'] as $key) {
            if (!isset($row[$key])) {
                return null;
            }
        }
        return new self(
            (int) $row['rate_bp'],
            (string) $row['rate_source'],
            (string) ($row['rounding'] ?? ''),
            (string) $row['currency'],
            (int) $row['exponent'],
            (int) ($row['vendor_id'] ?? 0),
            (int) $row['base_minor'],
            (int) $row['commission_minor'],
            (int) $row['vendor_minor'],
            (string) ($row['discount_allocation'] ?? '')
        );
    }

    /** @return array<string,scalar> for storage beside the ledger row */
    public function toArray(): array
    {
        return [
            'rate_bp' => $this->rateBasisPoints,
            'rate_source' => $this->rateSource,
            'rounding' => $this->roundingPolicy,
            'currency' => $this->currency,
            'exponent' => $this->exponent,
            'vendor_id' => $this->vendorUserId,
            'base_minor' => $this->baseMinor,
            'commission_minor' => $this->commissionMinor,
            'vendor_minor' => $this->vendorShareMinor,
            'discount_allocation' => $this->discountAllocation,
        ];
    }
}
