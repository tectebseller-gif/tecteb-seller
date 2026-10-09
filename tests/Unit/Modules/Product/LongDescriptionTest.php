<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\LongDescription;

/**
 * The four answers, and the two of them that `alpha.41` could not tell apart.
 */
final class LongDescriptionTest extends TestCase
{
    public function testTextIsWritten(): void
    {
        self::assertSame(
            '<p>تازه</p>',
            LongDescription::decide('<p>تازه</p>', LongDescription::BASE_NONE, false, null)
        );
        self::assertSame(
            '<p>تازه</p>',
            LongDescription::decide('<p>تازه</p>', LongDescription::BASE_VALUE, false, '<p>قدیمی</p>')
        );
    }

    /**
     * THE DEFECT. An empty box over a row that holds nothing is not an
     * instruction, and `alpha.41` read it as one — so opening a legacy
     * product's form and saving its title asked for the shop's description to
     * be deleted.
     */
    public function testAnEmptyBoxOverNothingChangesNothing(): void
    {
        self::assertNull(LongDescription::decide('', LongDescription::BASE_NONE, false, null));
    }

    /** An empty box over a value IS the instruction, and needs no tick. */
    public function testAnEmptyBoxOverAValueClearsIt(): void
    {
        self::assertSame('', LongDescription::decide('', LongDescription::BASE_VALUE, false, '<p>قدیمی</p>'));
    }

    /** The tick is the only way to reach the shop's own text, and it works. */
    public function testTheExplicitTickClearsEvenWhenNothingWasOnScreen(): void
    {
        self::assertSame('', LongDescription::decide('', LongDescription::BASE_NONE, true, null));
    }

    /**
     * «Not in play» keeps what is stored — and that is why `decide()` takes
     * the stored value at all.
     *
     * Returning `null` here would be a WRITE of null, because the column is
     * written with whatever comes back. A form rendered by `alpha.41` posting
     * into `alpha.42` names no base and would have had its long description
     * turned back into «never set».
     */
    public function testASubmissionThatSaysNothingKeepsWhatIsStored(): void
    {
        self::assertSame('<p>همان</p>', LongDescription::decide(null, null, false, '<p>همان</p>'));
        self::assertSame('<p>همان</p>', LongDescription::decide('هرچه', null, false, '<p>همان</p>'));
        self::assertSame('', LongDescription::decide(null, null, false, ''), 'a deliberate clear stays cleared');
        self::assertNull(LongDescription::decide(null, null, false, null));
    }

    /** The base, and the tick, are derived in one place so two paths agree. */
    public function testTheBaseAndTheTickAreDerivedFromTheStoredValue(): void
    {
        self::assertSame(LongDescription::BASE_NONE, LongDescription::baseOf(null));
        self::assertSame(LongDescription::BASE_VALUE, LongDescription::baseOf(''));
        self::assertSame(LongDescription::BASE_VALUE, LongDescription::baseOf('<p>x</p>'));

        self::assertTrue(LongDescription::offersClear(null), 'nothing on screen: the tick is the only way');
        self::assertFalse(LongDescription::offersClear(''), 'a cleared field is already clear');
        self::assertFalse(LongDescription::offersClear('<p>x</p>'), 'emptying the box IS the way');
    }

    /**
     * A whitespace-only box is TEXT, not a clear.
     *
     * Deliberate, and worth pinning: trimming it to a clear would make a
     * stray space delete the shop's description, and this field holds markup
     * where whitespace is meaningful.
     */
    public function testAWhitespaceBoxIsAValueAndNotAClear(): void
    {
        self::assertSame(' ', LongDescription::decide(' ', LongDescription::BASE_NONE, false, null));
    }
}
