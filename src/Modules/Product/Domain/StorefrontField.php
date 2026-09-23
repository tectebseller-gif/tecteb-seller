<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One field of a projected product, as both sides currently have it.
 *
 * `owner` is the only thing here that is a decision; the rest is evidence:
 *
 *   * `marketplace` — what the vendor's record says.
 *   * `storefront`  — what WooCommerce has right now.
 *   * `pending`     — the vendor's value, held because the manager had edited
 *                     the field. **Whether there is one is `hasPending`, not
 *                     `pending !== ''`**: a vendor who cleared a field
 *                     proposed an empty string, and reading absence off the
 *                     value makes that proposal invisible on this screen and
 *                     a no-op when it is accepted.
 *
 * The three are deliberately not merged into «the value»: the whole point of
 * the review screen is that somebody can see them apart and choose.
 */
final class StorefrontField
{
    public const OWNER_MARKETPLACE = 'marketplace';
    public const OWNER_MANAGER = 'manager';
    /**
     * Older than the stamps: a product a previous version projected.
     *
     * Not a third shade of «manager». It is the honest answer to a question
     * the database cannot settle — the text in WooCommerce may be what the
     * marketplace wrote a year ago, or it may be what the manager typed over
     * it, and nothing recorded which. The marketplace writes nothing while a
     * field is in this state, and the state ends when a person says so.
     */
    public const OWNER_UNKNOWN = 'unknown';

    /**
     * Fields that cannot be left empty, whoever is asking.
     *
     * Only the title. A product with no short description, no gallery or no
     * category is something a shop may legitimately want; a product with no
     * name is a row nobody can find again. Kept here rather than in the
     * WooCommerce layer because both the review screen and the write path
     * have to agree about it, and two lists are two rules.
     *
     * @var list<string>
     */
    public const REQUIRED = ['title'];

    public static function mayBeEmpty(string $field): bool
    {
        return !in_array($field, self::REQUIRED, true);
    }

    /**
     * Is a proposal waiting — regardless of what it says?
     *
     * Assigned rather than promoted so the default can be derived: callers
     * that predate the distinction pass only a value, and for them «there is
     * one» still means «it is not empty», which is what they meant.
     */
    public readonly bool $hasPending;

    public function __construct(
        public readonly string $key,
        public readonly string $marketplace,
        public readonly string $storefront,
        public readonly string $pending = '',
        public readonly string $owner = self::OWNER_MARKETPLACE,
        /** False for `description`, which the projector builds rather than copies. */
        public readonly bool $roundTrips = true,
        /**
         * The manager said «my version stays» about this field, in words.
         *
         * Distinct from merely reading as `manager`, which a stamp mismatch
         * also produces. Without the distinction `description` asks for ever:
         * the projector BUILDS that text, so the two sides go on differing
         * after the decision, and a screen that keeps asking a question
         * somebody has already answered teaches people to ignore it.
         */
        public readonly bool $decided = false,
        ?bool $hasPending = null
    ) {
        $this->hasPending = $hasPending ?? ($pending !== '');
    }

    /** Is there anything for a manager to decide about this field? */
    public function needsDecision(): bool
    {
        if ($this->decided) {
            return false;                       // asked, and answered
        }
        if ($this->owner === self::OWNER_UNKNOWN) {
            // Always — even when the two sides happen to agree today. The
            // field is frozen until somebody settles it, and a vendor whose
            // next edit silently becomes a proposal deserves a screen that
            // already said why.
            return true;
        }
        // `hasPending`, never `pending !== ''`. «فروشنده می‌خواهد این توضیح
        // پاک شود» is a proposal like any other, and the version that asked
        // the value showed it to nobody.
        return $this->hasPending || ($this->owner === self::OWNER_MANAGER && $this->differs());
    }

    /** True while nobody has established who this field belongs to. */
    public function isUnsettled(): bool
    {
        return $this->owner === self::OWNER_UNKNOWN;
    }

    public function differs(): bool
    {
        return self::normalise($this->marketplace) !== self::normalise($this->storefront);
    }

    /** Whitespace-insensitive, for the same reason the projector's stamp is. */
    private static function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
