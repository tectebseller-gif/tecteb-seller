<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Product\Domain\Product;

/**
 * Step 4's «سهم تخمینی»: what the vendor would keep if this sold today.
 *
 * It answers with the finance module's own calculator rather than a second
 * formula, so the number on the form and the number in the ledger cannot
 * drift apart. And when no rate is configured anywhere it returns
 * NeedsConfiguration, which the form prints as «تعیین‌نشده» — FIN-02 forbids
 * showing a guessed zero, and a vendor told «سهم شما ۱۰۰٪ است» because nobody
 * set a rate yet would be told something false.
 */
final class EstimateVendorShare
{
    public function __construct(
        private readonly ResolveCommissionRate $rates,
        private readonly CommissionCalculator $calculator
    ) {
    }

    public function forProduct(Product $product, string $today, string $currency = 'IRR', int $exponent = 0): CommissionOutcome
    {
        $base = $product->details->effectivePriceMinor($today);
        $resolved = $this->rates->forItem([
            'product' => (string) $product->id,
            'vendor' => (string) $product->vendorUserId,
            'category' => $product->details->categoryKey,
        ]);
        return $this->calculator->calculate(
            Money::of($base, $currency, $exponent),
            $resolved['rate'],
            $resolved['source'],
            $product->vendorUserId
        );
    }
}
