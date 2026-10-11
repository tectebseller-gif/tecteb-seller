<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * How many units of one sellable item a buyer may take — the whole rule, in
 * one place.
 *
 * Until `alpha.45` `minPurchase` and `maxPurchase` were stored, formed,
 * validated and exported to CSV, and then **nothing read them**: no filter,
 * no projection, no check at the till. Measured on `alpha.44` with real
 * WooCommerce 11.0.1 — `row_min=2 row_max=10`, and every candidate meta key
 * on the shop product `__absent__`. The owner's report that WooCommerce's
 * quantity fields were empty was therefore exactly right, and the reason was
 * not a mapping problem: there was no writer at all.
 *
 * ---------------------------------------------------------------------------
 * THE SEMANTICS, WRITTEN DOWN
 * ---------------------------------------------------------------------------
 *
 * These are stated rather than chosen, and each one is what the build already
 * did — «سازگار با رفتار موجود؛ تصمیم تجاری تازه را بی‌اعلام انتخاب نکن»:
 *
 *  - **minimum.** An integer of at least 1. Empty and zero both mean 1, which
 *    is `ProductArea::detailsFromPost()`'s `max(1, …)` as it has stood since
 *    `alpha.6`, and the column is `INT NOT NULL DEFAULT 1`. «No minimum» and
 *    «a minimum of one unit» are the same rule, so there is no third state to
 *    represent.
 *  - **maximum.** Nullable. `NULL` — which an empty box produces — means NO
 *    MAXIMUM: the buyer is bounded by stock and by nothing of ours. Zero is
 *    NOT «unlimited»: `validateQuantities()` has refused `max < min` since
 *    `alpha.6`, so `0` is `bad_quantity` and stays so. Reading `0` as
 *    unlimited would make the one value a vendor might type to mean «none»
 *    mean «any number».
 *  - **step.** An integer of at least 1, new in this round. `1` means no
 *    stepping, which is what every existing row gets and why the migration
 *    needs no backfill beyond its default.
 *  - **the step counts in multiples of ITSELF, and the minimum is raised to
 *    the next multiple.** With `min = 2, step = 3` the allowed quantities are
 *    3, 6, 9 — and the effective minimum is 3, not 2.
 *
 *    This convention is **WooCommerce's, not one invented here**, and it was
 *    read out of WooCommerce 11.0.1 rather than assumed. `QuantityLimits`
 *    builds the Store API's limits — the ones the block cart and checkout
 *    enforce — straight out of `wc_get_quantity_input_args()`, and then:
 *
 *        $minimum = max( $multiple_of, $this->limit_to_multiple( $minimum, $multiple_of, 'ceil' ) );
 *        $maximum = max( $minimum, $this->limit_to_multiple( $maximum, $multiple_of, 'floor' ) );
 *                              — src/StoreApi/Utilities/QuantityLimits.php:63
 *
 *    `limit_to_multiple()` rounds to a multiple OF THE STEP, counted from
 *    zero. So the shop's own block cart will enforce multiples-of-step no
 *    matter what this class believes, and a «base is the minimum» reading
 *    here would have meant TWO rules: one on the classic product page and a
 *    different one in the block cart, disagreeing about the same basket.
 *    «دو رندرکنندهٔ یک فهرست، دو فهرست‌اند» — so the arithmetic below is
 *    WooCommerce's, line for line, and there is one rule.
 *
 *    With `step = 1` — every product today, and every row the migration
 *    defaults — this degenerates to exactly the previous behaviour: the
 *    effective minimum is the minimum and the effective maximum is the
 *    maximum. Nothing about an existing product changes.
 *
 *    Whether the plugin being retired counted its «group of» the same way is
 *    a question about that plugin, and it is **not answered here**: it needs
 *    the real file, and if it differs, re-reading old rules is the owner's
 *    decision. See `docs/phase-43-purchase-quantity-and-plugin-exit.md` §3.
 *  - **a maximum need not be reachable.** With `min = 3, step = 3, max = 10`
 *    the largest allowed quantity is 9. The combination is accepted rather
 *    than refused, because refusing it would reject a perfectly sensible
 *    «up to ten, in threes», and `largestAllowed()` names the real ceiling
 *    wherever one is shown.
 *  - **the rule is per SELLABLE ITEM.** For a simple product that is the
 *    product; for a variable one it is each VARIATION, which is what a buyer
 *    actually chooses and what holds the stock. A variable product has no
 *    per-variation override in this round — the parent's rule applies to each
 *    variation alike — and that is a stated limitation, not a silent absence
 *    (`ProductVariation` carries no quantity fields).
 *  - **it counts the whole basket, not one line.** WooCommerce will happily
 *    hold the same item in two lines. A maximum enforced per line is a
 *    maximum of twice itself, so every check sums the quantity of that item
 *    across the cart.
 *
 * Nothing here knows about stock. A maximum is this vendor's selling rule and
 * stock is WooCommerce's number (ADR-008); the two compose by taking whichever
 * is smaller, at the one place that composes them, and never by this object
 * raising a ceiling somebody else lowered.
 */
