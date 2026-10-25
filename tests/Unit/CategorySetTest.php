<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\CategorySet;

/**
 * WHAT THIS PROVES: a set of category ids is compared AS A SET, and the single
 * key is derived rather than chosen.
 *
 * The owner's acceptance rule is the reason every assertion here is about
 * equality rather than size: «برابری مجموعهٔ شناسه‌ها را قبل و بعد از اعمال،
 * ویرایش و پذیرش اندازه بگیر؛ برابری تعداد کافی نیست.»
 */
final class CategorySetTest extends TestCase
{
    public function testOrderCarriesNoMeaning(): void
    {
        self::assertSame([244, 245], CategorySet::of([245, 244]));
        self::assertTrue(CategorySet::same([244, 245], [245, 244]));
        // ⛔ The counter-example that makes the point: equal SIZE is not
        // equality, which is exactly the comparison the owner ruled out.
        self::assertFalse(CategorySet::same([244, 245], [244, 246]));
    }

    public function testDuplicatesAreOneRelationship(): void
    {
        self::assertSame([7], CategorySet::of([7, 7, '7']));
    }

    /**
     * Non-numeric entries are DROPPED, never cast.
     *
     * A `category_key` from before `alpha.25` keyed the directory on term ids
     * is a slug, and `(int) 'medical-glue'` is `0` — casting it would invent
     * the id nought by a route that reads as if a value had been understood.
     */
    public function testASlugIsNotATermId(): void
    {
        self::assertSame([], CategorySet::of(['medical-glue']));
        self::assertSame([], CategorySet::of(['0', '', '-3', 'syringe', null, 1.5]));
        self::assertSame([12], CategorySet::of([' 12 ', 'x']));
    }

    public function testWhatAWriteWouldRemoveAndAdd(): void
    {
        self::assertSame([245], CategorySet::lost([244, 245], [244]));
        self::assertSame([], CategorySet::lost([244], [244, 245]));
        self::assertSame([245], CategorySet::gained([244], [244, 245]));
        // The sentence «completing it removes nothing» IS `lost() === []`.
        self::assertSame([], CategorySet::lost([244], [244, 245, 246]));
    }

    public function testTheEncodedFormRoundTrips(): void
    {
        self::assertSame('244,245', CategorySet::toValue([245, 244]));
        self::assertSame([244, 245], CategorySet::fromValue('245,244'));
        self::assertSame('', CategorySet::toValue([]));
        self::assertSame([], CategorySet::fromValue(''));
        // What `sanitize_key()` leaves behind is ONE number, and it must not
        // be read as the three it came from (measured: `207,208,209` becomes
        // `207208209`).
        self::assertSame([207208209], CategorySet::fromValue('207208209'));
    }

    /**
     * The single key is derived, and a set of several has none.
     *
     * ⛔ Not «pick one»: which category's spec template applies to a
     * multi-category product is the owner's open decision
     * (`docs/open-decisions-alpha82.md` §1), and «انتخاب اولین، عمیق‌ترین یا
     * ترکیب خودکار قواعد مجاز نیست».
     */
    public function testTheSingleKeyIsDerivedNeverChosen(): void
    {
        self::assertSame('244', CategorySet::soleKey([244]));
        self::assertSame('', CategorySet::soleKey([244, 245]));
        self::assertSame('', CategorySet::soleKey([]));
        // And a set that normalises to one member does have a key.
        self::assertSame('244', CategorySet::soleKey([244, '244']));
    }
}
