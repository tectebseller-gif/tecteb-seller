<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Exceptions\MigrationException;
use Tecteb\Marketplace\Core\Migration\MigrationLock;
use Tecteb\Marketplace\Core\Migration\MigrationRunner;
use Tecteb\Marketplace\Core\Migration\MigrationStatus;
use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Core\Migration\UpgradeGate;
use Tecteb\Marketplace\Core\Support\FixedClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpLockStore;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0021ReviewSeen;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: «شکست خواندن migration، موفقیت ارتقا محسوب نمی‌شود».
 *
 * **The defect.** `alpha.37` asked whether `usermeta` is there with
 * `(int) $db->getVar(...) === 0`. A failed read answers `null`, `(int) null` is
 * nought, and nought meant «the table is not there, nothing to carry» — so the
 * carry-over returned in silence while `up()` reported success. `verify()` only
 * inspects the DESTINATION table, so the schema reached 21 with not one
 * `alpha.36` mark carried, and every manager's badge came back full with no way
 * to tell why.
 *
 * Every assertion here is on the REAL database, with the failure injected into
 * exactly the one read the defect was about — `information_schema.TABLES` for
 * `wptest_usermeta` — and nothing else on the same connection touched.
 *
 * The chain is run from stored version 20, which is `alpha.36`: that is the
 * upgrade step this migration IS, so running the whole chain from nought would
 * be measuring twenty other steps and not this one.
 */
