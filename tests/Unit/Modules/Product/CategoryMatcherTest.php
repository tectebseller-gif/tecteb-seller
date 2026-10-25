<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\CategoryMatcher;

/**
 * The three spellings the owner named, and the line between «this is it» and
 * «this might be it».
 */
final class CategoryMatcherTest extends TestCase
{
    private const TARGET = 'آمبوبگ';

    public function testTheExactWordIsExact(): void
    {
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank('آمبوبگ', self::TARGET));
    }

    public function testAHalfSpaceOrASpaceInTheMiddleIsStillExact(): void
    {
        // «آمبو بگ» — what a phone keyboard produces.
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank('آمبو بگ', self::TARGET));
        // …and the zero-width non-joiner, which looks like nothing at all.
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank("آمبو\u{200c}بگ", self::TARGET));
    }

    public function testOneWrongLetterIsANearSuggestionAndIsLabelledAsOne(): void
    {
        // «امبوبک»: Arabic alef for آ, and ک where the catalogue has گ.
        $rank = CategoryMatcher::rank('امبوبک', self::TARGET);
        self::assertSame(CategoryMatcher::NEAR, $rank);
        self::assertTrue(CategoryMatcher::isFuzzy($rank), 'a guess must be presented as a guess');
    }

    public function testAnArabicKeyboardStillFindsThePersianTerm(): void
    {
        // ي and ك instead of ی and ک.
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank('كيف', 'کیف'));
    }

    public function testAPrefixBeatsAContainsWhichBeatsAGuess(): void
    {
        self::assertSame(CategoryMatcher::PREFIX, CategoryMatcher::rank('آمبو', self::TARGET));
        self::assertSame(CategoryMatcher::CONTAINS, CategoryMatcher::rank('بوبگ', self::TARGET));
        self::assertLessThan(
            CategoryMatcher::NEAR,
            CategoryMatcher::rank('آمبو', self::TARGET),
            'a certainty is ranked above a suggestion'
        );
    }

    public function testTheAncestryCanMatchButOnlyAsAContains(): void
    {
        $rank = CategoryMatcher::rank('بیهوشی', 'لوازم جانبی', 'تجهیزات پزشکی › بیهوشی و تنفسی › لوازم جانبی');
        self::assertSame(CategoryMatcher::CONTAINS, $rank, 'a parent match offers the row, it does not claim it');
    }

    /**
     * The guard that keeps «near» meaningful.
     *
     * Two edits out of three letters is not a typo, it is a different word —
     * and on 1,070 categories a short query would otherwise «nearly» match
     * most of the catalogue.
     */
    public function testAShortQueryDoesNotNearlyMatchEverything(): void
    {
        self::assertSame(CategoryMatcher::NO_MATCH, CategoryMatcher::rank('اب', self::TARGET));
        self::assertSame(CategoryMatcher::NO_MATCH, CategoryMatcher::rank('دستکش جراحی', self::TARGET));
    }

    public function testPersianAndArabicDigitsFoldToAscii(): void
    {
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank('ماسک ۳ لایه', 'ماسک 3 لایه'));
    }

    /**
     * PHP's own levenshtein() counts BYTES. Every Persian letter is two, so
     * one substituted letter reads as a distance of four and the cap rejects
     * it. This is the test that would fail if the character-wise walk were
     * swapped back for the built-in.
     */
    public function testDistanceIsCountedInLettersNotBytes(): void
    {
        // Measured, not assumed: «امبوبك» (Arabic alef AND Arabic kaf) is
        // three BYTE edits from «آمبوبگ» — past the cap — while after folding
        // it is one LETTER edit.
        self::assertGreaterThan(
            CategoryMatcher::MAX_EDITS,
            levenshtein('امبوبك', 'آمبوبگ'),
            'sanity: the byte-wise function really does over-count here'
        );
        self::assertSame(CategoryMatcher::NEAR, CategoryMatcher::rank('امبوبك', self::TARGET));
    }

    /**
     * The reported defect, as it was reported.
     *
     * «کاربر گزارش کرده جست‌وجوی «چسب تزریق پزشکی» صفحه را برمی‌گرداند و
     * نتیجهٔ قابل‌مشاهده ندارد، ولی «چسب» نتیجه می‌دهد و همان دسته در نتایج
     * هست.» Both halves are asserted here, because the second is what makes
     * the first a defect rather than an absent category: the row IS findable.
     */
    public function testEveryWordOfTheQueryPresentInTheNameAndItsAncestry(): void
    {
        $leaf = 'چسب';
        $path = 'تجهیزات پزشکی › مواد مصرفی › چسب';

        // One word finds it outright — this is the half that always worked.
        self::assertSame(CategoryMatcher::EXACT, CategoryMatcher::rank('چسب', $leaf, $path));

        // Three words, spread across the leaf and its ancestry.
        self::assertSame(
            CategoryMatcher::ALL_WORDS,
            CategoryMatcher::rank('چسب تزریق پزشکی', $leaf, $path . ' تزریق'),
            'every word is there; they are simply not one run of characters'
        );
    }

    /** Word order is not a requirement — nobody types the ancestry top-down. */
    public function testWordOrderDoesNotMatter(): void
    {
        self::assertSame(
            CategoryMatcher::ALL_WORDS,
            CategoryMatcher::rank('چسب تجهیزات', 'چسب', 'تجهیزات پزشکی › چسب')
        );
    }

    /**
     * A contiguous run still outranks scattered words.
     *
     * Otherwise «ماسک سه لایه» as a phrase and the same four words sprinkled
     * over an unrelated branch would arrive at the picker as equals.
     */
    public function testAContiguousRunStillRanksAboveScatteredWords(): void
    {
        $run = CategoryMatcher::rank('ماسک سه', 'ماسک سه لایه');
        $scattered = CategoryMatcher::rank('ماسک لایه', 'ماسک', 'پوشاک › لایه');

        self::assertSame(CategoryMatcher::PREFIX, $run);
        self::assertSame(CategoryMatcher::ALL_WORDS, $scattered);
        self::assertLessThan($scattered, $run);
    }

    /** It is a certainty, not a guess, so it is not labelled «پیشنهاد نزدیک». */
    public function testScatteredWordsAreNotFuzzy(): void
    {
        self::assertFalse(CategoryMatcher::isFuzzy(CategoryMatcher::ALL_WORDS));
        self::assertTrue(CategoryMatcher::isFuzzy(CategoryMatcher::NEAR));
    }

    /**
     * ⛔ Conjunctive. One missing word is no match, however common the others.
     *
     * The rule that would have been easier — «any word matches» — turns
     * «پزشکی» into a query for most of a 1,070-term catalogue, and the forty
     * shown would be forty rows the vendor did not ask for.
     */
    public function testOneMissingWordIsNoMatch(): void
    {
        self::assertSame(
            CategoryMatcher::NO_MATCH,
            CategoryMatcher::rank('چسب تزریق دستکش', 'چسب', 'تجهیزات پزشکی › چسب تزریق')
        );
        // And the pre-existing guarantee: two words, neither of them there.
        self::assertSame(CategoryMatcher::NO_MATCH, CategoryMatcher::rank('دستکش جراحی', self::TARGET));
    }

    /** A single word is never ALL_WORDS: the older ranks already decide it. */
    public function testASingleWordNeverReachesTheScatteredRank(): void
    {
        foreach (['آمبوبگ', 'آمبو', 'بوبگ', 'دستکش'] as $one) {
            self::assertNotSame(
                CategoryMatcher::ALL_WORDS,
                CategoryMatcher::rank($one, self::TARGET),
                $one
            );
        }
    }

    /** The folding still applies per word: Arabic shapes, digits, the half-space. */
    public function testFoldingAppliesToEachWordSeparately(): void
    {
        // ⛔ The ancestry deliberately does NOT hold «کیف 3» next to each
        // other. The first version of this test used «لوازم › کیف 3 لایه»,
        // where the folded query «کیف3» IS a contiguous run of the folded
        // path — so it ranked CONTAINS and said nothing about the new rank.
        // The oracle was wrong, not the code.
        self::assertSame(
            CategoryMatcher::ALL_WORDS,
            CategoryMatcher::rank('كيف ۳', 'کیف', 'لوازم › 3 لایه')
        );
    }

    /**
     * An empty result can name the word that is missing.
     *
     * «نتیجهٔ خالی پیام روشن داشته باشد» — and «چیزی پیدا نشد» is not that
     * message when three of the four words are in the catalogue.
     */
    public function testTheMissingWordCanBeNamed(): void
    {
        $words = CategoryMatcher::words('چسب تزریق دستکش');

        self::assertSame(['چسب', 'تزریق', 'دستکش'], $words);
        self::assertSame(
            ['دستکش'],
            CategoryMatcher::missingWords($words, 'چسب تجهیزات پزشکی › چسب تزریق')
        );
        self::assertSame([], CategoryMatcher::missingWords($words, 'چسب تزریق دستکش'));
    }

    public function testWordsCollapsesSpacingAndDropsTheEmpties(): void
    {
        self::assertSame(['چسب', 'تزریق'], CategoryMatcher::words('  چسب   تزریق  '));
        self::assertSame([], CategoryMatcher::words('   '));
    }
}
