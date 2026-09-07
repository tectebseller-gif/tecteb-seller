<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Config\Sanitizer\BoundedInteger;
use Tecteb\Marketplace\Core\Config\Sanitizer\DigitNormalizer;

final class BoundedIntegerTest extends TestCase
{
    public function testAcceptsAsciiPersianArabicDigitsAndInts(): void
    {
        self::assertSame(10, BoundedInteger::parse('10', 0, 365)->value);
        self::assertSame(10, BoundedInteger::parse('۱۰', 0, 365)->value);
        self::assertSame(10, BoundedInteger::parse('١٠', 0, 365)->value);
        self::assertSame(5, BoundedInteger::parse(5, 0, 365)->value);
        self::assertSame(0, BoundedInteger::parse('0', 0, 365)->value);
    }

    public function testRejects(): void
    {
        self::assertSame(BoundedInteger::ERR_FORMAT, BoundedInteger::parse('10.5', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_EMPTY, BoundedInteger::parse('', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_EMPTY, BoundedInteger::parse('   ', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_RANGE, BoundedInteger::parse('-1', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_RANGE, BoundedInteger::parse('366', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_RANGE, BoundedInteger::parse('0', 1, 100)->errorCode);
        self::assertSame(BoundedInteger::ERR_RANGE, BoundedInteger::parse('101', 1, 100)->errorCode);
        self::assertSame(BoundedInteger::ERR_FORMAT, BoundedInteger::parse('abc', 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_TYPE, BoundedInteger::parse(['1'], 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_TYPE, BoundedInteger::parse(1.5, 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_TYPE, BoundedInteger::parse(null, 0, 365)->errorCode);
        self::assertSame(BoundedInteger::ERR_TYPE, BoundedInteger::parse(true, 0, 365)->errorCode);
    }

    public function testDigitNormalizerOnlyTouchesDigitsAndSeparator(): void
    {
        self::assertSame('12.5', DigitNormalizer::normalize('۱۲٫۵'));
        self::assertSame('abc', DigitNormalizer::normalize(' abc '));
        self::assertSame('12,5', DigitNormalizer::normalize('12,5'), 'comma is not converted');
    }
}
