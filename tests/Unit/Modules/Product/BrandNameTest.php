<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;

/**
 * Which two spellings are one brand, and which two are two brands.
 *
 * §4 asks for duplicate detection that covers «فاصله، نیم‌فاصله و ی/ک عربی و
 * فارسی», is EXPLICIT and TESTED, and does not merge names that are genuinely
 * different. Both halves are asserted here, and the second half matters more:
 * a rule that folded too much would put one shop's goods under another shop's
 * name, and nothing downstream could tell.
 */
final class BrandNameTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function sameBrand(): array
    {
        return [
            'a space against a zero-width non-joiner' => ['آمبو بگ', 'آمبو‌بگ'],
            'a space against nothing' => ['آمبو بگ', 'آمبوبگ'],
            'two spaces against one' => ['برند  نمونه', 'برند نمونه'],
            'leading and trailing space' => ['  سینا  ', 'سینا'],
            'Arabic yeh against Persian yeh' => ['آزمايشي', 'آزمایشی'],
            'Arabic kaf against Persian kaf' => ['كوثر', 'کوثر'],
            'alef with hamza against bare alef' => ['أطلس', 'اطلس'],
            'alef with madda against bare alef' => ['آرین', 'ارین'],
            'heh with hamza against heh' => ['نمونۀ', 'نمونه'],
            'teh marbuta against heh' => ['صحة', 'صحه'],
            'a tatweel in the middle' => ['طـب', 'طب'],
            'Arabic diacritics' => ['طِبّ', 'طب'],
            'Arabic-Indic digits against Persian ones' => ['برند ٣', 'برند ۳'],
            'Persian digits against Latin ones' => ['برند ۳', 'برند 3'],
            'Latin case' => ['MedLine', 'medline'],
            'a non-breaking space' => ["برند\u{00A0}نمونه", 'برندنمونه'],
            'a right-to-left mark' => ["برند\u{200F}", 'برند'],
        ];
    }

    /**
     * @dataProvider sameBrand
     */
    public function testTheseSpellingsAreOneBrand(string $a, string $b): void
    {
        self::assertTrue(BrandName::same($a, $b), $a . ' and ' . $b);
        self::assertSame(BrandName::key($a), BrandName::key($b));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function differentBrands(): array
    {
        return [
            'one letter apart' => ['سینا', 'سیما'],
            'a letter missing' => ['اطلس', 'اطس'],
            'a plural ending' => ['برند', 'برندها'],
            'an article' => ['طب', 'الطب'],
            'a different digit' => ['برند ۳', 'برند ۴'],
            'a transliteration' => ['MedLine', 'مدلاین'],
            'one is a prefix of the other' => ['مد', 'مدلاین'],
            'a different word order' => ['طب تک', 'تک طب'],
        ];
    }

    /**
     * @dataProvider differentBrands
     */
    public function testTheseAreTwoBrands(string $a, string $b): void
    {
        self::assertFalse(BrandName::same($a, $b), $a . ' and ' . $b);
    }

    public function testAnEmptyNameIsNeverTheSameAsAnything(): void
    {
        // Including as another empty one: «no brand» is not a brand two
        // products can share, and `same('', '')` answering true would make
        // every brandless product one duplicate of every other.
        self::assertFalse(BrandName::same('', ''));
        self::assertFalse(BrandName::same('', 'سینا'));
        self::assertFalse(BrandName::same("\u{200C} ", 'سینا'));
    }

    public function testTheDisplayFormKeepsTheWordAndTidiesTheSpace(): void
    {
        self::assertSame('برند نمونه', BrandName::display('  برند   نمونه '));
        // The ZWNJ is NOT removed from what is shown: «آمبو‌بگ» is how the
        // brand is written, and only the comparison key drops it.
        self::assertSame('آمبو‌بگ', BrandName::display('آمبو‌بگ'));
        self::assertStringNotContainsString("\u{200C}", BrandName::key('آمبو‌بگ'));
    }

    public function testAValidNameIsOneSomebodyCanSeeAndTheColumnCanHold(): void
    {
        self::assertTrue(BrandName::isValid('سینا'));
        self::assertFalse(BrandName::isValid(''));
        self::assertFalse(BrandName::isValid('   '));
        self::assertFalse(BrandName::isValid("\u{200C}\u{200D}"));
        self::assertTrue(BrandName::isValid(str_repeat('ا', BrandName::MAX_LENGTH)));
        self::assertFalse(BrandName::isValid(str_repeat('ا', BrandName::MAX_LENGTH + 1)));
    }

    public function testTheKeyIsStableAcrossCalls(): void
    {
        // Pinned, because the key is what the database's unique index holds:
        // a key that changed between two builds would let a duplicate open.
        self::assertSame(BrandName::key('آمبو بگ'), BrandName::key('آمبوبگ'));
        self::assertSame('medline', BrandName::key('  Med Line  '));
        self::assertSame('برند3', BrandName::key('برند ۳'));
    }
}
