<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Domain;

use Tecteb\Marketplace\Modules\Product\Domain\PurchaseQuantity;
use Tecteb\Marketplace\Modules\Product\Domain\QuantitySet;

/**
 * One purchase-quantity rule as «Min Max Control» 8.1.0 holds it, and whether
 * this marketplace can hold the same rule.
 *
 * Read out of the real plugin — the owner's own copy, sha256
 * `1e5aa797…f125fa`, header 8.1.0, `WC_MMQ_VERSION` 8.1.0.0 — and not from
 * memory or documentation. Every sentence below names the file and line it
 * came from, because the whole value of this class is that it is the OTHER
 * plugin's arithmetic rather than a plausible reading of it.
 *
 * ---------------------------------------------------------------------------
 * WHERE A RULE COMES FROM, IN THE ORDER THE PLUGIN ASKS
 * ---------------------------------------------------------------------------
 *
 * `Min_Max_Controller::assignInputArg()` (includes/min-max-controller.php:488)
 * resolves in this order and stops at the first answer:
 *
 *  1. **the variation's own meta** — `setIfVariationArgs()`, and it is gated
 *     on `$this->is_pro`, which is `defined('WC_MMQ_PRO_VERSION')`. The
 *     submitted build has no premium loader and no `premium/` directory, so
 *     **in the owner's build this level never fires**: `organizeAndFinalizeArgs()`
 *     nulls the variation id outright when not pro (line 429). A variable
 *     product's rule is therefore the PARENT's, and the cart count sums every
 *     variation of that parent (`checkQtyInCart()`, the `! is_pro` branch).
 *     Recorded as a level anyway, because a variation meta row may exist on
 *     the site from an earlier premium install and would start applying again
 *     the moment one is installed.
 *  2. **the product's own meta** — taken when ANY of min/max/step is non-empty,
 *     or when min is `0`/`'0'`. Note «any»: a product with only a maximum set
 *     takes its min and step from this level too, as empty, and
 *     `finalizeArgs()` then fills them.
 *  3. **a term rule** — `setTermwiseArgs()`, from `$options['terms']`, matched
 *     against the product's terms in each configured taxonomy. The LAST
 *     intersecting term id wins (`end()`), not the most specific one.
 *  4. **the global default** — `$options['min_quantity'] ?? 1`,
 *     `$options['max_quantity'] ?? -1`, `$options['product_step'] ?? 1`.
 *
 * ---------------------------------------------------------------------------
 * WHAT EMPTY, ZERO AND «UNLIMITED» MEAN THERE — AND THEY ARE NOT OURS
 * ---------------------------------------------------------------------------
 *
 * `finalizeArgs()` (line 620) normalises, and each of these is a separate
 * fact that the conversion has to keep apart:
 *
 *  - **an empty minimum becomes `'1'`, and a minimum of `'0'` survives.** The
 *    test is `empty($min) && $min !== '0'`, so the STRING zero is kept and the
 *    INTEGER zero is not. A kept zero then passes `getModulous()`'s
 *    `min <= quantity` for a quantity of nought.
 *  - **an empty maximum means «as much as there is in stock»**, not «any
 *    number»: it becomes the stock quantity when backorders are off, and `''`
 *    — truly unlimited — only when stock is not tracked or backorders are on.
 *  - **the global default maximum is `-1`**, which is not empty, survives
 *    normalisation and reaches the cart as `-1`. `update_cart_validation()`
 *    reads `max < 0` as unlimited (line 1029).
 *  - **a maximum is lowered to stock and never raised** (line 645).
 *  - **an empty step becomes `'1'`.**
 *  - **`default_quantity` is not a limit at all**: it pre-fills the quantity
 *    box in the loop and Gutenberg templates
 *    (modules/module/loop-template-button.php:53). Nothing validates it.
 *
 * Stock is deliberately NOT part of this object. The stock clamp is
 * WooCommerce's number applied at render time by both plugins alike, and this
 * marketplace already composes its own ceiling with stock at the one place
 * that composes them (`PurchaseQuantity`'s closing note). Converting a rule
 * must not bake today's stock into tomorrow's ceiling.
 *
 * ---------------------------------------------------------------------------
 * THE ONE DIFFERENCE THAT DECIDES WHETHER A RULE CAN BE CONVERTED AT ALL
 * ---------------------------------------------------------------------------
 *
 * `PurchaseQuantity`'s docblock says, of the step, «Whether the plugin being
 * retired counted its “group of” the same way is a question about that plugin,
 * and it is **not answered here**: it needs the real file». The real file is
 * now in hand and **the answer is no**:
 *
 *     $final_qty = $quantity_int - $min_quantity_int;
 *     $module    = $final_qty % $step_int;
 *     if ( $module == 0 ) { $modulous = true; }
 *                     — includes/set_max_min_quantity.php:86-93
 *
 * Min Max allows every quantity CONGRUENT TO THE MINIMUM modulo the step. This
 * marketplace allows every MULTIPLE OF THE STEP, with the minimum raised to
 * the next multiple — WooCommerce's own convention, adopted in `alpha.45` and
 * `alpha.46` after measuring `QuantityLimits` pair for pair, precisely so that
 * the classic product page and the block cart cannot disagree about one
 * basket.
 *
 * With `min = 2, step = 3` Min Max allows 2, 5, 8 and this marketplace allows
 * 3, 6, 9 — **the two sets share no quantity whatsoever**. Converting such a
 * rule by copying the three numbers would at once REMOVE permission (2 was
 * allowed, now refused) and WIDEN it (3 was refused, now allowed). Either on
 * its own is forbidden by this round's instruction; both at once, silently, on
 * a live catalogue, is the kind of change nobody discovers until a buyer
 * complains.
 *
 * So the two rules agree exactly when the step divides the minimum — and that
 * includes every `step = 1`, which is every rule where no stepping was asked
 * for. Those convert. The rest are reported BY NAME and the Min Max exit stays
 * blocked for them, which is what «اگر قاعده‌ای جایگزین ندارد، خروج Min Max
 * مسدود بماند و دقیقاً همان قاعده نام برده شود» requires.
 *
 * Nothing here guesses a resolution. Rewriting `min = 2, step = 3` as
 * `min = 3, step = 3` would be this plugin deciding that a vendor who said
 * «twos, in threes» meant «threes» — a business decision about somebody
 * else's prices, and the owner's to make.
 */
