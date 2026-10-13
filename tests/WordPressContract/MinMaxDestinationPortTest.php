<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\WordPress\WpShopQuantityPort;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedQuantity;
use Tecteb\Marketplace\Tests\Support\CannedPostMetaDatabase;
use Tecteb\Marketplace\Tests\Support\FakeDatabase;
use TmcWpStubs\State;

/**
 * The handover's destination port: absent against empty, and an ownership
 * record that is compared rather than counted.
 *
 * **`count($record) === 3` was `alpha.53`'s hole, and it is the one a suite
 * can see.** A product projected earlier already carries a three-entry record;
 * if the record write does not land, the OLD one survives — three keys and all
 * — and `alpha.53` called that «stored». Measured on its own bytes with the
 * record write refused through WordPress's `update_post_metadata`
 * short-circuit: `port_says_stored=yes record_entries=3
 * record_entries_matching_shop=1 held_elsewhere_now=yes`. The last of those is
 * the damage: from the next read on, the marketplace believes a manager owns
 * two of the three rows, so a vendor's purchase-limit edit stops being applied
 * on a product this plugin wrote itself — `alpha.46`'s
 * `conflicts=_tmc_max_purchase`, arrived by a new road.
 *
 * These stubs are NOT WordPress and are never reported as it. What they give
 * is the meta API's one hard promise — `get_post_meta()` answers `''` both for
 * «no row» and for «a row holding an empty string», while `metadata_exists()`
 * separates them — which is the whole reason this port exists.
 */
final class MinMaxDestinationPortTest extends TestCase
{
    private const PRODUCT = 5411;

    protected function setUp(): void
    {
        State::reset();
        State::$posts[self::PRODUCT] = ['post_type' => 'product', 'status' => 'publish'];
    }

    /** An absent row is `null` and an empty row is `''`, and they stay apart. */
    public function testAnAbsentRowAndAnEmptyRowAreTwoAnswers(): void
    {
        update_post_meta(self::PRODUCT, ProjectedQuantity::META_MIN, '');
        // max is left out entirely — that is the state `null` is for.
        update_post_meta(self::PRODUCT, ProjectedQuantity::META_STEP, '2');

        $read = $this->port()->read(self::PRODUCT);

        self::assertSame('', $read['min'], 'a row holding an empty string');
        self::assertNull($read['max'], 'no row at all');
        self::assertSame('2', $read['step']);
    }

    /** The ordinary write: the three values and a record that matches them. */
    public function testAWriteClaimsExactlyWhatItStored(): void
    {
        $written = $this->port()->write(self::PRODUCT, '4', '10', '2');

        self::assertTrue($written['stored']);
        self::assertSame(['4', '10', '2'], [$written['min'], $written['max'], $written['step']]);
        $record = ProjectedQuantity::normalise(
            get_post_meta(self::PRODUCT, ProjectedQuantity::OWNED_META, true)
        );
        self::assertCount(3, $record);
        foreach (ProjectedQuantity::keys() as $key) {
            self::assertTrue(
                hash_equals(
                    $record[$key],
                    ProjectedQuantity::fingerprint((string) get_post_meta(self::PRODUCT, $key, true))
                ),
                $key . ' is recorded as what the shop holds'
            );
        }
        self::assertFalse($this->port()->read(self::PRODUCT)['held'], 'and the rows are ours');
    }

    /**
     * THE FINDING, as a suite can put it: a three-entry record left over from
     * an earlier write does not make a new write «stored».
     *
     * The stale record is seeded directly, which is exactly the state the
     * measured defect produces — and the assertion is on `recordIs()`'s
     * question, «is the record on disk the one just built», not on how many
     * entries it has.
     */
    public function testAStaleThreeEntryRecordIsNotTheRecordForThisWrite(): void
    {
        $this->port()->write(self::PRODUCT, '2', '6', '2');
        $stale = get_post_meta(self::PRODUCT, ProjectedQuantity::OWNED_META, true);
        self::assertCount(3, ProjectedQuantity::normalise($stale), 'three entries, as the old code counted');

        // The values move and the record does not — the shape a refused record
        // write leaves behind.
        foreach ([ProjectedQuantity::META_MIN => '4', ProjectedQuantity::META_MAX => '10'] as $key => $value) {
            update_post_meta(self::PRODUCT, $key, $value);
        }
        update_post_meta(self::PRODUCT, ProjectedQuantity::OWNED_META, $stale);

        $read = $this->port()->read(self::PRODUCT);

        self::assertTrue(
            $read['held'],
            'a record whose fingerprints do not match what is there is somebody else\'s'
        );
    }

