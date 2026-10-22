<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;

/**
 * The one money formatter, including the distinction the owner's words turn on.
 */
final class MoneyTextTest extends TestCase
{
    /**
     * The owner's own figures, as a person reads them.
     *
     * «کمیسیون دقیقاً ۱۲٫۶۲۵ تومان و سهم تأمین‌کننده دقیقاً ۸۸٫۳۷۵ تومان.»
     */
    public function testTheOwnersFiguresPrintAsTheyAreWritten(): void
    {
        self::assertSame('۱۲٫۶۲۵', MoneyText::format(126250, 4));
        self::assertSame('۸۸٫۳۷۵', MoneyText::format(883750, 4));
        self::assertSame('۱۰۱', MoneyText::format(1010000, 4));
    }

    /**
     * DROPPING TRAILING ZEROS IS NOT «حذف اعشار», and the pair proves it.
     *
     * A zero carries no value, so `450000.0000` and `450000` are one number
     * and printing the zeros would make every ordinary page unreadable.
     * `88.3750` keeps its `375`, because that is money somebody is owed —
     * which is the thing the owner ruled out losing.
     */
    public function testOnlyZeroPlacesAreDroppedAndNeverAValue(): void
    {
        self::assertSame('۴۵۰٬۰۰۰', MoneyText::format(4500000000, 4));
        self::assertSame('۸۸٫۳۷۵', MoneyText::format(883750, 4));
        self::assertSame('۰٫۰۰۰۱', MoneyText::format(1, 4), 'one ten-thousandth is still a value');
        self::assertSame('۰', MoneyText::format(0, 4));
    }

    /** `exact()` shows what the row holds, zeros and all. */
    public function testExactShowsEveryRecordedPlace(): void
    {
        self::assertSame('۸۸٫۳۷۵۰', MoneyText::exact(883750, 4));
        self::assertSame('۴۵۰٬۰۰۰٫۰۰۰۰', MoneyText::exact(4500000000, 4));
    }

    /** Persian separators, because an ASCII stop reads as a full stop here. */
    public function testTheSeparatorsArePersian(): void
    {
        self::assertSame('۱٬۲۳۴٬۵۶۷', MoneyText::format(1234567, 0));
        self::assertStringNotContainsString('.', (string) MoneyText::format(883750, 4));
        self::assertStringNotContainsString(',', (string) MoneyText::format(1234567, 0));
    }

    public function testANegativeAmountKeepsItsSign(): void
    {
        self::assertSame('-۸۸٫۳۷۵', MoneyText::format(-883750, 4));
    }

    /** An exponent this model cannot express is `null`, never a guessed scale. */
    public function testAnUnexpressibleExponentIsRefused(): void
    {
        self::assertNull(MoneyText::format(1, 7));
        self::assertNull(MoneyText::exact(1, -1));
    }
}
