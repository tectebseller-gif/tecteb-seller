<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;

/**
 * The rule that stops a vendor's save erasing the manager's edit.
 *
 * Before this existed, `project()` wrote the title, the short description and
 * the description on every run — so «manager edits in WooCommerce, vendor
 * presses save» lost the manager's text silently, with a successful
 * projection in the audit trail and nothing anywhere recording the loss.
 */
final class ProjectedFieldOwnershipTest extends TestCase
{
    /**
     * The assertion this replaces was the `alpha.26` defect, written down.
     *
     * It said «no stamp means nobody has claimed it», and that sentence is
     * true of exactly one thing: a post this projection just created. Said
     * of an EXISTING post it is false, and it was false about every product
     * on a shop that had been running an older version — which is every
     * product the protection was supposed to be for.
     */
    public function testAPostThisProjectionJustCreatedIsOursToWrite(): void
    {
        self::assertTrue(
            ProjectedFieldOwnership::mayWrite('', 'what we wrote a moment ago', false, true),
            'we created this post in this call; there is nobody else it could belong to'
        );
    }

    public function testAnExistingPostWithNoStampIsNotOursToWrite(): void
    {
        self::assertFalse(
            ProjectedFieldOwnership::mayWrite('', 'whatever WooCommerce happens to hold'),
            'older than the stamps: the text may be the manager\'s and nothing recorded it'
        );
        self::assertSame(
            ProjectedFieldOwnership::OWNER_UNKNOWN,
            ProjectedFieldOwnership::owner('', 'whatever WooCommerce happens to hold'),
            'and it says so rather than picking a side'
        );
    }

    public function testTheSafeAnswerIsTheOneACallerGetsByForgetting(): void
    {
        // `$createdByUs` defaults to false on purpose. A caller that forgets
        // to pass it gets «do not write», not «write».
        self::assertFalse(ProjectedFieldOwnership::mayWrite('', 'text'));
    }

    public function testAFieldStillHoldingWhatWeWroteIsStillOurs(): void
    {
        $stamp = ProjectedFieldOwnership::fingerprint('دستگاه فشارسنج');
        self::assertTrue(ProjectedFieldOwnership::mayWrite($stamp, 'دستگاه فشارسنج'));
    }

    public function testAFieldTheManagerEditedIsNotOverwritten(): void
    {
        $stamp = ProjectedFieldOwnership::fingerprint('دستگاه فشارسنج');
        self::assertFalse(
            ProjectedFieldOwnership::mayWrite($stamp, 'دستگاه فشارسنج بازویی — ویرایش مدیر'),
            'the manager typed something else; the vendor does not get to undo it'
        );
    }

    /**
     * WooCommerce is not byte-faithful, and a byte comparison would say so.
     *
     * `wp_filter_post_kses`, the editor and `wpautop` all normalise
     * whitespace on the way in and out. Comparing bytes would report every
     * untouched product as manager-edited on its very next projection, which
     * would freeze the whole catalogue.
     */
    public function testWhitespaceAloneIsNotAnEdit(): void
    {
        $stamp = ProjectedFieldOwnership::fingerprint("یک\nدو   سه");
        self::assertTrue(ProjectedFieldOwnership::mayWrite($stamp, "  یک دو سه\n"));
    }

    public function testCategoryAndGalleryComparisonsIgnoreOrderAndDuplicates(): void
    {
        // Same set, written differently: not an edit.
        self::assertSame(
            ProjectedFieldOwnership::idsToValue([12, 7, 12]),
            ProjectedFieldOwnership::idsToValue([7, 12])
        );
        // A different set IS an edit.
        self::assertNotSame(
            ProjectedFieldOwnership::idsToValue([7, 12]),
            ProjectedFieldOwnership::idsToValue([7, 12, 99])
        );
    }

    public function testTheFieldsUnderThisRuleAreOnlyTheEditableOnes(): void
    {
        // Price, stock and status are ADR-008's and are NOT here: the
        // marketplace owns them outright, and a manager editing a price in
        // WooCommerce is a different conversation from editing a sentence.
        self::assertSame(
            ['title', 'short_description', 'description', 'category', 'images'],
            ProjectedFieldOwnership::FIELDS
        );
    }

    public function testAManagerOwnedFieldIsNeverWrittenEvenWhenTheStampMatches(): void
    {
        $text = 'کیسهٔ تنفس دستی';
        // The stamp says the marketplace wrote this and nothing has changed
        // it — and the answer is still no, because somebody decided.
        self::assertTrue(ProjectedFieldOwnership::mayWrite(ProjectedFieldOwnership::fingerprint($text), $text));
        self::assertFalse(ProjectedFieldOwnership::mayWrite(ProjectedFieldOwnership::fingerprint($text), $text, true));
    }

    public function testAManagerOwnedFieldOutranksEvenAFreshlyCreatedPost(): void
    {
        // The flag is a person having spoken, so it beats both of the other
        // two facts — including «we made this post ourselves».
        self::assertTrue(ProjectedFieldOwnership::mayWrite('', 'anything', false, true));
        self::assertFalse(ProjectedFieldOwnership::mayWrite('', 'anything', true, true));
        self::assertSame(
            ProjectedFieldOwnership::OWNER_MANAGER,
            ProjectedFieldOwnership::owner('', 'anything', true, true)
        );
    }

    public function testTheDerivedDescriptionIsTheOneFieldThatCannotBeCopiedBack(): void
    {
        // Every other projected field is a value the marketplace row can
        // hold. `description` is assembled from three of them, so copying the
        // shop's version back would paste the rendered result into the raw
        // text and render it twice on the next run.
        self::assertContains('description', ProjectedFieldOwnership::FIELDS);
        self::assertNotContains('description', ProjectedFieldOwnership::ROUND_TRIP);
        foreach (ProjectedFieldOwnership::ROUND_TRIP as $field) {
            self::assertContains($field, ProjectedFieldOwnership::FIELDS, $field . ' must be a projected field');
        }
    }
}