    /** A restore says WHICH part would not store, not just that it failed. */
    public function testARestoreNamesThePartThatWouldNotStore(): void
    {
        $this->port()->write(self::PRODUCT, '4', '10', '2');
        // A filter that refuses one key, the way a storage failure refuses it
        // — WordPress's own `update_post_metadata` short-circuit, which the
        // stub honours for exactly this reason.
        $refuse = static function (mixed $check, mixed $id, mixed $key): mixed {
            return $key === ProjectedQuantity::META_STEP ? false : $check;
        };
        add_filter('update_post_metadata', $refuse, 10, 3);

        $problem = $this->port()->restore(self::PRODUCT, '2', '6', '3', null);

        // Removed here rather than left to the next `State::reset()`: a filter
        // that outlives its test refuses a write in a class that never asked.
        remove_filter('update_post_metadata', $refuse, 10);
        self::assertSame(ProjectedQuantity::META_STEP, $problem);
        self::assertSame('', $this->port()->restore(self::PRODUCT, '2', '6', '3', null), 'and it stores once the refusal is gone');
    }

    /** A restore to «there was no row» deletes, and says so when it cannot. */
    public function testARestoreToAbsenceDeletesTheRow(): void
    {
        $this->port()->write(self::PRODUCT, '4', '10', '2');

        $problem = $this->port()->restore(self::PRODUCT, null, null, null, null);

        self::assertSame('', $problem);
        self::assertFalse(metadata_exists('post', self::PRODUCT, ProjectedQuantity::META_MIN));
        self::assertFalse(metadata_exists('post', self::PRODUCT, ProjectedQuantity::OWNED_META));
        $read = $this->port()->read(self::PRODUCT);
        self::assertNull($read['min'], 'absent, not empty');
        self::assertNull($read['record']);
    }

    /** A record that is not a map is refused rather than restored as junk. */
    public function testARestoreRefusesARecordThatIsNotAMap(): void
    {
        $this->port()->write(self::PRODUCT, '4', '10', '2');

        $problem = $this->port()->restore(self::PRODUCT, '2', '6', '2', 'not-json');

        self::assertSame(ProjectedQuantity::OWNED_META, $problem);
    }

    /** The record comes back byte for byte, so ownership survives a rollback. */
    public function testARestoredRecordKeepsTheEarlierOwnership(): void
    {
        $this->port()->write(self::PRODUCT, '2', '6', '2');
        $before = $this->port()->read(self::PRODUCT);
        $this->port()->write(self::PRODUCT, '4', '10', '2');

        $problem = $this->port()->restore(
            self::PRODUCT,
            $before['min'],
            $before['max'],
            $before['step'],
            $before['record']
        );

        self::assertSame('', $problem);
        self::assertSame('2', (string) get_post_meta(self::PRODUCT, ProjectedQuantity::META_MIN, true));
        self::assertFalse($this->port()->read(self::PRODUCT)['held'], 'and they are still ours');
    }

    // ================================ §6-ب the read under the lock

    /**
     * THE FINDING: `readRows()` reports THE ROW, and `read()` reports what the
     * meta API has cached — so the two are asked to disagree here on purpose.
     *
     * `alpha.54` locked the three rows and then compared `get_post_meta()`,
     * which never looks at the row: `get_metadata_raw()` calls
     * `wp_cache_get($id, 'post_meta')` and only fills that cache when it is
     * cold. A copy warmed earlier in the request — by `wc_get_product()`, by
     * an admin screen, by this plugin's own preview — is what the guard
     * compared, and with a persistent object cache it can predate another
     * process's committed write.
     */
    public function testTheRowReadReportsTheRowAndNotTheCachedCopy(): void
    {
        // What the meta API will answer: the stale value.
        update_post_meta(self::PRODUCT, ProjectedQuantity::META_MAX, '10');
        // What the table actually holds, which is what a lock protects.
        $db = $this->canned();
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MAX, '7');
        $port = new WpShopQuantityPort($db);

