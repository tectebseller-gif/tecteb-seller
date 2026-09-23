<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

use Tecteb\Marketplace\Modules\Product\Domain\StorefrontImages;

/**
 * Who owns each field of a projected product — decided by evidence, not by a
 * rule written once and hoped for.
 *
 * `project()` used to write the title, the short description and the
 * description on every single run. So the sequence the owner described —
 * manager edits the product in WooCommerce, vendor later presses save — threw
 * the manager's text away without a word. The audit trail recorded a
 * successful projection; nothing recorded a loss.
 *
 * The rule now:
 *
 *   * **Never projected before** → the marketplace writes it. Nobody else has
 *     said anything about this field yet.
 *   * **Still exactly what we last wrote** → the marketplace writes it. An
 *     untouched field is still ours.
 *   * **Different from what we last wrote** → somebody edited it in
 *     WooCommerce. We do NOT write. The vendor's new value is kept beside it
 *     as a proposal for the manager to compare and accept.
 *
 * The comparison is against what WE wrote, not against what the vendor typed:
 * WooCommerce runs its own filters on save (`wp_filter_post_kses`, shortcode
 * and oEmbed handling), so the value that comes back is routinely not the one
 * that went in. Hashing our own post-write read is the only comparison that
 * does not report every product as manager-edited.
 *
 * Nothing here is a second copy of the truth. WooCommerce stays the published
 * product; the marketplace row stays what the vendor asked for; and the
 * pending meta is the difference between them, which is exactly what a
 * reviewer needs to see.
 */
final class ProjectedFieldOwnership
{
    /** The hash of what the marketplace last wrote into a field. */
    public const STAMP_PREFIX = '_tmc_projected_';

    /** A vendor value held back because the manager had edited that field. */
    public const PENDING_PREFIX = '_tmc_pending_';

    /**
     * The manager said «my version stays» about this field.
     *
     * A mismatched stamp alone is not a decision — it is a question, and it
     * asks itself again on every projection. This flag is the answer: the
     * field belongs to WooCommerce now, the marketplace stops writing it, and
     * the vendor's value stops being recorded as a proposal nobody wanted.
     * Clearing it hands the field back.
     */
    public const MANAGER_PREFIX = '_tmc_manager_owns_';

    /** Fields this applies to, and nothing else. Price, stock and status are ADR-008's. */
    public const FIELDS = ['title', 'short_description', 'description', 'category', 'images'];

    /**
     * Fields whose storefront value can be written back into the marketplace
     * row without inventing anything.
     *
     * `description` is the one that cannot: the projector BUILDS it out of
     * the short description, the brand and the medical specification fields,
     * so copying it back would paste the rendered table into the raw text and
     * the next projection would render it again. For that one field, the
     * manager keeping their version is a permanent split — which is stated on
     * the review screen rather than papered over.
     */
    public const ROUND_TRIP = ['title', 'short_description', 'category', 'images'];

    public static function fingerprint(string $value): string
    {
        // Whitespace-insensitive: WooCommerce and the editor disagree about
        // trailing newlines often enough that a byte comparison would call an
        // untouched field edited.
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /** Who a field belongs to right now. */
    public const OWNER_MARKETPLACE = 'marketplace';
    public const OWNER_MANAGER = 'manager';
    public const OWNER_UNKNOWN = 'unknown';

    /**
     * Who owns this field?
     *
     * **`$createdByUs` is the whole of the fix that `alpha.27` exists for.**
     * Until it existed, an empty stamp meant «nobody has said anything about
     * this field, so it is ours» — and that is true of exactly one thing: a
     * WooCommerce product this projection just created. It is NOT true of a
     * product that a previous version of this plugin projected, because those
     * versions stamped nothing. Those products carry whatever the manager
     * typed into WooCommerce months ago, and the first save after an upgrade
     * wrote straight over it. The protection was real and it protected only
     * products created after it shipped.
     *
     * So an existing field with no stamp is `unknown`, and unknown does not
     * write. Nothing here guesses which side is right and nothing adopts the
     * current value as a baseline: guessing wrong about a product description
     * is silently publishing the wrong text, and there is no evidence in the
     * database to guess from. The manager decides, once, on the review
     * screen, and the decision is what creates the baseline.
     *
     * @param string $stamp       what we recorded last time ('' when never)
     * @param string $current     what WooCommerce has right now
     * @param bool   $managerOwns the manager said «my version stays»
     * @param bool   $createdByUs this projection created the post, this run
     */
    public static function owner(
        string $stamp,
        string $current,
        bool $managerOwns = false,
        bool $createdByUs = false
    ): string {
        if ($managerOwns) {
            return self::OWNER_MANAGER;         // decided, and not by us
        }
        if ($createdByUs) {
            // Every byte of this post was written a moment ago by this
            // projection. There is nobody else it could belong to.
            return self::OWNER_MARKETPLACE;
        }
        if ($stamp === '') {
            return self::OWNER_UNKNOWN;         // older than the stamps
        }
        return hash_equals($stamp, self::fingerprint($current))
            ? self::OWNER_MARKETPLACE
            : self::OWNER_MANAGER;
    }

    /**
     * May the marketplace write this field?
     *
     * The default for `$createdByUs` is `false`, deliberately: the safe
     * answer has to be the one a caller gets by forgetting to pass anything.
     */
    public static function mayWrite(
        string $stamp,
        string $current,
        bool $managerOwns = false,
        bool $createdByUs = false
    ): bool {
        return self::owner($stamp, $current, $managerOwns, $createdByUs) === self::OWNER_MARKETPLACE;
    }

    /**
     * A SET of ids, for a field where order carries no meaning.
     *
     * Categories only. Pictures went to `StorefrontImages` in `alpha.27`
     * because sorting them hid the two edits a manager most often makes —
     * see that class for what it cost.
     *
     * @param list<int> $ids
     */
    public static function idsToValue(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return implode(',', $ids);
    }

    /**
     * The pictures, with the main one named and the gallery in its order.
     *
     * @param list<int>|array<int|string,int|string> $galleryIds
     */
    public static function imagesToValue(int $mainId, array $galleryIds): string
    {
        return StorefrontImages::encode($mainId, $galleryIds);
    }
}
