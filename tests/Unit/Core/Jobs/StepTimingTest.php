<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Core\Jobs;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Jobs\StepTiming;

/**
 * WHAT THIS PROVES: the step record names where a worker was, measures what
 * finished, and CANNOT carry anything sensitive.
 *
 * The instruction for this round says «اطلاعات حساس ثبت نشود». A sentence in
 * a docblock is not that; these tests are. They try to put a product title, a
 * file path, an address and an amount into the one field that takes a string,
 * and check that what comes out is a machine key.
 */
final class StepTimingTest extends TestCase
{
    public function testAFreshRecordHasNothingToSay(): void
    {
        $timing = new StepTiming();
        self::assertSame('', $timing->running());
        self::assertSame([], $timing->finished());
        self::assertSame(
            [],
            $timing->fragment(),
            'a cursor must not grow by four keys for a batch that recorded nothing'
        );
    }

    public function testTheStepInFlightIsNamedBeforeItFinishes(): void
    {
        $timing = new StepTiming();
        $timing->entering('image_metadata', 9343, 1_700_000_000);
        self::assertSame('image_metadata', $timing->running());
        self::assertSame(9343, $timing->runningUnit());
        self::assertSame(1_700_000_000, $timing->runningSince());
        self::assertSame(
            ['running' => 'image_metadata', 'running_unit' => 9343, 'running_since' => 1_700_000_000],
            $timing->fragment(),
            'this is the only record that can survive the process it is about'
        );
    }

    public function testAFinishedStepIsMeasuredAndTheFlightIsOver(): void
    {
        $timing = new StepTiming();
        $timing->entering('judge', 9343);
        $ms = $timing->left();

        self::assertGreaterThanOrEqual(0.0, $ms);
        self::assertSame('', $timing->running());
        self::assertCount(1, $timing->finished());
        self::assertMatchesRegularExpression('~^9343:judge:\d+$~', $timing->finished()[0]);
        self::assertArrayNotHasKey('running', $timing->fragment());
    }

    /**
     * Leaving without entering has measured nothing.
     *
     * Inventing a duration for it would put a number in the table that no
     * step produced, which is worse than an empty table.
     */
    public function testLeavingWithoutEnteringRecordsNothing(): void
    {
        $timing = new StepTiming();
        self::assertSame(0.0, $timing->left());
        self::assertSame([], $timing->finished());
    }

    /**
     * Starting a step ENDS the one in flight, with its real duration.
     *
     * ⛔ The first version marked it «abandoned» with a duration of nought,
     * and the cost was measured: the file copy is always followed by the
     * metadata, so it never once had a number against it — and on
     * network-backed storage that is the half most worth knowing about. Two
     * database tests failed and said so.
     */
    public function testStartingAStepEndsTheOneInFlightWithItsDuration(): void
    {
        $timing = new StepTiming();
        $timing->entering('image_file_copy', 9343);
        $timing->entering('image_metadata', 9343);

        self::assertCount(1, $timing->finished());
        self::assertMatchesRegularExpression(
            '~^9343:image_file_copy:\d+$~',
            $timing->finished()[0],
            'the copy has a real number, not a nought under another name'
        );

        $again = StepTiming::fromCursor($timing->fragment());
        self::assertCount(1, $again->finished(), 'and it reads back, or it is not a record');
        self::assertSame('image_metadata', $again->running(), 'the second is still in flight');
    }

    /**
     * And a worker that stops existing needs no special name.
     *
     * It leaves `running` set and NO record for that step, which is exactly
     * the fact `entering()` exists to leave behind — the step nothing
     * finished.
     */
    public function testAStepNothingFinishedLeavesNoNumberAndKeepsItsName(): void
    {
        $timing = new StepTiming();
        $timing->entering('judge', 9343);
        $timing->left();
        $timing->entering('image_metadata', 9343);
        // …and here the process stops existing. Nothing else runs.

        $again = StepTiming::fromCursor($timing->fragment());
        self::assertSame('image_metadata', $again->running());
        self::assertSame(9343, $again->runningUnit());
        $names = array_map(
            static fn (string $r): string => explode(':', $r, 3)[1],
            $again->finished()
        );
        self::assertSame(['judge'], $names, 'only the step that finished has a number');
    }

    public function testTheSlowestFinishedStepIsNamed(): void
    {
        $timing = StepTiming::fromCursor([
            'timings' => ['11:judge:2', '11:image_metadata:4089', '12:attach:7'],
        ]);
        self::assertSame(['image_metadata', 4089], $timing->slowest());
    }

    public function testWithNothingFinishedThereIsNoSlowest(): void
    {
        self::assertSame(['', 0], (new StepTiming())->slowest());
    }

