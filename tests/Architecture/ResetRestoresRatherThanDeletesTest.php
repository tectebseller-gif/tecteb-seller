<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * «Back to automatic» restores the rule; it never removes it.
 *
 * `alpha.46` shipped the control and made it delete the three quantity rows,
 * leaving the restoring to the next projection. Nothing was wrong with either
 * half on its own — `ProjectedQuantity::ruleFrom()` is right to read an absent
 * row as WooCommerce's defaults, because a product this plugin never
 * projected has no rule — and that is exactly why the two halves together
 * went unnoticed for a round. Measured on `alpha.46` in a fresh request, with
 * the marketplace rule reading `2/6/2`: `input_args=1/40/1`, and quantities
 * 1, 3 and 8 all `allowed` in the classic cart and through the Store API. A
 * reset raised what could be bought.
 *
 * So the rule is written down here rather than only in a docblock: no code
 * path in the panel may hand a quantity ROW to `delete_post_meta()`. The
 * ownership record, the conflict list and the contradiction flag may go —
 * they are bookkeeping about the rule, not the rule — and the test names them
 * so that an exemption has to be deliberate.
 *
 * Token-level, with comments blanked first: `alpha.22`'s rule, because bytes
 * do not tell code from prose and this file's own prose names the call it
 * forbids.
 */
final class ResetRestoresRatherThanDeletesTest extends TestCase
{
    private const PANEL = '/src/Modules/Product/Infrastructure/WooCommerce/QuantityPanel.php';

    /** Bookkeeping a reset is allowed to remove. */
    private const BOOKKEEPING = [
        'ProjectedQuantity::OWNED_META',
        'ProjectedQuantity::CONFLICT_META',
        'QuantityGuard::CONTRADICTION_META',
    ];

    /** Source with every comment and docblock blanked out. */
    private static function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    public function testThePanelNeverDeletesAQuantityRow(): void
    {
        $code = self::code(dirname(__DIR__, 2) . self::PANEL);
        // Every argument the file hands to `delete_post_meta()`, second
        // parameter only — the first is the product id.
        $found = [];
        if (preg_match_all('~delete_post_meta\(\s*[^,]+,\s*([^)]+)\)~', $code, $m) > 0) {
            foreach ($m[1] as $argument) {
                $found[] = trim($argument);
            }
        }
        self::assertNotSame([], $found, 'the scan found no delete_post_meta() call at all — it has stopped measuring');
        foreach ($found as $argument) {
            self::assertContains(
                $argument,
                self::BOOKKEEPING,
                'QuantityPanel hands ' . $argument . ' to delete_post_meta(). A quantity ROW must be '
                . 'REWRITTEN with the marketplace rule, never removed: between the removal and the next '
                . 'projection the product has no rule at all, and absence reads as «no minimum, no '
                . 'maximum, no step».'
            );
        }
    }

    public function testTheResetWritesTheRuleItIsRestoring(): void
    {
        $code = self::code(dirname(__DIR__, 2) . self::PANEL);
        self::assertStringContainsString(
            'ProjectedQuantity::values($rule)',
            $code,
            'the reset must write the rule it read out of the marketplace row'
        );
        self::assertStringContainsString(
            'PurchaseGuard::marketplaceProduct(',
            $code,
            'the reset must read the marketplace row, which is where the approved rule is'
        );
    }

    /**
     * And the enforcement point asks the marketplace row when the shop copy
     * is gone — so the gap cannot be reopened by any other route either: a
     * manager deleting the rows by hand, or a projection that half failed.
     */
    public function testTheGuardFallsBackToTheMarketplaceRowWhenThereAreNoRows(): void
    {
        $code = self::code(dirname(__DIR__, 2) . '/src/Modules/Product/Infrastructure/WooCommerce/QuantityGuard.php');
        self::assertStringContainsString('PurchaseGuard::marketplaceProduct(', $code);
        self::assertStringContainsString('->details->purchaseQuantity()', $code);
    }
}
