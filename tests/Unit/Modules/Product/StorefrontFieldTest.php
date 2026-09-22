<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;

/**
 * When does a field need a manager to decide about it?
 *
 * Getting this wrong in either direction costs something real: a screen that
 * asks about every field teaches people to click past it, and a screen that
 * asks about none lets the manager's text be overwritten in silence — which
 * is the failure that started this.
 */
final class StorefrontFieldTest extends TestCase
{
    public function testAFieldTheMarketplaceStillOwnsAsksNothing(): void
    {
        $field = new StorefrontField('title', 'آمبوبگ', 'آمبوبگ');
        self::assertFalse($field->differs());
        self::assertFalse($field->needsDecision());
    }

    public function testAHeldProposalAlwaysNeedsADecision(): void
    {
        $field = new StorefrontField(
            'title',
            'آمبوبگ نسخهٔ ۲',
            'آمبوبگ — ویرایش مدیر',
            'آمبوبگ نسخهٔ ۲',
            StorefrontField::OWNER_MANAGER
        );
        self::assertTrue($field->needsDecision());
    }

    public function testAManagerEditWithNoProposalStillNeedsADecision(): void
    {
        // Nobody has asked for anything yet, but the two sides say different
        // things — and until somebody says which is right, the marketplace is
        // refusing to write a field for a reason nobody has been told.
        $field = new StorefrontField(
            'title',
            'آمبوبگ',
            'آمبوبگ سیلیکونی',
            '',
            StorefrontField::OWNER_MANAGER
        );
        self::assertTrue($field->needsDecision());
    }

    public function testAManagerOwnedFieldThatAgreesAnywayAsksNothing(): void
    {
        $field = new StorefrontField(
            'title',
            'آمبوبگ',
            'آمبوبگ',
            '',
            StorefrontField::OWNER_MANAGER
        );
        self::assertFalse($field->needsDecision(), 'there is nothing to decide between two identical values');
    }

    public function testWhitespaceIsNotADisagreement(): void
    {
        // WooCommerce and the editor disagree about trailing newlines often
        // enough that a byte comparison would put half the catalogue on the
        // review screen.
        $field = new StorefrontField('short_description', "متن  نمونه\n", 'متن نمونه');
        self::assertFalse($field->differs());
    }
}