final class PurchaseQuantity
{
    public const OK = 'ok';
    public const BELOW_MIN = 'below_min';
    public const ABOVE_MAX = 'above_max';
    public const OFF_STEP = 'off_step';

    private function __construct(
        public readonly int $min,
        public readonly ?int $max,
        public readonly int $step
    ) {
    }

    /**
     * The rule as the stored row expresses it, coerced to the semantics above.
     *
     * Coerced rather than trusted: these three numbers come from a database
     * column, a CSV import, an approved proposal and a manager's edit in
     * WooCommerce, and a rule object that can hold `min = 0` is a rule object
     * whose every reader has to re-check it.
     */
    public static function of(int $min, ?int $max, int $step = 1): self
    {
        $step = max(1, $step);
        // A maximum below the minimum cannot be honoured and must not be
        // invented away either: it is refused on the way IN
        // (`bad_quantity_max_below_min`), so reaching here means a row written
        // before this round or by a hand that bypassed the form. Dropping it
        // is the conservative reading — «no maximum» sells, «a maximum of
        // zero» sells nothing and would take a vendor's whole catalogue off
        // sale without a word.
        if ($max !== null && $max < max(1, $min)) {
            $max = null;
        }
        // WooCommerce's own two lines, in WooCommerce's own order. Stored
        // here rather than recomputed by each reader, so «what does this rule
        // allow» has one answer: the vendor's form, the product page, the
        // classic cart, the block cart and the final order check all read
        // these three numbers.
        $min = max($step, self::toMultiple(max(1, $min), $step, 'ceil'));
        if ($max !== null) {
            $max = max($min, self::toMultiple($max, $step, 'floor'));
        }
        return new self($min, $max, $step);
    }

    /**
     * A number rounded to a multiple of another — `limit_to_multiple()`'s
     * integer half.
     *
     * Integer arithmetic only. WooCommerce divides and rounds in floating
     * point because it supports fractional stock; every quantity in this
     * plugin is an integer (`stock INT`, `min_purchase INT`), and `intdiv`
     * cannot be off by one the way `floor(0.1 + 0.2 …)` can.
     */
    private static function toMultiple(int $number, int $step, string $rounding): int
    {
        if ($step <= 0 || $number % $step === 0) {
            return $number;
        }
        return $rounding === 'ceil'
            ? (intdiv($number, $step) + 1) * $step
            : intdiv($number, $step) * $step;
    }

    public static function unrestricted(): self
    {
        return new self(1, null, 1);
    }

    /** True when this rule allows anything a plain shop would. */
    public function isUnrestricted(): bool
    {
        return $this->min === 1 && $this->max === null && $this->step === 1;
    }

    /**
     * Why this quantity is not allowed, or `OK`.
     *
     * The order matters: a quantity below the minimum is reported as below the
     * minimum even when it is also off the step, because the minimum is the
     * sentence a buyer can act on.
     */
    public function check(int $wanted): string
    {
        if ($wanted < $this->min) {
            return self::BELOW_MIN;
        }
        if ($this->max !== null && $wanted > $this->max) {
            return self::ABOVE_MAX;
        }
        if ($this->step > 1 && $wanted % $this->step !== 0) {
            return self::OFF_STEP;
        }
        return self::OK;
    }

    public function allows(int $wanted): bool
    {
        return $this->check($wanted) === self::OK;
    }

    /**
     * The largest quantity this rule allows, or `null` when it allows any.
     *
     * Not simply `$max`: with `min = 2, step = 3, max = 10` the top allowed
     * value is 8, and a message naming 10 would send a buyer to a quantity
     * the same rule then refuses.
     */
    public function largestAllowed(): ?int
    {
        // `of()` has already floored the maximum to a multiple and raised it
        // to at least the minimum, so the stored value IS the ceiling. Kept as
        // a method rather than inlined at the call sites: «the largest allowed
        // quantity» is a question the messages ask, and the day the arithmetic
        // moves it must move in one place.
        return $this->max;
    }

    /**
     * The allowed quantity nearest to what was asked, never below the minimum
     * and never above the ceiling.
     *
     * Used only to SHOW a buyer a number they can use. Nothing silently
     * replaces a submitted quantity with this: a basket that quietly changed
     * what somebody asked for is worse than one that says no.
     */
    public function nearestAllowed(int $wanted): int
    {
        if ($wanted <= $this->min) {
            return $this->min;
        }
        if ($this->max !== null && $wanted >= $this->max) {
            return $this->max;
        }
        $snapped = self::toMultiple($wanted, $this->step, 'floor');
        return $snapped < $this->min ? $this->min : $snapped;
    }
}
