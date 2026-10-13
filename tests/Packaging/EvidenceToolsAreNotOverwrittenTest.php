<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * Each round's evidence tool keeps its own name, and the script that runs it
 * names the same one.
 *
 * **`alpha.54` paid for this.** Its probe was written INTO
 * `tests/Support/alpha53-probe.php`, the previous round's tool. Everything
 * parsed, every suite stayed green, and `tools/alpha53-minmax-check.sh` then
 * reported «1 passed, 45 failed» about a build that was working — because the
 * verbs it calls had been replaced by a different round's. A round's evidence
 * is part of that round's delivery and has to outlive it: that is why
 * `dist/SHA256SUMS` and `docs/evidence-manifest.txt` exist at all, and a
 * probe is no different.
 *
 * Two things are checked, and the second is the one that catches the mistake:
 * every `alphaNN-*.sh` has a probe of its own round, and every probe a script
 * names is actually there with the verbs that script asks for.
 */
final class EvidenceToolsAreNotOverwrittenTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Every reproduction script's probe exists. */
    public function testEveryScriptsProbeIsOnDisk(): void
    {
        $missing = [];
        foreach ($this->scripts() as $script) {
            $body = (string) file_get_contents($script);
            if (preg_match_all('~tests/Support/([a-z0-9-]+\.php)~', $body, $m) === 0) {
                continue;
            }
            foreach (array_unique($m[1]) as $probe) {
                if (!is_file(self::ROOT . '/tests/Support/' . $probe)) {
                    $missing[] = basename($script) . ' → ' . $probe;
                }
            }
        }
        self::assertSame([], $missing, 'a script names a probe that is not there');
    }

    /**
     * And the probe a round's script names belongs to THAT round.
     *
     * `alpha53-minmax-check.sh` must call `alpha53-probe.php`, not whichever
     * file a later round happened to write into. The round number is in both
     * names, so this is a comparison rather than a list to maintain.
     */
    public function testEachRoundsScriptCallsItsOwnRoundsProbe(): void
    {
        $wrong = [];
        foreach ($this->scripts() as $script) {
            if (preg_match('~/alpha(\d+)-~', $script, $round) !== 1) {
                continue;
            }
            $body = (string) file_get_contents($script);
            if (preg_match_all('~tests/Support/alpha(\d+)-probe\.php~', $body, $m) === 0) {
                continue;
            }
            foreach (array_unique($m[1]) as $probeRound) {
                // A script may legitimately re-run an EARLIER round's probe as
                // a regression; what it must never do is call a LATER one,
                // because that is the file somebody overwrote.
                if ((int) $probeRound > (int) $round[1]) {
                    $wrong[] = basename($script) . ' calls alpha' . $probeRound . '-probe.php';
                }
            }
        }
        self::assertSame([], $wrong, 'a script calls a probe from a later round');
    }

    /**
     * The verbs a script asks for are the ones its probe answers.
     *
     * This is the check that would have failed the moment the probe was
     * overwritten, instead of forty-five checks failing on a live site.
     */
    public function testAScriptsVerbsExistInItsProbe(): void
    {
        $unknown = [];
        foreach ($this->scripts() as $script) {
            $body = (string) file_get_contents($script);
            if (preg_match('~tests/Support/(alpha\d+-probe\.php)~', $body, $m) !== 1) {
                continue;
            }
            $probe = self::ROOT . '/tests/Support/' . $m[1];
            if (!is_file($probe)) {
                continue;               // the first test says so already
            }
            $probeBody = (string) file_get_contents($probe);
            preg_match_all("~case '([a-z0-9-]+)'~", $probeBody, $cases);
            $known = $cases[1];
            if ($known === []) {
                continue;
            }
            // `probe <verb>` as a SHELL COMMAND — line-anchored, because
            // these scripts also write «the probe must», «the probe would»
            // and «probe ran» in their prose, and the first version of this
            // check read all of those as verbs and failed about nine scripts
            // that were correct.
            preg_match_all('~^[ \t]*probe ([a-z][a-z0-9-]*)~m', $body, $calls);
            foreach (array_unique($calls[1]) as $verb) {
                if (in_array($verb, $known, true)) {
                    continue;
                }
                $unknown[] = basename($script) . ' → ' . basename($probe) . ' has no verb «' . $verb . '»';
            }
        }
        self::assertSame([], $unknown);
    }

    /**
     * An interface that grows must not stop an earlier round's probe LOADING.
     *
     * **`alpha.55` paid for this one.** It added two methods to
     * `MinMaxHandoverRepositoryInterface`; `alpha.54`'s probe has a decorator
     * that implements that interface; PHP requires every method to be
     * declared, so the file stopped loading and
     * `tools/alpha54-reproduction.sh` reported SIXTY-ONE failures about a
     * build that was working. Exactly `alpha.32`'s rule — «رابطی که پارامتر
     * می‌گیرد، اسکریپت بازتولید را بی‌صدا از کار می‌اندازد» — one shape
     * further on: there it was a changed signature, here a new method.
     *
     * The check is static on purpose: a probe cannot be LOADED here (it needs
     * WordPress), so its class bodies are parsed and the method names it
     * declares are compared against the interface's own, read by reflection.
     * That is enough to catch the mistake, and it catches it in a suite
     * rather than on a live site.
     */
    public function testEveryProbesDecoratorStillDeclaresItsWholeInterface(): void
    {
        $missing = [];
        foreach (glob(self::ROOT . '/tests/Support/alpha*-probe.php') ?: [] as $probe) {
            $body = (string) file_get_contents($probe);
            // `final class X implements Y` — the shape every probe decorator
            // uses. The interface is named with its short name and imported at
            // the top of the file, so the `use` lines resolve it.
            if (preg_match_all('~class\s+(\w+)\s+implements\s+([\w\\\\]+)~', $body, $classes, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($classes as [$whole, $class, $shortName]) {
                $fqn = $this->resolve($body, $shortName);
                if ($fqn === '' || !interface_exists($fqn)) {
                    continue;           // not one of ours, or not an interface
                }
                $declared = [];
                // Only the methods of THIS class: the body from its opening
                // brace to the matching one is more machinery than this needs,
                // so every `public function` in the file counts — a probe that
                // declares a method anywhere is a probe that has it.
                preg_match_all('~public function (\w+)\s*\(~', $body, $found);
                $declared = $found[1];
                foreach ((new \ReflectionClass($fqn))->getMethods() as $method) {
                    if (!in_array($method->getName(), $declared, true)) {
                        $missing[] = basename($probe) . ': ' . $class . ' has no ' . $method->getName() . '()';
                    }
                }
            }
        }
        self::assertSame(
            [],
            $missing,
            "an interface grew and an earlier round's evidence tool can no longer load:\n"
                . implode("\n", $missing)
        );
    }

    /** The fully-qualified name of a short class name, from the file's `use`. */
    private function resolve(string $body, string $shortName): string
    {
        if (str_contains($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }
        $pattern = '~^use ([\\w\\\\]+\\\\' . preg_quote($shortName, '~') . ');~m';
        if (preg_match($pattern, $body, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /** @return list<string> */
    private function scripts(): array
    {
        $found = glob(self::ROOT . '/tools/alpha*.sh');
        return $found === false ? [] : array_values($found);
    }
}