final class MinMaxRule
{
    /** The four levels, in the order the plugin asks. */
    public const AT_VARIATION = 'variation';
    public const AT_PRODUCT = 'product';
    public const AT_TERM = 'term';
    public const AT_GLOBAL = 'global';

    /** The same rule, expressible here. */
    public const CONVERTIBLE = 'convertible';

    /**
     * Expressible, and the conversion narrows by one quantity WooCommerce
     * itself never allowed. Reported rather than silent.
     */
    public const MIN_ZERO_FLOORED = 'min_zero_floored';

    /** The step counts from a different base. No conversion exists. */
    public const STEP_BASE_DIFFERS = 'step_base_differs';

    /** A window Min Max itself allows nothing through, and nor would we. */
    public const MAX_BELOW_MIN = 'max_below_min';

    /** A pre-filled quantity box. This marketplace has no such field. */
    public const DEFAULT_QUANTITY_UNSUPPORTED = 'default_quantity_unsupported';

    private function __construct(
        /** One of the `AT_*` constants: where the plugin took this from. */
        public readonly string $level,
        /** Exactly the strings the source holds, before any normalisation. */
        public readonly string $sourceMin,
        public readonly string $sourceMax,
        public readonly string $sourceStep,
        public readonly string $sourceDefault,
        /** `finalizeArgs()` applied, stock left out of it. */
        public readonly int $min,
        /** `null` when Min Max would enforce no ceiling of its own. */
        public readonly ?int $max,
        public readonly int $step,
        /** `null` when no pre-filled quantity is configured. */
        public readonly ?int $default
    ) {
    }

