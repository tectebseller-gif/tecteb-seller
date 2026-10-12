<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\ProposedBrand;

/**
 * The rule, argued with directly.
 *
 * The cases are written as the three values they are — baseline, proposal,
 * product — because that is what the defect was about: `alpha.51` had two of
 * them and could not tell «the vendor asked for this brand» from «this brand
 * was what the product had when the vendor changed the title».
 *
 * The invariants are asserted over every combination of a small set of brands
 * rather than on chosen examples (`alpha.46`: «آزمون را روی ناوردا بنویسید،
 * نه روی مقدارِ یک مثال»), so a later change that happens to keep one example
 * working cannot pass.
 */
final class ProposedBrandTest extends TestCase
{
    private const A = 'آریا طب';
    private const B = 'سینا طب';
    private const C = 'مدلاین';

    public function testAProposalThatAsksForWhatIsAlreadyThereIsApplied(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, self::A, self::B, self::B));
    }

    /**
     * THE DEFECT. The vendor changed the stock; the payload carries the brand
     * the product had at the time; a manager has approved a different brand
     * since. The payload asks for nothing, so nothing of it is written.
     */
    public function testAFieldTheVendorDidNotAskAboutKeepsTheLiveValue(): void
    {
        self::assertSame(ProposedBrand::KEEP, ProposedBrand::decide(true, self::A, self::A, self::B));
    }

    /** The path that must not narrow: a real request, nobody else involved. */
    public function testAVendorsOwnBrandChangeIsApplied(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, self::A, self::C, self::A));
    }

    public function testTwoDecisionsWithDifferentAnswersAreAConflict(): void
    {
        self::assertSame(ProposedBrand::MOVED, ProposedBrand::decide(true, self::A, self::C, self::B));
    }

    public function testBothSidesMovedToTheSameBrandIsNotAConflict(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, self::A, self::C, self::C));
    }

    /**
     * «نمی‌دانم» is one of the answers, and it is not «assume the vendor
     * asked». A payload from `alpha.51` recorded no baseline.
     */
    public function testAnUnknownBaselineOverADifferentValueIsAQuestion(): void
    {
        self::assertSame(ProposedBrand::MOVED, ProposedBrand::decide(false, '', self::C, self::B));
    }

    public function testAnUnknownBaselineOverTheSameValueAsksNothing(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(false, '', self::B, self::B));
    }

    /**
     * «No brand» is a value, on both sides.
     *
     * `BrandName::same('', '')` is deliberately false — it answers about a
     * NAME — so a rule built on it would have called a product with no brand
     * and a payload with no brand a conflict and refused every such approval.
     */
    public function testNoBrandOnBothSidesHasNotMoved(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(false, '', '', ''));
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, '', '', ''));
    }

    public function testClearingTheBrandIsARequestLikeAnyOther(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, self::A, '', self::A));
    }

    public function testAddingABrandWhereThereWasNoneIsARequest(): void
    {
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, '', self::A, ''));
    }

    public function testAnUnaskedFieldKeepsTheLiveValueEvenWhenTheLiveValueIsNoBrand(): void
    {
        // The manager removed the brand after the proposal was written.
        self::assertSame(ProposedBrand::KEEP, ProposedBrand::decide(true, self::A, self::A, ''));
    }

    /** Normalisation applies: one brand with two spellings is one brand. */
    public function testTwoSpellingsOfOneBrandAreNotAChange(): void
    {
        $spaced = 'آمبو بگ';
        $tight = 'آمبوبگ';
        self::assertTrue(BrandName::same($spaced, $tight), 'the fixture assumes these fold together');
        self::assertSame(ProposedBrand::APPLY, ProposedBrand::decide(true, $spaced, $tight, $tight));
        self::assertSame(ProposedBrand::KEEP, ProposedBrand::decide(true, $spaced, $tight, self::C));
    }

    /**
     * THE INVARIANT, over every combination: a proposal is never applied over
     * a value somebody else decided unless it was told to.
     *
     * Said the other way round, which is how it is checked: whenever the
     * product has moved away from the baseline AND the proposal asks for
     * something else, the answer is `MOVED` — never `APPLY`. There is no
     * combination of three brands where a request and a third-party decision
     * are silently resolved.
     */
    public function testAProposalIsNeverSilentlyAppliedOverSomebodyElsesDecision(): void
    {
        $brands = ['', self::A, self::B, self::C, 'آمبو بگ'];
        $checked = 0;
        foreach ($brands as $baseline) {
            foreach ($brands as $proposed) {
                foreach ($brands as $current) {
                    $verdict = ProposedBrand::decide(true, $baseline, $proposed, $current);
                    $somebodyElse = BrandName::key($current) !== BrandName::key($baseline);
                    $vendorAsked = BrandName::key($proposed) !== BrandName::key($baseline);
                    $disagree = BrandName::key($proposed) !== BrandName::key($current);
                    $checked++;
                    if ($somebodyElse && $vendorAsked && $disagree) {
                        self::assertSame(ProposedBrand::MOVED, $verdict, sprintf(
                            'baseline=%s proposed=%s current=%s',
                            $baseline,
                            $proposed,
                            $current
                        ));
                        continue;
                    }
                    self::assertNotSame(ProposedBrand::MOVED, $verdict, sprintf(
                        'baseline=%s proposed=%s current=%s should not be a question',
                        $baseline,
                        $proposed,
                        $current
                    ));
                    // And when it is not a question, the value written is
                    // never one nobody asked for: either the proposal's (and
                    // then nobody else had moved it, or they moved it to the
                    // same place) or the live one.
                    if ($verdict === ProposedBrand::APPLY) {
                        self::assertTrue(
                            !$somebodyElse || !$disagree,
                            sprintf('APPLY would overwrite: baseline=%s proposed=%s current=%s', $baseline, $proposed, $current)
                        );
                    }
                }
            }
        }
        self::assertSame(125, $checked, 'every combination of five brands in three positions');
    }

    /**
     * And with NO baseline the same invariant holds with one value fewer: a
     * proposal that differs from the product is always a question.
     */
    public function testWithoutABaselineEveryDifferenceIsAQuestion(): void
    {
        $brands = ['', self::A, self::B, self::C, 'آمبو بگ'];
        foreach ($brands as $proposed) {
            foreach ($brands as $current) {
                $verdict = ProposedBrand::decide(false, '', $proposed, $current);
                $expected = BrandName::key($proposed) === BrandName::key($current)
                    ? ProposedBrand::APPLY
                    : ProposedBrand::MOVED;
                self::assertSame($expected, $verdict, sprintf('proposed=%s current=%s', $proposed, $current));
            }
        }
    }
}
