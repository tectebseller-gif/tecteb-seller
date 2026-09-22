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
}