    /**
     * A rule from the four raw values a level holds.
     *
     * The strings are kept as given — «مقدارهای منبع حفظ شوند» — and the
     * integers are what the plugin would enforce.
     */
    public static function from(
        string $level,
        string $min,
        string $max,
        string $step,
        string $default = ''
    ): self {
        return new self(
            $level,
            $min,
            $max,
            $step,
            $default,
            self::finalMin($min),
            self::finalMax($max),
            self::finalStep($step),
            self::finalDefault($default)
        );
    }

    /**
     * `finalizeArgs()`'s first line, and the string/integer zero distinction
     * is the whole content of it.
     *
     * A kept `'0'` is carried as 0 here. It is not a quantity WooCommerce ever
     * adds to a cart, and `convert()` floors it to 1 — but it is floored
     * LOUDLY, under its own code, rather than being read as «one» on the way
     * in and so becoming invisible.
     */
    private static function finalMin(string $min): int
    {
        $trimmed = trim($min);
        if ($trimmed === '0') {
            return 0;
        }
        if ($trimmed === '' || !is_numeric($trimmed) || (float) $trimmed == 0.0) {
            return 1;
        }
        return (int) $trimmed;
    }

    /**
     * `null` means «no ceiling from Min Max».
     *
     * Empty is one such case, and so is any negative number: the global
     * default IS `-1` and the cart reads `max < 0` as unlimited. Zero is NOT
     * — `empty('0')` is true, so `finalizeArgs()` replaces a zero maximum with
     * stock, which this object reports as no ceiling of Min Max's own. Stock
     * stays WooCommerce's business.
     */
    private static function finalMax(string $max): ?int
    {
        $trimmed = trim($max);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $value = (int) $trimmed;
        return $value <= 0 ? null : $value;
    }

    private static function finalStep(string $step): int
    {
        $trimmed = trim($step);
        if ($trimmed === '' || !is_numeric($trimmed) || (int) $trimmed < 1) {
            return 1;
        }
        return (int) $trimmed;
    }

    private static function finalDefault(string $default): ?int
    {
        $trimmed = trim($default);
        if ($trimmed === '' || !is_numeric($trimmed) || (int) $trimmed < 1) {
            return null;
        }
        return (int) $trimmed;
    }

    /** True when this level holds nothing at all — level 2's own test. */
    public function isEmpty(): bool
    {
        return trim($this->sourceMin) === ''
            && trim($this->sourceMax) === ''
            && trim($this->sourceStep) === ''
            && trim($this->sourceDefault) === '';
    }

    /**
     * Whether a level ANSWERS, by the plugin's own test rather than ours.
     *
     * `assignInputArg()` takes the product level when any of the three is
     * non-empty OR the minimum is zero — and `default_quantity` is NOT part of
     * that test, which is why it is asked for separately. A product carrying
     * only a `default_quantity` falls through to the term or global level for
     * its limits, and this method says so.
     */
    public function answers(): bool
    {
        return trim($this->sourceMin) !== ''
            || trim($this->sourceMax) !== ''
            || trim($this->sourceStep) !== ''
            || trim($this->sourceMin) === '0';
    }

    /**
     * True when this rule restricts nothing a plain WooCommerce shop allows.
     *
     * The global default `1 / -1 / 1` is exactly this, which matters: a site
     * whose global rule is the shipped default has nothing to migrate at that
     * level, and saying so is the difference between «3,200 rules to move» and
     * «none».
     */
    public function isUnrestricted(): bool
    {
        return $this->min <= 1 && $this->max === null && $this->step === 1 && $this->default === null;
    }

    /**
     * Whether this marketplace can hold the same rule, and why not.
     *
     * Several things can be wrong with one rule, so this answers a LIST and
     * the caller reports all of it. A single verdict would have let a rule
     * with both an impossible window and an unsupported default be reported
     * as one problem, fixed, and then fail again.
     *
     * @return list<string> empty when the rule converts unchanged
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->default !== null && $this->default !== $this->minAfterConversion()) {
            // Equal to the effective minimum is no difference: WooCommerce's
            // quantity input already starts at the minimum.
            $problems[] = self::DEFAULT_QUANTITY_UNSUPPORTED;
        }
        if ($this->max !== null && $this->max < max(1, $this->min)) {
            $problems[] = self::MAX_BELOW_MIN;
        }
        if ($this->step > 1 && $this->minAfterConversion() % $this->step !== 0) {
            $problems[] = self::STEP_BASE_DIFFERS;
        }
        return $problems;
    }

    /** @return list<string> things to report that are not refusals */
    public function notes(): array
    {
        return $this->min === 0 ? [self::MIN_ZERO_FLOORED] : [];
    }

