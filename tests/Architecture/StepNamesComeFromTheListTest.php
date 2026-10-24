<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Jobs\StepTiming;

/**
 * Every step name written anywhere in `src/` is one of `StepTiming::STEPS`.
 *
 * ### Why this is an architecture test and not a unit test
 *
 * `StepTiming` already refuses an unlisted name at runtime: it stores
 * `UNLISTED` instead. That is the guarantee the owner asked for — «برای نام
 * مراحل از فهرست ثابت مجاز استفاده کن» — and it holds whatever a caller does.
 *
 * But a caller that passes an unlisted name is still a defect, and a silent
 * one: the row would read `step_not_listed` and the person looking at it would
 * have lost the very word they came for. Runtime safety and correctness are
 * different questions, and this is the second one — caught in the build, where
 * it costs nothing, rather than on staging, where it costs an attempt.
 *
 * ⛔ Byte-scanned with comments removed first. A rule asserted against raw
 * source is satisfied by the sentence describing it (`alpha.22`), and this
 * file's own prose names several steps.
 */
final class StepNamesComeFromTheListTest extends TestCase
{
    /**
     * Exempt from the cursor-key rule, each for a stated reason.
     *
     * ⛔ Named one by one rather than matched by a pattern, because an
     * exemption that can be earned by accident is not an exemption.
     *
     *  - `StepTiming` is the one writer, which is the rule itself.
     *  - `OperationsMessages` has `'running' =>` and `'stalled' =>` as `match`
     *    arms over JOB STATUS — `JobStatus::Running` and the `stalled` state a
     *    job is in when its lease has expired. Same two words, a different
     *    subject, and nothing there touches a cursor: the file has no `$wpdb`,
     *    no repository and no array it hands to a checkpoint.
     */
    private const NOT_CURSOR_WRITERS = [
        'src/Core/Jobs/StepTiming.php',
        'src/Modules/Operations/Presentation/OperationsMessages.php',
    ];

    /** Files whose literals are step names, and the call that consumes them. */
    private const SITES = [
        'src/Modules/Migration/Application/ImportFromDokan.php' => 'entering',
        'src/Modules/Product/Infrastructure/WordPress/WpProductImages.php' => 'say',
    ];

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

    public function testEveryStepNameWrittenInTheSourceIsOnTheList(): void
    {
        $seen = [];
        foreach (self::SITES as $file => $call) {
            $code = $this->code($file);
            // `entering('name'` / `$say('name'`. A variable argument is
            // allowed and is checked by the test below instead: there is
            // exactly one, and it forwards a name from the other file.
            $pattern = $call === 'say'
                ? '~\$say\(\s*\'([^\']*)\'~'
                : '~->entering\(\s*\'([^\']*)\'~';
            preg_match_all($pattern, $code, $matches);
            foreach ($matches[1] as $name) {
                self::assertContains(
                    $name,
                    StepTiming::STEPS,
                    $file . ' passes a step name that is not on the list: ' . $name
                );
                $seen[$name] = true;
            }
        }
        // And the list has no member nothing ever writes: a name on the list
        // that no call site uses is a label the page can print and the import
        // can never produce, which is the other half of the same mistake.
        foreach (StepTiming::STEPS as $step) {
            self::assertArrayHasKey(
                $step,
                $seen,
                'nothing in src/ ever enters the step «' . $step . '»'
            );
        }
    }

    /**
     * The one indirect call site forwards a name; it does not invent one.
     *
     * `carryImage()` hands `WpProductImages` a closure so that the image
     * halves can name themselves as they start — the names live there because
     * that is the file that knows which half is running, and this keeps the
     * forwarding honest: whatever arrives is passed through unchanged, so the
     * scan above covers it.
     */
    public function testTheForwardedStepNameIsPassedThroughUnchanged(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        self::assertStringContainsString(
            '$progress->entering($step, $unitId);',
            $code,
            'the forwarding closure must pass the name it was given, not build one'
        );
        self::assertSame(
            1,
            preg_match_all('~->entering\(\s*\$~', $code),
            'exactly one indirect call site, and it is the one above'
        );
    }

    /**
     * `UNLISTED` is not a step, and no caller may pass it.
     *
     * It is what an unlisted name BECOMES, and a caller that passed it would
     * be claiming a step whose name is «not on the list» — a sentence, stored
     * as if it were a measurement.
     */
    public function testNoCallerPassesThePlaceholderItself(): void
    {
        foreach (array_keys(self::SITES) as $file) {
            self::assertStringNotContainsString(
                "'" . StepTiming::UNLISTED . "'",
                $this->code($file),
                $file . ' passes the placeholder as though it were a step'
            );
        }
    }

    /**
     * Only `StepTiming` writes the timing keys into a cursor.
     *
     * The same shape as `ProgressFollowsTheWorkTest`'s rule about progress:
     * a second writer of `running` or `timings` would be a second definition
     * of what those keys mean, and the one that is wrong would be invisible.
     *
     * `stalled` is included from this round — it is the key that has to
     * survive a process that stops existing, so a second writer of it would
     * be a second answer to «where did the last attempt stop».
     */
    public function testOnlyStepTimingWritesTheTimingKeys(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            if (in_array($relative, self::NOT_CURSOR_WRITERS, true)) {
                continue;
            }
            $code = $this->code($relative);
            foreach (["'running'", "'running_unit'", "'running_since'", "'timings'", "'worst'", "'stalled'"] as $key) {
                // A READ is fine and is how the page prints them; a write
                // into an array literal with `=>` is not.
                if (preg_match('~' . preg_quote($key, '~') . '\s*=>~', $code) === 1) {
                    $offenders[] = $relative . ' writes ' . $key;
                }
            }
        }
        self::assertSame([], $offenders);
    }

    /**
     * The exemption above is about labels, and this holds it to that.
     *
     * If `OperationsMessages` ever grew a cursor — a repository, a database
     * handle, or a checkpoint call — the exemption would be hiding a real
     * second writer. So the file is required to stay what it is.
     */
    public function testTheExemptedMessageFileStillTouchesNoCursor(): void
    {
        $code = $this->code('src/Modules/Operations/Presentation/OperationsMessages.php');
        foreach (['wpdb', 'Repository', 'checkpoint', 'cursor'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $code,
                'the label table may not become a cursor writer: ' . $forbidden
            );
        }
    }
}
