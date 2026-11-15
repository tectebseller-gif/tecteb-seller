<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * THE PARENT-FIGURES RULE IS TWO LAYERS, AND NEITHER IS REDUNDANT.
 *
 * §1-B of the instruction puts enforcement in Application and the projector
 * rather than the UI, and `SyncCatalog`'s own refusal exists because «a guard
 * that holds because the callers happen to ask first is an ordering, not a
 * guard» (`alpha.98`).
 *
 * ⛔ WHY THIS FILE EXISTS, MEASURED RATHER THAN ARGUED. Falsification of
 * `alpha.106` ran twenty-one mutations and three survived, all of them saying
 * the same thing from two sides:
 *
 *   3/21  writeAndVerifyStock drops the refusal   database:0  SURVIVED
 *   4/21  pushPrice drops the refusal             database:0  SURVIVED
 *   7/21  updateInventory pushes the stock regardless  database:0  SURVIVED
 *   8/21  saveAsRevision pushes the stock regardless    database:0  SURVIVED
 *
 * The first two survived because every behavioural test enters through
 * `ManageProducts`, which refuses first — closed by calling `SyncCatalog`
 * directly in `ImportedParentFiguresTest`. The last two are the mirror image and
 * cannot be closed that way: with `SyncCatalog`'s refusal in place, removing
 * the caller's condition changes nothing a fixture can observe, because
 * `pushStock()`'s return value is deliberately discarded there and the
 * projector is never reached. `SyncCatalog` is `final` and injected as a
 * concrete class, so there is no spy to count the call with — and widening a
 * production type for a test is not a trade this round makes.
 *
 * So the property is asserted in SOURCE and the mutation moved to this suite,
 * which is what `alpha.77`, `alpha.95` and `alpha.99` all say to do with a
 * double guard: keep it, write down the measurement, and let a source test be
 * the thing that notices.
 */
final class ParentFiguresGuardIsTwoLayersTest extends TestCase
{
    private const MANAGE = __DIR__ . '/../../src/Modules/Product/Application/ManageProducts.php';
    private const SYNC = __DIR__ . '/../../src/Modules/Product/Application/SyncCatalog.php';

    /**
     * LAYER ONE: neither save path pushes a figure that is not its own.
     *
     * Both conditions are pinned by their exact text, because that is what the
     * two surviving mutations replace — and a reformatting that breaks this
     * assertion is a reason to re-measure, not to loosen it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pushSites(): array
    {
        return [
            'the independent inventory path' => [
                'public function updateInventory(',
                'if (!$stockProposed && $stockBelongsToUs) {',
            ],
            'the revision path' => [
                'private function saveAsRevision(',
                'if (!$stockProposed && $this->catalog->priceAndStockOrigin($product->id)'
                    . '->mayWriteParentFigures()) {',
            ],
        ];
    }

    /** @dataProvider pushSites */
    public function testNeitherSavePathPushesAFigureThatIsNotItsOwn(
        string $signature,
        string $condition
    ): void {
        $body = self::bodyOf(self::MANAGE, $signature);

        self::assertSame(
            1,
            substr_count($body, '$this->catalog->pushStock('),
            'exactly one outward push in ' . $signature . ', or the assertions below '
            . 'are about the wrong one'
        );
        self::assertStringContainsString(
            $condition,
            $body,
            'the push must be conditioned on ownership — mutations 7 and 8 of the '
            . 'alpha.106 loop remove exactly these and no behavioural test can see it'
        );
        self::assertLessThan(
            strpos($body, '$this->catalog->pushStock('),
            strpos($body, $condition),
            'and the condition must come BEFORE the push, not merely appear in the method'
        );
    }

    /** LAYER TWO: and the service refuses on its own, whatever the caller did. */
    public function testTheServiceRefusesOnItsOwnInBothVerbs(): void
    {
        foreach (
            [
                'private function writeAndVerifyStock(' => "'parent_figures_live_in_shop'",
                'public function pushPrice(' => "'parent_figures_live_in_shop'",
            ] as $signature => $needle
        ) {
            $body = self::bodyOf(self::SYNC, $signature);
            self::assertStringContainsString('mayWriteParentFigures()', $body, $signature);
            self::assertStringContainsString($needle, $body, $signature);
        }
    }

    /**
     * Body by signature, to the next `function ` of any visibility — a window
     * of N characters is not a block, and a slice to the next PUBLIC function
     * swallows the private ones between (`alpha.98`).
     */
    private static function bodyOf(string $file, string $signature): string
    {
        $code = self::withoutComments($file);
        $start = strpos($code, $signature);
        self::assertIsInt($start, $signature . ' must be in ' . basename($file));
        $next = strpos($code, 'function ', $start + strlen($signature));
        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
    }

    /** A docblock is not code: `alpha.22`, and this file's own prose names these very methods. */
    private static function withoutComments(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
