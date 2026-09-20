<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\DokanFinanceSnapshot;
use Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbFinanceHandoverRepository;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbShopRecordRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;

/**
 * WHAT THIS PROVES: the balance hand-over cannot record a figure that moved
 * while it was deciding, and two managers deciding two shops cannot erase
 * each other.
 *
 * Three races, and none of them is visible to a test that calls one method
 * once. `alpha.19` guarded acceptance by re-reading the figures and comparing
 * a hash in PHP — every test it shipped passed, because every test decided
 * alone. A comparison between a read and a write is a comparison two writers
 * both pass, so the only honest way to test it is to interleave two real
 * processes against a real database, which is what this does.
 *
 * The forks are not decoration. A second PHP thread inside one process shares
 * the connection, and a shared connection cannot contend for a row lock: it
 * would test the fake and not the guard.
 */
final class HandoverConcurrencyTest extends DatabaseTestCase
{
    private const MANAGER = 501;
    private const SHOP_A = 9001;
    private const SHOP_B = 9002;

    private WpDatabase $db;
    private DbShopRecordRepository $records;
    private DbFinanceHandoverRepository $handover;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $clock = new SystemClock();
        $this->records = new DbShopRecordRepository($this->db, $clock);
        $this->handover = new DbFinanceHandoverRepository($this->db, $clock);
    }

    // ---------------------------------------------------------------- race 1

    /**
     * A change between the display and the token cannot make them disagree,
     * because there is only one read.
     *
     * Before this change the page asked for the report and then asked
     * separately for a token. Anything landing between those two calls left
     * the manager looking at one set of figures and holding a receipt for
     * another — and the decision would be accepted, against a number nobody
     * had been shown.
     */
    public function testTheReportAndItsTokenDescribeTheSameReadEvenWhenTheRowsMoveBetweenThem(): void
    {
        $this->seed(self::SHOP_A, '500000');
        $finance = $this->finance();

        // The page takes ONE snapshot and renders from it.
        $snapshot = $finance->snapshotFor(self::SHOP_A);

        // The imported past moves while the page is being built.
        $this->seed(self::SHOP_A, '300000', 2);

        $report = $finance->reportFrom($snapshot);

        // The printed figure and the token are the same read — the token is
        // the token FOR the number on screen, not for whatever is in the
        // table by the time the token is computed.
        self::assertSame($snapshot->closing, (string) $report['dokan']['closing']);
        self::assertSame($snapshot->token(), (string) $report['figures_token']);
        self::assertSame('500000.0000', (string) $report['dokan']['closing']);

        // And a decision carrying that token is refused, because the version
        // it names is no longer the version on the table.
        $result = $finance->decide(self::SHOP_A, ReconcileDokanFinance::ACCEPTED, '', (string) $report['figures_token']);
        self::assertFalse($result['ok']);
        self::assertSame('figures_changed', $result['reason']);
        self::assertSame(ReconcileDokanFinance::OPEN, $finance->decisionFor(self::SHOP_A)['decision']);
    }

    /**
     * The same figures at a different version are a different token.
     *
     * A digest of the amounts alone would collide the moment a rollback put
     * back exactly what it removed — and «the numbers came back» is still a
     * change a decision taken in between must not be carried across.
     */
    public function testFiguresThatLeaveAndComeBackAreNotTheSameReceipt(): void
    {
        $this->seed(self::SHOP_A, '500000', 1, 'run-one');
        $finance = $this->finance();
        $before = $finance->snapshotFor(self::SHOP_A);

        $this->records->deleteRun('run-one');
        $this->seed(self::SHOP_A, '500000', 1, 'run-two');
        $after = $finance->snapshotFor(self::SHOP_A);

        self::assertSame($before->closing, $after->closing, 'sanity: the amounts really are identical');
        self::assertNotSame($before->token(), $after->token(), 'but the version moved, so the receipt did too');

        $result = $finance->decide(self::SHOP_A, ReconcileDokanFinance::ACCEPTED, '', $before->token());
        self::assertFalse($result['ok']);
        self::assertSame('figures_changed', $result['reason']);
    }

    // ---------------------------------------------------------------- race 2

    /**
     * A write that arrives between the check and the record waits, and the
     * figure recorded is the figure that was checked.
     *
     * The parent holds the shop's version row under a write lock and decides
     * inside it. The child — a separate process with its own connection —
     * tries to add a balance row for the same shop and is made to wait. This
     * is the case re-reading cannot cover: both processes would read the same
     * figures, both would find their hash correct, and both would write.
     */
    public function testAnImportLandingBetweenTheCheckAndTheRecordIsMadeToWait(): void
    {
        $this->seed(self::SHOP_A, '500000');
        $finance = $this->finance();
        $expected = $this->records->closingBalance(self::SHOP_A);

        // The child touches this file only once its write has COMMITTED. Its
        // absence while the parent holds the lock is the whole measurement:
        // «did the other process actually have to wait», asked of the
        // filesystem rather than of the connection that is doing the
        // blocking. Reading the table from inside the parent's transaction
        // could never answer it — under REPEATABLE READ the parent cannot
        // see the child's commit whether it waited or not, which is exactly
        // how a lockless version of this test passes while proving nothing.
        $landed = sys_get_temp_dir() . '/tmc-late-import-' . getmypid();
        @unlink($landed);

        $this->wpdb->disconnect();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('fork failed');
        }
        if ($pid === 0) {
            usleep(200_000);                    // let the parent take the lock
            $wpdb = new \wpdb();
            $child = new DbShopRecordRepository(new WpDatabase($wpdb), new SystemClock());
            $outcome = $child->recordBalance('run-late', [
                'trn_id' => 77, 'vendor_user_id' => self::SHOP_A, 'trn_type' => 'order',
                'particulars' => 'دیرهنگام', 'debit' => '0', 'credit' => '900000',
                'status' => 'approved', 'trn_date' => '2026-02-01 00:00:00',
            ]);
            file_put_contents($landed, $outcome);
            exit(0);
        }

        $this->wpdb->reconnect();

        // Parent: one unit of work spanning the whole decision. decide()
        // nests inside it, so the lock is not released until the commit here.
        self::assertTrue($this->db->begin());
        $version = $this->records->lockRecordsVersion(self::SHOP_A);
        $snapshot = DokanFinanceSnapshot::read($this->records, self::SHOP_A, $version);

        // Long enough that an UNBLOCKED child would certainly have finished.
        usleep(1_200_000);
        self::assertFileDoesNotExist(
            $landed,
            'the concurrent import committed while the decision held the lock — it was never made to wait'
        );

        $result = $finance->decide(
            self::SHOP_A,
            ReconcileDokanFinance::ACCEPTED,
            'تصمیم زیر قفل',
            $snapshot->token()
        );
        self::assertTrue($result['ok'], (string) $result['reason']);
        self::assertTrue($this->db->commit());

        pcntl_waitpid($pid, $status);

        // Released, the waiting write goes through — it was delayed, not lost.
        self::assertFileExists($landed, 'the waiting write never completed at all');
        self::assertSame('recorded', (string) file_get_contents($landed));
        @unlink($landed);

        $recorded = $finance->decisionFor(self::SHOP_A);
        self::assertSame(ReconcileDokanFinance::ACCEPTED, $recorded['decision']);
        self::assertSame(
            $expected,
            (string) $recorded['closing_at_decision'],
            'the figure recorded is the figure that was checked, not the one the late import made'
        );
        self::assertSame(
            $version,
            (int) $recorded['records_version'],
            'and the record names the version it was taken against'
        );
        self::assertGreaterThan(
            (int) $recorded['records_version'],
            $this->records->recordsVersion(self::SHOP_A),
            'the waiting write landed after the commit and moved the version'
        );
        self::assertSame('1400000.0000', $this->records->closingBalance(self::SHOP_A));
    }

    /**
     * The version bump and the row it describes commit together.
     *
     * If a row could be committed while its bump was still pending, a reader
     * would be handed new figures under an old version — told they had not
     * moved when they had. Rolling an import back and reading both together
     * is the cheapest way to show they are one unit.
     */
    public function testEveryWriteToTheImportedPastMovesTheVersionWithIt(): void
    {
        self::assertSame(0, $this->records->recordsVersion(self::SHOP_A));

        $this->seed(self::SHOP_A, '500000', 1, 'run-one');
        $afterWrite = $this->records->recordsVersion(self::SHOP_A);
        self::assertGreaterThan(0, $afterWrite);

        // A duplicate writes nothing, so it must not move the counter: a
        // resumed import re-runs its last page every time it picks up, and a
        // version that moved for a no-op would refuse decisions for no reason.
        $this->seed(self::SHOP_A, '500000', 1, 'run-one');
        self::assertSame($afterWrite, $this->records->recordsVersion(self::SHOP_A), 'a duplicate is not a change');

        $this->records->deleteRun('run-one');
        self::assertGreaterThan($afterWrite, $this->records->recordsVersion(self::SHOP_A), 'a rollback is a change');
    }

    // ---------------------------------------------------------------- race 3

    /**
     * Two managers deciding two different shops at the same moment both keep
     * their decision.
     *
     * This is the one the option storage lost. Every decision lived in one
     * serialised array: read the whole map, set one key, write the map back.
     * Two writers both read the map, and the second write dropped the first
     * manager's decision — silently, about money.
     */
    public function testTwoDecisionsTakenAtTheSameMomentDoNotEraseEachOther(): void
    {
        $this->seed(self::SHOP_A, '500000');
        $this->seed(self::SHOP_B, '700000');

        // Both children compute their token before the barrier, so the write
        // is the only thing that happens at the contended moment.
        $startAt = microtime(true) + 1.0;
        $pids = [];

        $this->wpdb->disconnect();
        foreach ([self::SHOP_A, self::SHOP_B] as $shop) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                $wpdb = new \wpdb();
                $finance = $this->finance(new WpDatabase($wpdb), $wpdb);
                $token = $finance->figuresToken($shop);
                $wait = (int) (($startAt - microtime(true)) * 1_000_000);
                if ($wait > 0) {
                    usleep($wait);
                }
                $result = $finance->decide($shop, ReconcileDokanFinance::ACCEPTED, 'هم‌زمان', $token);
                exit($result['ok'] ? 0 : 1);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status), 'a child decision was refused');
        }
        $this->wpdb->reconnect();

        $finance = $this->finance();
        self::assertSame(
            ReconcileDokanFinance::ACCEPTED,
            $finance->decisionFor(self::SHOP_A)['decision'],
            'the first shop kept its decision'
        );
        self::assertSame(
            ReconcileDokanFinance::ACCEPTED,
            $finance->decisionFor(self::SHOP_B)['decision'],
            'and so did the second — neither write went through the other'
        );
        self::assertSame('500000.0000', (string) $finance->decisionFor(self::SHOP_A)['closing_at_decision']);
        self::assertSame('700000.0000', (string) $finance->decisionFor(self::SHOP_B)['closing_at_decision']);
        self::assertSame([], $finance->stillOpen(), 'and nothing is left undecided');
    }

    /**
     * The storage this replaced really did lose one of them.
     *
     * Without this, «one row per shop fixes it» is an assertion about a bug
     * nobody can see any more. Here the old shape is reproduced directly —
     * read the whole map, set one key, write the map back, from two processes
     * — and one decision disappears. The test asserts the LOSS, so if some
     * future change routes decisions back through a shared blob, the reason
     * that is forbidden is written down in a form that runs.
     */
    public function testTheSharedOptionShapeLosesADecisionAndIsWhyTheTableExists(): void
    {
        $option = 'tmc_handover_race_demo';
        $this->wpdb->pdo()->exec(
            "REPLACE INTO `{$this->wpdb->options}` (option_name, option_value, autoload)
             VALUES ('{$option}', '" . serialize([]) . "', 'no')"
        );

        $startAt = microtime(true) + 0.6;
        $pids = [];
        $this->wpdb->disconnect();
        foreach ([self::SHOP_A, self::SHOP_B] as $shop) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                $wpdb = new \wpdb();
                // Read-modify-write over one shared value — the exact shape
                // `allDecisions()` + `set()` had until alpha.20.
                $stmt = $wpdb->pdo()->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = ?");
                $stmt->execute([$option]);
                $map = unserialize((string) $stmt->fetchColumn(), ['allowed_classes' => false]);
                $wait = (int) (($startAt - microtime(true)) * 1_000_000);
                if ($wait > 0) {
                    usleep($wait);
                }
                $map[(string) $shop] = ['decision' => 'accepted'];
                $write = $wpdb->pdo()->prepare("UPDATE `{$wpdb->options}` SET option_value = ? WHERE option_name = ?");
                $write->execute([serialize($map), $option]);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $this->wpdb->reconnect();

        $stmt = $this->wpdb->pdo()->prepare("SELECT option_value FROM `{$this->wpdb->options}` WHERE option_name = ?");
        $stmt->execute([$option]);
        $final = unserialize((string) $stmt->fetchColumn(), ['allowed_classes' => false]);

        self::assertCount(
            1,
            $final,
            'the shared-blob shape kept BOTH decisions — if this ever passes with 2, the race window moved rather than closed'
        );

        // And the shape that replaced it keeps both, under the same barrier.
        $this->testTwoDecisionsTakenAtTheSameMomentDoNotEraseEachOther();
    }

    /**
     * A decision the storage refuses leaves the shop exactly where it was.
     *
     * The audit line is written after the commit for the same reason: a
     * record of a decision the database rejected is a record of something
     * that never happened.
     */
    public function testARefusedDecisionCommitsNothing(): void
    {
        $this->seed(self::SHOP_A, '500000');
        $finance = $this->finance();

        $blind = $finance->decide(self::SHOP_A, ReconcileDokanFinance::ACCEPTED);
        self::assertFalse($blind['ok']);
        self::assertSame('report_not_seen', $blind['reason']);
        self::assertFalse($this->db->inTransaction(), 'a refusal before the lock opens no transaction');

        $stale = $finance->decide(self::SHOP_A, ReconcileDokanFinance::ACCEPTED, '', str_repeat('0', 32));
        self::assertFalse($stale['ok']);
        self::assertSame('figures_changed', $stale['reason']);
        self::assertFalse($this->db->inTransaction(), 'and a refusal inside the lock rolls back rather than lingering');

        self::assertSame([], $this->handover->allDecisions(), 'no row was written by either refusal');
        self::assertSame([self::SHOP_A], $finance->stillOpen());
    }

    // ------------------------------------------------------------- fixtures

    private function finance(?WpDatabase $db = null, ?\wpdb $wpdb = null): ReconcileDokanFinance
    {
        $db ??= $this->db;
        $wpdb ??= $this->wpdb;
        $clock = new SystemClock();
        return new ReconcileDokanFinance(
            new DbShopRecordRepository($db, $clock),
            new DbFinanceHandoverRepository($db, $clock),
            $db,
            new AuditLogger(new WpAuditRepository($wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_VENDOR])
        );
    }

    private function seed(int $vendorUserId, string $credit, int $trnId = 1, string $runId = 'run-seed'): void
    {
        $this->records->recordBalance($runId, [
            'trn_id' => $trnId, 'vendor_user_id' => $vendorUserId, 'trn_type' => 'order',
            'particulars' => 'فروش', 'debit' => '0', 'credit' => $credit,
            'status' => 'approved', 'trn_date' => '2026-01-01 00:00:00',
        ]);
    }
}
