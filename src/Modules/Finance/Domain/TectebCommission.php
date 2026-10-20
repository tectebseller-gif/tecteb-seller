<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

use Tecteb\Marketplace\Core\Config\Sanitizer\DigitNormalizer;

/**
 * THE OWNER'S COMMISSION RULE FOR ONE PRODUCT — «نوع کمیسیون تک‌طب» and
 * «مقدار», and nothing else.
 *
 * This is a different model from `CommissionRate`, and the difference is a
 * decision rather than a refactor. `CommissionRate` answers FIN-02: a rate is
 * a percentage, an empty rate INHERITS from the vendor, the category and then
 * the marketplace default, and a sale with no rate anywhere may not happen.
 * The owner has now settled all three of those questions the other way:
 *
 *   * commission is Tecteb's share, **set per product** — «کمیسیون سهم تک‌طب
 *     است و روی صفحهٔ ویرایش محصول ووکامرس تعیین می‌شود»;
 *   * it may be a percentage **or a fixed amount per unit**, and a fixed
 *     amount multiplies by the quantity — ۲۰٬۰۰۰ × ۳ = ۶۰٬۰۰۰;
 *   * **unset or zero means no commission**, and a product with no rate is
 *     still approvable, publishable and sellable — «نبود نرخ مانع تأیید،
 *     انتشار یا فروش نیست».
 *
 * The third is why nothing here inherits. A general or vendor-level rate
 * standing in for an empty product rule would be exactly the silent
 * substitution the instruction forbids («نرخ عمومی یا سطح فروشنده نباید
 * بی‌صدا جای کمیسیون خالی محصول را بگیرد»), and it would do it on every
 * product of a site that has had a default since `alpha.6`. `CommissionRate`
 * and its scopes are NOT deleted — earlier financial records are read through
 * them and the instruction says not to erase them — they simply no longer
 * decide a new sale. The behaviour change is written down in the delivery.
 *
 * **Everything here is a string or an integer.** A percentage is basis points
 * (`۱۲٫۳۴٪` is `1234`), a fixed amount is the exact decimal text the manager
 * typed, and the conversion to minor units happens once, against the ORDER
 * LINE's own currency and scale, through `DecimalAmount`. No `float` appears
 * in this file or in the calculator: «از مکانیزم پول دقیق موجود استفاده شود؛
 * float یا تبدیل حدسی ریال/تومان ممنوع».
 */
final class TectebCommission
{
    /** No commission on this product. The default, and a complete answer. */
    public const NONE = 'none';
    /** A share of what was actually paid for the line, after discount. */
    public const PERCENT = 'percent';
    /** An amount per unit, multiplied by the quantity sold. */
    public const FIXED = 'fixed';

    /** The manager chose something this build does not know. */
    public const BAD_TYPE = 'commission_type_unknown';
    /** Not a number, or outside 0–100. */
    public const BAD_PERCENT = 'commission_percent_invalid';
    /** Not a number, negative, or more decimal places than money has. */
    public const BAD_FIXED = 'commission_fixed_invalid';

    /** Percent is stored to two decimal places, like every other rate here. */
    private const PERCENT_SCALE = 2;

