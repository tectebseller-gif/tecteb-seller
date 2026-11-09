<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0039WcOrderStatusOnRows as WcStatus;

/**
 * DEC-02's third sentence, asserted where no behavioural test can reach it.
 *
 * «تغییر وضعیت والد تنها، قلم را قابل تسویه نمی‌کند» — a WooCommerce order has
 * ONE status, and `completed` on it reads exactly like «every vendor on this
 * order delivered». The owner's answer is that it does not: each line is
 * confirmed by a manager, independently, through the existing ORDER-01
 * mechanism.
 *
 * `SettlementFlowTest::testInAMultiVendorOrderEachLineIsCompletedOnItsOwnAnd\
 * TheParentStatusSettlesNothing` drives a real `completed` through the real
 * sync and asserts that nothing became payable. This file is the half that
 * test cannot be: a NEGATIVE property is only as strong as the ways it could
 * be broken, and the two ways here are not reachable from a test —
 *
 *  - `SyncOrderLifecycle` growing a call that records a completion. Trying to
 *    mutate that in was how this file came to exist: a mutation that ADDS a
 *    write is not a one-line edit, and «a guard nothing can break is a claim»
 *    (`alpha.80`).
 *  - the settlement view growing the mirrored status column. Measured during
 *    falsification this round: a mutation that made eligibility treat a
 *    `completed` parent as settled **did not apply**, because
 *    `settlementView()` does not select that column at all — the key was
 *    absent and the condition could never be true. Per `alpha.82` a mutation
 *    that does not apply is a failure rather than an unbreakable guard, so
 *    the structural fact it stumbled on is pinned here instead.
 *
 * The second one is the one a future round will trip over. Adding
 * `wc_order_status` to the settlement view is an obvious convenience — the
 * report already reads it — and the moment it is in that array somebody can
 * write `=== 'completed'` beside it and settle 184 lines nobody confirmed.
 */
final class ParentStatusDoesNotSettleALineTest extends TestCase
{
    private const SYNC = __DIR__ . '/../../src/Modules/Order/Application/SyncOrderLifecycle.php';
    private const REPOSITORY = __DIR__ . '/../../src/Modules/Order/Infrastructure/DbOrderItemRepository.php';

    public function testTheLifecycleSyncNeverRecordsASettlementCompletion(): void
    {
        $body = self::withoutComments(self::SYNC);

        // The write itself, by both of its names: the interface method and the
        // column. A sync that reached either would be settling a line on a
        // status change.
        self::assertStringNotContainsString(
            'recordSettlementCompletion',
            $body,
            'the sync must not record a settlement completion'
        );
        self::assertStringNotContainsString(
            'settlement_completed_at',
            $body,
            'nor write the column by hand'
        );
        self::assertStringNotContainsString(
            'settlement_completed_by',
            $body,
            'nor its author column'
        );

        // …and it does mirror the status, so the test above is about a sync
        // that does something rather than one that is inert.
        self::assertStringContainsString(
            'recordWcOrderStatus',
            $body,
            'mirroring the status IS its job; only settling is not'
        );
    }

    /**
     * The settlement view does not carry the mirrored status — so eligibility
     * cannot read it even by accident.
     *
     * Asserted on the method body rather than on the file, because the same
     * repository legitimately selects that column for the report filters and
     * for the reconciliation scan (`alpha.77`: a containment check over a
     * whole file finds its sibling's copy).
     */
    public function testTheSettlementViewDoesNotSelectTheMirroredOrderStatus(): void
    {
        $body = self::methodBody(self::REPOSITORY, 'settlementView');
        self::assertNotSame('', $body, 'the method was found');

        self::assertStringNotContainsString(
            WcStatus::COLUMN,
            $body,
            'the settlement view must not carry the parent’s status'
        );
        self::assertStringNotContainsString(
            'wc_order_status',
            $body,
            'nor its alias — the alias is what a reader would reach for'
        );

        // The control: the column IS selected elsewhere in the same file, so
        // the assertion above is about this method rather than about a
        // repository that never mentions it.
        self::assertStringContainsString(
            WcStatus::COLUMN,
            self::withoutComments(self::REPOSITORY),
            'the repository does read the column — for the report, not for money'
        );
    }

    /** The file with every comment blanked — `alpha.22`: a docblock is not code. */
    private static function withoutComments(string $path): string
    {
        $source = (string) file_get_contents($path);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * One method's body, extracted by BRACE MATCHING rather than by scanning
     * to the next `function` — `alpha.77`, which paid for the difference.
     */
    private static function methodBody(string $path, string $method): string
    {
        $source = self::withoutComments($path);
        $at = strpos($source, 'function ' . $method . '(');
        if ($at === false) {
            return '';
        }
        $open = strpos($source, '{', $at);
        if ($open === false) {
            return '';
        }
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        return '';
    }
}
