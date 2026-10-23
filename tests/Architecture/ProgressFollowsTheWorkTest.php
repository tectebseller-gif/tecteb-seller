<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The rules this round turned into code, asserted from the BYTES rather than
 * from a docblock that says them.
 *
 * All four exist because the same sentence was true on `alpha.71` and the
 * queue still stopped: «a batch is resumable». It was resumable in the sense
 * that a cursor existed, and not in the sense that anything wrote it while the
 * work was happening.
 */
final class ProgressFollowsTheWorkTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Comments first, always: a rule asserted against raw bytes is satisfied by
     * the sentence that describes it (`alpha.22`).
     */
    private function code(string $relative): string
    {
        $out = '';
        foreach (token_get_all($this->source($relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** Every handler takes the checkpoint, or the runner cannot hand it one. */
    public function testEveryJobHandlerAcceptsTheCheckpoint(): void
    {
        $handlers = [
            'src/Modules/Migration/Application/DokanImportJob.php',
            'src/Modules/Operations/Application/DeliverEventsJob.php',
        ];
        foreach ($handlers as $file) {
            self::assertStringContainsString(
                'public function step(Job $job, ?\Closure $checkpoint = null): JobBatch',
                $this->code($file),
                $file . ' must accept the runner\'s checkpoint'
            );
        }
    }

    /**
     * The import's expensive phase records every unit, not every page.
     *
     * Asserted as «the page method is called WITH a sink», because the
     * difference between `alpha.71` and this tree is exactly that argument.
     */
    public function testTheImportPassesAPerUnitSinkToEveryPhase(): void
    {
        $code = $this->code('src/Modules/Migration/Application/DokanImportJob.php');
        foreach (['importVendorPage', 'importProductPage', 'importOrderPage'] as $method) {
            self::assertMatchesRegularExpression(
                '~' . $method . '\([^;]*\$progress\)~',
                $code,
                $method . ' must be given the per-unit sink'
            );
        }
        self::assertStringContainsString('new CursorProgress(', $code);
    }

    /**
     * And every unit is CLAIMED before any of its writes.
     *
     * ⛔ Rewritten, not loosened. `alpha.72` asserted that the attempt was
     * written before the per-unit sink was built, which was true of its own
     * code and said nothing about the thing that mattered: inside the page the
     * product row, the media copy and the manifest all happened before
     * anything token-guarded, so a worker whose lease had been handed on wrote
     * all three into an import it no longer owned. The order that is the guard
     * is the order inside the PAGE, so that is what is asserted — on the
     * bytes, in the file where the writes are.
     */
    public function testEveryUnitIsClaimedBeforeAnyOfItsWritesHappen(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        $page = substr(
            $code,
            (int) strpos($code, 'function importProductPage'),
            (int) strpos($code, 'function importGap') - (int) strpos($code, 'function importProductPage')
        );
        $claim = strpos($page, '$progress->claim($wcId)');
        $create = strpos($page, '$this->products->create(');
        $manifest = strpos($page, '$this->rememberRun($runId');
        $image = strpos($page, '$this->carryImage(');
        self::assertIsInt($claim, 'the unit must be claimed');
        self::assertIsInt($create);
        self::assertIsInt($manifest);
        self::assertIsInt($image);
        self::assertLessThan($create, $claim, 'claimed before the product row');
        self::assertLessThan($manifest, $claim, 'claimed before the manifest');
        self::assertLessThan($image, $claim, 'claimed before the picture');
    }

    /**
     * And the slow half asks again before it attaches anything.
     *
     * The copy is a file copy plus a full `wp_generate_attachment_metadata()`
     * — the place a host gives up — so the claim before the unit is not enough
     * on its own: by the time the copy is made the job may belong to somebody
     * else. The second ask, and the discard that goes with it, are what make
     * «no interleaved write, no duplicate media» true rather than hoped for.
     */
    public function testTheImageIsNotAttachedWithoutAskingForTheLeaseAgain(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        $carry = substr($code, (int) strpos($code, 'function carryImage'));
        $carry = substr($carry, 0, (int) strpos($carry, 'function giveUpOnProduct'));
        $copy = strpos($carry, 'duplicateForVendor(');
        $ask = strpos($carry, '$progress->stillOurs()');
        $attach = strpos($carry, '$this->products->saveImages(');
        self::assertIsInt($copy);
        self::assertIsInt($ask, 'the lease must be asked again, as a question');
        self::assertIsInt($attach);
        self::assertLessThan($ask, $copy, 'the copy comes first, or there is nothing to discard');
        self::assertLessThan($attach, $ask, 'and nothing is attached before the lease is confirmed');
        // All three conditions of `alpha.42`, together: we made it, the vendor
        // owns it, and nothing points at it.
        self::assertStringContainsString('mediaInUse($ownCopy)', $carry);
        self::assertStringContainsString('discard($ownCopy, $vendorUserId)', $carry);
    }

    /**
     * The attempt count is keyed to the unit it is about.
     *
     * ⛔ Measured on `alpha.72`'s own bytes: the same interruption read
     * `tries:1` inside the first product of a batch and `tries:0` inside the
     * second, because one counter was raised per batch and every landing
     * cleared it. A count that any other unit can reset is not a count of this
     * unit's attempts.
     */
    public function testTheAttemptCountBelongsToTheUnitNotTheBatch(): void
    {
        $code = $this->code('src/Modules/Migration/Application/CursorProgress.php');
        self::assertStringContainsString(
            '$this->tries = ($unitId === $this->at ? $this->tries : 0) + 1;',
            $code,
            'a different unit starts at one; the same unit carries on counting'
        );
        self::assertStringContainsString(
            '$unitId === $this->at && $this->tries >= $this->giveUpAfter',
            $code,
            'and the give-up is about the unit that was tried'
        );
        // Two counters, two facts, neither clearing the other.
        self::assertStringContainsString('page_tries', $code);
    }

    /**
     * Both ways of running a batch write down that one ran.
     *
     * `alpha.71` recorded only the cron tick, so «آخرین اجرا: هنوز اجرا نشده»
     * survived any number of manual runs and was read as a dead cron.
     */
    public function testBothTheTickAndTheButtonRecordThatABatchRan(): void
    {
        self::assertStringContainsString(
            "self::recordRun('cron')",
            $this->code('src/Infrastructure/WordPress/WpJobScheduler.php')
        );
        self::assertStringContainsString(
            "WpJobScheduler::recordRun('manual')",
            $this->code('src/Modules/Operations/Presentation/Admin/QueuePage.php')
        );
    }

    /**
     * And the page never says «چیزی در صف نبود» without having asked whether
     * there is something in the queue that it simply cannot claim.
     */
    public function testThePageExplainsWhyARunFoundNothing(): void
    {
        $code = $this->code('src/Modules/Operations/Presentation/Admin/QueuePage.php');
        self::assertStringContainsString('$this->whyNothingRan()', $code);
        // The reason can only be found by reading the live rows and looking at
        // the lease, so both are asserted: a sentence built from neither would
        // be the unconditional «چیزی در صف نبود» all over again.
        self::assertStringContainsString('live(50)', $code);
        self::assertStringContainsString("strtotime(\$job->lockedUntil) > time()", $code);
        self::assertStringContainsString("value=\"hand_over\"", $code, 'and offers the way past it');
    }
}
