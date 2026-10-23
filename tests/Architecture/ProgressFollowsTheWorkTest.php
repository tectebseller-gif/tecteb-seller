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
                '~' . $method . '\([^;]*\$recordOne\)~',
                $code,
                $method . ' must be given the per-unit sink'
            );
        }
        self::assertStringContainsString('$recordOne = $checkpoint === null', $code);
    }

    /**
     * And the attempt is recorded BEFORE the work, because a process that stops
     * existing writes nothing afterwards. The order of the two statements is
     * the whole guard, so the order is what is asserted.
     */
    public function testTheAttemptIsRecordedBeforeTheWorkIsAttempted(): void
    {
        $code = $this->code('src/Modules/Migration/Application/DokanImportJob.php');
        $attempt = strpos($code, '$tries + 1');
        $sink = strpos($code, '$recordOne = $checkpoint === null');
        self::assertIsInt($attempt, 'the attempt must be written');
        self::assertIsInt($sink);
        self::assertLessThan($sink, $attempt, 'the attempt is written before any unit is done');
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
