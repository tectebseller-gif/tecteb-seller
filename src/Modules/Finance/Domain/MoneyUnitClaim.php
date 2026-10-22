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
 *
 * ⛔ **WHAT «UNIT» MEANS HERE NARROWED IN `alpha.67`, AND HAD TO.** Until then
 * a claim compared the currency AND the exponent, because every amount in a
 * vendor's books was written at the order's own exponent and two exponents
 * meant two incompatible numbers. The owner's no-rounding decision changes
 * that: a commission is now recorded at FOUR more decimal places than the
 * order's unit (`CommissionPrecision`), so a site upgrading from `alpha.66`
 * has books at `exponent = 0` and a next capture offering `exponent = 4`.
 * Comparing exponents would have answered `unit_changed` and **refused every
 * sale on every existing site** — found by running the path, not by reading
 * it.
 *
 * So the claim is about the **currency**, and the exponent travels with it as
 * `finestExponent`: a difference of exponent within one currency is
 * reconcilable EXACTLY, by multiplying by a power of ten
 * (`Money::rescale()`), and that is a fact about arithmetic rather than a
 * conversion. Two CURRENCIES are still not comparable, ever —
 * `Money::assertSameUnit()` keeps refusing them a layer down, and `MIXED`
 * and `DIFFERENT` keep their separate names and separate work.
 *
 * **Nothing historical is rewritten by this.** The registry row keeps the
 * FIRST unit it recorded, which is exactly the right answer for a legacy row
 * that does not carry its own unit; the finest exponent is derived per read
 * and stored nowhere.
 */
final class MoneyUnitClaim
{
    /** The unit is this vendor's, now or already. */
    public const AGREED = 'agreed';
    /** This vendor's books hold a different CURRENCY from the one offered. */
    public const DIFFERENT = 'unit_changed';
    /** This vendor's books already hold more than one CURRENCY. A person decides. */
    public const MIXED = 'books_mixed';
    /** The database could not be asked. NOT «the books are empty». */
    public const UNREADABLE = 'unit_unreadable';
    /** The offered unit is not one `Money` can carry. */
    public const UNSUPPORTED = 'unit_unsupported';

    private function __construct(
        public readonly string $state,
        public readonly string $currency = '',
        public readonly int $exponent = 0,
        /**
         * The FINEST exponent of this currency anywhere in these books,
         * including the one just offered.
         *
         * Any sum over the vendor's rows has to be taken in this unit, and
         * every coarser row rescales into it losslessly. It is derived on
         * each read and recorded nowhere: a stored «finest» would be stale
         * the moment a finer row arrived.
         */
        public readonly int $finestExponent = 0
    ) {
    }

    public static function agreed(string $currency, int $exponent, int $finestExponent = -1): self
    {
        return new self(
            self::AGREED,
            $currency,
            $exponent,
            $finestExponent < 0 ? $exponent : $finestExponent
        );
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

    /**
     * The unit every amount in these books should be summed in.
     *
     * `null` unless the claim was agreed: a refused claim has no unit to
     * offer, and handing one back would let a caller sum rows it was just
     * told not to trust.
     *
     * @return array{currency:string, exponent:int}|null
     */
    public function summationUnit(): ?array
    {
        return $this->isAgreed()
            ? ['currency' => $this->currency, 'exponent' => $this->finestExponent]
            : null;
    }
}
