<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * A commission rate in basis points — or the explicit absence of one.
 *
 * FIN-02 draws the line this class exists to keep: **a rate of zero is a
 * decision, an empty rate is not**. Zero means "this sells without
 * commission"; empty means "nobody has said yet", and the two must never
 * collapse into the same value, because collapsing them would let the
 * marketplace quietly take nothing on every sale and call it configured.
 *
 * Basis points, not percent: 12.34% is 1234, an integer, so a rate can be
 * stored, compared and multiplied without ever becoming a float.
 */
final class CommissionRate
{
    public const MAX_BP = 10000;   // 100%

    private function __construct(public readonly ?int $basisPoints)
    {
    }

    public static function ofBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BP) {
            throw new \InvalidArgumentException('a commission rate must be between 0 and 100 percent');
        }
        return new self($basisPoints);
    }

    /** «هنوز تعیین نشده» — inherit from a wider scope, or refuse to sell. */
    public static function unset(): self
    {
        return new self(null);
    }

    /** Empty string, null and "not stored" all mean unset — never zero. */
    public static function fromStored(mixed $stored): self
    {
        if ($stored === null || $stored === '' || $stored === false) {
            return self::unset();
        }
        if (!is_numeric($stored)) {
            return self::unset();
        }
        return self::ofBasisPoints((int) $stored);
    }

    public function isSet(): bool
    {
        return $this->basisPoints !== null;
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    public function percent(): ?float
    {
        return $this->basisPoints === null ? null : $this->basisPoints / 100;
    }
}
