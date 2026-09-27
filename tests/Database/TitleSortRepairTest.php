<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Infrastructure\DbProductRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0020ProductTitleSort;
use Tecteb\Marketplace\Modules\Product\Infrastructure\TitleSortRepair;

/**
 * WHAT THIS PROVES: the sort key survives a rollback, and coming back does not
 * need a migration that cannot run.
 *
 * The owner read `tools/upgrade-alpha32-check.sh` and found the hole in the
 * measurement itself: after it puts `alpha.32` back, it creates and edits
 * nothing, so it never asks the one question that matters. From the code, three
 * facts answer it:
 *
 *  - `alpha.32` does not know `title_sort` exists. It writes `title` and leaves
 *    the key alone, so an EDITED title keeps the key of the name it used to
 *    have, and a CREATED product gets the column default, `''`;
 *  - coming forward again, `tmc_schema_version` still reads 20, so the upgrade
 *    gate has nothing to do and migration 20 never runs again;
 *  - and its backfill is `WHERE title_sort = ''` anyway, so even a forced re-run
 *    fills the created product's key and walks past the edited one.
 *
 * Every test here writes the way `alpha.32` writes — `UPDATE … SET title` with
 * no key, `INSERT` with no key — rather than calling a method that maintains
 * the key and then breaking it, because the defect is about which columns a
 * foreign build touches.
 */
