<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: in `RecordSupplierPayment`, the two methods that change a
 * payment's state read that state under a lock FIRST.
 *
 * The behaviour is pinned by `SupplierObligationFlowTest` and measured end to
 * end by `tools/alpha64-reproduction.sh`. This is the cheap guard beside
 * them, because the defect it watches for is an ORDERING and an ordering is
 * the easiest thing in the world to undo by accident: moving one read above
 * one `begin()` restores it, and every suite stays green except the two that
 * make the readings disagree on purpose.
 *
 * What `alpha.64` did, in its own words:
 *
 *     $payment = $this->payments->find($paymentId);   // line 664
 *     ...
 *     $this->tx->begin();                            // line 680
 *     $compensating = $payment->ledgerEvent !== '' ? ... : '';   // line 684
 *
 * so a `reconcile()` committing between 664 and 680 made the answer on 684
 * wrong — measured: `void_code=supplier_payment_voided`,
 * `compensating_event=__none__`, `reversal_entry_exists=no`.
 *
 * Byte-level over the TOKENISED file, because the docblocks of both methods
 * now quote the defective line in order to explain it, and a byte scan cannot
 * tell prose from code (`alpha.22`).
 */
final class FinancialDecisionsComeFromLockedRowsTest extends TestCase
{
    private const SERVICE = __DIR__
        . '/../../src/Modules/Finance/Application/RecordSupplierPayment.php';

    /**
     * @return array<string,array{string}>
     */
    public static function methods(): array
    {
        return [
            'void' => ['public function void('],
            'reconcile' => ['public function reconcile('],
        ];
    }

    /**
     * @dataProvider methods
     */
    public function testTheStateIsLockedBeforeItIsDecidedFrom(string $signature): void
    {
        $body = self::bodyOf($signature);
        $begin = strpos($body, '$this->tx->begin()');
        $locked = strpos($body, '$this->payments->findForUpdate(');
        self::assertIsInt($begin, 'the method must open a transaction');
        self::assertIsInt($locked, 'the method must read the row under a lock');
        self::assertGreaterThan(
            $begin,
            $locked,
            'a lock taken outside a transaction is released by the statement that took it'
        );
    }

    /**
     * The compensation decision in particular — the one that was measured
     * wrong, and the one whose cost is a ledger that disagrees with a
     * document.
     */
    public function testTheCompensationDecisionFollowsTheLockedRead(): void
    {
        $body = self::bodyOf('public function void(');
        $locked = strpos($body, '$this->payments->findForUpdate(');
        $decision = strpos($body, '$compensating =');
        self::assertIsInt($locked);
        self::assertIsInt($decision);
        self::assertGreaterThan($locked, $decision, 'the decision must be taken from the locked row');
    }

    /** The tokenised source of one method, comments blanked out. */
    private static function bodyOf(string $signature): string
    {
        $code = self::code();
        $start = strpos($code, $signature);
        self::assertIsInt($start, $signature . ' must be in the file');
        // To the next method declaration, or to the end.
        $next = strpos($code, 'public function ', $start + strlen($signature));
        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
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