    /**
     * The worst step survives leaving the window.
     *
     * ⛔ Measured, and it is why this exists: the import's own fixture has
     * twelve products and five steps each, so sixty records went through a
     * window of forty-eight and `page_read` — the one step that happens once
     * a batch, and the one that would be slow if the Dokan READER were the
     * problem — fell off the front every single time.
     */
    public function testTheWorstStepSurvivesFallingOutOfTheWindow(): void
    {
        $timing = StepTiming::fromCursor(['timings' => ['0:page_read:9000']]);
        self::assertSame(['page_read', 9000], $timing->slowest());

        for ($i = 1; $i <= StepTiming::KEPT + 10; $i++) {
            $timing->entering('judge', $i);
            $timing->left();
        }
        self::assertNotContains(
            '0:page_read:9000',
            $timing->finished(),
            'the record itself has left the window, as the bound says it must'
        );
        self::assertSame(
            ['page_read', 9000],
            $timing->slowest(),
            'and the answer to «which step is slow» has not'
        );
        self::assertSame('page_read:9000', $timing->fragment()['worst']);
    }

    /** A cursor from a build without the key still gets the window's worst. */
    public function testAnOlderCursorsWorstIsTakenFromItsWindow(): void
    {
        $timing = StepTiming::fromCursor([
            'timings' => ['11:judge:2', '11:image_metadata:4089', '12:attach:7'],
        ]);
        self::assertSame(['image_metadata', 4089], $timing->slowest());
    }

    /** And a malformed `worst` is ignored rather than half-read. */
    public function testAMalformedWorstIsIgnored(): void
    {
        $timing = StepTiming::fromCursor(['worst' => 'IMAGE METADATA:-4']);
        self::assertSame(['', 0], $timing->slowest());
    }

    /** A cursor is a position, not a log. */
    public function testTheRecordIsBoundedAndKeepsTheNewest(): void
    {
        $timing = new StepTiming();
        for ($i = 1; $i <= StepTiming::KEPT + 5; $i++) {
            $timing->entering('judge', $i);
            $timing->left();
        }
        $finished = $timing->finished();
        self::assertCount(StepTiming::KEPT, $finished);
        self::assertStringStartsWith('6:judge:', $finished[0], 'the oldest five fell off the front');
        self::assertStringStartsWith((string) (StepTiming::KEPT + 5) . ':judge:', end($finished));
    }

