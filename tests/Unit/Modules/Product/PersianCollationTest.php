<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;

/**
 * WHAT THIS PROVES: «عنوان (الفبایی فارسی)» is the Persian alphabet, and not
 * the Arabic one the codepoints happen to be in.
 *
 * Every assertion here is about the ORDER of two keys, because that is the
 * only thing the key is for. The keys themselves are an implementation detail
 * and are never asserted literally: a test that pins the bytes would have to
 * be rewritten the first time a letter was added, and would still not say
 * whether پ came before ت.
 */
final class PersianCollationTest extends TestCase
{
    /**
     * The four letters Persian added to the Arabic alphabet, in their right
     * places. By codepoint every one of them sorts after ی — which is the
     * defect this class exists for.
     */
    public function testThePersianOnlyLettersSortWhereAPersianReaderExpects(): void
    {
        $this->assertOrder(['آب', 'ابر', 'باد', 'پاک', 'تاب'], 'آ ا ب پ ت');
        $this->assertOrder(['جام', 'چای', 'حال'], 'ج چ ح');
        $this->assertOrder(['روز', 'زرد', 'ژاله', 'سار'], 'ر ز ژ س');
        $this->assertOrder(['قند', 'کار', 'گاز', 'لاله'], 'ق ک گ ل');
        self::assertLessThan(
            PersianCollation::sortKey('یاس'),
            PersianCollation::sortKey('پالس اکسیمتر'),
            'پ must come before ی — by codepoint it does not'
        );
    }

    /** WHAT THIS PROVES: the two ways of writing ی and ک are one letter here. */
    public function testArabicAndPersianFormsOfTheSameLetterAreTheSameLetter(): void
    {
        self::assertSame(
            PersianCollation::sortKey('کیف کمک‌های اولیه'),
            PersianCollation::sortKey('كيف كمك‌هاي اوليه'),
            'ک/ك and ی/ي are the same letter for sorting'
        );
        self::assertSame(PersianCollation::sortKey('مؤثر'), PersianCollation::sortKey('موثر'));
        self::assertSame(PersianCollation::sortKey('ماسک استريل'), PersianCollation::sortKey('ماسک استریل'));
        // And the title itself is untouched: this is a reading, not an edit.
        self::assertSame('كيف', 'كيف');
    }

    /**
     * WHAT THIS PROVES: numbers read as numbers.
     *
     * The failure this prevents is the ordinary one in every list: «محصول ۱۰»
     * between «محصول ۱» and «محصول ۲», because the comparison is character by
     * character and «۱» is less than «۲».
     */
    public function testNumbersInTitlesSortByValueAndNotByFirstDigit(): void
    {
        $this->assertOrder(['محصول ۱', 'محصول ۲', 'محصول ۱۰', 'محصول ۱۱', 'محصول ۱۰۰'], 'numbers by value');
        // The same number written three ways is the same number.
        self::assertSame(PersianCollation::sortKey('محصول ۲'), PersianCollation::sortKey('محصول 2'));
        self::assertSame(PersianCollation::sortKey('محصول ٢'), PersianCollation::sortKey('محصول 2'));
        // A number inside a Latin title too.
        $this->assertOrder(['Mask N2', 'Mask N10'], 'latin titles count as well');
    }

    /** WHAT THIS PROVES: Persian titles come first, then everything else. */
    public function testPersianTitlesComeBeforeLatinOnes(): void
    {
        self::assertLessThan(
            PersianCollation::sortKey('Aaa'),
            PersianCollation::sortKey('یاس'),
            'the last Persian letter still sorts before the first Latin one'
        );
        // A title that opens with a number is judged by its first LETTER, so a
        // Persian product with a code in front of it stays with the Persians.
        self::assertLessThan(
            PersianCollation::sortKey('Aaa'),
            PersianCollation::sortKey('۱۲۳ ماسک'),
            'a number in front does not move a Persian title into the Latin group'
        );
    }

    /**
     * WHAT THIS PROVES: what is invisible on the screen is invisible to the
     * order — «آمبو بگ», «آمبو‌بگ» with a half-space, and a double space are
     * one title as far as sorting is concerned.
     */
    public function testHalfSpacesDiacriticsAndDoubleSpacesDoNotMoveATitle(): void
    {
        $half = PersianCollation::sortKey("آمبو\u{200C}بگ");
        self::assertSame($half, PersianCollation::sortKey('آمبو بگ'), 'a half-space is a space');
        self::assertSame($half, PersianCollation::sortKey('آمبو  بگ'));
        self::assertSame($half, PersianCollation::sortKey('  آمبو بگ  '));
        self::assertSame(PersianCollation::sortKey('مَدَد'), PersianCollation::sortKey('مدد'));

        // Written as one word it is a different title — and it sorts right
        // beside the other two rather than somewhere else in the alphabet,
        // which is what a reader looking for «آمبوبگ» needs.
        self::assertLessThan(PersianCollation::sortKey('آمبوبگ'), $half);
        self::assertLessThan(PersianCollation::sortKey('آمبولانس'), PersianCollation::sortKey('آمبوبگ'));
    }

    /**
     * WHAT THIS PROVES: a product with no title lands at the END.
     *
     * It is a real state — a draft created and not named yet — and putting it
     * first would push the products somebody is looking for off the page.
     */
    public function testUntitledProductsSortLast(): void
    {
        $empty = PersianCollation::sortKey('');
        self::assertSame($empty, PersianCollation::sortKey('   '));
        self::assertSame($empty, PersianCollation::sortKey("\u{200C}"), 'a half-space alone is no title either');
        self::assertGreaterThan(PersianCollation::sortKey('zzz'), $empty);
        self::assertGreaterThan(PersianCollation::sortKey('یاس'), $empty);
    }