    public function convertible(): bool
    {
        return $this->problems() === [];
    }

    /**
     * The minimum this marketplace would store: never below one.
     *
     * Separate from `$this->min` on purpose — `$this->min` is what Min Max
     * enforces and may be zero; this is what we would write. Keeping them
     * apart is what lets `problems()` compare the step base against the value
     * that would actually be stored rather than against a zero that never
     * reaches a cart.
     */
    public function minAfterConversion(): int
    {
        return max(1, $this->min);
    }

    /**
     * The rule as this marketplace would hold it.
     *
     * Only ever called for a convertible rule; a caller that asks anyway gets
     * the honest coercion `PurchaseQuantity` applies, which for a
     * `STEP_BASE_DIFFERS` rule is NOT the same rule — hence the guard, so that
     * «it returned something» can never be mistaken for «it converted».
     */
    public function converted(): PurchaseQuantity
    {
        return PurchaseQuantity::of($this->minAfterConversion(), $this->max, $this->step);
    }

    /**
     * The set of quantities MIN MAX allows, exactly.
     *
     * The base is this rule's own MINIMUM, which is the one difference between
     * the two plugins' arithmetic and the whole reason this class exists:
     * `$module = ($quantity - $min_quantity) % $step`
     * (`set_max_min_quantity.php:86-93`).
     *
     * Exact, and therefore the thing to compare — `allowedUpTo()` below lists
     * examples and is not evidence of equality, which is what `alpha.70`'s
     * exit verdict used it as.
     */
    public function allowedSet(): QuantitySet
    {
        return QuantitySet::of($this->min, $this->max, $this->min, $this->step);
    }

    /**
     * The set this marketplace would allow once the rule is held here.
     *
     * For a rule that does not convert this is NOT the same set — which is the
     * point: `allowedSet()->equals($this->convertedSet())` is false exactly
     * when the conversion would change what a shopper may buy.
     */
    public function convertedSet(): QuantitySet
    {
        return QuantitySet::fromPurchaseQuantity($this->converted());
    }

    /**
     * The quantities both plugins allow, up to a bound — an ILLUSTRATION of
     * the two rules, for a report somebody reads.
     *
     * ⛔ NOT a test of equality, and `alpha.70` used it as one: two rules that
     * differ only above the bound produce identical lists. Ask
     * `allowedSet()->equals($this->convertedSet())` for that.
     *
     * Used by the preview and by the tests rather than by the migration, and
     * it exists because «the numbers are the same» is not the question. The
     * question is whether the SET of allowed quantities is the same, and for a
     * step rule those are different questions.
     *
     * @return array{source:list<int>, converted:list<int>}
     */
    public function allowedUpTo(int $bound): array
    {
        $source = [];
        $converted = [];
        $rule = $this->converted();
        for ($q = 1; $q <= max(1, $bound); $q++) {
            if ($this->sourceAllows($q)) {
                $source[] = $q;
            }
            if ($rule->allows($q)) {
                $converted[] = $q;
            }
        }
        return ['source' => $source, 'converted' => $converted];
    }

    /**
     * Min Max's own verdict on one quantity, with its own arithmetic.
     *
     * Transcribed from `add_to_cart_validation()` and
     * `wcmmq_qty_validation_by_step_modulous()`, in their order: the step is
     * only asked once the quantity is inside the window.
     */
    public function sourceAllows(int $quantity): bool
    {
        if ($quantity < $this->min) {
            return false;
        }
        if ($this->max !== null && $quantity > $this->max) {
            return false;
        }
        if ($this->step <= 1) {
            return true;
        }
        return ($quantity - $this->min) % $this->step === 0;
    }
}
