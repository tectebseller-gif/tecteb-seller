<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ApprovedBaseline;

/**
 * WHAT THIS PROVES: «no record of agreement» never decays into «we agreed
 * that everything is empty».
 *
 * The second of those would authorise overwriting a whole product, so every
 * shape that cannot be read — absent column, empty string, broken JSON —
 * has to come back as null rather than as an empty baseline.
 */
final class ApprovedBaselineTest extends TestCase
{
    public function testAnAbsentBaselineIsNullAndNotAnEmptyOne(): void
    {
        self::assertNull(ApprovedBaseline::decode(null));
        self::assertNull(ApprovedBaseline::decode(''));
        self::assertNull(ApprovedBaseline::decode('   '));
    }

    public function testTextThatIsNotJsonIsAlsoNoRecord(): void
    {
        // A truncated column is not a statement about the product.
        self::assertNull(ApprovedBaseline::decode('{"title":'));
        self::assertNull(ApprovedBaseline::decode('null'));
    }

    public function testAnEmptyBaselineIsARealThingAndDiffersFromAbsent(): void
    {
        $empty = ApprovedBaseline::decode('{}');
        self::assertNotNull($empty);
        self::assertSame([], $empty->all());
        self::assertFalse($empty->has('title'));
    }

    public function testItRoundTripsPersianTextWithoutEscaping(): void
    {
        $baseline = ApprovedBaseline::of(['title' => 'آمبوبگ سیلیکونی', 'images' => 'main:4|gallery:17,18']);
        $json = $baseline->encode();
        self::assertStringContainsString('آمبوبگ', $json, 'escaped unicode would make the column unreadable');
        $back = ApprovedBaseline::decode($json);
        self::assertNotNull($back);
        self::assertSame('آمبوبگ سیلیکونی', $back->get('title'));
        self::assertSame('main:4|gallery:17,18', $back->get('images'));
    }

    public function testAFieldRecordedAsEmptyIsStillRecorded(): void
    {
        // The distinction `alpha.28` was about, one layer up: «this field was
        // agreed to be empty» and «this field has no agreement» are opposite
        // statements about what the vendor is allowed to change.
        $baseline = ApprovedBaseline::of(['short_description' => '']);
        self::assertTrue($baseline->has('short_description'));
        self::assertSame('', $baseline->get('short_description'));
        self::assertFalse($baseline->has('title'));
    }

    public function testWithReplacesOneFieldAndLeavesTheRest(): void
    {
        $baseline = ApprovedBaseline::of(['title' => 'الف', 'category' => '30']);
        $next = $baseline->with('title', 'ب');
        self::assertSame('الف', $baseline->get('title'), 'the original is not mutated');
        self::assertSame('ب', $next->get('title'));
        self::assertSame('30', $next->get('category'));
    }
}
