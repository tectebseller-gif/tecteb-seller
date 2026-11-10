<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Order;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Application\UnrecordableOrder;

/**
 * WHAT THIS PROVES: the rule that decides whether an order needs the mark.
 *
 * The scoping is the part worth testing hardest. A guard that marked every
 * order while the module was off would be a worse defect than the gap it
 * closes — this plugin says nothing about the host shop's own sales or
 * Dokan's, in any state (`alpha.60`).
 */
final class UnrecordableOrderTest extends TestCase
{
    /** Capture is live: `OrderHooks` owns the order and this rule is silent. */
    public function testAWorkingSiteIsNeverMarked(): void
    {
        $verdict = UnrecordableOrder::judge(true, 'dokan_running', 3, [102, 128]);

        self::assertSame(UnrecordableOrder::RECORDED, $verdict['verdict']);
        self::assertSame('', $verdict['reason']);
        self::assertSame(0, $verdict['lines'], 'a recorded order reports no lines to chase');
        self::assertSame([], $verdict['vendors']);
    }

    /**
     * ⛔ The scoping guarantee: capture is asked FIRST, so no amount of line
     * counting can make this rule act on a site where orders are recorded.
     */
    public function testCaptureIsAskedBeforeAnythingAboutTheLines(): void
    {
        // Every other input says «mark this», and the answer is still silence.
        self::assertSame(
            UnrecordableOrder::RECORDED,
            UnrecordableOrder::judge(true, '', 99, [1, 2, 3])['verdict']
        );
    }

    /** Nothing of ours in it: the host shop's sale, or Dokan's. */
    public function testAnOrderWithNoMarketplaceLineIsNotOurBusiness(): void
    {
        $verdict = UnrecordableOrder::judge(false, 'dokan_running', 0, []);

        self::assertSame(UnrecordableOrder::NOT_OURS, $verdict['verdict']);
        self::assertSame([], $verdict['vendors'], 'no vendor is named about somebody else’s sale');
    }

    public function testOurLineWithNoWayToRecordItIsMarkedWithTheReason(): void
    {
        $verdict = UnrecordableOrder::judge(false, 'dokan_running', 2, [102]);

        self::assertSame(UnrecordableOrder::UNRECORDABLE, $verdict['verdict']);
        self::assertSame('dokan_running', $verdict['reason']);
        self::assertSame(2, $verdict['lines']);
        self::assertSame([102], $verdict['vendors']);
    }

    /**
     * A mark that cannot say why is a mark somebody reads as «we do not know
     * what happened here», so the reason is never empty.
     */
    public function testAMissingReasonStillNamesSomething(): void
    {
        self::assertSame(
            'order_module_inactive',
            UnrecordableOrder::judge(false, '', 1, [102])['reason']
        );
    }

    /** Whose money: de-duplicated and ordered, so two lines of one shop read as one shop. */
    public function testTheVendorsAreDeduplicatedAndSorted(): void
    {
        $verdict = UnrecordableOrder::judge(false, 'x', 4, [128, 102, 128, 102]);

        self::assertSame([102, 128], $verdict['vendors']);
        self::assertSame(4, $verdict['lines'], 'lines are counted, vendors are listed');
    }

    /** A vendor id of zero is not a shop; it is a product nobody owns. */
    public function testAnEmptyVendorIdIsNotListedAsAShop(): void
    {
        self::assertSame([], UnrecordableOrder::judge(false, 'x', 1, [0, -5])['vendors']);
    }

    public function testTheRegisterKeepsTheNewestAndDropsNoDuplicate(): void
    {
        self::assertSame([7, 8], UnrecordableOrder::register([7], 8));
        self::assertSame([7, 8], UnrecordableOrder::register([7, 8], 8), 'an id is listed once');
        self::assertSame([8, 7], UnrecordableOrder::register([8, 7], 7), 'and moves to the end');
    }

    /**
     * Bounded, newest kept — and the dropped id is still on its own order and
     * in the audit table, which is why dropping it here is affordable.
     */
    public function testTheRegisterIsBoundedAndKeepsTheNewest(): void
    {
        $ids = range(1, UnrecordableOrder::REGISTER_LIMIT + 10);
        $out = [];
        foreach ($ids as $id) {
            $out = UnrecordableOrder::register($out, $id);
        }

        self::assertCount(UnrecordableOrder::REGISTER_LIMIT, $out);
        self::assertSame(UnrecordableOrder::REGISTER_LIMIT + 10, end($out), 'the newest is kept');
        self::assertNotContains(1, $out, 'the oldest is the one dropped');
    }

    /** Rubbish in the stored option does not become an id. */
    public function testTheRegisterIgnoresWhatIsNotAnId(): void
    {
        self::assertSame([5], UnrecordableOrder::register([0, -3], 5));
    }
}
