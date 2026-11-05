<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontStatusReconciliation as Rule;

/**
 * The rule that decides which `status` values are provably not decisions.
 *
 * Pure: no database, no WordPress, no reader. Every assertion here is about
 * the three facts the rule takes and nothing else.
 */
final class StorefrontStatusReconciliationTest extends TestCase
{
    public function testAStampedDraftBesideAPublishedProductIsRepaired(): void
    {
        // The whole of shop 102: the intake wrote `draft`, the stamp proves
        // the projector never wrote it, and the shop says `publish`.
        $verdict = Rule::decide(ProductStatus::Draft, 'publish', 'publish');
        self::assertSame(Rule::REPAIR, $verdict['verdict']);
        self::assertSame(ProductStatus::Published, $verdict['target']);
    }

    public function testAStampedPublishedRowFollowsAShopThatSaysDraft(): void
    {
        // Both directions, because WooCommerce is the reference. This does not
        // make a published product a draft: the product is ALREADY a draft in
        // the shop, and only this plugin's column moves.
        $verdict = Rule::decide(ProductStatus::Published, 'publish', 'draft');
        self::assertSame(Rule::REPAIR, $verdict['verdict']);
        self::assertSame(ProductStatus::Draft, $verdict['target']);
    }

    public function testAgreementIsNotWork(): void
    {
        foreach ([[ProductStatus::Published, 'publish'], [ProductStatus::Draft, 'draft']] as [$row, $shop]) {
            $verdict = Rule::decide($row, 'publish', $shop);
            self::assertSame(Rule::AGREED, $verdict['verdict']);
            self::assertNull($verdict['target']);
        }
    }

    public function testPendingIsADraftBecauseThatIsWhatTheIntakeCallsIt(): void
    {
        // One vocabulary: `forIntakeOf` maps `pending` to `Draft`, so a row
        // that already says `Draft` beside a `pending` product agrees rather
        // than being rewritten to the same value for ever.
        $verdict = Rule::decide(ProductStatus::Draft, 'publish', 'pending');
        self::assertSame(Rule::AGREED, $verdict['verdict']);
    }

    /**
     * ⛔ The guard that makes the whole thing safe: no stamp, no repair.
     */
    public function testARowWithNoStampIsNeverRepaired(): void
    {
        foreach ([null, '', '   '] as $stamp) {
            $verdict = Rule::decide(ProductStatus::Draft, $stamp, 'publish');
            self::assertSame(Rule::NOT_HELD, $verdict['verdict'], var_export($stamp, true));
            self::assertNull($verdict['target']);
        }
    }

    public function testAnUnstampedRowIsDeclinedEvenWhenTheShopCannotBeRead(): void
    {
        // The order of the questions, asserted: «the projector owns this» is a
        // complete answer and needs no second fact. Reporting `UNREADABLE`
        // here would show a manager work that does not exist.
        self::assertSame(Rule::NOT_HELD, Rule::decide(ProductStatus::Draft, null, null)['verdict']);
    }

    public function testADecisionInProgressIsNeverOverwritten(): void
    {
        foreach ([
            ProductStatus::Submitted,
            ProductStatus::ChangesRequested,
            ProductStatus::Archived,
            ProductStatus::Suspended,
        ] as $status) {
            $verdict = Rule::decide($status, 'publish', 'publish');
            self::assertSame(Rule::IN_FLIGHT, $verdict['verdict'], $status->value);
            self::assertNull($verdict['target']);
        }
    }

    public function testAStatusTheShopHoldsAndWeCannotExpressIsDeclinedByName(): void
    {
        foreach (['private', 'future', 'trash', 'auto-draft', ''] as $shopStatus) {
            $verdict = Rule::decide(ProductStatus::Draft, 'publish', $shopStatus);
            self::assertSame(Rule::UNREADABLE, $verdict['verdict'], $shopStatus);
            self::assertNull($verdict['target']);
        }
    }

    public function testAShopThatCouldNotBeReadIsNotAgreement(): void
    {
        self::assertSame(Rule::UNREADABLE, Rule::decide(ProductStatus::Draft, 'publish', null)['verdict']);
    }

    /**
     * The scope and `forIntakeOf`'s range are the SAME set, and a case added
     * to `ProductStatus` later must fail here rather than quietly become
     * repairable.
     */
    public function testTheScopeIsExactlyWhatAnIntakeCanProduce(): void
    {
        $reachable = [];
        foreach (['publish', 'draft', 'pending', 'private', 'future', 'trash', 'x'] as $postStatus) {
            $mapped = ProductStatus::forIntakeOf($postStatus);
            if ($mapped !== null) {
                $reachable[$mapped->value] = true;
            }
        }
        $inScope = [];
        foreach (ProductStatus::cases() as $case) {
            if (Rule::inScope($case)) {
                $inScope[$case->value] = true;
            }
        }
        ksort($reachable);
        ksort($inScope);
        self::assertSame($reachable, $inScope);
    }

    public function testEveryVerdictNameIsDistinct(): void
    {
        $names = [Rule::AGREED, Rule::REPAIR, Rule::NOT_HELD, Rule::IN_FLIGHT, Rule::UNREADABLE];
        self::assertSame($names, array_values(array_unique($names)));
    }
}
