<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The quantities a purchase-limit rule allows, as a set — and whether two such
 * sets are the same set.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS, AND WHY SAMPLING WAS NOT ENOUGH
 * ---------------------------------------------------------------------------
 *
 * `MigrateMinMax::classifyDecided()` has to answer one question about a
 * product whose rule was migrated earlier: **do Min Max and this marketplace
 * allow exactly the same quantities today?** Until `alpha.71` it answered by
 * asking both sides about the quantities 1…60 and declaring them equal when
 * all sixty answers matched. Measured on `alpha.70`'s own installed bytes:
 *
 *  - Min Max `min=1, max=100, step=1` against stored rows `1 / 200 / 1`:
 *    sixty matching answers, first disagreement at **101**, verdict
 *    `permitted`. Deactivating Min Max would have WIDENED that product's
 *    ceiling from 100 to 200 with the exit report saying nothing.
 *  - Min Max `min=70` against stored rows `min=80`: both sides refuse every
 *    quantity from 1 to 60, so all sixty answers matched perfectly, and the
 *    verdict was `permitted`. Deactivating Min Max would have NARROWED that
 *    product from «70 or more» to «80 or more».
 *
 * Raising the bound is not a fix: whatever bound is chosen, a rule whose
 * ceiling or minimum sits above it is sampled into agreement. A bound is a
 * statement about the examples somebody thought of.
 *
 * ---------------------------------------------------------------------------
 * WHY THE QUESTION IS DECIDABLE WITHOUT ENUMERATING ANYTHING
 * ---------------------------------------------------------------------------
 *
 * Both sides allow an ARITHMETIC PROGRESSION and nothing else, and each is
 * fixed by the four values this class takes:
 *
 *  - Min Max allows every quantity congruent to its MINIMUM modulo the step,
 *    inside its window (`set_max_min_quantity.php:86-93`, transcribed in
 *    `MinMaxRule::sourceAllows()`) — so its base is its own minimum.
 *  - This marketplace allows every MULTIPLE OF THE STEP, with the minimum
 *    raised to the next multiple and the maximum floored to one
 *    (`PurchaseQuantity::of()`) — so its base is nought.
 *
 * A progression with two or more members has exactly one smallest member, one
 * common difference and one largest member (or none, when there is no
 * ceiling). So the triple `(first, step, last)` is a faithful fingerprint of
 * the set, and two sets are equal precisely when their triples are. Two
 * normalisations are needed to make it faithful, and both are measured
 * differences rather than tidiness:
 *
 *  - a set with ONE member has no common difference, so `{5}` written with
 *    step 2 and `{5}` written with step 7 are the same set;
 *  - an EMPTY set is one set however it was spelled, and both sides can
 *    produce one — Min Max from a window with no congruent quantity in it,
 *    this marketplace from `isSatisfiable() === false`.
 *
 * ---------------------------------------------------------------------------
 * AND «I CANNOT TELL» IS A THIRD ANSWER
 * ---------------------------------------------------------------------------
 *
 * `known() === false` is not «different» and it is certainly not «equal»: it
 * is the answer for a rule whose numbers lie outside what either store can
 * hold. `min_purchase`, `max_purchase` and `purchase_step` are `INT` columns
 * — signed 32-bit, measured from `M0005CreateProductTables` and
 * `M0024PurchaseStep` — while Min Max keeps its three values in
 * `wp_postmeta`, which is text and will hold any digits at all. A row saying
 * `5000000000` therefore describes a limit this marketplace cannot express,
 * and the honest verdict about it is «not provable», which the exit treats as
 * a blocker. `alpha.49`'s rule, one object down: «نمی‌دانم» is a third answer
 * and reading it as the safe one is how a guard comes to permit things.
 */
final class QuantitySet
{
    /**
     * The widest quantity any of the three columns can hold.
     *
     * Signed `INT`, not a number chosen here: `min_purchase INT NOT NULL
     * DEFAULT 1` and `max_purchase INT NULL` since migration 5, and
     * `purchase_step INT NOT NULL DEFAULT 1` since migration 24.
     */
    private const STORABLE = 2147483647;

