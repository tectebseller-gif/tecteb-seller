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
 *    **One of WooCommerce's two lines is NOT followed, deliberately.** Its
 *    `$maximum = max( $minimum, … )` raises a ceiling that floors below the
 *    minimum; this object refuses instead. So parity with `QuantityLimits`
 *    holds for every SATISFIABLE rule and is measured that way — and parity
 *    is not, and was never, evidence that the declared ceiling is kept. That
 *    needs its own test, and has one.
 *
 *    Whether the plugin being retired counted its «group of» the same way is
 *    a question about that plugin, and it is **not answered here**: it needs
 *    the real file, and if it differs, re-reading old rules is the owner's
 *    decision. See `docs/phase-43-purchase-quantity-and-plugin-exit.md` §3.
 *  - **a maximum need not be reachable EXACTLY, but it is never raised.**
 *    With `min = 3, step = 3, max = 10` the largest allowed quantity is 9:
 *    the ceiling is floored to a multiple, which lowers it. «Up to ten, in
 *    threes» is a sensible rule and is accepted.
 *  - **and a window with NO multiple in it is REFUSED, not widened.** With
 *    `min = 10, max = 11, step = 4` there is no multiple of four between ten
 *    and eleven. `alpha.45` answered `12/12` here — it applied WooCommerce's
 *    `max( $minimum, … )` line and so handed a vendor who had said «at most
 *    eleven» a rule allowing twelve. A ceiling is a ceiling; raising one is
 *    the one thing this object must never do, and a unit test asserting
 *    `12/12` was the defect written down as an expectation.
 *
 *    So that combination is `UNSATISFIABLE`: `validate()` refuses it on the
 *    way in at all four doors, and a rule object that nonetheless holds it —
 *    a row written before this round, or by a hand that bypassed the form —
 *    allows NOTHING and says so. Refusing is the only reading that cannot
 *    increase what may be bought, which is what «بدون افزایش مجوز خرید»
 *    requires of inconsistent data that already exists.
 *  - **an invalid maximum is not «no maximum».** `alpha.45` turned `max < min`
 *    into `null`, and `null` means unlimited — measured: `min=5 max=2` allowed
 *    9,999. That is the largest possible increase in purchase permission, and
 *    the comment defending it («no maximum sells») had the test backwards: the
 *    question is not which reading sells, it is which reading can only ever
 *    sell LESS than the vendor asked for.
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

    /**
     * No quantity at all satisfies this rule, so nothing may be bought under
     * it. Only reachable from data written before `alpha.46` or around the
     * form, because `validate()` refuses it at every door.
     */
    public const UNSATISFIABLE = 'unsatisfiable';

    /**
     * Why an incoming combination is refused, or `null`.
     *
     * These are the refusal CODES the whole plugin uses, and they live here
     * rather than in each caller because «قاعده در فرم فروشنده، پنل مدیر،
     * واردکننده و مسیر تأیید یکسان باشد»: four doors, one rule. Before this
     * round the vendor form, the CSV importer and the admin panel each had
     * their own copy of the comparison, and the panel's copy did not refuse at
     * all — it coerced.
     */
    public const INVALID_MIN = 'bad_quantity';
    public const INVALID_MAX = 'bad_quantity_max_below_min';
    public const INVALID_STEP = 'bad_quantity_step';
    public const NO_MULTIPLE = 'bad_quantity_unreachable';

    private function __construct(
        /** The effective minimum: the stated one, raised to a multiple. */
        public readonly int $min,
        /**
         * The ceiling.
         *
         * `null` means NO MAXIMUM. When `$satisfiable` is false this holds the
         * STATED maximum instead, so a message can name the impossible window
         * — and `allows()` answers false for every quantity, so no caller can
         * read it as permission. Permission is `allows()`; this is for text.
         */
        public readonly ?int $max,
        public readonly int $step,
        public readonly bool $satisfiable = true
    ) {
    }

    /** Whether any quantity at all satisfies this rule. */
    public function isSatisfiable(): bool
    {
        return $this->satisfiable;
    }

    /**
     * Why this combination may not be stored, or `null` when it may.
     *
     * Called BEFORE any write by every door. A refusal therefore leaves the
     * three stored values, and everything else on the product, exactly as
     * they were.
     */
    public static function validate(int $min, ?int $max, int $step): ?string
    {
        if ($min < 1) {
            return self::INVALID_MIN;
        }
        if ($step < 1) {
            return self::INVALID_STEP;
        }
        if ($max === null) {
            return null;                // no maximum: nothing to contradict
        }
        // Zero lands here and that is deliberate: «unlimited» is an EMPTY box,
        // never a zero, and a maximum of nought would take the product off
        // sale.
        if ($max < $min) {
            return self::INVALID_MAX;
        }
        if (self::toMultiple($max, $step, 'floor') < self::effectiveMinimum($min, $step)) {
            return self::NO_MULTIPLE;
        }
        return null;
    }

    /**
     * Two ways out of an impossible window, so a refusal can be acted on.
     *
     * ONE OF THEM MAY NOT EXIST, and that is the whole reason this takes the
     * minimum. The first version bracketed the MAXIMUM alone and named the
     * multiple below it — for `min 10, max 11, step 4` it said «8 works»,
     * and 8 does not work: the minimum raises to 12, so a maximum of 8 is
     * still below it. Its own test agreed with it only because the test
     * quietly changed the minimum to 4 as well. A refusal that names a value
     * which is refused in turn is worse than a refusal that names nothing.
     *
     * So each answer is the smallest change to ONE field that makes the
     * OTHER field's stated value work:
     *
     *  - raise the maximum to the effective minimum — always possible;
     *  - or lower the minimum to the largest multiple at or below the stated
     *    maximum — possible only when that multiple is a legal quantity, so
     *    `null` when the step itself is larger than the maximum.
     *
     * @return array{0:int,1:?int} the smallest workable maximum, then the
     *                             largest workable minimum or null
     */
    public static function workableWindow(int $min, int $max, int $step): array
    {
        $step = max(1, $step);
        $workableMax = self::effectiveMinimum(max(1, $min), $step);
        $floor = self::toMultiple(max(1, $max), $step, 'floor');
        return [$workableMax, $floor >= $step ? $floor : null];
    }

    private static function effectiveMinimum(int $min, int $step): int
    {
        return max($step, self::toMultiple(max(1, $min), $step, 'ceil'));
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
        $statedMin = max(1, $min);
        // Raising the MINIMUM to a multiple is safe in the one direction that
        // matters: it can only narrow what may be bought.
        $effectiveMin = self::effectiveMinimum($statedMin, $step);

        if ($max === null) {
            return new self($effectiveMin, null, $step);
        }
        // An invalid maximum is NOT «no maximum». `alpha.45` dropped it to
        // `null` here and so allowed 9,999 units for a vendor who had typed
        // «at most 2».
        if ($max < $statedMin) {
            return new self($effectiveMin, $max, $step, false);
        }
        // FLOORED, never raised. This is the line WooCommerce would follow
        // with `max( $minimum, … )` and this object does not: a window with no
        // multiple in it is impossible, not twelve.
        $effectiveMax = self::toMultiple($max, $step, 'floor');
        if ($effectiveMax < $effectiveMin) {
            return new self($effectiveMin, $max, $step, false);
        }
        return new self($effectiveMin, $effectiveMax, $step);
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
        return $this->satisfiable && $this->min === 1 && $this->max === null && $this->step === 1;
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
        // First, because nothing else about the rule can be acted on.
        if (!$this->satisfiable) {
            return self::UNSATISFIABLE;
        }
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
        // `of()` has already floored the maximum to a multiple, so the stored
        // value IS the ceiling — and it is never above the one the vendor
        // stated. `null` for an unsatisfiable rule because there is no largest
        // allowed quantity when none is allowed; callers decide permission
        // with `allows()`, never by reading this.
        return $this->satisfiable ? $this->max : null;
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
        if (!$this->satisfiable) {
            // There is no allowed quantity to name. The minimum is returned so
            // the type holds, and every message about an unsatisfiable rule
            // goes through its own sentence rather than through this.
            return $this->min;
        }
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
