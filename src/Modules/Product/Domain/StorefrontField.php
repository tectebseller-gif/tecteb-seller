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

    public function __construct(
        public readonly string $key,
        public readonly string $marketplace,
        public readonly string $storefront,
        public readonly string $pending = '',
        public readonly string $owner = self::OWNER_MARKETPLACE,
        /** False for `description`, which the projector builds rather than copies. */
        public readonly bool $roundTrips = true
    ) {
    }

    /** Is there anything for a manager to decide about this field? */
    public function needsDecision(): bool
    {
        return $this->pending !== '' || ($this->owner === self::OWNER_MANAGER && $this->differs());
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