    private function __construct(
        /** False when the rule's numbers put the answer out of reach. */
        public readonly bool $known,
        /** True when no quantity at all is allowed. */
        public readonly bool $empty,
        /** The smallest allowed quantity. Meaningless when empty or unknown. */
        public readonly int $first,
        /** The common difference, normalised to 1 when fewer than two members. */
        public readonly int $step,
        /** The largest allowed quantity, or `null` when there is no ceiling. */
        public readonly ?int $last
    ) {
    }

    /** No answer: the numbers are outside what either store can hold. */
    public static function unknown(): self
    {
        return new self(false, true, 0, 1, null);
    }

    /** One set: the empty one. */
    public static function nothing(): self
    {
        return new self(true, true, 0, 1, null);
    }

    /**
     * The set a rule allows, from the four values that define it.
     *
     * @param int      $min  the smallest quantity the rule itself admits
     * @param int|null $max  its ceiling, or `null` for none
     * @param int      $base the quantity the multiples are counted FROM —
     *                       the minimum for Min Max, nought here
     * @param int      $step the common difference; anything below 1 is no step
     */
    public static function of(int $min, ?int $max, int $base, int $step): self
    {
        if (
            !self::storable($min)
            || !self::storable($base)
            || !self::storable($step)
            || ($max !== null && !self::storable($max))
        ) {
            return self::unknown();
        }
        $stride = max(1, $step);
        // A basket quantity is never below one, so the set is taken over the
        // quantities a shopper can actually ask for. Min Max's own minimum
        // may be zero (`finalizeArgs()` keeps the string `'0'`) and that zero
        // is not a quantity WooCommerce adds to a cart.
        $lower = max(1, $min);
        // The smallest allowed quantity: the first one at or above the lower
        // bound that is congruent to the base. Both operands are inside
        // signed-32-bit by the guard above, so neither the subtraction nor the
        // addition below can overflow a 64-bit integer.
        $offset = (($lower - $base) % $stride + $stride) % $stride;
        $first = $offset === 0 ? $lower : $lower + ($stride - $offset);
        if ($max !== null && $max < $first) {
            // A window with no congruent quantity in it. Min Max reaches this
            // with `min = 2, max = 3, step = 4`; this marketplace reaches it
            // through `isSatisfiable() === false`. One set, either way.
            return self::nothing();
        }
        $last = $max === null ? null : $first + intdiv($max - $first, $stride) * $stride;
        return new self(
            true,
            false,
            $first,
            // One member, no common difference. Without this, `{5}` stored
            // with step 1 and `{5}` stored with step 5 would read as two
            // different sets, and the exit would block on a difference that
            // no shopper could ever meet.
            $last !== null && $last === $first ? 1 : $stride,
            $last
        );
    }

    /**
     * The set this marketplace would enforce for a rule it holds.
     *
     * Base nought, because `check()` asks `$wanted % $this->step !== 0` — the
     * one difference from Min Max that `MinMaxRule` was written to name.
     */
    public static function fromPurchaseQuantity(PurchaseQuantity $rule): self
    {
        if (!$rule->isSatisfiable()) {
            return self::nothing();
        }
        return self::of($rule->min, $rule->max, 0, $rule->step);
    }

    public function known(): bool
    {
        return $this->known;
    }

    /**
     * Whether two rules allow exactly the same quantities.
     *
     * An unknown set equals nothing, INCLUDING another unknown set: two rules
     * nobody can read are not two rules somebody checked.
     */
    public function equals(self $other): bool
    {
        if (!$this->known || !$other->known) {
            return false;
        }
        if ($this->empty || $other->empty) {
            return $this->empty && $other->empty;
        }
        return $this->first === $other->first
            && $this->step === $other->step
            && $this->last === $other->last;
    }

    /** The set in one short string, for a report a manager can disagree with. */
    public function describe(): string
    {
        if (!$this->known) {
            return 'unknown';
        }
        if ($this->empty) {
            return 'none';
        }
        return $this->first . '..' . ($this->last ?? '∞') . '/' . $this->step;
    }

    private static function storable(int $value): bool
    {
        return $value >= -self::STORABLE - 1 && $value <= self::STORABLE;
    }
}