final class ReviewSeenCarryOverTest extends DatabaseTestCase
{
    private const ANA = 41;
    private const BABAK = 42;

    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        State::reset();
        State::$optionsBackedByWpdb = true;
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->createUserMeta();
    }

    /**
     * Gives back BOTH things this fixture spends.
     *
     * `wptest_usermeta` is not prefixed `tmc_`, so `resetSchema()` does not drop
     * it — and it must be dropped here, because leaving it behind would make
     * this migration carry marks over in every other test in two suites
     * («fixtureی که چیزی را خرج می‌کند باید پس بدهد»).
     */
    protected function tearDown(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->metaTable() . '`');
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    /**
     * WHAT THIS PROVES: with the existence read broken, the step fails loudly
     * and the schema does NOT advance.
     *
     * The falsification is in the same test: `assertNotSame([], $gate->refused)`
     * — a run where the injected failure never fired would prove nothing, and
     * would look exactly like a passing test.
     */
    public function testAFailedExistenceReadStopsTheUpgradeInsteadOfSkippingTheCarryOver(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0', 12 => 's4.r7']);
        $this->atVersion20();

        $gate = new FailingDatabase($this->db);
        $gate->failReadWhen(['information_schema.TABLES', $this->metaTable()]);

        $result = $this->runner($gate)->run();

        self::assertNotSame([], $gate->refused, 'the injected failure never fired: this test proved nothing');
        self::assertSame(MigrationStatus::Failed, $result->status, 'a read that broke is not a completed upgrade');
        self::assertSame(20, $this->storedSchemaVersion(), 'the site stays on alpha.36, which works');

        $error = $this->rawOption(SchemaVersion::LAST_ERROR_OPTION);
        self::assertNotNull($error, 'the reason is recorded, not lost');
        self::assertStringContainsString('0021_review_seen', (string) $error, 'and it names the step');
        self::assertStringContainsString('usermeta', (string) $error, 'and what it could not read');

        self::assertSame(0, $this->seenRows(), 'nothing was carried, and nothing claims it was');
    }

    /**
     * WHAT THIS PROVES: and `alpha.37` would have called the same run a
     * success. Measured here rather than asserted in prose, by asking the one
     * question the old line asked.
     */
    public function testTheOldQuestionCannotTellAFailedReadFromAnAbsentTable(): void
    {
        $gate = new FailingDatabase($this->db);
        $gate->failReadWhen(['information_schema.TABLES', $this->metaTable()]);

        $broken = $gate->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$this->metaTable()]
        );
        $absent = $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            ['wptest_a_table_that_is_not_there']
        );

        self::assertNull($broken, 'a refused read is null');
        self::assertSame('0', (string) $absent, 'an absent table is the string nought');
        // The `alpha.37` line, run on both answers.
        self::assertTrue((int) $broken === 0, 'which the old cast made the same answer');
        self::assertTrue((int) $absent === 0);
        // And the new one, which separates them.
        self::assertTrue($broken === null);
        self::assertFalse($absent === null);
    }

    /**
     * WHAT THIS PROVES: a retry after the failure is cleared completes the
     * carry-over — nothing was consumed by the failed attempt.
     *
     * The cooldown is the gate's, not the runner's, and is checked separately
     * (`testTheGateHoldsItsCooldownAndThenCompletes`): here the runner is asked
     * again directly, which is what the gate does once the five minutes are up.
     */
    public function testTheRetryAfterTheErrorIsClearedCompletesTheCarryOver(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0', 12 => 's4.r7']);
        $this->seedLegacyMark(self::BABAK, [11 => 's3.r0']);
        $this->atVersion20();

        $gate = new FailingDatabase($this->db);
        $gate->failReadWhen(['information_schema.TABLES', $this->metaTable()]);
        self::assertSame(MigrationStatus::Failed, $this->runner($gate)->run()->status);
        self::assertSame(0, $this->seenRows());

        $gate->stopFailing();
        delete_option(SchemaVersion::LAST_ERROR_OPTION);
        $result = $this->runner($gate, 'owner-retry')->run();

        self::assertSame(MigrationStatus::Applied, $result->status, (string) $result->error);
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion(), 'the gate reaches the target, whatever this round moved it to');
        self::assertSame(
            [[self::ANA, 11, 3, 0], [self::ANA, 12, 4, 7], [self::BABAK, 11, 3, 0]],
            $this->marks(),
            'all three marks, both managers, with their two ids'
        );
    }

    /**
     * WHAT THIS PROVES: running the step again creates no duplicate row, does
     * not overwrite a mark the plugin has written SINCE, and leaves the legacy
     * meta value in place for a rollback.
     *
     * `INSERT IGNORE` is the whole mechanism, and «zero changed rows» is what it
     * answers for a row that is already there — which must not be read as a
     * failed write (`alpha.8`). This test is the one that would catch that
     * reading, because a re-run consists almost entirely of such writes.
     */
    public function testARerunWritesNoDuplicateAndDoesNotOverwriteANewerMark(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0', 12 => 's4.r7']);
        $this->atVersion20();
        self::assertSame(MigrationStatus::Applied, $this->runner($this->db)->run()->status);

        // The plugin records a newer view of product 11 after the upgrade.
        self::assertNotNull($this->db->execute(
            'UPDATE `' . $this->seenTable() . '` SET `submission_id` = 9, `revision_id` = 0'
                . ' WHERE `user_id` = %d AND `product_id` = %d',
            [self::ANA, 11]
        ));

        // And the step runs again — a crash between the DDL and the version
        // write, or an operator re-running the gate.
        (new M0021ReviewSeen())->up($this->db);

        self::assertSame(2, $this->seenRows(), 'two marks, not four');
        self::assertSame(
            [[self::ANA, 11, 9, 0], [self::ANA, 12, 4, 7]],
            $this->marks(),
            'the newer mark is the one that survived'
        );
        self::assertSame(
            1,
            (int) $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $this->metaTable() . '` WHERE `meta_key` = %s AND `user_id` = %d',
                [M0021ReviewSeen::LEGACY_META_KEY, self::ANA]
            ),
            'the alpha.36 meta value is still there, which is what a rollback reads'
        );
    }

    /**
     * WHAT THIS PROVES: a database with no `usermeta` at all is not a failure —
     * nought is still a valid answer and still means «nothing to carry».
     *
     * The other half of the fix. A change that made every nought throw would
     * have broken every fresh install, which is the ordinary case.
     */
    public function testAnAbsentUserMetaTableIsNotAFailure(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->metaTable() . '`');
        $this->atVersion20();

        $result = $this->runner($this->db)->run();

        self::assertSame(MigrationStatus::Applied, $result->status, (string) $result->error);
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion(), 'the gate reaches the target, whatever this round moved it to');
        self::assertSame(0, $this->seenRows());
    }

    /**
     * WHAT THIS PROVES: a failure while READING the legacy pages — not the
     * existence check, the page read itself — also stops the upgrade.
     *
     * `getResults()` answers an empty array both for «no more rows» and for a
     * broken read, which is the same collision one level down. It was already
     * handled in `alpha.37` by checking `lastError()`; this holds that in
     * place, because a fix to one of the two could easily have removed the
     * other.
     */
    public function testAFailureReadingTheLegacyPagesAlsoStopsTheUpgrade(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0']);
        $this->atVersion20();

        $gate = new FailingDatabase($this->db);
        $gate->failReadWhen(['FROM `' . $this->metaTable() . '`', 'meta_key']);

        $result = $this->runner($gate)->run();

        self::assertNotSame([], $gate->refused, 'the injected failure never fired');
        self::assertSame(MigrationStatus::Failed, $result->status);
        self::assertSame(20, $this->storedSchemaVersion());
    }

    /**
     * WHAT THIS PROVES: a failed write while carrying a mark over stops the
     * upgrade too, and the retry finishes what it started.
     *
     * The third place the step can break, and the one where «half carried» is
     * possible: the second manager's row is refused after the first manager's
     * landed. The retry must complete rather than duplicate.
     */
    public function testAFailedCarryOverWriteStopsTheUpgradeAndTheRetryFinishesIt(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0']);
        $this->seedLegacyMark(self::BABAK, [12 => 's4.r7']);
        $this->atVersion20();

        $gate = new FailingDatabase($this->db);
        // The SECOND carry-over insert only: the first one lands.
        $gate->failWhen(['INSERT IGNORE INTO', M0021ReviewSeen::SEEN], 2);

        self::assertSame(MigrationStatus::Failed, $this->runner($gate)->run()->status);
        self::assertNotSame([], $gate->refused);
        self::assertSame(20, $this->storedSchemaVersion(), 'half carried is not carried');
        self::assertSame(1, $this->seenRows(), 'and the half that did land is still there');

        $gate->stopFailing();
        delete_option(SchemaVersion::LAST_ERROR_OPTION);
        self::assertSame(MigrationStatus::Applied, $this->runner($gate, 'owner-finish')->run()->status);
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion(), 'the gate reaches the target, whatever this round moved it to');
        self::assertSame(
            [[self::ANA, 11, 3, 0], [self::BABAK, 12, 4, 7]],
            $this->marks(),
            'both, once each'
        );
    }

    /**
     * WHAT THIS PROVES: the gate holds its five-minute cooldown after the
     * failure and then completes the carry-over on its own.
     *
     * «بازیابی با رعایت cooldown واقعی». No magic number is written here: the
     * clock is moved by `UpgradeGate::RETRY_COOLDOWN_SECONDS`, so the day that
     * constant changes this test follows it instead of failing about a site
     * that is behaving correctly. The step before the cooldown is the one that
     * matters — a gate that retried immediately would make a permanently
     * failing migration run on every admin request.
     */
    public function testTheGateHoldsItsCooldownAndThenCompletes(): void
    {
        $this->seedLegacyMark(self::ANA, [11 => 's3.r0']);
        $this->atVersion20();

        $gate = new FailingDatabase($this->db);
        $gate->failReadWhen(['information_schema.TABLES', $this->metaTable()]);

        $clock = new FixedClock(new \DateTimeImmutable('2026-10-08T09:00:00+00:00'));
        $runner = $this->runner($gate, 'owner-cooldown', $clock);
        $upgrade = new UpgradeGate($runner, new WpOptionStore(), $clock);

        self::assertSame(MigrationStatus::Failed, $upgrade->runIfNeeded()?->status);
        self::assertNotSame([], $gate->refused, 'the injected failure never fired');
        self::assertSame(20, $this->storedSchemaVersion());

        // The cause is gone, but the gate has not reached its retry window.
        $gate->stopFailing();
        $clock->advance(UpgradeGate::RETRY_COOLDOWN_SECONDS - 1);
        self::assertNull($upgrade->runIfNeeded(), 'inside the cooldown the gate declines');
        self::assertSame(1, $upgrade->secondsUntilRetry(), 'and says how long is left');
        self::assertSame(20, $this->storedSchemaVersion());
        self::assertSame(0, $this->seenRows());

        // One more second, and the next admin request finishes the job.
        $clock->advance(1);
        self::assertSame(MigrationStatus::Applied, $upgrade->runIfNeeded()?->status);
        self::assertSame(SchemaVersion::TARGET, $this->storedSchemaVersion(), 'the gate reaches the target, whatever this round moved it to');
        self::assertSame([[self::ANA, 11, 3, 0]], $this->marks(), 'the mark alpha.36 held was carried after all');
    }

    // ------------------------------------------------------------------ helpers

    private function runner(
        \Tecteb\Marketplace\Contracts\DatabaseInterface $db,
        string $owner = 'owner-carry-over',
        ?FixedClock $clock = null
    ): MigrationRunner {
        $clock ??= new FixedClock();
        return new MigrationRunner(
            $db,
            new WpOptionStore(),
            new WpLockStore($this->wpdb),
            new MigrationLock(new WpLockStore($this->wpdb), $clock, $owner),
            Bootstrap::migrations(),
            $clock,
            // Read, not written: a fixture that spells the target out breaks
            // the first round that moves the schema, about a site that is
            // perfectly correct (`alpha.33`'s rule, which this line was).
            SchemaVersion::TARGET
        );
    }

    /**
     * The site as `alpha.36` left it: the schema says 20, and the table this
     * step creates is not there.
     */
    private function atVersion20(): void
    {
        $this->wpdb->dropTable($this->seenTable());
        update_option(SchemaVersion::OPTION, 20);
        delete_option(SchemaVersion::LAST_ERROR_OPTION);
    }

    private function createUserMeta(): void
    {
        $this->wpdb->pdo()->exec('DROP TABLE IF EXISTS `' . $this->metaTable() . '`');
        $this->wpdb->pdo()->exec(
            'CREATE TABLE `' . $this->metaTable() . '` (
                `umeta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `meta_key` VARCHAR(255) NULL,
                `meta_value` LONGTEXT NULL,
                PRIMARY KEY (`umeta_id`),
                KEY `user_id` (`user_id`),
                KEY `meta_key` (`meta_key`(191))
            ) DEFAULT CHARSET=utf8mb4'
        );
    }

    /** One `alpha.36` meta value, in the shape `alpha.36` actually wrote. */
    private function seedLegacyMark(int $userId, array $tokensByProduct): void
    {
        $value = [];
        foreach ($tokensByProduct as $productId => $token) {
            $value[$productId] = ['token' => $token, 'at' => '2026-09-27 08:30:00'];
        }
        $stmt = $this->wpdb->pdo()->prepare(
            'INSERT INTO `' . $this->metaTable() . '` (`user_id`, `meta_key`, `meta_value`) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, M0021ReviewSeen::LEGACY_META_KEY, serialize($value)]);
    }

    private function seenRows(): int
    {
        return (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->seenTable() . '`');
    }

    /** @return list<array{0:int,1:int,2:int,3:int}> */
    private function marks(): array
    {
        $out = [];
        foreach ($this->db->getResults(
            'SELECT `user_id`, `product_id`, `submission_id`, `revision_id` FROM `' . $this->seenTable() . '`'
                . ' ORDER BY `user_id`, `product_id`'
        ) as $row) {
            $out[] = [
                (int) $row['user_id'],
                (int) $row['product_id'],
                (int) $row['submission_id'],
                (int) $row['revision_id'],
            ];
        }
        return $out;
    }

    private function seenTable(): string
    {
        return $this->wpdb->prefix . M0021ReviewSeen::SEEN;
    }

    private function metaTable(): string
    {
        return $this->wpdb->prefix . 'usermeta';
    }
}
