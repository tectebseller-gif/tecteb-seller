<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The brand inside a pending proposal, and whether it is still the vendor's to apply.
 *
 * **The gap this closes.** A proposal is a SNAPSHOT. `proposeStock()` carries
 * the whole pending payload forward on purpose — so a stock edit does not throw
 * away a title or a brand the vendor is still waiting on (`alpha.48`) — and
 * `saveAsRevision()` writes every sensitive field into it, whether the vendor
 * changed that field or not. So the payload holds values the vendor never asked
 * about, and approving the proposal wrote all of them.
 *
 * For every other field that is harmless, because the only way those values
 * change is through this same queue. The brand is the exception: from `alpha.48`
 * it has a SECOND decision path of its own — `tmc_brand_proposals`, approved by
 * `ManageBrands::finish()`, which writes the product's brand, its term id and
 * the shop's attribute without going near the revision. Measured on the
 * `alpha.51` bytes, in the owner's own order:
 *
 *     brand_approve_code=brand_approved
 *     after_brand_row_brand=سینا طب ۵۲   row_term=92   shop_brand=سینا طب ۵۲
 *     payload_brand=آریا طب ۵۲
 *     stock_approve_code=revision_approved     ← reported success
 *     after_stock_row_brand=آریا طب ۵۲   row_term=89   shop_brand=آریا طب ۵۲
 *
 * All three places the owner named went back, and the sentence on the screen
 * said the stock had been approved. «تصمیم دربارهٔ یک فیلد نباید تصمیم جدیدتر
 * دربارهٔ فیلد دیگر را برگرداند.»
 *
 * **Two values cannot say who asked for what** — `alpha.29`'s rule, and the
 * reason this needs a third. With the value the proposal was BUILT ON recorded
 * beside it, the two questions come apart exactly as they do in
 * {@see FieldMerge}:
 *
 *     vendorAsked  = the proposal no longer equals its own baseline
 *     somebodyElse = the product no longer equals that baseline either
 *
 * and only both-at-once-with-different-answers is a disagreement.
 *
 * **Why this is not `FieldMerge`.** That class answers about the marketplace
 * record against the SHOP, using a projection stamp as its provenance. This one
 * answers about a PROPOSAL against the record, and has no stamp to ask: a
 * payload is immutable once written, so its baseline is the whole of its
 * provenance. Two different pairs of values, one shape — written twice rather
 * than once behind a parameter nobody could read, and each docblock points at
 * the other so there is never a third.
 *
 * **«I do not know» is one of the answers** (`alpha.49`). A payload written by
 * `alpha.51` or earlier records no baseline, and for those the two questions
 * CANNOT be separated: a brand that differs from the product's might be the
 * vendor's request or might be a value carried past somebody else's decision.
 * Guessing is silent and wrong in one direction either way, so an unknown
 * baseline over a value that differs is `MOVED` — named on the screen with a
 * control that applies the proposal anyway, the same shape as the stock
 * conflict beside it.
 *
 * Nothing here calls WordPress and nothing reads a database: it is the rule,
 * alone, so a unit test can argue with it.
 */
final class ProposedBrand
{
    /** The proposal's brand is the vendor's request. Write it. */
    public const APPLY = 'apply';

    /**
     * The vendor asked nothing about the brand and somebody else has since
     * decided it. The live brand stays, and the approval says so.
     */
    public const KEEP = 'keep';

    /**
     * Two decisions, two answers, and only a person can say which. Refused and
     * named, with an explicit control to apply the proposal regardless.
     */
    public const MOVED = 'moved';

    /**
     * @param bool   $hasBaseline was the brand this proposal was built on recorded?
     * @param string $baseline    that brand ('' is a real value: «no brand»)
     * @param string $proposed    what the payload holds
     * @param string $current     what the product holds now
     */
    public static function decide(
        bool $hasBaseline,
        string $baseline,
        string $proposed,
        string $current
    ): string {
        // NOTHING TO DECIDE, and this is the overwhelmingly common case: a
        // vendor edits a title and the payload carries the brand the product
        // already has. Asked first, so a missing baseline never turns an
        // ordinary save into a question.
        if (self::alike($proposed, $current)) {
            return self::APPLY;
        }
        if (!$hasBaseline) {
            // Older than the baseline. The two differ and there is no way to
            // tell a request from a carried-forward snapshot, so it is a
            // question rather than a default.
            return self::MOVED;
        }
        if (self::alike($proposed, $baseline)) {
            // The proposal asks for exactly what was there when it was
            // written, so it asks for nothing: whatever moved the product's
            // brand since is the only decision about this field.
            return self::KEEP;
        }
        if (self::alike($current, $baseline)) {
            // The vendor asked, and nobody else has touched it. THIS is the
            // path that must not narrow: a vendor choosing an approved brand
            // on a published product is a legitimate request, and the
            // approval is what grants it.
            return self::APPLY;
        }
        return self::MOVED;
    }

    /**
     * Two brands, compared as brands — INCLUDING «no brand».
     *
     * `BrandName::same()` deliberately answers false for two empty strings: it
     * is asked «are these the same NAMED brand», and an empty string names
     * nothing. Here the question is «has this value moved», and «no brand on
     * both sides» has not moved — so using `same()` would have read a product
     * with no brand and a payload with no brand as a conflict and refused
     * every such approval. Written out rather than loosening `same()`, whose
     * other callers rely on it answering about a name.
     */
    private static function alike(string $a, string $b): bool
    {
        return BrandName::key($a) === BrandName::key($b);
    }
}
