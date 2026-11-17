<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One named property of a product, with the values it can take — and TWO
 * independent decisions about what that property is FOR.
 *
 * `alpha.108` had neither decision, and the owner's §3 asks for both: «تعیین
 * کند ویژگی در صفحه محصول دیده شود» and «تعیین کند ویژگی برای ساخت تنوع
 * استفاده شود». They are independent because WooCommerce models them
 * independently (`WC_Product_Attribute::set_visible()` and `set_variation()`)
 * and because all four combinations are things a vendor means:
 *
 *  - visible, not an axis → «جنس: پلاستیک» in the «اطلاعات بیشتر» table. The
 *    case `alpha.108` could not express at all.
 *  - visible and an axis → «تعداد در بسته», the scenario's own example: the
 *    buyer chooses it AND it is listed among the product's properties.
 *  - an axis, not visible → the buyer chooses it and it is not repeated in the
 *    table. WooCommerce's own default for a variation attribute.
 *  - neither → stored, shown to the vendor, and claimed by nothing. Useless
 *    but not harmful, and refusing it would be a guard wider than any defect.
 *
 * ⛔ **BOTH DEFAULT TO TRUE, AND THAT IS THE WHOLE OF THE COMPATIBILITY
 * ARGUMENT.** Every row that exists today was written by a build in which
 * `WooCommerceProjector::wcAttributes()` set `set_visible(true)` and
 * `set_variation(true)` unconditionally — measured on `alpha.108`:
 *
 *     wcattr_…_visible=yes
 *     wcattr_…_variation=yes
 *
 * So `true`/`true` is not a guess about old data, it is a restatement of what
 * that data already means, and `M0040AttributeRoles` carries it as a column
 * default rather than a backfill for exactly that reason (the `alpha.51`
 * argument: `DEFAULT 1` IS the backfill when the default is the old
 * behaviour). No existing product's axes stop being axes, and the new
 * behaviour is reached only by a vendor who unticks the box.
 *
 * `key` is stable and lowercase ASCII for the same reason a spec field's key
 * is: it names the axis in every variation's combination string, and renaming
 * it would orphan every variation stored under the old name.
 */
final class ProductAttribute
{
    public const MAX_OPTIONS = 30;

    /** @param list<string> $options */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $label,
        public readonly array $options = [],
        public readonly int $sort = 0,
        /** Listed among the product's properties on the public page. */
        public readonly bool $visible = true,
        /** Used to build combinations the buyer chooses between. */
        public readonly bool $variation = true
    ) {
    }

    public function has(string $option): bool
    {
        return in_array($option, $this->options, true);
    }

    public function isUsable(): bool
    {
        return $this->key !== '' && $this->label !== '' && $this->options !== [];
    }

    /**
     * Whether this attribute may contribute an axis to a combination.
     *
     * ⛔ **ONE DEFINITION, BECAUSE FOUR PLACES ASK.** The combination
     * validator, the generator, the projector and the panel all need «is this
     * one of the axes», and `alpha.108` had the question spelled out at each
     * site as «is it in the attribute list» — which is why a fact attribute
     * made every `saveVariation()` answer `incomplete_combination`. A single
     * predicate means a route that forgets the flag cannot exist.
     */
    public function isAxis(): bool
    {
        return $this->variation && $this->isUsable();
    }

    /**
     * Only the axes, in their stored order.
     *
     * @param list<ProductAttribute> $attributes
     * @return list<ProductAttribute>
     */
    public static function axesOf(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            static fn (ProductAttribute $a): bool => $a->isAxis()
        ));
    }

    /**
     * How many combinations these axes describe, or `null` when the number is
     * larger than this marketplace will talk about.
     *
     * The product of the options, computed WITHOUT building anything, so a
     * panel can say «این کار ۱۲ ترکیب می‌سازد» before the vendor commits and
     * the cap can refuse by a number rather than by running out (the owner's
     * §4: «قبل از ایجاد، تعداد ترکیب‌ها نمایش داده شود»).
     *
     * `null` rather than a big integer because four axes of thirty options are
     * 810,000 combinations and an `int` that large is not a thing to print, to
     * compare against a cap, or to multiply again. The caller treats `null` as
     * «over any cap», which is the only honest reading.
     *
     * @param list<ProductAttribute> $attributes
     */
    public static function combinationCount(array $attributes, int $ceiling = 100000): ?int
    {
        $axes = self::axesOf($attributes);
        if ($axes === []) {
            return 0;
        }
        $total = 1;
        foreach ($axes as $axis) {
            $options = count($axis->options);
            if ($options <= 0) {
                return 0;
            }
            if ($total > intdiv($ceiling, $options)) {
                return null;        // would pass the ceiling; do not multiply
            }
            $total *= $options;
        }
        return $total;
    }
}
