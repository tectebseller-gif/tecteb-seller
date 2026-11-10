<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the four halves of a refund are described as they are.
 *
 * ⛔ **Why it exists.** `RefundScope`'s docblock said half three — the
 * WooCommerce refund record — was «done here, but only when a manager asks».
 * It is built, it is wired, and **no code in `src/` calls it**: there is no
 * screen on which a manager can ask. A capability with no caller is not a
 * capability (`alpha.80`), and a document that claims one is worse than a
 * missing feature, because somebody reads it and stops looking.
 *
 * Both directions are pinned, deliberately. If a later round wires the
 * method up, this test fails and the sentence has to be corrected back —
 * which is the point: the claim and the call site cannot drift apart again
 * without something going red.
 */
final class RefundHalvesAreNamedHonestlyTest extends TestCase
{
    private const SCOPE = 'src/Modules/Order/Application/RefundScope.php';
    private const SERVICE = 'src/Modules/Order/Application/ManageReturns.php';
    private const METHOD = 'recordWooCommerceRefund';

    /**
     * The measurement itself: how many places in `src/` call it.
     *
     * Counted over tokenised source, because the corrected docblock in
     * `RefundScope` NAMES the method while explaining that nothing calls it —
     * a byte scan would count the explanation (`alpha.22`).
     */
    public function testTheWooCommerceRefundHalfHasNoProductionCaller(): void
    {
        $callers = [];
        foreach (self::sourceFiles() as $path) {
            $code = self::codeWithoutComments($path);
            // The definition is not a call site.
            $code = str_replace('function ' . self::METHOD, 'function __definition', $code);
            if (str_contains($code, self::METHOD . '(')) {
                $callers[] = $path;
            }
        }

        self::assertSame(
            [],
            $callers,
            "`" . self::METHOD . "()` now has a caller in src/, so `RefundScope`'s half three is\n"
            . "reachable and its docblock must say so rather than calling it unreachable:\n"
            . implode("\n", $callers)
        );
    }

    /** And the sentence that describes it says the same thing. */
    public function testTheScopeDocumentSaysTheHalfIsUnreachable(): void
    {
        $text = (string) file_get_contents(self::root() . self::SCOPE);

        self::assertStringContainsString(
            'BUILT AND UNREACHABLE',
            $text,
            'half three is unreachable in this build and the document has to say it'
        );
        self::assertStringNotContainsString(
            'Done here since `alpha.11`, but only when a manager asks',
            $text,
            'the old claim promised a screen that does not exist'
        );
    }

    /**
     * The two halves that ARE done, and the one that cannot be — still named,
     * so this test does not become a licence to quietly drop the honest parts.
     */
    public function testTheOtherThreeHalvesAreStillNamed(): void
    {
        $text = (string) file_get_contents(self::root() . self::SCOPE);

        foreach (['**LEDGER**', '**STOCK**', '**THE MONEY**'] as $half) {
            self::assertStringContainsString($half, $text, $half . ' must stay named');
        }
        self::assertStringContainsString(
            'canTransferMoney',
            $text,
            'the money half is refused by a method that answers false, not by prose'
        );
    }

    /**
     * And the service still HAS the method.
     *
     * Deleting it would also make the first test pass, and that is not the
     * outcome this file is asking for: the code is correct and crash-safe,
     * it simply has no door. Keeping it named means the smallest fix stays
     * available (one action value and one branch).
     */
    public function testTheMethodItselfIsStillThere(): void
    {
        self::assertStringContainsString(
            'function ' . self::METHOD,
            (string) file_get_contents(self::root() . self::SERVICE),
            'the half is built; what it lacks is a caller, and that is the finding'
        );
    }

    /** @return list<string> paths relative to the repository root */
    private static function sourceFiles(): array
    {
        $root = self::root();
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'src'));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root));
            }
        }
        sort($files);
        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/';
    }

    /** Comments blanked, strings kept. */
    private static function codeWithoutComments(string $relative): string
    {
        $code = (string) file_get_contents(self::root() . $relative);
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
