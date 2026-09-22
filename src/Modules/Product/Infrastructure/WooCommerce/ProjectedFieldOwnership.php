<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce;

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

    /**
     * May the marketplace write this field?
     *
     * @param string $stamp   what we recorded last time ('' when never)
     * @param string $current what WooCommerce has right now
     */
    public static function mayWrite(string $stamp, string $current, bool $managerOwns = false): bool
    {
        if ($managerOwns) {
            return false;                       // decided, and not by us
        }
        if ($stamp === '') {
            return true;                        // never projected: it is ours
        }
        return hash_equals($stamp, self::fingerprint($current));
    }

    /** @param list<int> $ids */
    public static function idsToValue(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return implode(',', $ids);
    }
}
