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

    public function testAFieldOlderThanTheStampsAlwaysAsks(): void
    {
        // Even when the two sides agree today. The field is frozen until
        // somebody settles it, and a vendor whose next edit silently becomes
        // a proposal deserves a screen that already said why.
        $agreeing = new StorefrontField(
            'title',
            'آمبوبگ',
            'آمبوبگ',
            '',
            StorefrontField::OWNER_UNKNOWN
        );
        self::assertFalse($agreeing->differs());
        self::assertTrue($agreeing->needsDecision());
        self::assertTrue($agreeing->isUnsettled());
    }

    public function testASettledFieldIsNotUnsettled(): void
    {
        foreach ([StorefrontField::OWNER_MARKETPLACE, StorefrontField::OWNER_MANAGER] as $owner) {
            self::assertFalse(
                (new StorefrontField('title', 'x', 'x', '', $owner))->isUnsettled(),
                $owner . ' has been established'
            );
        }
    }

    public function testADecidedFieldStopsAsking(): void
    {
        // `description` is the one that proves this matters: the projector
        // builds it, so the two sides go on differing after the manager has
        // chosen — and a question that survives its own answer is one people
        // learn to click past.
        $decided = new StorefrontField(
            'description',
            'متنی که بازارگاه می‌سازد',
            'متنی که مدیر نوشته',
            '',
            StorefrontField::OWNER_MANAGER,
            false,
            true
        );
        self::assertTrue($decided->differs(), 'they still differ, and always will');
        self::assertFalse($decided->needsDecision(), 'but it has been answered');
    }

    public function testAnUndecidedManagerEditStillAsks(): void
    {
        $fresh = new StorefrontField(
            'title',
            'عنوان بازارگاه',
            'عنوان مدیر',
            '',
            StorefrontField::OWNER_MANAGER
        );
        self::assertTrue($fresh->needsDecision());
    }
}