    private function __construct(
        public readonly string $type,
        /** 0–10000, or null when the type is not `percent`. */
        public readonly ?int $percentBasisPoints,
        /** The exact decimal text of the per-unit amount; `''` unless fixed. */
        public readonly string $fixedPerUnit
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, null, '');
    }

    public static function percent(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > CommissionRate::MAX_BP) {
            throw new \InvalidArgumentException('a commission percentage must be between 0 and 100');
        }
        return new self(self::PERCENT, $basisPoints, '');
    }

    public static function fixed(string $perUnit): self
    {
        if (self::readFixed($perUnit) === null) {
            throw new \InvalidArgumentException('a fixed commission must be a non-negative decimal amount');
        }
        return new self(self::FIXED, null, trim($perUnit));
    }

    /**
     * WHAT A MANAGER TYPED, or the NAME of what is wrong with it.
     *
     * Two values and one refusal, never a coerced third thing. `alpha.45`
     * learned this one floor down, on the purchase window: a panel that
     * corrects an impossible input writes a rule nobody chose, and on money
     * that rule is somebody's share of a sale.
     *
     * Zero is a value and not an error — «تعیین‌نشده یا صفر یعنی بدون
     * کمیسیون» — so `percent` with `0` stores a rule that computes nothing,
     * which is a different fact from a product nobody has decided about and
     * is kept as one.
     *
     * @return array{rule:?self, reason:string} `rule` null exactly when `reason` is not ''
     */
    public static function fromInput(string $type, string $value): array
    {
        $type = trim($type);
        // THE FORM BOUNDARY, and the one place digits are translated.
        //
        // A manager types «۱۲٫۵» — the spelling this panel's own hint gives
        // as the example — and `alpha.65` refused it: `readPercent()` ends in
        // `DecimalAmount::toMinor()`, whose pattern is ASCII, and
        // `readFixed()`'s is too. Measured on the installed bytes: `12.5`
        // saved and survived a reload, `۱۲٫۵` and `١٢٫٥` both answered
        // `commission_percent_invalid` with nothing written.
        //
        // Translated HERE and not one floor down. `DecimalAmount` is the
        // conversion WooCommerce hands `wc_format_decimal()` output to, and
        // its own docblock says anything else is a caller mistake worth
        // seeing; loosening it would make every money path in the plugin
        // accept digits no storefront produces. `fromStored()` stays strict
        // for the same reason: a row can only hold what `fromInput()` let
        // through, so a non-ASCII stored value is somebody else's data and
        // «no commission» is the documented safe reading of it.
        //
        // `DigitNormalizer` was already here — `PercentToBasisPoints` and
        // `BoundedInteger` have used it since the settings page existed, and
        // it maps U+06F0–9, U+0660–9 and the Arabic decimal separator U+066B
        // and trims NBSP/ZWNJ/ZWSP. The `alpha.57` panel simply never asked
        // it («پیش از ساختن مکانیزمِ تازه، بپرسید همین مخزن قبلاً کدام را دارد»).
        //
        // What it deliberately does NOT translate is a GROUPING separator —
        // neither `٬` (U+066C) nor a comma. «۲۰٬۰۰۰» is refused by name, and
        // that is the conservative direction: a separator is positional and
        // reading one wrong changes the amount by a factor of a thousand,
        // which on somebody's share of a sale is not a thing to guess
        // («تبدیل … یا پذیرش مبهم و حدسی اضافه نکن»).
        $value = DigitNormalizer::normalize($value);

        if ($type === '' || $type === self::NONE) {
            // The amount is ignored on purpose: a manager who switches a
            // product back to «بدون کمیسیون» has said what they mean, and
            // refusing because a stale number is still in the box would be a
            // refusal about a field the form no longer shows.
            return ['rule' => self::none(), 'reason' => ''];
        }
        if ($type === self::PERCENT) {
            $bp = self::readPercent($value);
            return $bp === null
                ? ['rule' => null, 'reason' => self::BAD_PERCENT]
                : ['rule' => self::percent($bp), 'reason' => ''];
        }
        if ($type === self::FIXED) {
            return self::readFixed($value) === null
                ? ['rule' => null, 'reason' => self::BAD_FIXED]
                : ['rule' => self::fixed($value), 'reason' => ''];
        }
        return ['rule' => null, 'reason' => self::BAD_TYPE];
    }

    /**
     * What is on the product, read defensively.
     *
     * Anything unreadable is `none`, because this is asked at checkout: a rule
     * that cannot be parsed must not stop a sale, and «no commission» is the
     * documented meaning of every absence in this model. A rule that cannot be
     * parsed also cannot be SAVED — `fromInput()` refuses it by name — so the
     * only way to get here is data from somewhere else, and the safe reading
     * of somebody else's row is that it says nothing about Tecteb's share.
     */
    public static function fromStored(mixed $type, mixed $value): self
    {
        $type = is_string($type) ? trim($type) : '';
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if ($type === self::PERCENT) {
            $bp = self::readPercent($value);
            return $bp === null ? self::none() : self::percent($bp);
        }
        if ($type === self::FIXED) {
            return self::readFixed($value) === null ? self::none() : self::fixed($value);
        }
        return self::none();
    }

    /** Whether this rule can produce money at all. Zero percent cannot, and says so. */
    public function isNone(): bool
    {
        return $this->type === self::NONE;
    }

    /**
     * The number a form should put back in the box — the manager's own text.
     *
     * Percent comes back through the same two-decimal conversion it went in
     * by, so `۱۲٫۵` is `12.5` and not `12.50000000000001`: the value never
     * becomes a float, here or anywhere else.
     */
    public function displayValue(): string
    {
        if ($this->type === self::PERCENT) {
            return (string) DecimalAmount::toDecimal((int) $this->percentBasisPoints, self::PERCENT_SCALE);
        }
        return $this->type === self::FIXED ? $this->fixedPerUnit : '';
    }

    /** `0` – `10000`, or null when the text is not a percentage in range. */
    private static function readPercent(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $bp = DecimalAmount::toMinor($value, self::PERCENT_SCALE);
        if ($bp === null || $bp < 0 || $bp > CommissionRate::MAX_BP) {
            return null;
        }
        return $bp;
    }

    /**
     * The per-unit amount, checked for SHAPE only.
     *
     * It is deliberately not converted to minor units here. The scale that
     * decides whether `۲۰٬۰۰۰٫۵۰` is storable belongs to the order's currency,
     * which this class cannot see and which can differ from the shop's display
     * setting; the conversion happens once, in the calculator, against the
     * line's own exponent, and a value that will not fit is refused there by
     * name rather than rounded.
     */
    private static function readFixed(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d+(?:\.\d{1,' . DecimalAmount::MAX_EXPONENT . '})?$/', $value) !== 1) {
            return null;
        }
        return $value;
    }
}
