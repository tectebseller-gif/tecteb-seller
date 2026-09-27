<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRowVersion;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0020ProductTitleSort;
use Tecteb\Marketplace\Modules\Product\Infrastructure\TitleSortRepair;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * WHAT THIS PROVES: a repair that did not happen is not a repair that happened,
 * and a key rebuilt from a title nobody has any more is not a repair at all.
 *
 * `alpha.34` shipped `TitleSortRepair` with two holes the owner found by
 * reading it:
 *
 *   1. **A failed write ended the sweep as a success.** `rewrite()` answered
 *      with a COUNT, so the one thing the caller could not learn from it was
 *      that a row had been refused. `sweep()` then moved the cursor to the last
 *      id of the batch — past the row that was never written — and, when the
 *      batch came back short, recorded `finished` and stamped the new
 *      `PersianCollation::BUILD` as the format every key in the table was now
 *      in. The row stayed wrong and nothing would ever look at it again: the
 *      build matches, so no sweep opens, and the rotating audit is the only
 *      thing left. `audit()` had the same shape in miniature — a refusal on its
 *      first row left `$repaired === 0`, which it reported as `clean`.
 *      The in-code comment even promised the opposite («the cursor does not
 *      advance past this batch's start»), which is the strongest evidence there
 *      is that the two halves were never read together.
 *   2. **The write was keyed on `id` alone.** The title and its key are read,
 *      then written back in a separate statement. A vendor saving that product
 *      in the gap — the ordinary path, which maintains the key itself — has
 *      their new key replaced by the key of the name the product used to have.
 *      The repair would then have caused exactly the staleness it exists to
 *      remove.
 *
 * Every test here was written against the `alpha.34` bytes first and failed
 * there; `tools/alpha34-reproduction.sh` runs them against those bytes from git
 * so the reproduction is repeatable rather than remembered.
 *
 * Nothing here sleeps or races. `FailingDatabase` refuses a named statement by
 * occurrence, and `InterferingDatabase` runs a callback immediately before one
 * — so «the row moved between the SELECT and the UPDATE» happens in the same
 * order on every run.
 */
final class TitleSortRepairFailureTest extends DatabaseTestCase
{
    /** Small enough to fill several batches without seeding thousands of rows. */
    private const BATCH = TitleSortRepair::SWEEP;

    private WpDatabase $real;
    private DbProductRepository $products;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->real = new WpDatabase($this->wpdb);
        $this->resetSchema($this->real);
        $this->products = new DbProductRepository($this->real, new SystemClock());
        $this->table = $this->wpdb->prefix . 'tmc_products';
    }

    // ------------------------------------------------- 1. a refused write

    /**
     * WHAT THIS PROVES: the first row of a batch being refused does not let the
     * cursor past it, does not close the sweep, and does not stamp the build.
     *
     * @dataProvider positionsInABatch
     */
    public function testARefusedWriteStopsTheSweepWhereverItHappens(int $failAt, int $rows): void
    {
        $ids = $this->seed($rows);
        $this->breakEveryKey();
        $this->markBuild(1);                 // so a sweep opens for a real reason

        $db = new FailingDatabase($this->real);
        $db->failWhen(['UPDATE', M0020ProductTitleSort::COLUMN], $failAt);
        $result = $this->repairOn($db)->run();

        self::assertNotSame('finished', $result['state'], 'a refused write is not a finished sweep');
        self::assertSame($failAt - 1, $result['repaired'], 'only the rows written before the refusal count');
        self::assertCount(1, $db->refused, 'the fixture refused exactly one statement');

        $status = $this->repairState();
        self::assertTrue($status['open'], 'the sweep stays open');
        self::assertNotSame(PersianCollation::BUILD, $status['build'], 'and the build is not stamped');
        self::assertSame(
            $failAt === 1 ? 0 : $ids[$failAt - 2],
            $status['cursor'],
            'the cursor stops at the last row that was actually written'
        );

        // The row that was refused, and every row after it, is still wrong.
        self::assertSame('x', $this->key($ids[$failAt - 1]), 'the refused row was not written');
        self::assertSame($rows - ($failAt - 1), $this->staleCount(), 'nor was anything past it');
    }

    /** @return iterable<string,array{int,int}> failAt, rows */
    public static function positionsInABatch(): iterable
    {
        // A FULL batch and a SHORT last batch, and within each the beginning,
        // the middle and the end — the short batch being the one that used to
        // record `finished`.
        yield 'short batch, first row'  => [1, 5];
        yield 'short batch, middle row' => [3, 5];
        yield 'short batch, last row'   => [5, 5];
        yield 'full batch, first row'   => [1, self::BATCH];
        yield 'full batch, middle row'  => [(int) (self::BATCH / 2), self::BATCH];
        yield 'full batch, last row'    => [self::BATCH, self::BATCH];
    }

    /**
     * WHAT THIS PROVES: the audit does not answer «clean» about a row it failed
     * to repair, and does not walk past it either.
     *
     * The audit is the only net under a staleness with no other symptom, so an
     * audit that loses a pending repair loses it for good.
     */
    public function testARefusedWriteInTheAuditIsNotReportedAsClean(): void
    {
        $ids = $this->seed(3);
        $this->settle();
        self::assertSame('clean', $this->repairOn($this->real)->run()['state'], 'settled first');

        $this->breakKeyOf($ids[1]);          // the middle row, so neither edge is special

        $db = new FailingDatabase($this->real);
        $db->failWhen(['UPDATE', M0020ProductTitleSort::COLUMN], 1);
        $result = $this->repairOn($db)->run();

        self::assertNotSame('clean', $result['state'], 'a refused repair is not a clean table');
        self::assertSame(0, $result['repaired']);
        self::assertSame(1, $this->staleCount(), 'the row is still wrong');

        // And the repair is not lost: the next request, with the database
        // working again, finds and fixes it.
        $this->repairOn($this->real)->run();
        self::assertSame(0, $this->staleCount(), 'the pending repair survived the refusal');
    }

    /**
     * WHAT THIS PROVES: a retry finishes the table with no gap and no row
     * counted twice.
     *
     * The second half of «stop on failure» — stopping is only useful if
     * resuming lands exactly where it stopped.
     */
    public function testAfterTheFailureIsLiftedTheRetryFinishesWithoutDoubleCounting(): void
    {
        $rows = self::BATCH + 7;             // one full batch and a short one
        $this->seed($rows);
        $this->breakEveryKey();
        $this->markBuild(1);

        $db = new FailingDatabase($this->real);
        $db->failWhen(['UPDATE', M0020ProductTitleSort::COLUMN], 4);
        $repair = $this->repairOn($db);

        $first = $repair->run();
        self::assertSame(3, $first['repaired'], 'three rows written, then the refusal');
        self::assertTrue($this->repairState()['open']);

        $db->stopFailing();
        $passes = 1;
        $total = $first['repaired'];
        for ($i = 0; $i < 10 && $this->repairState()['open']; $i++) {
            $next = $repair->run();
            $total += $next['repaired'];
            $passes++;
        }

        self::assertFalse($this->repairState()['open'], "the sweep closed after {$passes} passes");
        self::assertSame(PersianCollation::BUILD, $this->repairState()['build'], 'and stamped the build');
        self::assertSame(0, $this->staleCount(), 'every row is right');
        self::assertSame(
            $rows,
            $total,
            'each row was repaired exactly once across the retry — no row recounted, none skipped'
        );
    }

    /**
     * WHAT THIS PROVES: `0` from `execute()` is not a failure, and the two are
     * still told apart.
     *
     * `alpha.8`'s rule — zero changed rows is a SUCCESS — has to survive this
     * change, or the repair would stop dead on every row it had nothing to do
     * to.
     */
    public function testZeroChangedRowsIsNotTreatedAsAFailure(): void
    {
        $ids = $this->seed(3);
        $this->settle();

        // Every key already correct: the repair writes nothing at all and must
        // still report a clean, closed table rather than an unresolved one.
        $result = $this->repairOn($this->real)->run();
        self::assertSame('clean', $result['state']);
        self::assertSame(0, $result['repaired'], 'nothing needed writing');
        self::assertFalse($this->repairState()['open'], 'and nothing was left open');
        self::assertSame(0, $this->staleCount());
        self::assertSame(3, count($ids));
    }

    // ------------------------------------- 2. somebody else saved meanwhile

    /**
     * WHAT THIS PROVES: the defect the owner named — a title saved through the
     * ordinary path between the repair's SELECT and its UPDATE keeps its own
     * key.
     *
     * The interference is scheduled, not raced for: `InterferingDatabase` runs
     * the vendor's save immediately before the repair's write, in the same
     * process, every run.
     */
    public function testAConcurrentSaveKeepsItsOwnKeyAndItsOwnTitle(): void
    {
        $id = $this->create('آمبو بگ');
        $this->settle();
        $this->breakKeyOf($id);              // so the repair has a reason to write

        $db = new InterferingDatabase($this->real);
        $db->before(['UPDATE', M0020ProductTitleSort::COLUMN], 1, function () use ($id): void {
            // The ordinary save path, which maintains `title_sort` itself.
            // `UNGUARDED` because this fixture is not testing the row-version
            // counter: an empty token is a REFUSAL since `alpha.15`, so passing
            // one would have the fixture write nothing and the test pass about
            // a save that never happened.
            self::assertTrue(
                $this->products->updateDetails($id, $this->details('ویلچر تاشو'), ProductRowVersion::UNGUARDED),
                'the interfering save must actually land'
            );
        });
        $result = $this->repairOn($db)->run();

        self::assertCount(1, $db->fired, 'the interference really happened');
        self::assertSame('ویلچر تاشو', $this->title($id), 'the repair never writes the title');
        self::assertSame(
            PersianCollation::sortKey('ویلچر تاشو'),
            $this->key($id),
            'and the new key stands — the old name\'s key was not written over it'
        );
        self::assertSame(0, $result['repaired'], 'a row somebody else fixed is not our repair');
        self::assertSame(0, $this->staleCount());
    }

    /**
     * WHAT THIS PROVES: a row that moved and is STILL wrong is left for the
     * next request rather than passed over or miscounted.
     *
     * The other half of the same guard: the write is refused because the
     * snapshot is stale, and the row is genuinely unresolved.
     */
    public function testARowThatMovedAndIsStillWrongStaysUnresolved(): void
    {
        $ids = $this->seed(3);
        $this->settle();
        $this->breakEveryKey();
        $this->markBuild(1);

        $moved = $ids[1];
        $db = new InterferingDatabase($this->real);
        // Before the write that would fix the SECOND row, somebody changes that
        // row's title and leaves a key that is wrong for the new title too.
        $db->before(['UPDATE', M0020ProductTitleSort::COLUMN], 2, function () use ($moved): void {
            $this->real->execute(
                'UPDATE `' . $this->table . '` SET title = %s, `' . M0020ProductTitleSort::COLUMN . '` = %s WHERE id = %d',
                ['ترالی اورژانس', 'still-wrong', $moved]
            );
        });
        $result = $this->repairOn($db)->run();

        self::assertCount(1, $db->fired);
        self::assertSame('ترالی اورژانس', $this->title($moved), 'the title is whoever wrote it last');
        self::assertSame('still-wrong', $this->key($moved), 'the stale snapshot was not written');
        self::assertSame(1, $result['repaired'], 'only the first row counted');
        self::assertTrue($this->repairState()['open'], 'the sweep is still open');
        self::assertSame($ids[0], $this->repairState()['cursor'], 'and stopped before the row that moved');

        // The next request re-reads it and puts it right, from the title it has
        // now — bounded, one row at a time, no loop.
        $this->repairOn($this->real)->run();
        self::assertSame(PersianCollation::sortKey('ترالی اورژانس'), $this->key($moved));
        self::assertSame('ترالی اورژانس', $this->title($moved), 'and still nobody rewrote the title');
    }

    /**
     * WHAT THIS PROVES: the guard compares BYTES, so no collation can decide
     * two different titles are the same row state.
     *
     * The title column is `utf8mb4_unicode_520_ci`, which equates strings that
     * differ in case AND in accents. The pair matters: «MASK N2» would have
     * proved nothing, because `sortKey()` lowercases and both spellings produce
     * the same key — the assertion could not have failed. «Café» and «Cafe» are
     * equal to that collation and produce DIFFERENT keys, so a guard written as
     * a plain `=` really does write the wrong one.
     *
     * Measured on this MariaDB: `'Cafe' = 'Café' COLLATE utf8mb4_unicode_520_ci`
     * is 1, while `sortKey('Cafe')` is `2cccacfce` and `sortKey('Café')` is
     * `2cccacfd0e9`.
     */
    public function testTheGuardIsNotFooledByACaseInsensitiveCollation(): void
    {
        $id = $this->create('Café');
        $this->settle();
        $this->breakKeyOf($id);
        self::assertSame(
            '1',
            (string) $this->real->getVar("SELECT 'Cafe' = 'Café' COLLATE utf8mb4_unicode_520_ci"),
            'the premise: this collation really does equate the two titles'
        );
        self::assertNotSame(
            PersianCollation::sortKey('Café'),
            PersianCollation::sortKey('Cafe'),
            'and the premise that their keys really do differ'
        );

        $db = new InterferingDatabase($this->real);
        $db->before(['UPDATE', M0020ProductTitleSort::COLUMN], 1, function () use ($id): void {
            self::assertTrue(
                $this->products->updateDetails($id, $this->details('Cafe'), ProductRowVersion::UNGUARDED),
                'the interfering save must actually land'
            );
        });
        $this->repairOn($db)->run();

        self::assertSame('Cafe', $this->title($id));
        self::assertSame(
            PersianCollation::sortKey('Cafe'),
            $this->key($id),
            'the collation says these titles are equal; the guard must not'
        );
    }

    // ------------------------------------------------------------ helpers

    private function repairOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $db): TitleSortRepair
    {
        return new TitleSortRepair($db, new WpOptionStore());
    }

    /** @return list<int> the ids, in ascending order */
    private function seed(int $rows): array
    {
        $ids = [];
        for ($i = 1; $i <= $rows; $i++) {
            $ids[] = $this->create(sprintf('ماسک %d', $i));
        }
        sort($ids);
        return $ids;
    }

    private function create(string $title): int
    {
        return $this->products->create(7, $this->details($title), ProductStatus::Draft);
    }

    private function details(string $title): ProductDetails
    {
        return new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3);
    }

    /**
     * Bring the repair to rest, so a test about one trigger is not measuring
     * another one. Uses the REAL database: a fixture must not be the thing
     * under test.
     */
    private function settle(): void
    {
        $repair = $this->repairOn($this->real);
        for ($i = 0; $i < 20; $i++) {
            $status = $repair->status();
            if (!$status['open'] && $status['build'] === PersianCollation::BUILD) {
                return;
            }
            $repair->run();
        }
        self::fail('the repair never settled, which is a defect and not a fixture problem');
    }

    /** Every key wrong at once — the shape a format change leaves behind. */
    private function breakEveryKey(): void
    {
        $written = $this->real->execute(
            'UPDATE `' . $this->table . '` SET `' . M0020ProductTitleSort::COLUMN . "` = 'x'"
        );
        self::assertNotNull($written, 'the fixture itself must write');
    }

    private function breakKeyOf(int $id): void
    {
        $written = $this->real->execute(
            'UPDATE `' . $this->table . '` SET `' . M0020ProductTitleSort::COLUMN . "` = 'x' WHERE id = %d",
            [$id]
        );
        self::assertSame(1, $written, 'the fixture must break exactly the row it names');
    }

    private function markBuild(int $build): void
    {
        $options = new WpOptionStore();
        $state = $options->get(TitleSortRepair::OPTION, []);
        $state = is_array($state) ? $state : [];
        $state['build'] = $build;
        $state['reason'] = '';
        $state['cursor'] = 0;
        $options->set(TitleSortRepair::OPTION, $state);
    }

    /** @return array<string,mixed> */
    private function repairState(): array
    {
        return $this->repairOn($this->real)->status();
    }

    private function key(int $id): string
    {
        return (string) $this->real->getVar(
            'SELECT `' . M0020ProductTitleSort::COLUMN . '` FROM `' . $this->table . '` WHERE id = %d',
            [$id]
        );
    }

    private function title(int $id): string
    {
        return (string) $this->real->getVar('SELECT title FROM `' . $this->table . '` WHERE id = %d', [$id]);
    }

    private function staleCount(): int
    {
        $stale = 0;
        foreach ($this->real->getResults(
            'SELECT title, `' . M0020ProductTitleSort::COLUMN . '` AS k FROM `' . $this->table . '`'
        ) as $row) {
            if (PersianCollation::sortKey((string) $row['title']) !== (string) $row['k']) {
                $stale++;
            }
        }
        return $stale;
    }
}
