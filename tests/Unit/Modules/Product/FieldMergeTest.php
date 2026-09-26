<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Product;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\FieldMerge;

/**
 * WHAT THIS PROVES: one vendor edit produces one question, not five.
 *
 * The owner ran the real thing and wrote down what happened: they edited the
 * title and the long description in WooCommerce, the vendor changed ONLY the
 * short description and resubmitted, and the review screen asked about all
 * three. «هدف، حذف تصمیم‌های تکراری در جریان عادی است؛ صرفاً مخفی‌کردن دکمه‌ها
 * پذیرفته نیست» — so the rule has to stop asking, not stop showing.
 */
final class FieldMergeTest extends TestCase
{
    private const OURS = 'عنوانی که بازارگاه نوشت';
    private const MANAGER = 'عنوانی که مدیر در ووکامرس نوشت';

    private static function stampOf(string $value): string
    {
        return FieldMerge::fingerprint($value);
    }

    public function testAFieldTheVendorDidNotTouchIsNotAQuestion(): void
    {
        // The whole round, in one assertion. The baseline and the record
        // agree — the vendor changed nothing here — so whatever the manager
        // did with it stands, and nobody is asked anything.
        self::assertSame(FieldMerge::SKIP, FieldMerge::decide(
            true,
            self::OURS,          // baseline: what was agreed
            self::OURS,          // record:   the vendor left it alone
            self::MANAGER,       // shop:     the manager rewrote it
            self::stampOf(self::OURS)
        ));
    }

