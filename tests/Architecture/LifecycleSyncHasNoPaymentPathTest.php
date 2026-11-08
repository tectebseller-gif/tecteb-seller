<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the lifecycle sync can void an accrual and nothing else.
 *
 * «هیچ استرداد وجه بانکی خودکار، پرداخت یا تسویه خارجی انجام نده» is a
 * statement about ABSENCE, and a docblock cannot guarantee absence. The
 * behaviour tests beside this one read the ledger and the line before and
 * after a real cancel; this one says the capability is not in the file, so a
 * future edit has to delete this test to get it back.
 *
 * The needles are named one at a time rather than matched by a pattern, so a
 * reader can see exactly what this service is not allowed to become.
 */
final class LifecycleSyncHasNoPaymentPathTest extends TestCase
{
    private const SERVICE = __DIR__ . '/../../src/Modules/Order/Application/SyncOrderLifecycle.php';

    /** @return array<string,array{string}> */
    public static function forbidden(): array
    {
        return [
            // WooCommerce's own refund, in both spellings. `alpha.10` proved
            // that `wc_create_refund()` with `refund_payment=false` writes a
            // RECORD and moves no money — and a status flip is still not the
            // event that gets to write one.
            'wc refund factory' => ['wc_create_refund'],
            'refund recorder port' => ['RefundRecorderInterface'],
            'woo refund recorder' => ['WcRefundRecorder'],
            // The return cycle, which is a manager's instrument and reverses
            // through its own table with its own bookkeeping. Two instruments
            // reversing one line is how money gets reversed twice.
            'return service' => ['ManageReturns'],
            'return repository' => ['recordReversal'],
            'return status' => ['ReturnStatus'],
            // Settlement and payout: three independent things (§6) and this
            // is none of them.
            'withdrawal request' => ['RequestWithdrawal'],
            'withdrawal review' => ['ReviewWithdrawals'],
            'withdrawal repository' => ['WithdrawalRepositoryInterface'],
            'supplier payment' => ['RecordSupplierPayment'],
            'payout account' => ['VendorPayout'],
            // Recording a sale complete for settlement is ORDER-01's manager
            // decision. A cancel must not reach it in either direction.
            'settlement completion' => ['recordSettlementCompletion'],
            // Re-accruing. §3-9's «no double counting» is kept by not being
            // able to accrue at all, so an un-cancel cannot create money.
            'accrual service' => ['RecordCommission'],
            'rate resolution' => ['ResolveCommissionRate'],
            // Stock. The mirror is `CaptureOrder::refreshStock()`'s job on its
            // own hooks; WooCommerce restores a cancelled order's stock itself
            // and a second writer here is how it gets restored twice
            // (`alpha.12`, measured).
            'stock push' => ['pullStock'],
            'catalogue sync' => ['SyncCatalog'],
            'woo stock helper' => ['wc_update_product_stock'],
            // Outbound anything.
            'http post' => ['wp_remote_post'],
            'http get' => ['wp_remote_get'],
            'mail' => ['wp_mail'],
        ];
    }

    /** @dataProvider forbidden */
    public function testTheServiceCannotDoIt(string $needle): void
    {
        $source = self::code();
        self::assertNotSame('', $source, 'the service must be readable for this test to mean anything');
        self::assertStringNotContainsString($needle, $source, $needle);
    }

    /**
     * WHAT THIS PROVES: the Application layer stays free of WordPress.
     *
     * `alpha.9`'s rule, and this service is the kind that invites breaking it:
     * it is driven by a WooCommerce hook, so the temptation is to read the
     * order back. It does not — the status arrives as a string from the
     * hook — and the one translation function the layer may never call is
     * named explicitly because an outcome key is not a sentence.
     */
    public function testItCallsNoWordPressFunction(): void
    {
        $source = self::code();
        foreach (['__(', 'esc_html', 'wc_get_order', 'get_post_status', 'current_user_can', 'add_action'] as $needle) {
            self::assertStringNotContainsString($needle, $source, $needle);
        }
    }

    /**
     * WHAT THIS PROVES: the document is written before the status, inside one
     * unit of work.
     *
     * Asserted by POSITION, because the direction is the whole finding of this
     * round. A line cancelled beside a standing accrual is exactly the defect
     * the owner measured on staging; writing the status first would re-create
     * it on any ledger failure. And a transaction that will not begin is a
     * REFUSAL rather than a licence to write one half.
     */
    public function testTheLedgerIsWrittenBeforeTheStatusAndBothAreInOneTransaction(): void
    {
        $body = self::methodBody('voidAccrual');
        $begin = strpos($body, 'tx->begin()');
        $record = strpos($body, 'ledger->record(');
        $status = strpos($body, 'updateStatus(');
        $commit = strpos($body, 'tx->commit()');
        self::assertIsInt($begin, 'the void must open a transaction');
        self::assertIsInt($record, 'the void must record a reversing document');
        self::assertIsInt($status, 'the void must cancel the line');
        self::assertIsInt($commit, 'the void must commit');
        self::assertLessThan($record, $begin, 'the transaction must open before the document is written');
        self::assertLessThan($status, $record, 'the document must be written before the line is cancelled');
        self::assertLessThan($commit, $status, 'the line must be cancelled before the commit');
        self::assertStringContainsString("'void_not_atomic'", $body, 'a build without a transaction must refuse by name');
    }

    /**
     * WHAT THIS PROVES: a failed status write rolls the document back.
     *
     * The `rollback()` has to be INSIDE the branch that failed, and a test
     * that only looked for the string would pass on a file where it sat
     * anywhere. So the branch is extracted by brace matching from the status
     * check — `alpha.98`'s lesson, where a 420-character window slid past a
     * closing brace into the next branch and answered about that one instead.
     */
    public function testAStatusThatWillNotWriteRollsTheDocumentBack(): void
    {
        $body = self::methodBody('voidAccrual');
        $at = strpos($body, 'if (!$this->items->updateStatus(');
        self::assertIsInt($at, 'the status write must be guarded');
        $branch = self::block($body, $at);
        self::assertStringContainsString('rollback()', $branch, 'the branch must roll the document back');
        self::assertStringContainsString("'void_status_unwritten'", $branch, 'and say which half failed');
    }

    /**
     * WHAT THIS PROVES: the reversing document has a key of its own.
     *
     * Three instruments can reverse a line — this one, a return, and nothing
     * else — and FIN-03's idempotency is a unique index on the event key. A
     * key derived from the accrual's own would collide with the accrual and
     * the reversal would never be written at all.
     */
    public function testTheVoidKeyIsDerivedFromTheAccrualKeyAndIsNotIt(): void
    {
        $source = self::code();
        self::assertStringContainsString('CaptureOrder::eventKey(', $source);
        self::assertStringContainsString("':void'", $source);
    }

    /** The body of one method, taken by matching braces rather than a window. */
    private static function methodBody(string $name): string
    {
        $source = self::code();
        $at = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($at, $name . ' must exist for this test to mean anything');
        $open = strpos($source, '{', $at);
        self::assertIsInt($open, $name . ' must have a body');
        return self::block($source, $open);
    }

    /** From the first brace at or after `$from` to the one that closes it. */
    private static function block(string $source, int $from): string
    {
        $open = strpos($source, '{', $from);
        if (!is_int($open)) {
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

    private static function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::SERVICE)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
