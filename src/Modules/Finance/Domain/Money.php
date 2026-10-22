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

    /**
     * THE SAME MONEY, written in a FINER unit — exactly, or not at all.
     *
     * Multiplying by a power of ten is lossless, so `۱۰۱` at `e = 0` and
     * `۱٬۰۱۰٬۰۰۰` at `e = 4` are the same amount and this is not a
     * conversion: the currency does not change and nothing is divided. That
     * is what makes an exact commission possible at all — a share of
     * ۸۸٫۳۷۵ تومان is an integer at `e = 4` and is not one at `e = 0`.
     *
     * **It refuses to go coarser**, and that is the point rather than an
     * omission: `۱٬۲۶٬۲۵۰` at `e = 4` is ۱۲٫۶۲۵، and asking for it at `e = 0`
     * is asking which way to round somebody's share. `null` instead, and the
     * caller says so by name — the `DecimalAmount::toMinor()` rule one object
     * up. It also refuses an exponent `Money` cannot carry, and an overflow:
     * `(int)` saturates silently on a value past `PHP_INT_MAX`, so the
     * multiplication is checked by dividing back.
     */
    public function rescale(int $exponent): ?self
    {
        if ($exponent < 0 || $exponent > 6 || $exponent < $this->exponent) {
            return null;
        }
        if ($exponent === $this->exponent) {
            return $this;
        }
        $factor = 10 ** ($exponent - $this->exponent);
        if ($this->minor !== 0 && intdiv(PHP_INT_MAX, abs($this->minor)) < $factor) {
            return null;
        }
        return new self($this->minor * $factor, $this->currency, $exponent);
    }

    /**
     * This amount as the exact decimal string its unit means.
     *
     * For printing and for handing to WooCommerce. `null` only when the
     * exponent is out of `DecimalAmount`'s range, which `of()` already
     * prevents.
     */
    public function toDecimal(): ?string
    {
        return DecimalAmount::toDecimal($this->minor, $this->exponent);
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
