<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the Dokan guard is WIRED, is asked FIRST, and asks the
 * right question.
 *
 * Three claims, and each of them is invisible to a behavioural test:
 *
 *  1. **It is wired.** The gate's fourth parameter is optional on purpose —
 *     a required one would stop the evidence probes of `alpha.38` and
 *     `alpha.39` loading, which is the `alpha.32` trap this repository has
 *     paid for three times. The cost of that choice is that `null` reads as
 *     «no Dokan», so the container binding is the only thing that makes the
 *     guard real on a site. A function with no caller is not a capability
 *     (`alpha.80`), and a guard with no wiring is not a guard.
 *  2. **It is asked before the trial.** The trial switch waives DEC-02 and
 *     DEC-04 because those are definitions nobody has written down. Dokan
 *     running is not: it is a second system recording the same sale. If the
 *     trial were consulted first, the one state this guard exists for —
 *     staging, trial on, Dokan active — would be exactly the state it let
 *     through. Pinned by BYTE OFFSET, because `alpha.74` measured that
 *     position in a file is the only way to say «before» and a `strpos` of a
 *     name says nothing about order of evaluation.
 *  3. **It does not ask about Dokan's TABLES.** `DOKAN-EXIT-PLAN-FA.md`
 *     deliberately keeps them — deactivation is not deletion and the
 *     financial archive is the evidence — so a table-shaped test would block
 *     order recording for ever after the exit, which is the state this whole
 *     migration exists to reach. That failure would appear only after the
 *     owner switched Dokan off, which is the worst possible moment.
 */
final class OrderGateAsksAboutDokanTest extends TestCase
{
    private const GATE = __DIR__ . '/../../src/Modules/Order/Application/OrderOperationsGate.php';
    private const BOOTSTRAP = __DIR__ . '/../../src/Infrastructure/WordPress/Bootstrap.php';
    private const PROBE = __DIR__ . '/../../src/Modules/Order/Infrastructure/WordPress/WpDokanPresence.php';

    /** 1. The container passes a real probe into the gate. */
    public function testTheContainerPassesARealProbeIntoTheGate(): void
    {
        $bootstrap = $this->tokenised(self::BOOTSTRAP);

        self::assertStringContainsString(
            'new WpDokanPresence()',
            $bootstrap,
            'something has to construct the probe'
        );
        self::assertStringContainsString(
            '$c->bind(DokanPresenceInterface::class,',
            $bootstrap,
            'and bind it under the contract the gate asks for'
        );

        $binding = $this->between($bootstrap, '$c->bind(OrderOperationsGate::class,', '));');
        self::assertStringContainsString(
            '$c->get(DokanPresenceInterface::class)',
            $binding,
            'the gate binding must PASS it; an optional parameter nobody fills is not a guard'
        );
    }

    /** 2. Dokan is asked before the trial can waive anything — in both methods. */
    public function testDokanIsAskedBeforeTheTrialInBothMethods(): void
    {
        $gate = $this->tokenised(self::GATE);

        foreach (['private function evaluate(' => 'evaluate', 'public function blockers(' => 'blockers'] as $needle => $name) {
            $body = $this->methodBody($gate, $needle);
            $dokan = strpos($body, '$this->dokan?->isRunning()');
            $trial = strpos($body, '$this->trial?->isActive()');

            self::assertIsInt($dokan, $name . ' asks about Dokan');
            self::assertIsInt($trial, $name . ' still asks about the trial');
            self::assertLessThan($trial, $dokan, $name . ' asks about Dokan BEFORE the trial');
        }
    }

