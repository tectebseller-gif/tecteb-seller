<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Which rows' `status` column is PROVABLY not a decision, and what the shop
 * says it should be instead.
 *
 * ### The defect this answers
 *
 * `countsByStatus()` reads `tmc_products.status`. On a site whose products
 * were imported by a package older than `alpha.92`, every imported row carries
 * `draft`, because that intake wrote `ProductStatus::Draft` unconditionally —
 * the defect `alpha.92` §1 fixed in the PATH, which fixes nothing already on
 * disk. `M0038ObservedMirroredStatusBackfill` then filled the STAMP for those
 * rows and deliberately left the status column alone, and nothing else in this
 * plugin ever brings it back into line: `refreshFrom()` rewrites thirteen
 * fields plus the stamp and not the status, and it refuses a row that is no
 * longer `observed` anyway, which after an ownership transfer is all of them.
 *
 * So a shop whose 184 products are live in WooCommerce reports `draft: 184`,
 * and the consequences get worse the further down you read:
 *
 *  1. **The count.** The manager's shop page and the vendor's own chips both
 *     come from that column, so the vendor cannot find their live products.
 *  2. **⛔ The review queue is bypassed.** `ProductStatus::isEditableByVendor()`
 *     is true for `Draft`, so `ManageProducts::save()` takes `saveInPlace()`
 *     rather than `saveAsRevision()` — a sensitive change to a product that is
 *     live in WooCommerce goes straight out with NO manager review, because
 *     the row claims to be a draft. This is the half that is not cosmetic.
 *  3. **Deferred overwriting.** Anything that clears the stamp lets the next
 *     projection write `draft` + `hidden` over a published product
 *     (`alpha.93`'s declared hazard). The hazard exists BECAUSE the column is
 *     wrong; correcting it is what removes it, rather than a warning not to
 *     touch the panel.
 *
 * ### Why the stamp is the whole guard
 *
 * The question «may this row's status be rewritten?» has an exact answer, and
 * it is not the ownership and not the age of the row. A stamp means
 * `project()` has NEVER written this product's status — `decide()` returns
 * `HELD` and `WooCommerceProjector` skips both the status and the catalogue
 * visibility inside one `if (!$statusHeld)`. So for a stamped row our column
 * cannot have reached the shop, cannot have come from `updateStatus()` (which
 * clears the stamp in the same statement), and is therefore the intake's
 * default rather than anybody's decision. There is nothing to preserve.
 *
 * The converse is the reason this is safe: the moment a manager decides a
 * status through the panel, `updateStatus()` clears the stamp, and from then
 * on this rule declines the row by name (`NOT_HELD`). A decision is never
 * overwritten, and that property comes from the stamp the decision itself
 * removes — not from a list anybody has to maintain.
 *
 * ### And a status in flight is never touched
 *
 * `Submitted`, `ChangesRequested`, `Archived` and `Suspended` are decisions in
 * progress — a vendor waiting for review, a product a manager took off sale. Writing the
 * shop's status over one of those would erase a pending submission, so only
 * `Draft` and `Published` are in scope. That is also exactly the pair the
 * intake can produce (`ProductStatus::forIntakeOf`), so the scope and the
 * defect's reach are the same set rather than two lists that can drift apart.
 *
 * ### Both directions, and why that is not «unpublishing»
 *
 * WooCommerce is the reference for the publication status — the owner's §3 —
 * so a stamped row follows the shop whichever way they disagree. A row that
 * says `Published` beside a shop that says `draft` becomes `Draft` here, and
 * that does NOT make a published product a draft: the product is ALREADY a
 * draft in WooCommerce, and this writes only this plugin's column. Nothing in
 * this class reaches `wp_posts`, and `apply()`'s repository method names one
 * column.
 */
final class StorefrontStatusReconciliation
{
    /** The column already says what the shop says. Nothing to write. */
    public const AGREED = 'agreed';

    /** The column is the intake's default and the shop disagrees. */
    public const REPAIR = 'repair';

    /** No stamp: the projector owns this status. Leave it. */
    public const NOT_HELD = 'not_held';

    /** A decision in progress. Leave it. */
    public const IN_FLIGHT = 'in_flight';

    /** The shop's status could not be read, or is one we cannot express. */
    public const UNREADABLE = 'unreadable';

    /**
     * The verdict, and for `REPAIR` the status the shop holds.
     *
     * ⛔ The stamp is asked about FIRST, before the shop's value, and that
     * order is a decision: an unstamped row must come back `NOT_HELD` even
     * when the shop cannot be read, because «the projector owns this» is a
     * complete answer that needs no second fact. Asking the other way round
     * would report `UNREADABLE` for rows this rule has nothing to say about,
     * and a manager reading the preview would see work where there is none.
     *
     * @return array{verdict:string, target:?ProductStatus}
     */
    public static function decide(
        ProductStatus $rowStatus,
        ?string $mirroredStatus,
        ?string $shopStatus
    ): array {
        if ($mirroredStatus === null || trim($mirroredStatus) === '') {
            return ['verdict' => self::NOT_HELD, 'target' => null];
        }
        if (!self::inScope($rowStatus)) {
            return ['verdict' => self::IN_FLIGHT, 'target' => null];
        }
        // `forIntakeOf` and not a match of our own: one vocabulary for «what
        // this marketplace can express about a WooCommerce status», used by
        // the intake, by the plan and here. A `private` or `future` product
        // comes back `null` from it and is declined by name rather than
        // flattened into a draft (`alpha.66`).
        $target = $shopStatus === null ? null : ProductStatus::forIntakeOf($shopStatus);
        if ($target === null) {
            return ['verdict' => self::UNREADABLE, 'target' => null];
        }
        if ($target === $rowStatus) {
            return ['verdict' => self::AGREED, 'target' => null];
        }
        return ['verdict' => self::REPAIR, 'target' => $target];
    }

    /**
     * Whether a row's current status is one this rule may rewrite.
     *
     * Named rather than inlined so the architecture test can bind it to
     * `ProductStatus::forIntakeOf`'s own range: the two must stay the same
     * set, and a `ProductStatus` case added later must fail a test rather
     * than silently become repairable.
     */
    public static function inScope(ProductStatus $rowStatus): bool
    {
        return $rowStatus === ProductStatus::Draft || $rowStatus === ProductStatus::Published;
    }
}
