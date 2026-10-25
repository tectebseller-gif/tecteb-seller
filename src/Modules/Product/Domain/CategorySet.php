<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The set of `product_cat` term ids a product is in — normalised, and compared
 * as a SET.
 *
 * The owner's acceptance rule for this round is explicit: «برابری مجموعهٔ
 * شناسه‌ها را قبل و بعد از اعمال، ویرایش و پذیرش اندازه بگیر؛ برابری تعداد
 * کافی نیست.» Equal counts are not equality, and `[244,245]` and `[245,244]`
 * are the same set — so there has to be one definition of both, in the domain,
 * rather than a sort scattered across every caller.
 *
 * **Order carries no meaning here**, exactly as `StorefrontField::idsToValue()`
 * already assumes for the encoded form: WooCommerce keeps a product's terms as
 * a set and `get_category_ids()` makes no promise about their order, so a
 * difference in order is not a difference in the relationship. Images are the
 * opposite and have their own encoder, because there the order IS the fact
 * (`StorefrontImages`, `alpha.28`).
 *
 * This object only normalises and compares. It attaches nothing, detaches
 * nothing, and knows nothing about WooCommerce.
 */
final class CategorySet
{
    /**
     * Normalise anything a caller might hold into a sorted, unique list of
     * positive ints.
     *
     * Non-numeric entries are DROPPED rather than cast. A legacy
     * `category_key` from before the key became a term id is a slug, and
     * `(int) 'medical-glue'` is `0` — casting it would invent the id nought
     * and then drop it anyway, but by a route that reads as if a value had
     * been understood. `AliasingSpecTemplateRepository` is the thing that
     * still understands those keys.
     *
     * @param iterable<mixed> $ids
     * @return list<int>
     */
    public static function of(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                $value = $id;
            } elseif (is_string($id) && preg_match('/^\d+$/', trim($id)) === 1) {
                $value = (int) trim($id);
            } else {
                continue;
            }
            if ($value > 0 && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Whether two sets name the same relationship.
     *
     * @param iterable<mixed> $a
     * @param iterable<mixed> $b
     */
    public static function same(iterable $a, iterable $b): bool
    {
        return self::of($a) === self::of($b);
    }

    /**
     * The ids in `$before` that are not in `$after` — what a write would
     * REMOVE.
     *
     * This is the number the owner's instruction is about: «هیچ رابطه‌ای
     * خودکار حذف یا اضافه نشود.» A guard that compares counts cannot answer
     * it, and a guard that compares the encoded strings cannot say WHICH id
     * went.
     *
     * @param iterable<mixed> $before
     * @param iterable<mixed> $after
     * @return list<int>
     */
    public static function lost(iterable $before, iterable $after): array
    {
        return array_values(array_diff(self::of($before), self::of($after)));
    }

    /**
     * The ids in `$after` that are not in `$before` — what a write would ADD.
     *
     * @param iterable<mixed> $before
     * @param iterable<mixed> $after
     * @return list<int>
     */
    public static function gained(iterable $before, iterable $after): array
    {
        return array_values(array_diff(self::of($after), self::of($before)));
    }

    /**
     * The encoded form: the same spelling `StorefrontField::idsToValue()`
     * produces, so a set and a storefront field value compare directly.
     *
     * @param iterable<mixed> $ids
     */
    public static function toValue(iterable $ids): string
    {
        return implode(',', self::of($ids));
    }

    /**
     * Decode that spelling back.
     *
     * @return list<int>
     */
    public static function fromValue(string $value): array
    {
        return self::of(explode(',', $value));
    }

    /**
     * The one key to store in `tmc_products.category_key` for this set, and
     * `''` whenever there is not exactly one.
     *
     * **This is deliberately not «pick one».** `category_key` is still the
     * single key the spec-template lookup and the Dokan mapping read, and
     * which category's template applies to a multi-category product is the
     * owner's decision, not this method's
     * (`docs/open-decisions-alpha82.md` §1). So a set of two or more answers
     * `''` — «no single key» — rather than naming a winner, which is what
     * «اولین دسته را خودسرانه انتخاب نکن» asks for. A set of one answers that
     * one, because then there is nothing to decide.
     *
     * @param iterable<mixed> $ids
     */
    public static function soleKey(iterable $ids): string
    {
        $set = self::of($ids);
        return count($set) === 1 ? (string) $set[0] : '';
    }
}
