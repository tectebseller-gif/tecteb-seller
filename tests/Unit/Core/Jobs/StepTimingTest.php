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
     * Nothing a product carries can be recorded, because the type will not
     * hold it.
     *
     * Four attempts, each one a real field from this very import path.
     */
    public function testNothingSensitiveCanBeRecordedThroughTheStepName(): void
    {
        $attempts = [
            'آمبوبگ سیلیکونی سینا طب' => '',
            '/home/user/public_html/wp-content/uploads/2026/10/photo.jpg'
                => 'home_user_public_html_wp-content_u',
            'https://tecteb.com/?p=9343&key=secret' => 'https_tecteb_com_p_9343_key_secre',
            '۱۲٫۵ تومان' => '',
        ];
        foreach ($attempts as $hostile => $_) {
            $timing = new StepTiming();
            $timing->entering($hostile, 9343);
            $key = $timing->running();
            self::assertMatchesRegularExpression(
                '~^[a-z0-9_]{0,32}$~',
                $key,
                'whatever went in, what is stored is a machine key: ' . $key
            );
            self::assertLessThanOrEqual(StepTiming::NAME_LIMIT, strlen($key));
            // And specifically: not one character of Persian, and no slash,
            // colon, dot, question mark or ampersand survives.
            self::assertSame(
                $key,
                (string) preg_replace('~[^a-z0-9_]+~', '', $key),
                'no separator and no non-ASCII character may be stored'
            );
        }
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