    public function testTheFieldTheVendorDidTouchIsWrittenWithoutAQuestion(): void
    {
        $vendor = 'توضیح کوتاه تازهٔ فروشنده';
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            'توضیح کوتاه قبلی',
            $vendor,
            'توضیح کوتاه قبلی',     // the manager left this one alone
            self::stampOf('توضیح کوتاه قبلی')
        ));
    }

    public function testOnlyBothSidesMovingItToDifferentValuesIsAConflict(): void
    {
        self::assertSame(FieldMerge::CONFLICT, FieldMerge::decide(
            true,
            self::OURS,
            'عنوان تازهٔ فروشنده',
            self::MANAGER,
            self::stampOf(self::OURS)
        ));
    }

    public function testBothMovingItToTheSameValueIsNotADisagreement(): void
    {
        // They agree. Asking would be noise, and writing is a no-op that
        // keeps the stamp honest.
        $same = 'همان عنوانی که هر دو نوشتند';
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            self::OURS,
            $same,
            $same,
            self::stampOf(self::OURS)
        ));
    }

    public function testWithNoBaselineTheOlderNarrowerRuleApplies(): void
    {
        // An upgrade must not change behaviour before the first approval has
        // recorded anything. Stamp matches ⇒ ours to write; stamp does not
        // ⇒ held, exactly as `alpha.28` did.
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            false,
            '',
            'هرچه فروشنده نوشته',
            self::OURS,
            self::stampOf(self::OURS)
        ));
        self::assertSame(FieldMerge::CONFLICT, FieldMerge::decide(
            false,
            '',
            'هرچه فروشنده نوشته',
            self::MANAGER,
            self::stampOf(self::OURS)
        ));
    }

    public function testAProductOlderThanTheStampsIsUnsettledWhateverTheBaselineSays(): void
    {
        // `alpha.27`'s rule survives intact: no stamp means nobody knows who
        // wrote what is there, and a baseline cannot answer that question.
        self::assertSame(FieldMerge::UNSETTLED, FieldMerge::decide(
            true,
            self::OURS,
            'هرچه فروشنده نوشته',
            self::MANAGER,
            ''
        ));
    }

    public function testAnExplicitManagerDecisionOutranksEverything(): void
    {
        self::assertSame(FieldMerge::MANAGER, FieldMerge::decide(
            true,
            self::OURS,
            'هرچه فروشنده نوشته',
            self::MANAGER,
            self::stampOf(self::OURS),
            true
        ));
        // Even on a post this projection just created, which is the one case
        // where everything else says «ours».
        self::assertSame(FieldMerge::MANAGER, FieldMerge::decide(
            false,
            '',
            'x',
            'y',
            '',
            true,
            true
        ));
    }

    public function testAPostThisProjectionJustCreatedIsOursInFull(): void
    {
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(false, '', 'x', '', '', false, true));
    }

    public function testNeitherSideMovingItIsStillNotAQuestion(): void
    {
        self::assertSame(FieldMerge::SKIP, FieldMerge::decide(
            true,
            self::OURS,
            self::OURS,
            self::OURS,
            self::stampOf(self::OURS)
        ));
    }

    public function testWhitespaceAloneIsNotAnEdit(): void
    {
        // WooCommerce and the editor disagree about trailing newlines often
        // enough that a byte comparison would report half the catalogue as
        // changed on both sides at once.
        self::assertSame(FieldMerge::SKIP, FieldMerge::decide(
            true,
            "عنوان  نمونه\n",
            'عنوان نمونه',
            "عنوان نمونه ",
            self::stampOf('عنوان نمونه')
        ));
    }

    public function testTheFingerprintIsTheOneTheStampUses(): void
    {
        // Two implementations of «is this the same text» would disagree about
        // untouched fields, which is the failure both of them exist to stop.
        self::assertSame(
            FieldMerge::fingerprint('متن'),
            \Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership::fingerprint('متن')
        );
    }

    public function testAnEmptyValueIsAValueOnBothSides(): void
    {
        // `alpha.28`'s rule, carried forward: the vendor clearing a field is
        // a change like any other, and it must reach the shop.
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            'توضیح کوتاه قبلی',
            '',
            'توضیح کوتاه قبلی',
            self::stampOf('توضیح کوتاه قبلی')
        ));
        // And clearing it on a field the manager has rewritten is a real
        // disagreement, not a silent win for either side.
        self::assertSame(FieldMerge::CONFLICT, FieldMerge::decide(
            true,
            'توضیح کوتاه قبلی',
            '',
            'متنی که مدیر نوشته',
            self::stampOf('توضیح کوتاه قبلی')
        ));
    }

    public function testAGeneratedFieldTheManagerEditedBecomesTheirs(): void
    {
        // The description is rendered from the short description, the brand
        // and the specification — nobody types it. So a shop value that no
        // longer matches our stamp can only be a person's edit, and the
        // answer is «it is theirs», not «you two disagree». Otherwise every
        // later short-description change re-renders the text and asks the
        // same question again, for ever.
        self::assertSame(FieldMerge::MANAGER, FieldMerge::decide(
            true,
            'متنی که بازارگاه ساخته بود',
            'متن تازه‌ای که بازارگاه می‌سازد',
            'متنی که مدیر در ووکامرس نوشته',
            self::stampOf('متنی که بازارگاه ساخته بود'),
            false,
            false,
            true
        ));
    }

    public function testAGeneratedFieldNobodyTouchedIsStillOursToWrite(): void
    {
        // `derived` does not mean «hands off»; it means «a difference on this
        // field has only one possible author».
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            'متن قبلی',
            'متن تازه',
            'متن قبلی',
            self::stampOf('متن قبلی'),
            false,
            false,
            true
        ));
    }

    public function testTheOwnersScenarioProducesExactlyOneWriteAndNoQuestions(): void
    {
        // The whole round, as it was run on the real site: the manager edited
        // the title and the long description in WooCommerce, the vendor
        // changed ONLY the short description, and the screen asked three
        // questions. Five fields, and not one of them is a question now.
        $verdicts = [
            'title' => FieldMerge::decide(
                true,
                'عنوان تأییدشده',
                'عنوان تأییدشده',                 // the vendor left it alone
                'عنوانی که مدیر نوشت',
                self::stampOf('عنوان تأییدشده')
            ),
            'short_description' => FieldMerge::decide(
                true,
                'توضیح کوتاه قبلی',
                'توضیح کوتاه تازهٔ فروشنده',
                'توضیح کوتاه قبلی',
                self::stampOf('توضیح کوتاه قبلی')
            ),
            'description' => FieldMerge::decide(
                true,
                'متن ساخته‌شدهٔ قبلی',
                'متن ساخته‌شدهٔ تازه',             // rebuilt, because the short one changed
                'متنی که مدیر نوشت',
                self::stampOf('متن ساخته‌شدهٔ قبلی'),
                false,
                false,
                true
            ),
            'category' => FieldMerge::decide(true, '30', '30', '30', self::stampOf('30')),
            'images' => FieldMerge::decide(
                true,
                'main:4|gallery:17,18',
                'main:4|gallery:17,18',
                'main:4|gallery:17,18',
                self::stampOf('main:4|gallery:17,18')
            ),
        ];

        self::assertSame(FieldMerge::WRITE, $verdicts['short_description'], 'the one thing the vendor changed');
        self::assertSame(FieldMerge::SKIP, $verdicts['title'], 'the manager\'s title, left alone and unasked');
        self::assertSame(FieldMerge::MANAGER, $verdicts['description'], 'and their long text, not rebuilt');
        self::assertSame(FieldMerge::SKIP, $verdicts['category']);
        self::assertSame(FieldMerge::SKIP, $verdicts['images']);

        $questions = array_filter(
            $verdicts,
            static fn (string $v): bool => in_array($v, [FieldMerge::CONFLICT, FieldMerge::UNSETTLED], true)
        );
        self::assertSame([], $questions, 'یک ویرایش فروشنده، هیچ پرسش تکراری');
    }

    public function testAnOldStampIsNotEvidenceOnceTheBaselineHasMoved(): void
    {
        // `alpha.29`'s gap, in one assertion — and the owner's required
        // scenario: the manager changed the title in WooCommerce, the vendor
        // changed only the short description, and the approval reconciled and
        // recorded the manager's title as the new baseline. Nothing
        // re-stamped it, because the marketplace never wrote it. Now the
        // vendor edits that newly approved title and nobody else has touched
        // it since.
        //
        // Measured against the stamp — the fingerprint of the title we wrote
        // two agreements ago — the shop looks changed, and the vendor's edit
        // was called a conflict on that alone: «این نباید صرفاً به‌دلیل مهر
        // قدیمی تعارض محسوب شود».
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            self::MANAGER,               // baseline: the agreement adopted the manager's title
            'عنوانی که فروشنده تازه نوشته',
            self::MANAGER,               // shop: untouched since that agreement
            self::stampOf(self::OURS)    // stamp: two agreements old, and not evidence
        ));
    }

    public function testARealManagerEditAfterTheBaselineMovedIsStillAConflict(): void
    {
        // The other half of the same change. The baseline replaced the stamp
        // as the measure, and it still measures.
        self::assertSame(FieldMerge::CONFLICT, FieldMerge::decide(
            true,
            self::MANAGER,
            'عنوانی که فروشنده تازه نوشته',
            'عنوانی که مدیر پس از تأیید نوشته',
            self::stampOf(self::OURS)
        ));
    }

    public function testAManagerEditAfterTheBaselineMovedStillStandsUnasked(): void
    {
        self::assertSame(FieldMerge::SKIP, FieldMerge::decide(
            true,
            self::MANAGER,
            self::MANAGER,               // the vendor left it alone
            'عنوانی که مدیر پس از تأیید نوشته',
            self::stampOf(self::OURS)
        ));
    }

    public function testAStaleBaselineDoesNotFreezeTheGeneratedDescription(): void
    {
        // Why the generated field keeps the stamp. Between two approvals the
        // marketplace rewrites this text itself: the vendor changes the short
        // description twice, so the shop holds OUR second render while the
        // baseline still holds the render from the last agreement. Measured
        // against the baseline, our own work would read as a manager's edit —
        // and the field would freeze over something nobody did.
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            'متن ساخته‌شده در زمان تأیید',
            'متن ساخته‌شدهٔ سوم',
            'متن ساخته‌شدهٔ دوم',                  // written by us, after that agreement
            self::stampOf('متن ساخته‌شدهٔ دوم'),   // and stamped as ours
            false,
            false,
            true
        ));
    }

    public function testARecordedAgreementDoesNotSettleAFieldOlderThanTheStamps(): void
    {
        // An approval settles what the two sides hold, not who typed the text
        // that was already in WooCommerce. `alpha.27`'s protection is not a
        // side effect of a baseline existing.
        self::assertSame(FieldMerge::UNSETTLED, FieldMerge::decide(
            true,
            self::MANAGER,
            'عنوانی که فروشنده تازه نوشته',
            self::MANAGER,
            ''
        ));
    }

    public function testOurOwnEarlierWriteIsNotSomebodyElsesEdit(): void
    {
        // The other direction, and the one that cost this round a run on real
        // WooCommerce: between two approvals the marketplace writes fields
        // itself, so the baseline goes stale while the STAMP follows every
        // write. Measured against the baseline alone, clearing the pictures
        // came back as a conflict with an edit nobody had made — the shop held
        // exactly what the previous projection had put there.
        $ours = 'main:0|gallery:4,17,18';
        self::assertSame(FieldMerge::WRITE, FieldMerge::decide(
            true,
            'main:4|gallery:17,18',      // baseline: agreed two writes ago
            'main:0|gallery:',           // record:   the vendor removed everything
            $ours,                       // shop:     what WE wrote last time
            self::stampOf($ours)
        ));
    }

    public function testAManagerEditIsStillFoundWhenTheBaselineIsStale(): void
    {
        // And the guard above does not swallow a real edit: neither the stamp
        // nor the agreement recognises what is in the shop.
        self::assertSame(FieldMerge::CONFLICT, FieldMerge::decide(
            true,
            'main:4|gallery:17,18',
            'main:0|gallery:',
            'main:99|gallery:17,18',                    // a person chose this
            self::stampOf('main:0|gallery:4,17,18')     // and it is not what we wrote
        ));
    }
}
