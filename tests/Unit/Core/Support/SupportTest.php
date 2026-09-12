<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Support;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Lifecycle\PhpRequirement;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Core\Support\TextSanitizer;

final class SupportTest extends TestCase
{
    public function testPersianDigits(): void
    {
        self::assertSame('۱۲٬۳۴۵ و ۰', PersianDigits::toPersian('12٬345 و 0'));
    }

    public function testExceptionSummaryIsSingleLineWithoutTrace(): void
    {
        $e = new \RuntimeException("first line\nsecond line " . str_repeat('z', 300));
        $s = TextSanitizer::exceptionSummary($e);
        self::assertStringStartsWith('RuntimeException: first line second line', $s);
        self::assertStringNotContainsString("\n", $s);
        self::assertLessThanOrEqual(160, mb_strlen($s));
        self::assertStringNotContainsString(__FILE__, $s);
    }

    public function testSystemClockIsUtc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testCapabilitiesAreExactlyTheFourOfPhaseOne(): void
    {
        self::assertSame([
            'tmc_view_dashboard', 'tmc_view_health', 'tmc_manage_settings', 'tmc_view_modules',
            'tmc_review_vendor', 'tmc_manage_vendor_documents',
        ], Capabilities::all());
        // The applicant capability is NOT here on purpose: granting it would
        // mean writing into roles the site already has (Dokan's included).
        self::assertNotContains('tmc_apply_vendor', Capabilities::all());
        self::assertSame('administrator', Capabilities::TARGET_ROLE);
    }

    public function testPhpRequirement(): void
    {
        self::assertTrue(PhpRequirement::isSatisfied('8.1.34'));
        self::assertTrue(PhpRequirement::isSatisfied('8.4.19'));
        self::assertFalse(PhpRequirement::isSatisfied('8.0.30'));
        self::assertFalse(PhpRequirement::isSatisfied('7.4.33'));
    }
}
