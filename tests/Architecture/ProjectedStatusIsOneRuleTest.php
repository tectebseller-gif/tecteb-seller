<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: what a projection would write to a storefront status is
 * decided in ONE place, the projector asks it, the transfer guard asks it,
 * and the test double asks it too.
 *
 * ⛔ **Why these are source assertions.** A behaviour test can show that one
 * held row kept its publish state. It cannot show that the next edit to
 * `project()` will not spell the rule out again with a ternary — and on the
 * day the two spellings disagree, the guard would permit a transfer whose
 * projection changes a product its vendor is selling, with every behaviour
 * test green (`alpha.77`).
 *
 * This is the `alpha.78` rule on a value rather than a predicate: two pieces
 * of code answering one question are right until the day they are not.
 *
 * ⛔ Comments are stripped first: these files describe the rule they follow,
 * and a check against raw source is satisfied by the sentence (`alpha.22`).
 */
final class ProjectedStatusIsOneRuleTest extends TestCase
{
    private const RULE = 'src/Modules/Product/Domain/ProjectedStatus.php';

    private const PROJECTOR = 'src/Modules/Product/Infrastructure/WooCommerce/WooCommerceProjector.php';

    private const TRANSFER = 'src/Modules/Migration/Application/TransferOwnership.php';

    private const PLAN = 'src/Modules/Migration/Application/OwnershipTransferPlan.php';

    private const DOUBLE = 'tests/Support/FakeCatalogProjector.php';

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertIsInt($start, 'no such method: ' . $method);
        $open = strpos($code, '{', (int) $start);
        self::assertIsInt($open, $method . ' has no body');
        $depth = 0;
        $length = strlen($code);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . ' is unbalanced');
    }

    /** The rule is pure: no WordPress, no database, no user-facing text. */
    public function testTheRuleTouchesNothing(): void
    {
        $code = $this->code(self::RULE);
        foreach (['get_post', 'wc_get_product', 'update_post_meta', '$wpdb', '__(', 'esc_html'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'ProjectedStatus must stay a pure rule: ' . $forbidden
            );
        }
    }

    /** The projector writes the two values THROUGH the rule, not beside it. */
    public function testTheProjectorAsksTheRuleForBothValues(): void
    {
        $body = $this->methodBody($this->code(self::PROJECTOR), 'project');
        self::assertStringContainsString('ProjectedStatus::statusOf($product->status)', $body);
        self::assertStringContainsString('ProjectedStatus::visibilityOf($product->status)', $body);
    }

    /**
     * And it does not spell the rule out a second time.
     *
     * The exact text this replaced. A `project()` that carried both would be
     * two answers with nothing saying which one the guard predicts.
     */
    public function testTheProjectorNoLongerCarriesItsOwnTernary(): void
    {
        $body = $this->methodBody($this->code(self::PROJECTOR), 'project');
        self::assertStringNotContainsString("ProductStatus::Published ? 'publish'", $body);
        self::assertStringNotContainsString("ProductStatus::Published ? 'visible'", $body);
    }

    /** The hold is still the condition over both writes. */
    public function testTheHoldStillGuardsBothWrites(): void
    {
        $body = $this->methodBody($this->code(self::PROJECTOR), 'project');
        self::assertStringContainsString('$statusHeld = $product->storefrontStatusIsHeld();', $body);
        self::assertStringContainsString('if (!$statusHeld) {', $body);
    }

    /**
     * `take()` refuses on the rule's verdict, and the condition is pinned —
     * not just the call.
     *
     * ⛔ `if (false)` keeps the text of a call exactly where it was, so
     * containment on the call alone stays green while the guard is dead
     * (`alpha.79`). The whole condition is asserted.
     */
    public function testTheTransferRefusesOnTheRuleAndTheConditionIsPinned(): void
    {
        $body = $this->methodBody($this->code(self::TRANSFER), 'take');
        self::assertStringContainsString(
            'if ($status !== null && !ProjectedStatus::settled($status[\'verdict\'])) {',
            $body
        );
        self::assertStringContainsString("'storefront_status_unreadable'", $body);
        self::assertStringContainsString("'storefront_status_would_change'", $body);
    }

    /**
     * And it refuses BEFORE the risks, so the gravest reason is the one
     * reported.
     *
     * Position, because a status refusal placed after the risk loop would be
     * unreachable for every row that also carries a risk — and «ریسک واقعی»
     * is the wrong sentence for a product about to be taken off sale.
     */
    public function testTheStatusRefusalComesFirst(): void
    {
        $body = $this->methodBody($this->code(self::TRANSFER), 'take');
        $status = strpos($body, 'ProjectedStatus::settled(');
        $risks = strpos($body, '$risks = $this->risksFor($productId);');
        self::assertIsInt($status);
        self::assertIsInt($risks);
        self::assertLessThan($risks, $status, 'the publish state is asked first');
    }

    /** The planner reaches the same verdict from the read it already does. */
    public function testThePlannerAsksTheSameRule(): void
    {
        $body = $this->methodBody($this->code(self::PLAN), 'classify');
        self::assertStringContainsString(
            'ProjectedStatus::decide($product->status, $product->mirroredStatus, $shopStatus)',
            $body
        );
        self::assertStringContainsString('!ProjectedStatus::settled($status)', $body);
    }

    /**
     * ⛔ And the DOUBLE carries the real guard.
     *
     * Until `alpha.92` `FakeCatalogProjector::project()` wrote the status
     * unconditionally while the real one holds it back, so «a held row keeps
     * its publish state» was unexpressible: the assertion would have failed
     * on correct code. A double that models a verb more generously than the
     * real one makes an incomplete guard look complete (`alpha.59`) — and
     * here it hid the guard that stops 184 published products becoming
     * drafts.
     */
    public function testTheDoubleHoldsTheStatusBackToo(): void
    {
        $body = $this->methodBody($this->code(self::DOUBLE), 'project');
        self::assertStringContainsString('if (!$product->storefrontStatusIsHeld()) {', $body);
        self::assertStringContainsString('ProjectedStatus::statusOf($product->status)', $body);
        self::assertStringContainsString('ProjectedStatus::visibilityOf($product->status)', $body);
    }
}
