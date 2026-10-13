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

    /** @return list<string> */
    private function scripts(): array
    {
        $found = glob(self::ROOT . '/tools/alpha*.sh');
        return $found === false ? [] : array_values($found);
    }
}
