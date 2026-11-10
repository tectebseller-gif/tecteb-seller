<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ⛔ A GUARD NO BEHAVIOURAL TEST IN THIS REPOSITORY CAN REACH.
 *
 * The gated deactivation path counts what it left on sale and writes the
 * number where the next admin page reads it. Falsification put a literal `0`
 * in place of the count and EVERY suite stayed green — so the number nobody
 * measured could have been nought on a shop with a hundred products up, and
 * the warning would have said «none».
 *
 * It is not reachable, and that was measured rather than assumed:
 * `noteWhatIsLeftOnSale()` asks the container for
 * `ProductRepositoryInterface`, and the only test that runs `Deactivator`
 * (`ActivationFlowTest`) boots a minimal container where the product module
 * is not registered. The `get()` throws, the whole method is wrapped, and the
 * documented answer is `0` — so a mutant returning `0` is indistinguishable
 * from the healthy build THERE. A double is not the way out either: the
 * interface has 69 methods, and `CatalogProjectionTest`, which does hold real
 * projected products, never boots the container at all.
 *
 * So the property is asserted in the source and the mutation moves to this
 * suite — `alpha.77`'s rule, and `alpha.95`'s («a mutation unreachable in the
 * existing fixtures is asserted in the source, not deleted»).
 */
final class DeactivationCountsWhatItLeftBehindTest extends TestCase
{
    private const DEACTIVATOR = 'src/Infrastructure/WordPress/Lifecycle/Deactivator.php';

    /**
     * The number written is the one the catalogue reported, not a literal.
     *
     * Asserted on the method body with braces matched, because the option
     * name and the words «left on sale» also appear in the prose above it and
     * in the audit payload below (`alpha.22`, `alpha.77`).
     */
    public function testTheNoteCarriesTheCatalogueCountAndNotAConstant(): void
    {
        $body = self::methodBody('noteWhatIsLeftOnSale');

        self::assertStringContainsString(
            '->countProjected()',
            $body,
            'the count comes from the catalogue'
        );
        self::assertSame(
            1,
            preg_match(
                '/update_option\(\s*DeactivationStopPolicy::LEFT_ON_SALE_OPTION\s*,\s*\$left\s*,/',
                $body
            ),
            'and the value written is that count, held in $left — never a literal'
        );
        self::assertSame(
            1,
            preg_match('/\$left\s*=\s*\$products->countProjected\(\);/', $body),
            '$left is assigned from the catalogue reading itself'
        );
    }

    /**
     * And the two paths stay two paths: the count is reached only when the
     * owner has NOT asked for a stop.
     *
     * Without this, moving the count inside the stop branch would satisfy the
     * assertions above while the plain deactivation went back to writing
     * nothing — the half of the defect that made the notice possible.
     */
    public function testTheCountBelongsToThePathThatLeavesTheShelfUp(): void
    {
        $body = self::methodBody('deactivate');

        self::assertSame(
            1,
            preg_match(
                '/if\s*\(\s*\$alsoStop\s*\)\s*\{\s*'
                . '\$withdrawn\s*=\s*self::stopSellingMarketplaceProducts\(\);\s*'
                . '\}\s*else\s*\{\s*'
                . '\$leftOnSale\s*=\s*self::noteWhatIsLeftOnSale\(\);\s*\}/',
                $body
            ),
            'stop on the owner\'s yes; count what is left on the default no'
        );
        self::assertStringContainsString(
            "'stopped_selling' => \$alsoStop ? 'yes' : 'no'",
            $body,
            'and the audit row says which of the two ran'
        );
    }

    /** The body between the opening brace of a method and its match. */
    private static function methodBody(string $method): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/' . self::DEACTIVATOR
        );
        $at = strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($at, $method . '() exists');

        $open = strpos($source, '{', (int) $at);
        self::assertNotFalse($open, $method . '() has a body');

        $depth = 0;
        $length = strlen($source);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . '() braces are balanced');
    }
}
