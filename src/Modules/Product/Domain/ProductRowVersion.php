<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * The token a product form carries so its save can be refused if somebody
 * else wrote first — and the rules about what counts as one.
 *
 * `alpha.14` treated an empty token and a token it did not understand as
 * «no check to do» and wrote anyway. That is the hole this closes. A form with
 * no stamp is not a form that may skip the queue; it is a form from a build
 * that predates the counter, and the honest answer is to refuse the write,
 * keep what the vendor typed, and let them look at the row as it is now before
 * they submit again. Silently winning is how the other editor's work
 * disappears, which is the whole thing the counter exists to prevent.
 *
 * `UNGUARDED` is the one exception, and it is a word rather than an omission
 * on purpose: an unguarded write is now something a reader can grep for and a
 * reviewer can question. It belongs to the manager applying a revision they
 * have already approved — an act with one actor, no competing form, and no
 * stamp to carry.
 */
final class ProductRowVersion
{
    /** A deliberate write with no version check. Greppable by design. */
    public const UNGUARDED = '*';

    /** Whether this token can be compared against the stored counter. */
    public static function isUsable(string $token): bool
    {
        return $token !== '' && ctype_digit($token);
    }

    /** Whether the caller asked, in so many words, not to be guarded. */
    public static function isDeliberatelyUnguarded(string $token): bool
    {
        return $token === self::UNGUARDED;
    }

    /**
     * Whether a write may proceed at all.
     *
     * Anything else — an empty string, a `2026-09-16 12:00:00` from an
     * `alpha.13` form still open in somebody's browser, a hand-edited value —
     * is refused rather than waved through.
     */
    public static function permitsWrite(string $token): bool
    {
        return self::isUsable($token) || self::isDeliberatelyUnguarded($token);
    }
}
