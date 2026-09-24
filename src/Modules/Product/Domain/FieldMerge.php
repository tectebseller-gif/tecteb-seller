<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * Who, if anyone, has to decide about one field — answered from three values
 * instead of two.
 *
 * The version this replaces compared the marketplace record against the shop
 * and called every difference a possible conflict. The owner's own test run
 * showed what that costs: the manager edits the title and the long
 * description in WooCommerce, the vendor changes ONLY the short description
 * and resubmits — and the review screen asks three questions, two of them
 * about fields nobody asked to change. «مخفی‌کردن دکمه‌ها پذیرفته نیست»: the
 * problem was never the buttons, it was that the question was asked at all.
 *
 * With a baseline the two questions come apart:
 *
 *   vendorChanged  = the record no longer equals the baseline
 *   managerChanged = the shop no longer equals what we last wrote
 *
 * and only `vendorChanged && managerChanged && the two results differ` is a
 * disagreement. Everything else has exactly one answer.
 *
 * Nothing here calls WordPress and nothing here reads a database: it is the
 * rule, alone, so it can be argued with in a unit test.
 */
final class FieldMerge
{
    /** The vendor's value goes to the shop. Nobody has to be asked. */
    public const WRITE = 'write';

    /** The vendor did not touch this field. Nothing is written and nothing is asked. */
    public const SKIP = 'skip';

    /** Both sides moved it, to different values. One question, on this field only. */
    public const CONFLICT = 'conflict';

    /** Older than the stamps: nobody knows who wrote what is there (`alpha.27`). */
    public const UNSETTLED = 'unsettled';

    /** The manager said «my version stays», in words. Asked, and answered. */
    public const MANAGER = 'manager';

    /**
     * @param bool   $hasBaseline is there a recorded agreement for this field?
     * @param string $baseline    the value at that agreement
     * @param string $record      what the marketplace row says now
     * @param string $shop        what WooCommerce holds now
     * @param string $stamp       fingerprint of what we last wrote ('' = never)
     * @param bool   $managerOwns the manager's explicit decision
     * @param bool   $createdByUs this projection created the post, this run
     */
    public static function decide(
        bool $hasBaseline,
        string $baseline,
        string $record,
        string $shop,
        string $stamp,
        bool $managerOwns = false,
        bool $createdByUs = false,
        /**
         * True for a field the marketplace GENERATES rather than copies —
         * today only the product description, which is rendered from the
         * short description, the brand and the medical specification.
         *
         * It changes one answer, and it is the answer the owner asked for:
         * nobody types a generated field, so if the shop no longer holds what
         * we rendered, a person edited it in WooCommerce. That is evidence,
         * not a guess, and the conclusion is «it is theirs now» rather than
         * «the two of you disagree». Without this, every later change to the
         * short description re-renders the text, re-raises the difference and
         * asks the same question again for ever.
         */
        bool $derived = false
    ): string {
        if ($managerOwns) {
            return self::MANAGER;
        }
        if ($createdByUs) {
            // Every byte of this post was written a moment ago by this
            // projection. There is nobody else it could belong to.
            return self::WRITE;
        }
        if ($stamp === '') {
            return self::UNSETTLED;
        }

        $managerChanged = !hash_equals($stamp, self::fingerprint($shop));

        if ($derived && $managerChanged) {
            return self::MANAGER;
        }
        if (!$hasBaseline) {
            // No recorded agreement, so «did the vendor change it?» has no
            // honest answer. Fall back to the narrower rule this replaces:
            // write while the field is still ours, hold it otherwise. That is
            // what `alpha.28` did, so an upgrade changes nothing until the
            // first approval records a baseline.
            return $managerChanged ? self::CONFLICT : self::WRITE;
        }

        if (self::fingerprint($baseline) === self::fingerprint($record)) {
            // The whole point. The vendor did not touch this field, so
            // whatever the manager did with it stands, unasked.
            return self::SKIP;
        }
        if (!$managerChanged) {
            return self::WRITE;
        }
        // Both moved it. If they moved it to the same place there is nothing
        // to disagree about — writing is a no-op and asking would be noise.
        return self::fingerprint($record) === self::fingerprint($shop) ? self::WRITE : self::CONFLICT;
    }

    /**
     * Whitespace-insensitive, and deliberately the same rule the projection
     * stamp uses: two fingerprints computed differently would disagree about
     * untouched fields, which is the failure this class exists to end.
     */
    public static function fingerprint(string $value): string
    {
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