        self::assertSame('10', (string) $port->read(self::PRODUCT)['max'], 'the cached copy');
        $rows = $port->readRows(self::PRODUCT);
        self::assertTrue($rows['read']);
        self::assertSame('7', (string) $rows['max'], 'and the row itself');
    }

    /** Absent and empty stay two answers when the rows are read directly too. */
    public function testTheRowReadKeepsAbsentAndEmptyApart(): void
    {
        $db = $this->canned();
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MIN, '');
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_STEP, '2');

        $rows = (new WpShopQuantityPort($db))->readRows(self::PRODUCT);

        self::assertSame('', $rows['min'], 'a row holding an empty string');
        self::assertNull($rows['max'], 'no row at all');
        self::assertSame('2', $rows['step']);
    }

    /**
     * A read that failed is not a destination that is empty.
     *
     * Taken for absence, a rollback would delete three rows that are there —
     * which is why the count is asked with `COUNT(*)`, where `null` can only
     * mean failure.
     */
    public function testAFailedReadSaysSoRatherThanAnsweringAbsent(): void
    {
        $db = $this->canned();
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MIN, '4');
        $db->failReads = true;

        $rows = (new WpShopQuantityPort($db))->readRows(self::PRODUCT);

        self::assertFalse($rows['read']);
        self::assertNull($rows['min'], 'and it does not pretend to have a value');
    }

    /**
     * Two rows for one key: the LOWEST `meta_id` is used — the one WordPress
     * itself would read — and the extra is counted rather than hidden.
     *
     * `wp_postmeta` has no unique index on `(post_id, meta_key)`:
     * `PRIMARY(meta_id)`, `KEY post_id`, `KEY meta_key`, read off WordPress
     * 7.1's own table. `update_meta_cache()` orders by `meta_id ASC` and
     * `get_metadata_raw()` takes `[0]`, so that is the value in force.
     */
    public function testADuplicateRowIsCountedAndTheFirstOneIsUsed(): void
    {
        $db = $this->canned();
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MAX, '10');
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MAX, '99');

        $rows = (new WpShopQuantityPort($db))->readRows(self::PRODUCT);

        self::assertSame('10', (string) $rows['max'], 'the lowest meta_id wins');
        self::assertSame([ProjectedQuantity::META_MAX => 1], $rows['duplicates']);
    }

    /** The ownership record comes off the row, unserialised as WordPress would. */
    public function testTheOwnershipRecordIsReadFromTheRowAndStillDecidesOwnership(): void
    {
        $db = $this->canned();
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MIN, '4');
        $db->addRow(
            self::PRODUCT,
            ProjectedQuantity::OWNED_META,
            serialize([ProjectedQuantity::META_MIN => ProjectedQuantity::fingerprint('4')])
        );

        $rows = (new WpShopQuantityPort($db))->readRows(self::PRODUCT);

        self::assertFalse($rows['held'], 'recorded, and unchanged since: ours');
        self::assertIsString($rows['record']);

        $db->rows = [];
        $db->addRow(self::PRODUCT, ProjectedQuantity::META_MIN, '9');
        self::assertTrue(
            (new WpShopQuantityPort($db))->readRows(self::PRODUCT)['held'],
            'a present row with no record is somebody else\'s'
        );
    }

    private function canned(): CannedPostMetaDatabase
    {
        $db = new CannedPostMetaDatabase();
        $db->posts[self::PRODUCT] = 'product';
        return $db;
    }

    private function port(): WpShopQuantityPort
    {
        return new WpShopQuantityPort(new FakeDatabase());
    }
}
