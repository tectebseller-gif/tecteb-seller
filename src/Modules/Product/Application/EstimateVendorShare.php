<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Finance\Application\ResolveCommissionRate;
use Tecteb\Marketplace\Modules\Finance\Application\TectebCommissionSourceInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionCalculator;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;
use Tecteb\Marketplace\Modules\Product\Domain\Product;

/**
 * Step 4's «سهم تخمینی»: what the vendor would keep if this sold today.
 *
 * It answers with the finance module's own calculator rather than a second
 * formula, so the number on the form and the number in the ledger cannot
 * drift apart.
 *
 * ⛔ **And until `alpha.66` it asked the wrong model, which is the same thing
 * as drifting apart.** `alpha.57` moved commission to a per-product rule on
 * the WooCommerce product and `RecordCommission::accrue()` has consulted
 * nothing else since; this class went on resolving the SCOPED general rate.
 * Measured on the installed `alpha.65` bytes, with a product whose own
 * commission meta was absent: `estimate_rate_source=general`,
 * `estimate_rate_bp=1000`, `estimate_commission_minor=45000` on a 450,000
 * line — a vendor told that Tecteb would take 45,000 from a product that
 * under the owner's own model has NO commission at all. Two models answering
 * one question is exactly what `TectebCommission`'s docblock was written to
 * prevent, one class away.
 *
 * So the order is now the same as `accrue()`'s: when the per-product source
 * is wired it is the ONLY one asked, and a product with no rule estimates a
 * full share rather than inheriting one — «نرخ عمومی یا سطح فروشنده نباید
 * بی‌صدا جای کمیسیون خالی محصول را بگیرد».
 *
 * The scoped rules are kept, not deleted: lines recorded before `alpha.57`
 * are explained through them, and a build without the WooCommerce-backed
 * source (the unit suites, the evidence probes of `alpha.38`/`alpha.39`) still
 * has an answer rather than a fatal — the `alpha.32` trap.
 */
final class EstimateVendorShare
{
    public function __construct(
        private readonly ResolveCommissionRate $rates,
        private readonly CommissionCalculator $calculator,
        /**
         * THE OWNER'S PER-PRODUCT COMMISSION. Optional for the one reason
         * `RecordCommission` states: positional construction in the evidence
         * probes, and a required argument would stop them loading.
         * `CommissionOwnershipTest::testTheContainerWiresTheOwnersModelIntoTheShareEstimateToo()`
         * is the proof that the real container provides it, not this sentence.
         */
        private readonly ?TectebCommissionSourceInterface $tectebRules = null,
        private readonly ?TectebCommissionCalculator $tectebCalculator = null
    ) {
    }

    public function forProduct(Product $product, string $today, string $currency = 'IRR', int $exponent = 0): CommissionOutcome
    {
        $base = Money::of($product->details->effectivePriceMinor($today), $currency, $exponent);
        if ($this->tectebRules !== null && $this->tectebCalculator !== null) {
            // The rule lives on the WooCommerce product, so a product that has
            // not been projected yet has nowhere to carry one. `forItem(0)`
            // answers `FROM_NONE`, which is «no commission» — a complete
            // answer and not a refusal, and the truthful one: there is nothing
            // on the shop to read.
            ['rule' => $rule, 'source' => $source] = $this->tectebRules->forItem((int) ($product->wcProductId ?? 0));
            return $this->tectebCalculator->calculate(
                $base,
                // One unit. The estimate is «what this product earns per
                // item», and a fixed commission is per unit; multiplying by a
                // quantity nobody has ordered would be inventing a basket.
                1,
                $rule,
                $source,
                $product->vendorUserId
            );
        }
        $resolved = $this->rates->forItem([
            'product' => (string) $product->id,
            'vendor' => (string) $product->vendorUserId,
            'category' => $product->details->categoryKey,
        ]);
        return $this->calculator->calculate($base, $resolved['rate'], $resolved['source'], $product->vendorUserId);
    }
}
