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
 *                     the field. Empty when there is nothing waiting.
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
        public readonly bool $decided = false
    ) {
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
        return $this->pending !== '' || ($this->owner === self::OWNER_MANAGER && $this->differs());
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
