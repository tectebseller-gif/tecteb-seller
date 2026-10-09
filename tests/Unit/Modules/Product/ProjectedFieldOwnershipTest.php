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

    /**
     * Every projected field can now be copied back — and that is the change,
     * not a relaxation.
     *
     * Rewritten in `alpha.41`. This test pinned the rule «`description` is the
     * one field that cannot round-trip», which was true for one reason: the
     * projector BUILT that text from the short description, the brand and the
     * specifications, so writing the shop's version into the marketplace row
     * would have pasted a rendered result into the raw source. §4 gave the
     * vendor the field itself, the projector copies it like any other, and the
     * reason is gone with it.
     *
     * A test that writes a defective rule down fails when the rule changes —
     * and should (`alpha.27`). What is kept is the invariant that actually
     * matters: nothing may round-trip that is not projected in the first
     * place.
     */
    public function testEveryRoundTrippingFieldIsAProjectedFieldAndNowThatIsAllOfThem(): void
    {
        self::assertContains('description', ProjectedFieldOwnership::FIELDS);
        self::assertContains(
            'description',
            ProjectedFieldOwnership::ROUND_TRIP,
            'the vendor writes it now, so «نسخهٔ من بماند» can write the manager version back'
        );
        foreach (ProjectedFieldOwnership::ROUND_TRIP as $field) {
            self::assertContains($field, ProjectedFieldOwnership::FIELDS, $field . ' must be a projected field');
        }
        self::assertSame(
            ProjectedFieldOwnership::FIELDS,
            ProjectedFieldOwnership::ROUND_TRIP,
            'and with description joining, the two lists are the same — no field is left unrecoverable'
        );
    }
}
