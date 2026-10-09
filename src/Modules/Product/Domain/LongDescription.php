<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The four answers a submitted long description can carry, and the one rule
 * that turns them into a value to store.
 *
 * `alpha.41` gave «توضیحات کامل» its own nullable column and three states:
 * `null` is «this row predates the field, so WooCommerce's own text stands»,
 * `''` is «the vendor cleared it», and a string is the text. The column was
 * right and the FORM was not: it sent `description_given=1` on every render,
 * so a legacy product whose box renders empty — because there is nothing of
 * the vendor's to show — posted an empty string the moment anyone saved the
 * title. `''` is an instruction, so opening the form and pressing save
 * **asked for the shop's description to be deleted**.
 *
 * The missing piece is that an empty box means two different things depending
 * on what the form was showing. So the form now carries that, and there are
 * four answers rather than three:
 *
 *   1. **not in play** — no base field at all. A form from an older build, the
 *      CSV importer reading a file with no such column, any caller that does
 *      not mention the field. The stored value is left exactly as it is.
 *   2. **unchanged** — the box is empty and the form was showing nothing,
 *      because the row has nothing. Still `null`: nobody has asked for
 *      anything, so WooCommerce's text, the manager's edits to it and their
 *      SEO stay where they are.
 *   3. **new text** — the box has content. Written.
 *   4. **a deliberate clear** — the box is empty AND either a value was on
 *      screen to be emptied, or the vendor ticked the «delete it» box that is
 *      offered precisely when there was nothing on screen. Written as `''`.
 *
 * The distinction between 2 and 4 cannot be made from the POST alone, and it
 * must not be made by re-reading the row at save time either — that is a
 * comparison between a read and a write, which is the check two concurrent
 * editors both pass (`alpha.14`). So the form states what it SHOWED, in a
 * hidden field, and the decision is made from the submission.
 */
final class LongDescription
{
    /** What the form was showing: `value` or `none`. Absent ⇒ not in play. */
    public const BASE_FIELD = 'description_base';

    /** The explicit «delete the shop's description» tick. */
    public const CLEAR_FIELD = 'description_clear';

    /** A value was on screen, so emptying the box is an instruction. */
    public const BASE_VALUE = 'value';

    /** Nothing was on screen, so an empty box is not an instruction. */
    public const BASE_NONE = 'none';

    /**
     * The value to store.
     *
     * `$stored` is what to keep when the submission is not in play, and it
     * has to be passed in rather than defaulted to `null`, because `null` is
     * a VALUE on the way out — the column is written with it. «Leave the row
     * alone» and «set the row to null» are two different instructions that
     * would otherwise be the same return, and a form rendered by `alpha.41`
     * posting into `alpha.42` lands on exactly that path: it names no base,
     * and a `null` written there would turn a vendor's long description back
     * into «never set». Measured by `ProductFormPostTest`, which failed on
     * the first version of this method for that reason.
     *
     * @param string|null $submitted the text as posted, or null when the
     *                               submission does not mention the field
     * @param string|null $base      what the form was showing: `BASE_VALUE`,
     *                               `BASE_NONE`, or null when the submission
     *                               says nothing about the field at all
     * @param bool $clearRequested   the vendor ticked the explicit box
     * @param string|null $stored    the row's current value
     */
    public static function decide(
        ?string $submitted,
        ?string $base,
        bool $clearRequested,
        ?string $stored
    ): ?string {
        if ($submitted === null || $base === null) {
            // Not in play. Not «empty» and not «null»: a caller that never
            // mentions the field cannot be asking for it to change, and
            // reading absence as an instruction is the whole defect this
            // class exists for.
            return $stored;
        }
        if ($submitted !== '') {
            return $submitted;
        }
        if ($clearRequested || $base === self::BASE_VALUE) {
            return '';
        }
        return null;
    }

    /**
     * The base to render for a row holding `$stored`.
     *
     * One place, so the form that renders the box and the hidden field that
     * travels with it cannot disagree about what was on screen.
     */
    public static function baseOf(?string $stored): string
    {
        return $stored === null ? self::BASE_NONE : self::BASE_VALUE;
    }

    /**
     * Whether a row in this state should be offered the explicit clear.
     *
     * Only when there is nothing of the vendor's on screen: with a value in
     * the box, emptying it IS the instruction and a second control would be a
     * second way to say one thing. With nothing on screen the vendor has no
     * way at all to reach the shop's own text, which is the case the tick is
     * for — and it is deliberately NOT ticked by default, because the default
     * has to be the answer that changes nothing.
     */
    public static function offersClear(?string $stored): bool
    {
        return $stored === null;
    }
}
