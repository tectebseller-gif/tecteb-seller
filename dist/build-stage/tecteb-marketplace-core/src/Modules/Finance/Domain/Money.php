<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Domain;

/**
 * An amount, as an integer number of minor units plus the currency and the
 * exponent that give those units meaning (FIN-01).
 *
 * Never a float. 0.1 + 0.2 is not 0.3 in binary floating point, and a
 * marketplace that splits every sale between two parties would accumulate
 * that error into a real discrepancy between what it collected and what it
 * owes. Integers cannot drift.
 *
 * The exponent travels with the amount because «۹۰۰٬۰۰۰ ریال» and
 * «۹۰٬۰۰۰ تومان» are the same money written two ways, and the spec is
 * explicit that a toman label is not a licence to convert (FIN-01). Nothing
 * here converts anything; conversion belongs at an adapter boundary that can
 * be tested against a known ×10.
 */
final class Money
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
        public readonly int $exponent
    ) {
    }

    public static function of(int $minor, string $currency = 'IRR', int $exponent = 0): self
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('currency must be a three-letter code');
        }
        if ($exponent < 0 || $exponent > 6) {
            throw new \InvalidArgumentException('exponent out of range');
        }
        return new self($minor, $currency, $exponent);
    }

    public function zero(): self
    {
        return new self(0, $this->currency, $this->exponent);
    }

    public function add(self $other): self
    {
        $this->assertSameUnit($other);
        return new self($this->minor + $other->minor, $this->currency, $this->exponent);
    }

    public function subtract(self $other): self
    {
        $this->assertSameUnit($other);
        return new self($this->minor - $other->minor, $this->currency, $this->exponent);
    }

    /** The same amount on the other side of the books. */
    public function negate(): self
    {
        return new self(-$this->minor, $this->currency, $this->exponent);
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor
            && $this->currency === $other->currency
            && $this->exponent === $other->exponent;
    }

    /** Two amounts in different currencies are not comparable, ever. */
    private function assertSameUnit(self $other): void
    {
        if ($this->currency !== $other->currency || $this->exponent !== $other->exponent) {
            throw new \InvalidArgumentException('cannot combine ' . $this->describe() . ' with ' . $other->describe());
        }
    }

    public function describe(): string
    {
        return $this->minor . ' ' . $this->currency . '/1e' . $this->exponent;
    }
}
