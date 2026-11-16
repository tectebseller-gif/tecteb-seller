<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\FieldSummary;

/**
 * The display summary a comparison cell uses for a long value.
 *
 * ⛔ AND THE FIRST TEST HERE IS THE ONE THAT MATTERS MOST: this class is not
 * sanitisation. Nothing it returns is trusted, `esc_html()` still stands
 * between it and the browser, and the pair of tests at the bottom pins that
 * the stored value is handed back untouched — because the day somebody reads
 * `text()` as «the safe version» is the day it is used on the way in.
 */
final class FieldSummaryTest extends TestCase
{
    public function testMarkupIsDetectedByStrippingItRatherThanByLookingForABracket(): void
    {
        self::assertTrue(FieldSummary::carriesMarkup('<p>سلام</p>'));
        self::assertTrue(FieldSummary::carriesMarkup('یک<br>دو'), 'a single break is markup');
        // A less-than sign in prose is not markup, and calling it markup would
        // fold a perfectly readable sentence.
        self::assertFalse(FieldSummary::carriesMarkup('وزن < ۵ کیلوگرم'));
        self::assertFalse(FieldSummary::carriesMarkup('متن ساده'));
    }

    public function testFoldingIsForMarkupOrForLength(): void
    {
        self::assertFalse(FieldSummary::needsFolding('یک جملهٔ کوتاه.'));
        self::assertTrue(FieldSummary::needsFolding('<p>کوتاه ولی نشانه‌گذاری‌دار</p>'));
        self::assertFalse(FieldSummary::needsFolding(str_repeat('ا', FieldSummary::FOLD_CHARS)));
        self::assertTrue(FieldSummary::needsFolding(str_repeat('ا', FieldSummary::FOLD_CHARS + 1)));
    }

    /**
     * Characters, never bytes — a Persian letter is two of them.
     *
     * `alpha.12` paid for this in a shell script and `alpha.21` paid again:
     * a byte bound lands inside a letter and prints a replacement character.
     */
    public function testLengthAndPreviewAreCountedInCharacters(): void
    {
        $persian = str_repeat('ب', 300);
        self::assertSame(300, FieldSummary::characters($persian));
        self::assertSame(
            FieldSummary::PREVIEW_CHARS + 1,
            mb_strlen(FieldSummary::preview($persian)),
            'the preview is bounded in characters, plus the ellipsis'
        );
        self::assertStringEndsWith('…', FieldSummary::preview($persian));
        // Nothing was cut, so nothing says it was.
        self::assertStringEndsNotWith('…', FieldSummary::preview('کوتاه'));
    }

    /** The number quoted is the STORED length, not the readable one. */
    public function testTheQuotedLengthIsTheStoredValuesOwn(): void
    {
        $value = '<p data-start="0" data-end="9">نه حرف</p>';
        self::assertSame(mb_strlen($value), FieldSummary::characters($value));
        self::assertGreaterThan(mb_strlen(FieldSummary::text($value)), FieldSummary::characters($value));
    }

    public function testScriptAndStyleBodiesGoRatherThanBeingReadAloud(): void
    {
        // `strip_tags()` alone leaves the CONTENT of these two behind, and a
        // page of CSS is not a description.
        self::assertSame('متن', FieldSummary::text('<style>.a{color:red}</style>متن'));
        self::assertSame('متن', FieldSummary::text('<script>alert(1)</script>متن'));
    }

    public function testEntitiesAreDecodedForReadingAndParagraphsSurvive(): void
    {
        self::assertSame('آب & برق', FieldSummary::text('<p>آب &amp; برق</p>'));
        self::assertSame("یک\n\nدو", FieldSummary::text("یک\n\n\n\nدو"), 'blank runs collapse to one');
        self::assertSame('یک دو', FieldSummary::text("یک    \t دو"), 'horizontal runs collapse');
    }

    /**
     * A value made only of markup has no text, and saying so is the answer.
     *
     * An empty preview line looks like a bug; «this has no text» is a fact the
     * reader can act on.
     */
    public function testAValueWithNoTextPreviewsAsEmptyRatherThanAsSomethingElse(): void
    {
        self::assertSame('', FieldSummary::preview('<img src="a.png" alt="">'));
        self::assertTrue(FieldSummary::needsFolding('<img src="a.png" alt="">'));
    }

    /**
     * ⛔ NOT A SANITISER, AND THESE ARE THE TESTS THAT SAY SO.
     *
     * `text()` DECODES entities, which means it can hand back a string with
     * real angle brackets in it. That is correct for reading and catastrophic
     * if anybody ever mistook it for an escaping step — so it is pinned here:
     * the class makes no safety claim, and the caller escapes.
     */
    public function testTheReadableTextIsNotEscapedAndMakesNoSafetyClaim(): void
    {
        self::assertSame('<img onerror=1>', FieldSummary::text('&lt;img onerror=1&gt;'));
        self::assertSame(
            '<img onerror=1>',
            FieldSummary::preview('&lt;img onerror=1&gt;'),
            'the preview is no safer than the text, and the view escapes both'
        );
    }

    /** It has no writer: the stored value is never passed through it inward. */
    public function testTheClassHasNoWriterAtAll(): void
    {
        $body = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Modules/Product/Domain/FieldSummary.php'
        );
        foreach (['update', 'insert', 'save', 'delete', 'wpdb', 'wp_'] as $verb) {
            self::assertStringNotContainsStringIgnoringCase(
                $verb . '(',
                $body,
                'a display summary writes nothing and calls no platform'
            );
        }
    }
}
