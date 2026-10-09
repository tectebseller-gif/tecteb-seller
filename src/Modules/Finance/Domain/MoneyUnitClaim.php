<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * The answer to «may this vendor's books take an amount in this unit?».
 *
 * Five answers, and the point of the type is that four of them are not «no»
 * with a shrug. `alpha.39` asked the question as a `bool` derived from
 * `SELECT DISTINCT … ` over the ledger, which collapsed three different
 * situations into «true»:
 *
 *  - the books are empty, so this unit becomes theirs;
 *  - the books hold this unit already;
 *  - the READ FAILED, and `getResults()` answers an empty array for that too —
 *    so a broken database read looked exactly like a vendor's first sale and
 *    the unit of their whole ledger was decided by it.
 *
 * And it collapsed the two refusals into one: a vendor whose books are mixed
 * needs a person, while a vendor whose books are sound and whose incoming unit
 * is wrong needs the ORDER looked at. Different work, different words.
 */
final class MoneyUnitClaim
{
    /** The unit is this vendor's, now or already. */
    public const AGREED = 'agreed';
    /** This vendor's books hold a different single unit from the one offered. */
    public const DIFFERENT = 'unit_changed';
    /** This vendor's books already hold more than one unit. A person decides. */
    public const MIXED = 'books_mixed';
    /** The database could not be asked. NOT «the books are empty». */
    public const UNREADABLE = 'unit_unreadable';
    /** The offered unit is not one `Money` can carry. */
    public const UNSUPPORTED = 'unit_unsupported';

    private function __construct(
        public readonly string $state,
        public readonly string $currency = '',
        public readonly int $exponent = 0
    ) {
    }

    public static function agreed(string $currency, int $exponent): self
    {
        return new self(self::AGREED, $currency, $exponent);
    }

    /** @param string $state one of DIFFERENT, MIXED, UNREADABLE, UNSUPPORTED */
    public static function refused(string $state, string $currency = '', int $exponent = 0): self
    {
        return new self($state, $currency, $exponent);
    }

    public function isAgreed(): bool
    {
        return $this->state === self::AGREED;
    }

    /**
     * What the books hold, when that is known and differs from what was
     * offered — so a message can name both rather than just refusing.
     */
    public function storedUnit(): ?array
    {
        return $this->state === self::DIFFERENT
            ? ['currency' => $this->currency, 'exponent' => $this->exponent]
            : null;
    }
}
