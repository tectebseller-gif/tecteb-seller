<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * WHETHER ONE MARKETPLACE ROW AND ONE SHOP PRODUCT ARE THE SAME THING.
 *
 * §۴ of the owner's instruction asks for a reconciliation that can be read by
 * ID rather than by count — «حداقل باید بتوان ۱۸۴ محصول #102 را با مجموعه
 * شناسه‌ها (نه تعداد صرف) تطبیق داد» — and for the leftovers to be named:
 * «چهار رکورد فقط TMC و یک رکورد فقط Woo با شناسه و علت»، and «`unreadable`
 * را صفر یا محصول ناموجود ننام».
 *
 * So the rule lives here, once, as a pure function of facts already read, and
 * BOTH readers ask it: the manager's screen and `tools/production-audit.php`.
 * Two copies of a classification are two tools that can disagree about one
 * site, which is the shape this repository has paid for repeatedly
 * (`alpha.78`).
 *
 * ⛔ IT DECIDES NOTHING ABOUT WHAT TO DO. No answer here means «delete»,
 * «move», «relink» or «publish». §۴ is explicit — «اختلاف‌ها را خودکار
 * پاک/منتقل نکن» — and this class has no verb at all: it names what was
 * found, and a person decides.
 */
final class CatalogueLink
{
    /** A row, a product post, one each. */
    public const LINKED = 'linked';

    /** The row names no shop product at all — which is a real state, not a fault. */
    public const TMC_ONLY = 'tmc_only';

    /** The row names an id the shop has not got. */
    public const WOO_MISSING = 'woo_missing';

    /** The id exists and is not a product (a page, an attachment, a variation). */
    public const WRONG_TYPE = 'wrong_type';

    /** Two or more rows name ONE product — the state a projection overwrites. */
    public const DUPLICATE = 'duplicate';

    /** A read failed. ⛔ NOT a zero and NOT «the product is gone». */
    public const UNREADABLE = 'unreadable';

    /** A shop product that claims this vendor and has no row of ours. */
    public const WOO_ONLY = 'woo_only';

    /** Every answer, in the order a person would work through them. */
    public const ALL = [
        self::LINKED,
        self::TMC_ONLY,
        self::WOO_MISSING,
        self::WRONG_TYPE,
        self::DUPLICATE,
        self::WOO_ONLY,
        self::UNREADABLE,
    ];

    private function __construct()
    {
    }

    /**
     * One row's answer, and only ever one.
     *
     * THE ORDER IS A DECISION, not the order the facts were read in:
     *
     *  1. `$shopReadable === false` first. A failed read is not a fact about
     *     the shop, and every answer below would be inventing one — the
     *     mistake `alpha.8` is about, in a new place.
     *  2. «names nothing» before everything, because a row with no
     *     `wc_product_id` cannot be missing, duplicated or of the wrong type.
     *  3. «the product is not there» before «two rows name it»: when the id
     *     does not exist there is nothing to overwrite, so the duplicate is
     *     not the thing to act on.
     *  4. «not a product» before «two rows name it», for the same reason.
     *  5. `DUPLICATE` last before `LINKED`, because it is the only answer
     *     that is about a row OTHER than this one.
     *
     * @param ?int    $wcProductId  what the row says, `null`/0 for «nothing»
     * @param ?string $postType     the post's type, `null` when there is no post
     * @param bool    $shopReadable whether the shop side could be read at all
     * @param int     $rowsNamingIt how many of OUR rows name this product id
     */
    public static function decide(
        ?int $wcProductId,
        ?string $postType,
        bool $shopReadable,
        int $rowsNamingIt
    ): string {
        if (!$shopReadable) {
            return self::UNREADABLE;
        }
        if ($wcProductId === null || $wcProductId <= 0) {
            return self::TMC_ONLY;
        }
        if ($postType === null || $postType === '') {
            return self::WOO_MISSING;
        }
        if ($postType !== 'product') {
            return self::WRONG_TYPE;
        }
        if ($rowsNamingIt > 1) {
            return self::DUPLICATE;
        }
        return self::LINKED;
    }

    /** Whether this answer is something a person has to look at. */
    public static function needsAttention(string $answer): bool
    {
        return $answer !== self::LINKED && $answer !== self::TMC_ONLY;
    }
}
