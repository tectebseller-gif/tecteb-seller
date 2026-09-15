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
