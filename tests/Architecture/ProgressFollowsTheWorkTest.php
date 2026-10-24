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

    /** The same code with every run of whitespace removed. */
    private function squeeze(string $code): string
    {
        return (string) preg_replace('/\\s+/', '', $code);
    }

    /**
     * Every handler takes all three, or the runner cannot hand it one.
     *
     * The gate is the second argument for the same reason the checkpoint is
     * the first: a handler that does not take it cannot be given it, and the
     * runner has no way to notice — it would simply pass `null` to a parameter
     * that is not there and the handler would write unheld. The budget is the
     * third for the same reason again: a handler that cannot be told how long
     * the request has runs until something kills it.
     *
     * Asserted on the SQUEEZED source, so whether a signature is written on
     * one line or five is not part of the rule — the parameters are.
     */
    public function testEveryJobHandlerAcceptsTheCheckpointTheLeaseGateAndTheBudget(): void
    {
        $handlers = [
            'src/Modules/Migration/Application/DokanImportJob.php',
            'src/Modules/Operations/Application/DeliverEventsJob.php',
        ];
        foreach ($handlers as $file) {
            self::assertStringContainsString(
                'publicfunctionstep(Job$job,?\Closure$checkpoint=null,'
                    . '?\Closure$underLease=null,?JobBudget$budget=null):JobBatch',
                $this->squeeze($this->code($file)),
                $file . ' must accept the checkpoint, the lease gate and the time budget'
            );
        }
    }

    /**
     * The time question comes BEFORE the claim, in every phase.
     *
     * ⛔ Order, not presence. Claiming a unit raises its attempt count, so a
     * unit asked about after being claimed would be three strikes from being
     * stepped over for a decision that was never about it — and the step-over
     * names rows, which is how an innocent product gets blamed. The same
     * mistake the whole `page_tries` separation exists to avoid, one layer up.
     */
    public function testTheImportAsksAboutTimeBeforeItClaimsAUnit(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        foreach (
            [
                'importVendorPage' => 'vendorsAfter',
                'importProductPage' => 'productsAfter',
                'importOrderPage' => 'ordersAfter',
            ] as $method => $_reader
        ) {
            $body = substr($code, (int) strpos($code, 'function ' . $method));
            $loop = substr($body, (int) strpos($body, 'foreach ('));
            $asked = strpos($loop, 'keepGoing()');
            $claimed = strpos($loop, '->claim(');
            self::assertIsInt($asked, $method . ' must ask whether there is time for another unit');
            self::assertIsInt($claimed, $method . ' must claim its units');
            self::assertLessThan(
                $claimed,
                $asked,
                $method . ' must ask about time BEFORE claiming, or the claim counts an attempt nobody made'
            );
        }
    }

    /**
     * EVERY STEP IS NAMED BEFORE IT RUNS.
     *
     * ⛔ This test exists because its absence was found by falsification:
     * moving `entering('attach')` to AFTER the write it names left every
     * other test in this file green. And a marker written after its step is
     * not a marker — the failure it is for is a process that stops existing,
     * which writes nothing afterwards. The same rule
     * `DisposableOnlyToolsTest` applies to its refusal: presence is not
     * enough, the ORDER is the property.
     *
     * Byte offsets inside each method, on the comment-stripped source.
     */
    public function testEveryStepIsNamedBeforeItRuns(): void
    {
        $cases = [
            // file, method, the marker, the work it must come before
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'importProductPage',
                "entering('judge'", '$this->judgeProduct($product)'],
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'importProductPage',
                "entering('row_and_manifest'", '$progress->writing($make)'],
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'carryImage',
                "entering('attach'", '$progress->writing('],
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'importProductPage',
                "entering('page_read'", '$this->dokan->productsAfter('],
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'importVendorPage',
                "entering('page_read'", '$this->dokan->vendorsAfter('],
            ['src/Modules/Migration/Application/ImportFromDokan.php', 'importOrderPage',
                "entering('page_read'", '$this->dokan->ordersAfter('],
            // And the two halves of the slow work, in the library that does it.
            ['src/Modules/Product/Infrastructure/WordPress/WpProductImages.php', 'duplicateForVendor',
                "\$say('image_file_copy')", 'wp_unique_filename('],
            ['src/Modules/Product/Infrastructure/WordPress/WpProductImages.php', 'duplicateForVendor',
                "\$say('image_metadata')", 'wp_generate_attachment_metadata('],
        ];
        foreach ($cases as [$file, $method, $marker, $work]) {
            $body = substr($this->code($file), (int) strpos($this->code($file), 'function ' . $method));
            $named = strpos($body, $marker);
            $ran = strpos($body, $work);
            self::assertIsInt($named, $method . ' must name the step: ' . $marker);
            self::assertIsInt($ran, $method . ' must do the work: ' . $work);
            self::assertLessThan(
                $ran,
                $named,
                $method . ': ' . $marker . ' must come BEFORE ' . $work
                    . ' — a marker written afterwards cannot survive the process it is for'
            );
        }
    }

    /**
     * A batch that stopped itself says so where somebody reads it.
     *
     * ⛔ In the CURSOR, not in `JobBatch::notes()` — the runner drops notes,
     * which is the `alpha.72` finding, and a reason nobody reads is not a
     * reason. And `more` has to be true, or a budget that ran out on the last
     * page of a phase would read as «the phase is finished».
     */
    public function testStoppingForTimeKeepsTheJobResumableAndSaysWhy(): void
    {
        $import = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        // ⛔ Per METHOD, not over the whole file. The first version searched
        // the file and the three phases each carry this line — so a mutation
        // that took it out of the PRODUCTS phase still found the vendor
        // phase's copy and the test passed. The substring trap of `alpha.33`,
        // in a test written to catch exactly this.
        foreach (['importVendorPage', 'importProductPage', 'importOrderPage'] as $method) {
            self::assertStringContainsString(
                "\$stopped !== '' || count(\$page) >= \$limit",
                $this->methodBody($import, $method),
                $method . ': a page the batch stopped itself in is not the end of its phase'
            );
        }
        $handler = $this->code('src/Modules/Migration/Application/DokanImportJob.php');
        self::assertStringContainsString(
            'stoppedBecause(',
            $handler,
            'the reason has to reach the cursor, because the runner drops notes'
        );
        self::assertStringContainsString(
            "unset(\$cursor['stopped'])",
            $handler,
            "and it has to be cleared, or a finished request's reason sits on the page for ever"
        );
    }

    /**
     * Only `StepTiming` writes the timing keys.
     *
     * The privacy guarantee for this round is structural: a step name is
     * reduced to `[a-z0-9_]` on its way in, and a unit is an `int`. That holds
     * only while everything goes through the one class that does the
     * reduction — a second writer putting its own strings into `timings` would
     * be a field a title could reach.
     */
    public function testOnlyStepTimingWritesTheTimingKeys(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            if (str_ends_with($file, 'Core/Jobs/StepTiming.php')) {
                continue;
            }
            $code = $this->code($file);
            foreach (["'running_unit'", "'running_since'", "'timings'", "'worst'"] as $key) {
                if (str_contains($code, $key)) {
                    $offenders[] = $file . ' → ' . $key;
                }
            }
        }
        self::assertSame([], $offenders, "only StepTiming may write the timing keys:\n" . implode("\n", $offenders));
    }

    /**
     * And the reduction is really in the path, both ways.
     *
     * In on `entering()`, and again in the constructor — because a cursor is
     * read back from the database and whatever is in it was last written by
     * some other build.
     */
    public function testTheStepNameIsReducedOnTheWayInAndOnTheWayBack(): void
    {
        $code = $this->code('src/Core/Jobs/StepTiming.php');
        self::assertStringContainsString(
            '$this->running = self::key($step);',
            $code,
            'a step starting must have its name reduced'
        );
        self::assertStringContainsString(
            '~[^a-z0-9_]+~',
            $code,
            'the reduction is what makes the privacy guarantee structural'
        );
        self::assertStringContainsString(
            'self::NAME_LIMIT',
            $code,
            'and it is bounded, so a long string cannot ride in as a name'
        );
        self::assertStringContainsString(
            '~^\d+:([a-z0-9_]{1,32}):\d+$~',
            $code,
            'a record read back from a cursor is shape-checked, not trusted'
        );
        // ⛔ And the shape is no longer the whole of the guarantee. Reduction
        // left a SKU, a slug or a path's tail nearly intact, so the name is
        // also held to a closed list — on the way in and on the way back.
        // `StepNamesComeFromTheListTest` holds the call sites to the same
        // list; this holds the class that stores them.
        self::assertStringContainsString(
            'in_array($key, self::STEPS, true) ? $key : self::UNLISTED',
            $code,
            'a name that is not on the list must not be stored as given'
        );
        self::assertSame(
            3,
            preg_match_all('~in_array\(\$parts\[1\], self::STEPS, true\)~', $code),
            'all three records read back from a cursor — the window, the worst and the stall — are checked against the list'
        );
    }

    /**
     * One method's body, from its signature to the next method's.
     *
     * Needed because several of these rules are about ONE phase and the file
     * has three that read alike — searching the whole file finds a sibling's
     * copy and reports a property the method under test does not have.
     */
    private function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method);
        self::assertIsInt($start, $method . ' must exist');
        $rest = substr($code, $start + 1);
        // ⛔ The next METHOD, at its own indentation — not the next `function`,
        // which in `importProductPage` is the `$landed` closure a few lines in.
        // The first version used that and sliced the body off before its own
        // return statement, then failed about a property the method has.
        if (preg_match('~\n    (?:public|private|protected)(?: static)? function ~', $rest, $_m, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, (int) $_m[0][1]);
        }
        return $rest;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root = (string) realpath(__DIR__ . '/../../src');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(
                    str_replace((string) realpath(__DIR__ . '/../../'), '', (string) $file->getRealPath()),
                    '/'
                );
            }
        }
        sort($files);
        return $files;
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
     * The attach happens INSIDE the hold, not after an answer about it.
     *
     * ⛔ **Rewritten, not loosened — and the rule it replaces was the defect.**
     * `alpha.73` asserted exactly this ordering:
     *
     *     duplicateForVendor() → stillOurs() → saveImages()
     *
     * which is CHECK and then WRITE, two round trips with a gap between them,
     * and a test that pinned the gap in place. Measured on `alpha.73`'s own
     * installed bytes: a worker parked in that gap had its checkpoint refused
     * a moment later — `outcome:lease_lost` — and had already replaced the
     * picture a second worker attached, leaving that worker's copy in the
     * shop's media library with nothing pointing at it
     * (`orphan_copies=1`). So the question was REMOVED rather than kept
     * beside the gate (`alpha.69`), and what is asserted now is that the
     * write cannot happen except inside the unit of work that validates it.
     */
    public function testTheImageIsAttachedOnlyInsideTheHoldThatValidatesTheLease(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        $carry = substr($code, (int) strpos($code, 'function carryImage'));
        $carry = substr($carry, 0, (int) strpos($carry, 'function handBack'));

        // The decoy is gone. A question that reads like a guard beside a real
        // guard is how this gap came to exist.
        self::assertStringNotContainsString(
            '$progress->stillOurs()',
            $carry,
            'the question was replaced by the gate, not kept next to it'
        );

        $copy = strpos($carry, 'duplicateForVendor(');
        $hold = strpos($carry, '$progress->writing(');
        self::assertIsInt($copy);
        self::assertIsInt($hold, 'the attach must run under a held lease');
        self::assertLessThan($hold, $copy, 'the slow half stays OUTSIDE the hold');

        // Exactly two attaches, and each one accounted for: the first belongs
        // to the path that has no lease at all, the second is the one inside
        // the hold. A third would be an attach nobody can place.
        $attaches = [];
        $at = 0;
        while (($at = strpos($carry, '$this->products->saveImages(', $at)) !== false) {
            $attaches[] = $at;
            $at++;
        }
        self::assertCount(2, $attaches, 'one for the no-lease path, one under the hold');
        $noLease = strpos($carry, 'if ($progress === null)');
        self::assertIsInt($noLease);
        self::assertLessThan($attaches[0], $noLease, 'the bare attach is the no-lease path');

        // ⛔ AND «INSIDE» MEANS INSIDE, which position alone cannot say.
        // The first version of this test asserted only that the attach came
        // AFTER the hold began, and a mutation that handed the gate an empty
        // closure and then attached on the next line PASSED it — the very
        // shape `alpha.73` shipped. So the attach must BE the closure the gate
        // is handed, asserted on the code with its whitespace squeezed out.
        self::assertStringContainsString(
            '$progress->writing(fn():bool=>$this->products->saveImages($productId,[$ownCopy],$ownCopy)',
            $this->squeeze($carry),
            'the attach is the held closure itself, not a statement after it'
        );

        // All three conditions of `alpha.42`, together: we made it, the vendor
        // owns it, and nothing points at it.
        self::assertStringContainsString('mediaInUse($ownCopy)', $code);
        self::assertStringContainsString('discard($ownCopy, $vendorUserId)', $code);
    }

    /**
     * And the row and its manifest entry are one unit too.
     *
     * The other window the report named is between `claim(unit)` and
     * `create()`. On `alpha.73` the UNIQUE key `tmc_product_wc` happened to
     * refuse the stale worker's insert, so no duplicate appeared — the SCHEMA
     * held it, not the lease. That is worth keeping true on purpose rather
     * than by luck, because the day that index goes nothing else is watching.
     */
    public function testTheRowAndItsManifestEntryAreWrittenUnderTheSameHold(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        $page = substr(
            $code,
            (int) strpos($code, 'function importProductPage'),
            (int) strpos($code, 'function importGap') - (int) strpos($code, 'function importProductPage')
        );
        $make = strpos($page, '$make = function ()');
        $hold = strpos($page, '$progress->writing($make)');
        self::assertIsInt($make, 'the row goes in a closure, not straight out');
        self::assertIsInt($hold, 'the row must be made under a held lease');
        // ⛔ Sliced from the closure, not searched across the whole method:
        // the repair path a few hundred lines up writes a manifest entry of
        // its own, and a plain `strpos` over the method finds THAT one first —
        // which is how the first version of this test read the order
        // backwards and failed about code that was right.
        $closure = substr($page, (int) $make, (int) $hold - (int) $make);
        $create = strpos($closure, '$this->products->create(');
        $manifest = strpos($closure, '$this->rememberRun($runId');
        self::assertIsInt($create, 'the row is written inside the closure');
        self::assertIsInt($manifest, 'and the manifest entry with it');
        self::assertLessThan($manifest, $create, 'the row first, then what names it');
    }

    /**
     * Nothing slow may go inside a hold.
     *
     * The lock is on a row every worker of this job type needs, so a file copy
     * or a `wp_generate_attachment_metadata()` in there would make one import
     * able to stall every other worker and every hand-over. Asserted on the
     * bytes of the two closures that the gate is actually handed.
     */
    public function testTheHeldClosuresContainNothingSlow(): void
    {
        $code = $this->code('src/Modules/Migration/Application/ImportFromDokan.php');
        $carry = substr($code, (int) strpos($code, 'function carryImage'));
        $carry = substr($carry, 0, (int) strpos($carry, 'function handBack'));
        $held = substr($carry, (int) strpos($carry, '$progress->writing('));
        foreach (['duplicateForVendor', 'wp_generate_attachment_metadata', 'productsAfter', 'vendorsAfter'] as $slow) {
            self::assertStringNotContainsString(
                $slow,
                $held,
                $slow . ' must not run while the job row is held'
            );
        }
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