    /**
     * NOTHING A PRODUCT CARRIES CAN BE RECORDED — the list sees to that.
     *
     * ⛔ Reduction alone was not enough, and that is the correction this round
     * was asked for: «محدودکردن یک رشته به حروف لاتین اثبات غیرحساس‌بودن آن
     * نیست». A SKU, a slug, an order reference or an e-mail's local part all
     * survive `[a-z0-9_]` and thirty-two characters nearly intact — the second
     * case below used to be stored as `home_user_public_html_wp-content_u`,
     * which is a path, kept in the row, and readable.
     *
     * Now a name that is not one of `STEPS` becomes `UNLISTED`: the fact that
     * SOME step was in flight survives, and nothing about which.
     */
    public function testNothingSensitiveCanBeRecordedThroughTheStepName(): void
    {
        $hostile = [
            'آمبوبگ سیلیکونی سینا طب',
            '/home/user/public_html/wp-content/uploads/2026/10/photo.jpg',
            'https://tecteb.com/?p=9343&key=secret',
            '۱۲٫۵ تومان',
            'SKU-AMB-9343',
            'rezaafchar@example.com',
            'order 1402-77 for 450000 IRT',
            // A sentence that a reduction would have kept word for word.
            'image_metadata for sku AMB 9343',
        ];
        foreach ($hostile as $name) {
            $timing = new StepTiming();
            $timing->entering($name, 9343);
            $key = $timing->running();
            // `UNLISTED`, or nothing at all when the name had no ASCII left
            // to reduce to — the Persian title takes that second path, which
            // is stronger still: there is no record to print.
            self::assertContains(
                $key,
                [StepTiming::UNLISTED, ''],
                'off the list, so nothing of it may be stored: ' . $name
            );
            // And nothing of the original reaches the cursor either — the
            // fragment is the whole of what a row would carry.
            $written = implode('|', array_map(
                static fn (mixed $v): string => is_array($v) ? implode(',', $v) : (string) $v,
                $timing->fragment()
            ));
            foreach (preg_split('~[^A-Za-z0-9]+~', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                // Digits are skipped: the product id IS recorded, on purpose
                // and in its own field, and a hostile string that happens to
                // contain it would otherwise fail a test about step names.
                if (strlen($word) < 3 || ctype_digit($word)) {
                    continue;
                }
                self::assertStringNotContainsStringIgnoringCase(
                    $word,
                    $written,
                    'a word of the caller\'s string survived into the cursor: ' . $word
                );
            }
        }
    }

    /** And a listed name is stored exactly, because that is the whole point. */
    public function testEveryListedStepIsStoredUnderItsOwnName(): void
    {
        foreach (StepTiming::STEPS as $step) {
            $timing = new StepTiming();
            $timing->entering($step, 9343);
            self::assertSame($step, $timing->running());
        }
        // The list is the contract the architecture test holds call sites to,
        // so its contents are asserted here rather than only read.
        self::assertSame(
            [
                'page_read',
                'judge',
                'row_and_manifest',
                'image_file_copy',
                'image_metadata',
                'attach',
                // Writing one shop record — staff, balance row, withdrawal.
                'record_write',
            ],
            StepTiming::STEPS
        );
        self::assertNotContains(StepTiming::UNLISTED, StepTiming::STEPS, 'the placeholder is not a step');
    }

    /**
     * A cursor record naming a step that is on neither list is dropped.
     *
     * The row is a value from the database: it can come from an older build
     * that had a step we have since removed, or from somebody who edited it.
     * Either way it is dropped rather than printed, because this table is read
     * by a person deciding what to do next.
     */
    public function testACursorRecordNamingAnUnlistedStepIsDropped(): void
    {
        $timing = StepTiming::fromCursor([
            'timings' => [
                '9343:image_metadata:4100',
                '9343:sku_amb_9343:12',
                '9343:' . StepTiming::UNLISTED . ':7',
            ],
            'worst' => 'sku_amb_9343:99999',
        ]);
        self::assertSame(
            ['9343:image_metadata:4100', '9343:' . StepTiming::UNLISTED . ':7'],
            $timing->finished()
        );
        self::assertSame(['image_metadata', 4100], $timing->slowest(), 'the unlisted worst is not believed');
    }

    /**
     * A step a previous process opened becomes `stalled`, not `running`.
     *
     * ⛔ Two reasons, and both were asked for. The row must never show a stale
     * step as a live run («مرحلهٔ کهنه … نباید به‌عنوان اجرای زنده نمایش داده
     * شود»), and the next attempt must not erase where the last one stopped —
     * on a host that cuts off every attempt, that would leave exactly one
     * data point however many times the button is pressed.
     */
    public function testARestoredStepBecomesTheStallRecordWhenThisWorkerStarts(): void
    {
        $timing = StepTiming::fromCursor([
            'running' => 'image_metadata',
            'running_unit' => 9343,
            'running_since' => 1_700_000_000,
        ]);
        // Before this process enters anything, the row still reads as it was
        // written: a step opened and never closed.
        self::assertSame('image_metadata', $timing->running());
        self::assertSame(['', 0, 0], $timing->stalled());

        $timing->entering('page_read', 0);
        self::assertSame('page_read', $timing->running(), 'ours is the live one');
        self::assertSame(['image_metadata', 9343, 1_700_000_000], $timing->stalled());

        $fragment = $timing->fragment();
        self::assertSame('page_read', $fragment['running']);
        self::assertSame('image_metadata:9343:1700000000', $fragment['stalled']);
        self::assertSame(
            [],
            $timing->finished(),
            'and no duration is invented for a step this process never timed'
        );
    }

    /** A step THIS process closed properly is not a stall. */
    public function testAStepThatClosedProperlyLeavesNoStallRecord(): void
    {
        $timing = new StepTiming();
        $timing->entering('image_file_copy', 9343);
        $timing->left();
        $timing->entering('image_metadata', 9343);
        self::assertSame(['', 0, 0], $timing->stalled());
        self::assertArrayNotHasKey('stalled', $timing->fragment());
    }

    /** A malformed or unlisted `stalled` is ignored, like every other record. */
    public function testAMalformedStallRecordIsIgnored(): void
    {
        foreach (['image_metadata', 'image_metadata:9343', 'sku_amb:9343:1', ':9343:1', ''] as $bad) {
            $timing = StepTiming::fromCursor(['stalled' => $bad]);
            self::assertSame(['', 0, 0], $timing->stalled(), 'accepted: ' . $bad);
        }
        $good = StepTiming::fromCursor(['stalled' => 'attach:9343:1700000000']);
        self::assertSame(['attach', 9343, 1_700_000_000], $good->stalled());
    }

    public function testAnUnnameableStepIsNotRecordedAtAll(): void
    {
        $timing = new StepTiming();
        $timing->entering('—', 9343);
        self::assertSame(
            '',
            $timing->running(),
            'a name that reduces to nothing is no name, and an empty key in the table would be noise'
        );
        self::assertSame([], $timing->fragment());
    }

    /** A negative unit id is not an id. */
    public function testANegativeUnitIsReadAsNone(): void
    {
        $timing = new StepTiming();
        $timing->entering('judge', -5);
        self::assertSame(0, $timing->runningUnit());
    }

    /**
     * A malformed record from a cursor is dropped, not repaired.
     *
     * A half-read record would be a measurement nobody took, and this cursor
     * is read by a page that prints numbers to a human.
     */
    public function testMalformedRecordsFromACursorAreDropped(): void
    {
        $timing = StepTiming::fromCursor([
            'timings' => [
                '11:judge:2',
                'not a record',
                ['an', 'array'],
                '11:JUDGE:2',
                '11:judge:-2',
                ':judge:2',
                '12:attach:7',
            ],
        ]);
        self::assertSame(['11:judge:2', '12:attach:7'], $timing->finished());
    }

    public function testACursorFromABuildWithoutThisClassReadsAsEmpty(): void
    {
        $timing = StepTiming::fromCursor(
            ['phase' => 'products', 'after' => 9343, 'at' => 0, 'tries' => 0, 'page_tries' => 0]
        );
        self::assertSame('', $timing->running());
        self::assertSame([], $timing->finished());
    }
}
