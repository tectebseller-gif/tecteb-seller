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
 *   managerChanged = the shop is neither what we wrote nor what was agreed
 *
 * and only `vendorChanged && managerChanged && the two results differ` is a
 * disagreement. Everything else has exactly one answer.
 *
 * **Both halves are measured from the same value, and `alpha.30` is here
 * because `alpha.29` measured them from two.** The vendor's half was measured
 * against the baseline and the manager's against the projection stamp — the
 * fingerprint of what the marketplace last WROTE. Those are answers to
 * different questions, and they part company the moment an approval adopts
 * the manager's value as the new agreement: the baseline moves, the stamp
 * does not, and from then on the stamp says «somebody changed this» about a
 * field the manager has not touched since. The next vendor edit was called a
 * conflict on that evidence alone.
 *
 * So the two are now separate in meaning as well as in name, and the
 * manager's half needs BOTH of them:
 *
 *   * the **baseline** is what both sides agreed — «is what the shop holds
 *     the value this vendor's edit was measured from?»;
 *   * the **stamp** is provenance — «is the text in the shop still the text we
 *     put there?».
 *
 * Each goes stale on its own: the stamp when an agreement adopts the manager's
 * value, the baseline when the marketplace writes a field between two
 * agreements. A field has been changed by somebody else only when neither one
 * recognises what is there.
 *
 * Nothing here adopts the manager's value into the marketplace's ownership to
 * achieve that — «راه‌حل نباید مقدار مدیر را بدون مجوز به مالکیت افزونه
 * درآورد». No stamp is written and no flag is set by this class; the stamp
 * simply stops being asked a question it cannot answer.
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
         *
         * It needs no special case in the measurement below: a field the
         * marketplace re-renders between two agreements still carries OUR stamp
         * on what the shop holds, and that is enough to know a person has not
         * touched it.
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
            // Older than the stamps, and a recorded agreement does not change
            // that: an approval settles what the two sides hold, not who
            // typed the text that was already in WooCommerce. `alpha.27`
            // exists because that guess is how a manager's year-old
            // description gets overwritten, and the manager still ends this
            // state per field, in words.
            return self::UNSETTLED;
        }

        // Two pieces of evidence, and each is stale in a different direction —
        // which is why `alpha.29` got this wrong with one of them and the first
        // attempt at `alpha.30` got it wrong with the other:
        //
        //   * the STAMP is rewritten every time the marketplace writes, so it
        //     knows about our own writes — but it says nothing about a value the
        //     agreement ADOPTED from the manager, and goes on reporting their
        //     untouched field as changed for ever;
        //   * the BASELINE knows what both sides agreed — but between one
        //     agreement and the next the marketplace writes fields itself, and
        //     measured against a baseline that has not moved, our own write
        //     reads as somebody else's edit.
        //
        // So somebody else has changed this field only when BOTH say so: what
        // the shop holds is neither what we wrote nor what was agreed. Measured,
        // not reasoned — with the baseline alone, clearing the pictures on a real
        // WooCommerce install came back as a conflict with an edit nobody made.
        $agreed = $hasBaseline && hash_equals(self::fingerprint($baseline), self::fingerprint($shop));
        $managerChanged = !$agreed && !hash_equals($stamp, self::fingerprint($shop));

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
