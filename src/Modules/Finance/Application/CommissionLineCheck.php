<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\TectebCommissionCalculator;

/**
 * «CAN THIS LINE BE SOLD WITHOUT MAKING THE SUPPLIER'S SHARE NOUGHT OR
 * NEGATIVE?» — asked before money is taken, never after.
 *
 * The owner's §1: «اگر کمیسیون ثابت پس از تخفیف از مبلغ قلم بیشتر شود، خرید
 * آن قلم پیش از دریافت وجه متوقف شود، با پیام قابل‌فهم؛ مدیر نرخ یا تخفیف را
 * اصلاح کند. سهم تأمین‌کننده هرگز نباید خودکار صفر یا منفی شود.»
 *
 * The calculator has refused this case by name since `alpha.57`
 * (`EXCEEDS_LINE`) and that refusal is NOT changed here: capping would invent
 * a rate nobody set and subtracting would make a vendor owe money for selling
 * something. What was missing is WHEN the question gets asked. Until this
 * build it was asked at capture — after the buyer had paid — so the money was
 * taken, the line recorded no share, and the sale sat in
 * `incompleteCaptures()` waiting for a person. Now it is asked while the
 * basket is being validated, which is before every payment path in both UIs.
 *
 * **One question, one implementation, both baskets.** The classic cart and
 * the block cart do not share a validation hook — except that they do:
 * `CartController::validate_cart()` runs `do_action('woocommerce_check_cart_items')`
 * itself (`src/StoreApi/Utilities/CartController.php:525` in WooCommerce
 * 11.0.1) and converts the notices it produces into `WP_Error`s. So hooking
 * that one action is not a convenience, it is how «سبد کلاسیک و Store API یک
 * رفتار داشته باشند» becomes a property of the code rather than two code
 * paths somebody has to keep in step.
 *
 * **What this never does.** It does not touch an order that has been paid:
 * «سفارش گذشتهٔ پرداخت‌شده نباید با این قاعده لغو یا بازنویسی شود» — an old
 * inconsistency is the manager's to look at, and a guard that reached
 * backwards into settled money would be worse than the defect. It does not
 * change a commission rule, it does not change a discount, and it says
 * nothing at all about a product that is not this marketplace's.
 */
final class CommissionLineCheck
{
    /** The line is sellable as far as the commission rule is concerned. */
    public const OK = 'ok';
    /** The commission is larger than what the line would actually pay. */
    public const EXCEEDS_LINE = 'commission_exceeds_line';
    /** The amount could not be read exactly, so no claim is made either way. */
    public const UNREADABLE = 'commission_base_unreadable';

    public function __construct(
        private readonly TectebCommissionSourceInterface $rules,
        private readonly TectebCommissionCalculator $calculator
    ) {
    }

    /**
     * @param string $lineTotalAfterDiscount the line's own total, after
     *        discount and before tax, as exact decimal TEXT. A caller holding
     *        a float must format it the way WooCommerce itself formats the
     *        same number when it writes the order line, so that the answer
     *        here is about the figure the order will carry.
     * @return array{ok:bool, reason:string, commission_minor:int, base_minor:int,
     *               exponent:int}
     */
    public function check(
        int $wcProductId,
        int $variationId,
        string $lineTotalAfterDiscount,
        int $quantity,
        string $currency,
        int $exponent
    ): array {
        if ($quantity < 1 || $currency === '' || $exponent < 0) {
            return $this->answer(true, self::UNREADABLE, 0, 0, max(0, $exponent));
        }
        $minor = DecimalAmount::toMinor(trim($lineTotalAfterDiscount), $exponent);
        if ($minor === null) {
            // «نمی‌دانم» is not «بد است» (`alpha.49`). An amount this code
            // cannot read exactly is not evidence that the commission exceeds
            // it, and refusing a sale on a reading failure would stop a
            // storefront over a formatting question. The capture path still
            // refuses the line by name if it really is unsellable.
            return $this->answer(true, self::UNREADABLE);
        }
        if ($minor < 0) {
            return $this->answer(true, self::UNREADABLE);
        }

        ['rule' => $rule, 'source' => $source] = $this->rules->forItem($wcProductId, $variationId);
        // THE SAME CALCULATOR THE CAPTURE USES, with the same arguments in the
        // same order. Not a second implementation of the rule: a copy here
        // would be two rules disagreeing about one basket, which is the shape
        // `alpha.45` paid for on purchase quantities.
        $outcome = $this->calculator->calculate(
            Money::of($minor, $currency, $exponent),
            $quantity,
            $rule,
            $source,
            0
        );
        if ($outcome->isCalculated()) {
            // BOTH FIGURES IN ONE UNIT. The commission comes back at the
            // finer exponent an exact commission needs, so reporting it
            // beside the caller's own `$minor` would put two scales in one
            // array — and the first reader to add or compare them would be
            // wrong by ten thousand. The base is taken from the outcome,
            // which is the same money rescaled, and the unit is named.
            return $this->answer(
                true,
                self::OK,
                $outcome->commission->minor,
                (int) $outcome->base?->minor,
                $outcome->commission->exponent
            );
        }
        return $outcome->reason === TectebCommissionCalculator::EXCEEDS_LINE
            ? $this->answer(false, self::EXCEEDS_LINE, 0, $minor, $exponent)
            // Any other refusal is about the rule being unreadable or the
            // quantity being odd, and neither is a reason to stop a shopper:
            // those are refusals the capture path reports to the manager.
            : $this->answer(true, self::UNREADABLE, 0, $minor, $exponent);
    }

    /**
     * @return array{ok:bool, reason:string, commission_minor:int, base_minor:int,
     *               exponent:int}
     */
    private function answer(
        bool $ok,
        string $reason,
        int $commission = 0,
        int $base = 0,
        int $exponent = 0
    ): array {
        return [
            'ok' => $ok,
            'reason' => $reason,
            'commission_minor' => $commission,
            'base_minor' => $base,
            // The unit the two figures above are in. On a refusal they are
            // the caller's own, because there is no outcome to take one from.
            'exponent' => $exponent,
        ];
    }
}
