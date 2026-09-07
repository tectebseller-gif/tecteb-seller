<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Config\BasisPoints;
use Tecteb\Marketplace\Core\Config\Sanitizer\PercentToBasisPoints;

/** CORE-07 + owner correction 4: percent input ≠ stored value; no float; null ≠ 0. */
final class PercentToBasisPointsTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            'two decimals' => ['12.34', 1234],
            'whole' => ['12', 1200],
            'zero' => ['0', 0],
            'hundred' => ['100', 10000],
            'half' => ['0.5', 50],
            'hundredth' => ['0.05', 5],
            'max fraction' => ['99.99', 9999],
            'persian digits + arabic decimal sep' => ['۱۲٫۳۴', 1234],
            'arabic-indic digits' => ['١٢.٣٤', 1234],
            'surrounding spaces' => [' 12.3 ', 1230],
            'nbsp + zwnj' => ["\u{00A0}7\u{200C}", 700],
            'empty means not set' => ['', null],
            'null means not set' => [null, null],
            'int is whole percent' => [7, 700],
        ];
    }

    #[DataProvider('validInputs')]
    public function testValidInputs(mixed $raw, ?int $expected): void
    {
        $r = PercentToBasisPoints::parse($raw);
        self::assertTrue($r->ok, (string) $r->errorCode);
        self::assertSame($expected, $r->value);
    }

    public static function invalidInputs(): array
    {
        return [
            'over 100' => ['100.01', PercentToBasisPoints::ERR_RANGE],
            'negative' => ['-1', PercentToBasisPoints::ERR_FORMAT],
            'three decimals' => ['1.234', PercentToBasisPoints::ERR_DECIMALS],
            'text' => ['abc', PercentToBasisPoints::ERR_FORMAT],
            'comma separator' => ['12,34', PercentToBasisPoints::ERR_FORMAT],
            'exponent' => ['1e2', PercentToBasisPoints::ERR_FORMAT],
            'four digits' => ['1000', PercentToBasisPoints::ERR_FORMAT],
            'array' => [['12'], PercentToBasisPoints::ERR_TYPE],
            'float rejected by design' => [12.34, PercentToBasisPoints::ERR_TYPE],
            'bool' => [true, PercentToBasisPoints::ERR_TYPE],
            'object' => [new \stdClass(), PercentToBasisPoints::ERR_TYPE],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputs(mixed $raw, string $code): void
    {
        $r = PercentToBasisPoints::parse($raw);
        self::assertFalse($r->ok);
        self::assertSame($code, $r->errorCode);
        self::assertNull($r->value);
    }

    public function testNullAndZeroAreDistinct(): void
    {
        self::assertNull(PercentToBasisPoints::parse('')->value);
        self::assertSame(0, PercentToBasisPoints::parse('0')->value);
        self::assertNotSame(PercentToBasisPoints::parse('')->value, PercentToBasisPoints::parse('0')->value);
    }

    public function testRoundTripForEveryBasisPointValue(): void
    {
        for ($bp = 0; $bp <= 10000; $bp++) {
            $text = BasisPoints::toPercentString($bp);
            $back = PercentToBasisPoints::parse($text);
            self::assertTrue($back->ok, $text);
            self::assertSame($bp, $back->value, "round trip for {$bp} via '{$text}'");
        }
    }

    public function testFormatting(): void
    {
        self::assertSame('12.34', BasisPoints::toPercentString(1234));
        self::assertSame('12', BasisPoints::toPercentString(1200));
        self::assertSame('12.3', BasisPoints::toPercentString(1230));
        self::assertSame('0', BasisPoints::toPercentString(0));
        self::assertSame('0.05', BasisPoints::toPercentString(5));
        self::assertSame('100', BasisPoints::toPercentString(10000));
    }

    public function testNoFloatArithmeticEverHappens(): void
    {
        // 0.1 + 0.2 style inputs would break a float implementation; integers cannot.
        self::assertSame(30, PercentToBasisPoints::parse('0.30')->value);
        self::assertSame(1, PercentToBasisPoints::parse('0.01')->value);
        self::assertSame(2999, PercentToBasisPoints::parse('29.99')->value);
    }
}
