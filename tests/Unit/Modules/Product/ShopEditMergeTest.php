<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ShopEditMerge as Merge;

/**
 * WHAT THIS PROVES: a price, SKU or weight edited by hand in WooCommerce is
 * never overwritten by the marketplace's old copy — and the vendor is never
 * stopped from changing their own.
 *
 * Both halves, because either one alone is a defect: a rule that keeps
 * everything restricts the vendor, and a rule that writes everything is the
 * `alpha.82` behaviour this class replaces.
 */
final class ShopEditMergeTest extends TestCase
{
    /** @return array{sent:string, shop:string} */
    private function record(string $field, string $sent, string $shop): array
    {
        $sentCanonical = Merge::canonical($field, $sent);
        $shopCanonical = Merge::canonical($field, $shop);
        self::assertIsString($sentCanonical);
        self::assertIsString($shopCanonical);
        return Merge::record($sentCanonical, $shopCanonical);
    }

    public function testAnUntouchedShopNeverRestrictsTheVendor(): void
    {
        // We wrote 450000 and the shop still holds it, so the vendor's new
        // 500000 goes out. THIS is «فروشنده همیشه مجاز است قیمت محصول خودش را
        // تغییر دهد»: without this arm the fix would be a restriction.
        $record = $this->record(Merge::PRICE, '450000', '450000');
        self::assertSame(
            Merge::WRITE_FRESH,
            Merge::decide(Merge::PRICE, $record, '450000', '500000')
        );
        self::assertTrue(Merge::writes(Merge::WRITE_FRESH));
        self::assertFalse(Merge::reports(Merge::WRITE_FRESH));
    }

    public function testAnEditInWooCommerceIsNotOverwrittenByOurOldCopy(): void
    {
        // The measured defect: we wrote 450000, somebody made it 480000 in
        // WooCommerce, our row still says 450000. Writing would restore the
        // old copy — «همگام‌سازی نباید قیمت ویرایش‌شده در WooCommerce را با
        // نسخهٔ قدیمی ذخیره‌شده در افزونه جایگزین کند».
        $record = $this->record(Merge::PRICE, '450000', '450000');
        $answer = Merge::decide(Merge::PRICE, $record, '480000', '450000');
        self::assertSame(Merge::KEEP_EDITED, $answer);
        self::assertFalse(Merge::writes($answer));
        self::assertTrue(Merge::reports($answer));
    }

    public function testBothSidesMovedAppliesTheFreshValueAndReportsIt(): void
    {
        // «تغییر جدید باید اعمال شود» AND «تعارض باید ثبت و گزارش شود» — so
        // the one answer that both writes and reports.
        $record = $this->record(Merge::PRICE, '450000', '450000');
        $answer = Merge::decide(Merge::PRICE, $record, '480000', '500000');
        self::assertSame(Merge::WRITE_CONFLICT, $answer);
        self::assertTrue(Merge::writes($answer));
        self::assertTrue(Merge::reports($answer));
    }

    public function testWithNoRecordOnlyANoOpWriteIsTaken(): void
    {
        // `alpha.44`'s rule in its own words: an argument about the write
        // being harmless, not a proof of historical ownership.
        self::assertSame(Merge::WRITE, Merge::decide(Merge::PRICE, null, '450000', '450000'));
        $answer = Merge::decide(Merge::PRICE, null, '480000', '450000');
        self::assertSame(Merge::KEEP_UNKNOWN, $answer);
        self::assertFalse(Merge::writes($answer));
        self::assertTrue(Merge::reports($answer));
    }

    public function testWithNoRecordAFreshVendorValueIsAppliedAndTheConflictReported(): void
    {
        // ⛔ The case an UPGRADED site is in, and the hole the upgrade check
        // found: every product taken in by an older package has no record, so
        // if somebody edited it by hand the two differ and the answer above is
        // KEEP. For a PROJECTION that is right — our copy may be months old.
        // For the vendor's OWN price change it is a restriction, and
        // «این اختیار نباید محدود شود».
        //
        // The stamps cannot tell the two apart; the CALLER can, so it says so.
        $answer = Merge::decide(Merge::PRICE, null, '480000', '500000', true);
        self::assertSame(Merge::WRITE_CONFLICT, $answer);
        self::assertTrue(Merge::writes($answer), 'the vendor is never held back');
        self::assertTrue(Merge::reports($answer), 'and the difference is never silent');

        // The same call WITHOUT the flag — the projection — still keeps.
        self::assertSame(Merge::KEEP_UNKNOWN, Merge::decide(Merge::PRICE, null, '480000', '500000'));

        // And freshness is not a licence to write anything: a value that
        // cannot be compared exactly is still refused, and an equal pair is
        // still the ordinary no-op write rather than a conflict.
        self::assertSame(Merge::UNREADABLE, Merge::decide(Merge::PRICE, null, '480000.50', '500000', true));
        self::assertSame(Merge::WRITE, Merge::decide(Merge::PRICE, null, '500000', '500000', true));
    }

