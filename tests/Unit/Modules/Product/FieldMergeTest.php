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
}