    /**
     * 2b. And the Dokan branch returns rather than falling through, so the
     * trial cannot be reached while it is true. A check whose body did
     * nothing would satisfy the ordering test above.
     */
    public function testTheDokanBranchReturnsRatherThanRecording(): void
    {
        $gate = $this->tokenised(self::GATE);

        foreach (['private function evaluate(', 'public function blockers('] as $needle) {
            $body = $this->methodBody($gate, $needle);
            // ⛔ The REAL block, by brace matching — not a window of N
            // characters after the call.
            //
            // The first version of this test took `substr($body, $at, 420)`
            // and asked whether `return` appeared in it. A mutation that
            // replaced the return with an assignment SURVIVED: the branch got
            // shorter, so the fixed window slid past the closing brace and
            // into the next branch, which returns. The needle was true about
            // a different piece of code (`alpha.33`), and the window hid it.
            $branch = $this->braceBlockAfter($body, '$this->dokan?->isRunning()');
            self::assertStringContainsString('return', $branch, 'the branch has to stop the method');
            self::assertStringContainsString('DOKAN_RUNNING', $branch, 'and name the reason it stopped for');
            // And the reason must be ON the return, not merely somewhere in
            // the block: an assignment that mentions the constant and falls
            // through satisfies both assertions above.
            $at = strpos($branch, 'return');
            self::assertIsInt($at);
            self::assertStringContainsString(
                'DOKAN_RUNNING',
                substr($branch, $at),
                'the value that is RETURNED has to carry the reason'
            );
        }
    }

    /**
     * 3. The probe asks about loaded code and the active-plugin list, and
     * about nothing else.
     *
     * The forbidden list is spelled out rather than matched by shape, so a
     * reader can see which questions are wrong here and why each one is.
     */
    public function testTheProbeNeverAsksAboutTablesOrCallsDokan(): void
    {
        $probe = $this->tokenised(self::PROBE);

        $forbidden = [
            // Tables: kept on purpose after the exit, so they are not a
            // signal that Dokan is RUNNING.
            'dokan_orders',
            'dokan_vendor_balance',
            'dokan_withdraw',
            'SHOW TABLES',
            'information_schema',
            // The database at all: this question is about this request.
            '$wpdb',
            'getResults',
            'getVar',
            'tableExists',
            // The migration reader's broader question, which includes tables.
            'DokanReaderInterface',
            'isAvailable',
            // Calling Dokan: a plugin mid-fatal must still be detectable.
            'dokan_get_',
            'dokan()->',
        ];
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $probe,
                'the running-check must not ask: ' . $needle
            );
        }

        // And the other half: the signals it DOES ask, because a probe that
        // asked nothing would pass every assertion above.
        foreach (['WeDevs_Dokan', 'function_exists', 'active_plugins', 'active_sitewide_plugins'] as $needle) {
            self::assertStringContainsString($needle, $probe, 'the probe must still ask: ' . $needle);
        }

        // ⛔ AND THE ANSWER MUST BE USED, which the four needles above do not
        // say. A mutation that replaced `$active = $this->activePlugins()`
        // with `$active = []` SURVIVED this test: the method it stopped
        // calling was still in the file, with every string in it, so every
        // assertion above stayed true while the fail-closed signal was gone.
        // A function with no caller is not a capability (`alpha.80`) — and a
        // caller that discards the answer is the same thing one line later.
        $detect = $this->methodBody($probe, 'private function detect(');
        self::assertStringContainsString(
            '$this->activePlugins()',
            $detect,
            'detect() must CALL the active-plugin list, not merely sit beside it'
        );
        self::assertMatchesRegularExpression(
            '/\$active\s*=\s*\$this->activePlugins\(\)/',
            $detect,
            'and the list it iterates must be the one it just read'
        );
    }

    /** Source with comments blanked: a byte scan cannot tell prose from code. */
    private function tokenised(string $path): string
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

    /** The body of one method, by brace matching rather than «to the next `function`». */
    private function methodBody(string $source, string $signature): string
    {
        $at = strpos($source, $signature);
        self::assertIsInt($at, 'method not found: ' . $signature);
        $open = strpos($source, '{', $at);
        self::assertIsInt($open);
        $depth = 0;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail('unbalanced braces after ' . $signature);
    }

    /** The `{ … }` block that the given needle sits in, by brace matching. */
    private function braceBlockAfter(string $source, string $needle): string
    {
        $at = strpos($source, $needle);
        self::assertIsInt($at, 'needle not found: ' . $needle);
        $open = strpos($source, '{', $at);
        self::assertIsInt($open);
        $depth = 0;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail('unbalanced braces after ' . $needle);
    }

    private function between(string $source, string $from, string $to): string
    {
        $at = strpos($source, $from);
        self::assertIsInt($at, 'not found: ' . $from);
        $end = strpos($source, $to, $at);
        self::assertIsInt($end, 'no end after: ' . $from);
        return substr($source, $at, $end - $at);
    }
}