    public function testTheComparisonIsByValueAndNotByText(): void
    {
        // A shop with two decimal places writes the same price as
        // `'450000.00'`. Comparing the strings would call every untouched
        // product edited.
        $record = $this->record(Merge::PRICE, '450000', '450000');
        self::assertSame(
            Merge::WRITE_FRESH,
            Merge::decide(Merge::PRICE, $record, '450000.00', '450000')
        );
        // And the weight in kilograms against grams, which is the inverse of
        // `round($grams / 1000, 3)`.
        self::assertSame('12500', Merge::canonical(Merge::WEIGHT, '12.5'));
        self::assertSame('12500', Merge::canonical(Merge::WEIGHT, '12.500'));
        $weight = $this->record(Merge::WEIGHT, '12.5', '12.5');
        self::assertSame(
            Merge::WRITE_FRESH,
            Merge::decide(Merge::WEIGHT, $weight, '12.500', '14')
        );
    }

    public function testACanonicalRecordIsCheckedAndNeverConvertedTwice(): void
    {
        // ⛔ The bug this class had for one draft, pinned so it cannot come
        // back: the stored weight is GRAMS, and putting it through
        // `canonical()` again would make 12500 into 12,500,000 — so every
        // weight would read as edited and every weight edit would be
        // «kept» for ever.
        $weight = $this->record(Merge::WEIGHT, '12.5', '12.5');
        self::assertSame(['sent' => '12500', 'shop' => '12500'], $weight);
        self::assertSame(
            ['weight' => ['sent' => '12500', 'shop' => '12500']],
            Merge::normalise(['weight' => $weight])
        );
        // Unchanged on both sides, so the vendor's value may be written.
        self::assertSame(
            Merge::WRITE_FRESH,
            Merge::decide(Merge::WEIGHT, $weight, '12.5', '12.5')
        );
    }

    public function testAValueThatCannotBeComparedExactlyIsNeitherWrittenNorRounded(): void
    {
        // `alpha.67`: exact or refused. And `alpha.68`: «نمی‌دانم» is not «safe».
        self::assertNull(Merge::canonical(Merge::PRICE, '480000.50'));
        self::assertNull(Merge::canonical(Merge::WEIGHT, '0.0125'));
        $record = $this->record(Merge::PRICE, '450000', '450000');
        $answer = Merge::decide(Merge::PRICE, $record, '480000.50', '450000');
        self::assertSame(Merge::UNREADABLE, $answer);
        self::assertFalse(Merge::writes($answer));
        self::assertTrue(Merge::reports($answer));
    }

    public function testNothingToWriteIsNeitherAConflictNorAWrite(): void
    {
        // An absent price, an absent SKU and a zero weight were «not written»
        // before this class existed and are reported where they belong.
        // Putting a row in front of a manager here would be noise about a
        // difference nobody is going to act on.
        $answer = Merge::decide(Merge::PRICE, null, '480000', '');
        self::assertSame(Merge::NO_VALUE, $answer);
        self::assertFalse(Merge::writes($answer));
        self::assertFalse(Merge::reports($answer));
    }

    public function testAnEmptyShopValueIsAValueAndNotAFailure(): void
    {
        // «این محصول قیمتی ندارد» and «قیمت را نتوانستم بخوانم» are opposite
        // answers (`alpha.8`), so only the second is `null`.
        self::assertSame('', Merge::canonical(Merge::PRICE, ''));
        self::assertSame('', Merge::canonical(Merge::PRICE, '   '));
        // No record, the shop holds nothing, we want a price: not a no-op, so
        // we do not claim it.
        self::assertSame(Merge::KEEP_UNKNOWN, Merge::decide(Merge::PRICE, null, '', '450000'));
    }

    public function testAMalformedRecordReadsAsNoRecord(): void
    {
        self::assertSame([], Merge::normalise('nonsense'));
        self::assertSame([], Merge::normalise(['price' => 'nonsense']));
        self::assertSame([], Merge::normalise(['price' => ['sent' => '450000']]));
        // A value that is not canonical — a kilogram string where grams
        // belong — is dropped rather than compared.
        self::assertSame([], Merge::normalise(['weight' => ['sent' => '12.5', 'shop' => '12.5']]));
        // And a field nobody writes is not a field.
        self::assertSame([], Merge::normalise(['title' => ['sent' => 'x', 'shop' => 'x']]));
    }

    public function testTheSkuIsTextAndTrimmedOnBothSides(): void
    {
        // WooCommerce trims on save, so a trailing space would otherwise read
        // as somebody's edit.
        $record = $this->record(Merge::SKU, 'SKU-1', 'SKU-1');
        self::assertSame(
            Merge::WRITE_FRESH,
            Merge::decide(Merge::SKU, $record, ' SKU-1 ', 'SKU-2')
        );
        self::assertSame(
            Merge::KEEP_EDITED,
            Merge::decide(Merge::SKU, $record, 'SKU-OTHER', 'SKU-1')
        );
    }

    public function testEveryFieldHasAnAnswerForEveryState(): void
    {
        // A loop, so a field added to `FIELDS` without a canonical form fails
        // here rather than silently answering `UNREADABLE` in production.
        foreach (Merge::FIELDS as $field) {
            self::assertIsString(Merge::canonical($field, ''), $field);
            $value = $field === Merge::SKU ? 'A-1' : '7';
            $canonical = Merge::canonical($field, $value);
            self::assertIsString($canonical, $field);
            self::assertTrue(Merge::isCanonical($field, $canonical), $field);
            self::assertSame(
                Merge::WRITE,
                Merge::decide($field, null, $value, $value),
                $field . ': an identical value with no record is a no-op write'
            );
        }
    }
}