final class TitleSortRepairTest extends DatabaseTestCase
{
    private WpDatabase $db;
    private DbProductRepository $products;
    private TitleSortRepair $repair;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->products = new DbProductRepository($this->db, new SystemClock());
        $this->repair = new TitleSortRepair($this->db, new WpOptionStore());
        $this->table = $this->wpdb->prefix . 'tmc_products';
    }

    /**
     * WHAT THIS PROVES: the scenario the owner described, start to finish.
     *
     * A title edited on `alpha.32` and a product created there. Neither is
     * reachable by migration 20 — and the first is not reachable by its backfill
     * at all — and both come out right.
     */
    public function testATitleEditedAndAProductCreatedByAnOlderBuildAreBothRepaired(): void
    {
        $edited = $this->create('آمبو بگ');
        $this->create('پالس اکسیمتر');
        $this->settle();

        // ---- what `alpha.32` does, in its own columns
        $this->rollbackEdit($edited, 'یدک خودرو');
        $created = $this->insertLikeOldBuild('چای سبز');

        self::assertSame(
            PersianCollation::sortKey('آمبو بگ'),
            $this->key($edited),
            'the stale key is the OLD name, which is the defect'
        );
        self::assertSame('', $this->key($created), 'and a created row has no key at all');

        // ---- the migration cannot help, and this is measured, not argued
        self::assertFalse(
            (new M0020ProductTitleSort())->verify($this->db),
            'an empty key is the one thing verify() does notice'
        );
        (new M0020ProductTitleSort())->up($this->db);
        self::assertSame(
            PersianCollation::sortKey('چای سبز'),
            $this->key($created),
            'the backfill does fill the created row — that half was never in doubt'
        );
        self::assertSame(
            PersianCollation::sortKey('آمبو بگ'),
            $this->key($edited),
            'and walks past the edited one, because its key is not empty'
        );
        self::assertSame(1, $this->staleCount(), 'so one row is still lying about its name');
        self::assertTrue(
            (new M0020ProductTitleSort())->verify($this->db),
            'and the migration now reports the table as done — which is the trap: '
            . 'its own check cannot see the row it could not fix'
        );

        // ---- the repair.
        //
        // The reason is `audit_mismatch` and not `missing_key` precisely BECAUSE
        // the migration ran first: filling the created row removed the cheap
        // symptom and left the expensive one. This is the case where the
        // rotating audit is the only thing left, and the assertion names it so
        // that a change which finds it some other way is a visible change.
        $result = $this->repair->run();
        self::assertSame(1, $result['repaired']);
        self::assertSame(TitleSortRepair::REASON_AUDIT, $this->repair->status()['reason']);
        self::assertSame(PersianCollation::sortKey('یدک خودرو'), $this->key($edited));
        self::assertSame(PersianCollation::sortKey('چای سبز'), $this->key($created));
        self::assertSame(0, $this->staleCount(), 'and no row disagrees with its title any more');
    }

    /**
     * WHAT THIS PROVES: with the created row's key still empty — which is the
     * state a real re-upgrade starts from, before anything re-runs a migration —
     * the cheap trigger is what opens the sweep, and one sweep fixes both rows.
     *
     * One indexed `LIMIT 1` is all it costs to ask, and a row with no key is
     * evidence about the whole table rather than about itself: the build that
     * created it did not maintain the rows it EDITED either.
     */
    public function testAnEmptyKeyOpensTheSweepAndTheSweepFixesTheEditedRowToo(): void
    {
        $edited = $this->create('آمبو بگ');
        $this->settle();
        $this->rollbackEdit($edited, 'یدک خودرو');
        $created = $this->insertLikeOldBuild('چای سبز');

        $result = $this->repair->run();
        self::assertSame('finished', $result['state'], 'a two-row table finishes in one pass');
        self::assertSame(
            TitleSortRepair::REASON_MISSING,
            $result['reason'] === '' ? TitleSortRepair::REASON_MISSING : $result['reason'],
            'the reason is cleared when the sweep closes; it opened as missing_key'
        );
        self::assertSame(2, $result['swept_repaired'], 'both rows, in one sweep');
        self::assertSame(PersianCollation::sortKey('یدک خودرو'), $this->key($edited));
        self::assertSame(PersianCollation::sortKey('چای سبز'), $this->key($created));
    }

    /**
     * WHAT THIS PROVES: the order the list renders is right again — which is the
     * thing the manager sees, and the only reason the key exists.
     */
    public function testTheOrderIsCorrectAgainAfterTheRepair(): void
    {
        $a = $this->create('آب مقطر');
        $b = $this->create('یدک');
        $this->settle();

        // The two titles are SWAPPED by the old build, so the stored keys now
        // say the opposite of the truth: ordering by the column puts them the
        // wrong way round until something notices.
        $this->rollbackEdit($a, 'یدک');
        $this->rollbackEdit($b, 'آب مقطر');
        self::assertSame([$a, $b], $this->idsInKeyOrder(), 'wrong on purpose, before the repair');

        $this->repair->requestSweep();
        $this->repair->run();
        self::assertSame([$b, $a], $this->idsInKeyOrder(), 'and right after it');
    }

    /**
     * WHAT THIS PROVES: a changed key FORMAT rebuilds every key, without any row
     * having to look broken.
     *
     * This is `alpha.34`'s own case. The number block grew a length prefix, so
     * every key `alpha.33` wrote is stale — and not one of them is empty, so
     * nothing else in this class would find them. The recorded build is the only
     * evidence there is.
     */
    public function testAChangedKeyFormatRebuildsEveryKey(): void
    {
        $id = $this->create('کالا 1000000000000');
        $this->settle();

        // What `alpha.33` stored for this title: the last twelve digits, padded
        // — which for a thirteen-digit number is twelve zeros, the number
        // nought. Written straight into the column, because the class that
        // produced it no longer exists.
        $legacy = '1azabbbab 000000000000';
        $this->db->execute(
            'UPDATE `' . $this->table . '` SET `' . M0020ProductTitleSort::COLUMN . '` = %s WHERE id = %d',
            [$legacy, $id]
        );
        $this->markBuild(1);

        self::assertSame($legacy, $this->key($id), 'the old format is in the column');
        $result = $this->repair->run();
        self::assertSame('finished', $result['state']);
        self::assertSame(1, $result['repaired']);
        self::assertSame(PersianCollation::sortKey('کالا 1000000000000'), $this->key($id));
        self::assertSame(PersianCollation::BUILD, $this->repair->status()['build']);
    }

    /**
     * WHAT THIS PROVES: the sweep is BOUNDED and RESUMABLE — the two properties
     * the owner asked for by name.
     *
     * A whole-catalogue rebuild per request is the shape `alpha.32`'s paging
     * exists to avoid. So a run does at most `SWEEP` rows, says where it
     * stopped, and the next run starts there.
     */
    public function testTheSweepIsBoundedPerRunAndResumesWhereItStopped(): void
    {
        $rows = TitleSortRepair::SWEEP + 25;
        for ($i = 1; $i <= $rows; $i++) {
            $this->create(sprintf('ماسک %d', $i));
        }
        $this->settle();
        // Every key wrong at once, the way a format change leaves them.
        $this->db->execute('UPDATE `' . $this->table . '` SET `' . M0020ProductTitleSort::COLUMN . "` = 'x'");
        $this->markBuild(1);

        $first = $this->repair->run();
        self::assertSame('sweeping', $first['state'], 'a table this size does not finish in one request');
        self::assertSame(TitleSortRepair::SWEEP, $first['checked'], 'and it stopped at the bound');
        self::assertSame(TitleSortRepair::SWEEP, $first['repaired']);
        self::assertSame(25, $first['remaining'], 'it says how much is left');
        self::assertGreaterThan(0, $this->repair->status()['cursor'], 'and where it got to');
        self::assertSame(25, $this->staleCount(), 'exactly the rows it has not reached are still stale');

        $second = $this->repair->run();
        self::assertSame('finished', $second['state']);
        self::assertSame(25, $second['repaired'], 'the second run did the rest, not the whole table again');
        self::assertSame(0, $this->staleCount());
        self::assertSame(0, $this->repair->status()['cursor'], 'and the cursor is put away');
    }

    /**
     * WHAT THIS PROVES: a clean table costs reads and NO writes, however many
     * requests go past.
     *
     * The audit runs on every wp-admin request, so «it does nothing when there
     * is nothing to do» is the difference between a correctness net and a tax.
     */
    public function testAnUpToDateTableIsNeverWrittenTo(): void
    {
        $this->create('دستکش لاتکس');
        $this->create('سرنگ ۵ سی‌سی');
        $this->settle();

        for ($i = 0; $i < 4; $i++) {
            $result = $this->repair->run();
            self::assertSame('clean', $result['state'], 'nothing is open');
            self::assertSame(0, $result['repaired'], 'and nothing is written');
            self::assertSame(2, $result['audited'], 'the rows were read, though — this is a real check');
        }
    }

    /**
     * WHAT THIS PROVES: the case with NO other symptom is still caught — by
     * nobody noticing anything.
     *
     * An older build that edited titles and created nothing leaves no empty key
     * and no build mismatch. The rotating audit is the only thing that can find
     * it, so it is measured on its own: one edited title, no other damage, and
     * the state already settled on the current build.
     */
    public function testTheRotatingAuditFindsAStaleKeyWithNoOtherSymptom(): void
    {
        $id = $this->create('گاز استریل');
        $this->settle();
        self::assertSame('clean', $this->repair->run()['state'], 'settled first, so the audit is what acts');

        $this->rollbackEdit($id, 'ویلچر تاشو');
        self::assertSame(PersianCollation::BUILD, $this->repair->status()['build'], 'no build mismatch');
        self::assertSame(0, $this->emptyCount(), 'and no empty key');

        // Falsification: with the audit taken out of the picture, nothing here
        // would open a sweep at all. That is asserted as the REASON, so a future
        // change that finds it some other way is a visible change of story.
        $result = $this->repair->run();
        self::assertSame(1, $result['repaired'], 'the audit repaired the row it read');
        self::assertSame(TitleSortRepair::REASON_AUDIT, $this->repair->status()['reason']);
        self::assertSame(PersianCollation::sortKey('ویلچر تاشو'), $this->key($id));
    }

    /**
     * WHAT THIS PROVES: the audit wraps, so every row is reached however long
     * the table is.
     *
     * A cursor that only ever moves forward verifies the first page for ever and
     * the last one never.
     */
    public function testTheAuditWrapsRoundToTheStartOfTheTable(): void
    {
        $rows = TitleSortRepair::AUDIT + 10;
        $ids = [];
        for ($i = 1; $i <= $rows; $i++) {
            $ids[] = $this->create(sprintf('باند %d', $i));
        }
        $this->settle();

        $this->repair->run();                                    // rows 1..AUDIT
        $afterFirst = $this->repair->status()['audit_cursor'];
        self::assertSame($ids[TitleSortRepair::AUDIT - 1], $afterFirst);

        $this->repair->run();                                    // the last 10, then past the end
        $this->repair->run();                                    // wrapped: rows 1..AUDIT again
        self::assertSame($afterFirst, $this->repair->status()['audit_cursor'], 'back at the beginning');
    }

    /** WHAT THIS PROVES: a table with no `title_sort` column is left alone. */
    public function testASiteWhoseMigrationHasNotRunYetIsLeftAlone(): void
    {
        $this->create('ترمومتر');
        $this->db->execute('ALTER TABLE `' . $this->table . '` DROP INDEX `' . M0020ProductTitleSort::INDEX . '`');
        $this->db->execute('ALTER TABLE `' . $this->table . '` DROP COLUMN `' . M0020ProductTitleSort::COLUMN . '`');

        $result = $this->repair->run();
        self::assertSame('no_column', $result['state']);
        self::assertSame(0, $this->repair->status()['build'], 'and nothing was recorded about a column that is not there');
    }

    // ------------------------------------------------------------------ helpers

    private function create(string $title): int
    {
        return $this->products->create(
            7,
            new ProductDetails(title: $title, categoryKey: 'gloves', priceMinor: 100000, stock: 3),
            ProductStatus::Draft
        );
    }

    /**
     * Bring the repair to rest on the current build, so a test that is about
     * one trigger is not measuring another one.
     *
     * A fixture that leaves the state where it found it would make the FIRST
     * assertion of every test about «build 0 means sweep», which is a different
     * question and one this class asks separately.
     */
    private function settle(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $status = $this->repair->status();
            if (!$status['open'] && $status['build'] === PersianCollation::BUILD) {
                return;
            }
            $this->repair->run();
        }
        self::fail('the repair never settled, which is a defect and not a fixture problem');
    }

    /** An edit the way `alpha.32` makes one: the title, and not the key. */
    private function rollbackEdit(int $id, string $title): void
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->table . '` SET title = %s WHERE id = %d',
            [$title, $id]
        );
        self::assertNotNull($written, 'the fixture itself must write');
        self::assertSame(1, $written, 'and it must write exactly the row it names');
    }

    /** A product created by a build that does not know the column exists. */
    private function insertLikeOldBuild(string $title): int
    {
        $written = $this->db->execute(
            'INSERT INTO `' . $this->table . '`
             (vendor_user_id, title, type, category_key, brand, short_description, price_minor,
              sku, stock, min_purchase, weight_grams, dimensions, tax_class, status, link_ownership,
              created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %d, %s, %d, %d, %d, %s, %s, %s, %s, %s, %s)',
            [7, $title, 'simple', 'gloves', '', '', 100000, '', 3, 1, 0, '', '', 'draft', 'marketplace',
             '2026-09-27 10:00:00', '2026-09-27 10:00:00']
        );
        self::assertNotNull($written, 'the fixture insert must succeed');
        return (int) $this->db->getVar('SELECT MAX(id) FROM `' . $this->table . '`');
    }

    private function markBuild(int $build): void
    {
        $options = new WpOptionStore();
        $state = $options->get(TitleSortRepair::OPTION, []);
        $state = is_array($state) ? $state : [];
        $state['build'] = $build;
        $options->set(TitleSortRepair::OPTION, $state);
    }

    private function key(int $id): string
    {
        return (string) $this->db->getVar(
            'SELECT `' . M0020ProductTitleSort::COLUMN . '` FROM `' . $this->table . '` WHERE id = %d',
            [$id]
        );
    }

    /** Rows whose stored key does not match their own title, counted in PHP. */
    private function staleCount(): int
    {
        $stale = 0;
        foreach ($this->db->getResults('SELECT title, `' . M0020ProductTitleSort::COLUMN . '` AS k FROM `' . $this->table . '`') as $row) {
            if (PersianCollation::sortKey((string) $row['title']) !== (string) $row['k']) {
                $stale++;
            }
        }
        return $stale;
    }

    private function emptyCount(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->table . '` WHERE `' . M0020ProductTitleSort::COLUMN . "` = ''"
        );
    }

    /** @return list<int> */
    private function idsInKeyOrder(): array
    {
        $ids = [];
        foreach ($this->db->getResults(
            'SELECT id FROM `' . $this->table . '` ORDER BY `' . M0020ProductTitleSort::COLUMN . '` ASC, id ASC'
        ) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }
}