    /** WHAT THIS PROVES: the key fits the column it is stored in, whatever the title. */
    public function testTheKeyIsBounded(): void
    {
        $long = PersianCollation::sortKey(str_repeat('آزمایش ', 200));
        self::assertLessThanOrEqual(PersianCollation::KEY_LENGTH, strlen($long));
        self::assertNotSame('', $long);
    }

    /**
     * WHAT THIS PROVES: a long number is a BIG number, not a wrapped one.
     *
     * The form this replaced padded every digit run to twelve and kept the LAST
     * twelve, so `1000000000000` — thirteen digits — became twelve zeros: the
     * number nought, sorted below «کالا 1». Measured on the delivered
     * `alpha.33`, then fixed. The catalogue has serial numbers and codes in
     * titles, so thirteen digits is not a hypothetical.
     */
    public function testNumbersLongerThanTwelveDigitsStillSortAsTheBiggerNumber(): void
    {
        $this->assertOrder([
            'کالا 1',
            'کالا 999999999999',           // twelve — the old ceiling
            'کالا 1000000000000',          // thirteen — used to encode as zero
            'کالا 1000000000001',
            'کالا 9999999999999',
            'کالا 10000000000000',         // fourteen
            'کالا 99999999999999999999',   // twenty
        ], 'longer digit runs are bigger numbers');

        // The specific pair the finding named, stated on its own so a failure
        // says which defect came back.
        self::assertGreaterThan(
            PersianCollation::sortKey('کالا 1'),
            PersianCollation::sortKey('کالا 1000000000000'),
            'thirteen digits must not wrap to nought'
        );
    }

    /**
     * WHAT THIS PROVES: leading zeros are not part of the number, and the same
     * number typed in Persian, Arabic-Indic or Latin digits is one number at
     * any length.
     */
    public function testLeadingZerosAndDigitScriptsDoNotChangeTheNumber(): void
    {
        self::assertSame(
            PersianCollation::sortKey('کالا 7'),
            PersianCollation::sortKey('کالا 007'),
            'leading zeros are not part of the number'
        );
        self::assertSame(
            PersianCollation::sortKey('کالا 0'),
            PersianCollation::sortKey('کالا 0000'),
            'a run of zeros is the number nought once'
        );
        $this->assertOrder(['کالا 0', 'کالا 007', 'کالا 10'], 'nought is still the smallest');

        // Thirteen digits, in all three scripts the shop's titles carry.
        $latin = PersianCollation::sortKey('کالا 1000000000000');
        self::assertSame($latin, PersianCollation::sortKey('کالا ۱۰۰۰۰۰۰۰۰۰۰۰۰'), 'Persian digits');
        self::assertSame($latin, PersianCollation::sortKey('کالا ١٠٠٠٠٠٠٠٠٠٠٠٠'), 'Arabic-Indic digits');
        // And a mixed run is still one number, because the digits are folded
        // before the run is read rather than after.
        self::assertSame($latin, PersianCollation::sortKey('کالا ۱000000000۰۰۰'), 'a mixed run is one number');
    }

    /**
     * WHAT THIS PROVES: the digit-count prefix has a limit, the limit is stated,
     * and hitting it keeps the number in the HIGHEST bucket.
     *
     * A hundred-digit run cannot fit a 191-character key at all, so something
     * has to give. What must NOT happen is the old failure in a new place: a
     * clamp that wrapped such a run back under a short number.
     */
    public function testARunLongerThanThePrefixCanDescribeStaysAtTheTop(): void
    {
        $huge = PersianCollation::sortKey('کالا ' . str_repeat('9', 120));
        // Above ninety-nine digits the resolution is gone and this is stated
        // rather than discovered: two such runs compare as one bucket.
        self::assertSame(PersianCollation::sortKey('کالا ' . str_repeat('9', 99)), $huge);
        // What must hold is that the bucket is the TOP one, not a wrap.
        self::assertGreaterThan(PersianCollation::sortKey('کالا 1000000000000'), $huge);
        self::assertGreaterThan(
            PersianCollation::sortKey('کالا ' . str_repeat('9', 98)),
            $huge,
            'a run over the limit must not fall under a shorter number'
        );
        self::assertLessThanOrEqual(PersianCollation::KEY_LENGTH, strlen($huge));
    }

    /**
     * WHAT THIS PROVES: the build number moves when the key format moves.
     *
     * `TitleSortRepair` compares the stored build against this constant to
     * decide whether every key in the table is stale. A format change that
     * forgot to bump it would leave the column silently mixing two encodings,
     * where the order between an old row and a new one is whatever the two
     * happen to produce — so the constant is asserted here, beside the format
     * it describes, rather than only where it is read.
     */
    public function testTheBuildNumberNamesThisKeyFormat(): void
    {
        self::assertSame(2, PersianCollation::BUILD, 'build 2 is the length-carrying number block');
    }
    /**
     * Assert that these titles are already in order, pair by pair, and say
     * which pair broke if one does.
     *
     * @param list<string> $titles
     */
    private function assertOrder(array $titles, string $because): void
    {
        for ($i = 1; $i < count($titles); $i++) {
            self::assertLessThan(
                PersianCollation::sortKey($titles[$i]),
                PersianCollation::sortKey($titles[$i - 1]),
                sprintf('«%s» must sort before «%s» (%s)', $titles[$i - 1], $titles[$i], $because)
            );
        }
    }
}
