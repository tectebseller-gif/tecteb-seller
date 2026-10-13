<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Migration\Infrastructure\DbMinMaxHandoverRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0027MinMaxHandover;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\RecordingQuantityPort;
use Tecteb\Marketplace\Tests\Support\RefusingTransaction;
use Tecteb\Marketplace\Tests\Support\RefusingWritePort;
use Tecteb\Marketplace\Tests\Support\StubMinMaxReader;

/**
 * The Min Max handover as ONE unit of work — `alpha.54`'s four findings.
 *
 * Every one of these was measured on `alpha.53`'s own bytes before it was
 * written here, on real WordPress with WooCommerce 11.0.1 and Min Max 8.1.0
 * (`docs/evidence/alpha54-reproduction/before-alpha53.txt`). What the suite
 * adds is the part only a real database can answer: after a refusal, WHAT IS
 * IN THE TABLE — with real transaction semantics underneath, not a fake's
 * account of the SQL it was handed.
 *
 * The four:
 *
 *  1. a refused `begin()`, a missing transaction, a refused history row and a
 *     refused `commit()` all left the destination written and reported
 *     success. Measured: `begin_called=1 rows_after=4/10/2
 *     refused_for_atomicity=0`, and `record_called=1 committed=1
 *     ledger_after=__none__` — a migrated rule with NO record of what the
 *     destination held before, so nothing can ever roll it back;
 *  2. the batch advanced its cursor past a product whose write failed:
 *     `middle_id=374 cursor_after=375 cursor_past_failure=yes`;
 *  3. the rollback was four separate writes and left `4/__absent__/2` — a rule
 *     nobody ever wrote — when one of them refused;
 *  4. a rolled-back product answered `already_decided` for ever:
 *     `second_migrated=0 second_already_decided=1`.
 */
final class MinMaxHandoverUnitOfWorkTest extends DatabaseTestCase
{
    private const FIRST = 5401;
    private const MIDDLE = 5402;
    private const LAST = 5403;

    private WpDatabase $db;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);
        $this->table = M0027MinMaxHandover::table($this->db);
    }

    protected function tearDown(): void
    {
        $this->resetSchema($this->db);
        parent::tearDown();
    }

    // ===================================================== §1 atomicity

    /** A refused `begin()` refuses the whole product, BEFORE the first write. */
    public function testARefusedTransactionStartWritesNothingAtAll(): void
    {
        $port = $this->port();
        $service = $this->service($port, $this->ledger(), new RefusingTransaction());

        $outcome = $this->migrate($service);

        self::assertSame('not_atomic', $outcome);
        self::assertSame([], $port->writes, 'the destination was never touched');
        self::assertSame('__absent__/__absent__/__absent__', $port->describe(self::FIRST));
        self::assertSame(0, $this->countRows(), 'and no history row either');
    }

    /** No transaction at all is the same refusal, named differently. */
    public function testWithNoTransactionTheMigrationRefusesRatherThanDoingHalf(): void
    {
        $port = $this->port();
        $service = new MigrateMinMax(
            (new StubMinMaxReader())->withProducts([self::FIRST]),
            $this->ledger(),
            $port,
            null
        );

        $outcome = $this->migrate($service);

        self::assertSame('no_transaction', $outcome);
        self::assertSame([], $port->writes);
        self::assertSame(0, $this->countRows());
    }

    /**
     * A transaction the CALLER owns is refused, not joined and not committed.
     *
     * «تراکنش متعلق به caller را بی‌اجازه commit نکن» — and `alpha.53` already
     * got this half right (measured: `still_in_transaction_after=yes`), so the
     * test is here to keep it right rather than to fix it.
     */
    public function testItRefusesInsideTheCallersTransactionAndLeavesItOpen(): void
    {
        $port = $this->port();
        $service = $this->service($port, $this->ledger(), $this->db);

        self::assertTrue($this->db->begin());
        $outcome = $this->migrate($service);

        self::assertSame('caller_transaction', $outcome);
        self::assertTrue($this->db->inTransaction(), 'the caller still owns it');
        self::assertSame([], $port->writes);
        $this->db->rollback();
    }

    /**
     * The destination write is refused: nothing recorded, and the product is
     * claimable again because the claim went back with the transaction.
     */
    public function testARefusedDestinationWriteLeavesNoClaimBehind(): void
    {
        $port = $this->port();
        $port->refuseWrite = true;
        $ledger = $this->ledger();
        $service = $this->service($port, $ledger, $this->db);

        $outcome = $this->migrate($service);

        self::assertSame('not_written', $outcome);
        self::assertSame(0, $this->countRows(), 'the claim rolled back with the write');
        self::assertNull($ledger->find(self::FIRST), 'so the product is claimable again');
        self::assertSame(1, $port->forgotten, 'and the uncommitted values are out of the cache');
    }

    /**
     * THE WORST OF THE FOUR: the history row will not store.
     *
     * `alpha.53` discarded that result and committed, so the destination
     * carried the migrated rule and the only record of its earlier values was
     * the row that never landed. Now the whole product goes back.
     */
    public function testARefusedHistoryRowTakesTheDestinationWriteWithIt(): void
    {
        $port = $this->port();
        $failing = new FailingDatabase($this->db);
        // The claim must succeed and the FINISH must fail, so the refusal
        // names the update rather than the insert.
        $failing->failWhen(['UPDATE', 'tmc_minmax_handover', 'written_min']);
        $service = $this->service($port, new DbMinMaxHandoverRepository($failing), $failing);

        $outcome = $this->migrate($service);

        self::assertSame('history_not_written', $outcome, 'and it is NOT reported as a migration');
        self::assertSame(0, $this->countRows(), 'the claim went back, so nothing claims the product');
        self::assertNull($this->ledger()->find(self::FIRST), 'and a retry asks the same question again');
        self::assertSame(1, $port->forgotten, 'the uncommitted values are dropped from the cache');
        // **What this suite cannot answer, and does not pretend to.** Whether
        // the three META ROWS come back is a property of the real port: its
        // writes go through `$wpdb`, the same connection `begin()` opened, so
        // they take part in this transaction and a `ROLLBACK` undoes them.
        // The double writes to an array, which MariaDB cannot roll back. That
        // half is measured on real WordPress instead —
        // `tools/alpha54-reproduction.sh`, verb `record-failure`, which reads
        // the rows back with straight SQL past every cache.
    }

    /** A refused `commit()` is never reported as a migration. */
    public function testARefusedCommitIsNotASuccess(): void
    {
        $port = $this->port();
        $transaction = new RefusingTransaction($this->db, 'commit');
        $service = $this->service($port, $this->ledger(), $transaction);

        $outcome = $this->migrate($service);

        self::assertSame('not_committed', $outcome);
        self::assertNotContains($outcome, [
            MinMaxHandoverRepositoryInterface::MIGRATED,
            MinMaxHandoverRepositoryInterface::UNENFORCED,
        ]);
        self::assertSame(0, $this->countRows(), 'nothing persisted');
    }

    /**
     * Two runs on one product: the second finds the decision taken and never
     * reaches the shop.
     *
     * The claim is an `INSERT` on `UNIQUE (live_key)` taken BEFORE the write,
     * which is the whole of `alpha.54`'s §1: `alpha.53`'s key could only
     * refuse the loser AFTER both had already replaced the three meta rows.
     */
    public function testASecondRunOnOneProductIsRefusedBeforeItTouchesTheShop(): void
    {
        $ledger = $this->ledger();
        $first = $this->port();
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            $this->migrate($this->service($first, $ledger, $this->db))
        );

        $second = $this->port();
        $outcome = $this->migrate($this->service($second, $ledger, $this->db));

        self::assertSame('already_decided', $outcome);
        self::assertSame([], $second->writes, 'the loser never wrote');
        self::assertSame(1, $this->countRows(), 'and there is still one decision');
    }

    // ===================================================== §2 continuation

    /**
     * THE FINDING: the batch stops at the failed product and the cursor does
     * not pass it.
     *
     * `alpha.53` returned the page's end whatever happened, so the middle
     * product was never looked at again by any later continuation.
     */
    public function testTheBatchStopsAtTheFailedProductAndNamesIt(): void
    {
        $ledger = $this->ledger();
        $port = new RefusingWritePort(self::MIDDLE);
        foreach ([self::FIRST, self::MIDDLE, self::LAST] as $id) {
            $port->present[$id] = true;
            $port->owned[$id] = true;
        }
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST, self::MIDDLE, self::LAST]);
        $service = new MigrateMinMax($reader, $ledger, $port, $this->db);

        $batch = $service->migrateBatch(1, 0);

        self::assertFalse($batch['ok'], 'a stopped batch is not an ok batch');
        self::assertSame('batch_stopped', $batch['code']);
        self::assertSame(self::MIDDLE, $batch['stopped_at'], 'and the product is named');
        self::assertSame([['post_id' => self::MIDDLE, 'outcome' => 'not_written']], $batch['failed']);
        self::assertSame(self::FIRST, $batch['cursor'], 'the cursor stops BEFORE the failure');
        self::assertLessThan(self::MIDDLE, $batch['cursor']);
        self::assertFalse($batch['done']);
        self::assertSame(1, $batch['counts'][MinMaxHandoverRepositoryInterface::MIGRATED]);
        self::assertSame(1, $batch['counts']['not_written']);
        self::assertNull($ledger->find(self::LAST), 'the batch did not run past the failure');
    }

    /**
     * Continuing re-reads the failed product and NOT the ones before it.
     *
     * Both halves matter: «محصول ناموفق گم نشود» and «موفقیت‌های قبلی دوباره
     * نوشته نشوند» are one cursor.
     */
    public function testContinuingRetriesTheFailedProductAndRewritesNothingEarlier(): void
    {
        $ledger = $this->ledger();
        $port = new RefusingWritePort(self::MIDDLE);
        foreach ([self::FIRST, self::MIDDLE, self::LAST] as $id) {
            $port->present[$id] = true;
            $port->owned[$id] = true;
        }
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST, self::MIDDLE, self::LAST]);
        $service = new MigrateMinMax($reader, $ledger, $port, $this->db);
        $stopped = $service->migrateBatch(1, 0);

        // The cause is removed, the manager presses continue with the stored
        // cursor, and the walk picks the failed product up again.
        $port->refuseFor = 0;
        $again = $service->migrateBatch(2, (int) $stopped['cursor']);

        self::assertTrue($again['ok']);
        self::assertSame(2, $again['counts'][MinMaxHandoverRepositoryInterface::MIGRATED]);
        self::assertSame(0, $again['counts']['already_decided'], 'the first product was not revisited');
        self::assertSame(
            [0, self::FIRST],
            $reader->asked,
            'the second page starts after the last FINISHED product'
        );
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            (string) ($ledger->find(self::MIDDLE)['status'] ?? '')
        );
        self::assertSame(['write:' . self::FIRST, 'write:' . self::MIDDLE, 'write:' . self::LAST], $port->writes);
    }

    // ===================================================== §3 rollback

    /** The ordinary rollback: three values, the record and the status together. */
    public function testRollbackPutsEverythingBackAndKeepsTheRowAsHistory(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2', '{"_tmc_min_purchase":"old"}');
        $this->migrate($this->service($port, $ledger, $this->db));
        self::assertSame('4/10/2', $port->describe(self::FIRST));

        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertTrue($result->ok, $result->code);
        self::assertSame('2/__absent__/2', $port->describe(self::FIRST), 'absent came back absent');
        self::assertNull($ledger->find(self::FIRST), 'no live decision stands');
        self::assertSame(1, $this->countRows(), 'and the row is still there, as history');
        self::assertCount(1, $ledger->history(self::FIRST));
        self::assertSame(
            MinMaxHandoverRepositoryInterface::ROLLED_BACK,
            (string) $ledger->history(self::FIRST)[0]['status']
        );
    }

    /**
     * THE FINDING: a restore that cannot finish leaves NOTHING half-done, and
     * says which part refused.
     */
    public function testAFailedRestoreStepLeavesTheDestinationWhereTheMigrationLeftIt(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $this->migrate($this->service($port, $ledger, $this->db));

        $port->refuseRestore = '_tmc_max_purchase';
        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_not_stored', $result->code);
        self::assertSame('_tmc_max_purchase', $result->context['part'], 'the part is named');
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            (string) ($ledger->find(self::FIRST)['status'] ?? ''),
            'the decision still stands, so the rollback can be tried again'
        );
        self::assertSame(1, $port->forgotten, 'and the half-written values are out of the cache');
    }

    /** A refused `markRolledBack()` takes the restore back with it. */
    public function testARefusedLedgerMarkTakesTheRestoreBackWithIt(): void
    {
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $this->migrate($this->service($port, $this->ledger(), $this->db));

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_minmax_handover', 'rolled_back_at']);
        $result = $this->service($port, new DbMinMaxHandoverRepository($failing), $failing)
            ->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_unrecorded', $result->code);
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            (string) ($this->ledger()->find(self::FIRST)['status'] ?? ''),
            'the ledger did not move'
        );
    }

    /** A rollback without a lock is refused rather than performed unprotected. */
    public function testWithoutTheRowLocksTheRollbackRefuses(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $this->migrate($this->service($port, $ledger, $this->db));

        $port->refuseLock = true;
        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_not_locked', $result->code);
        self::assertSame('4/10/2', $port->describe(self::FIRST), 'and nothing moved');
    }

    /** A newer value at the destination wins over the rollback, by name. */
    public function testANewerManagerValueRefusesTheRollbackAndIsKept(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $this->migrate($this->service($port, $ledger, $this->db));

        $port->rows[self::FIRST]['max'] = '8';
        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_changed', $result->code);
        self::assertSame('max', $result->context['field']);
        self::assertSame('10', $result->context['wrote']);
        self::assertSame('8', $result->context['now']);
        self::assertSame('4/8/2', $port->describe(self::FIRST), 'their value is untouched');
    }

    /** A rollback the caller's transaction owns is refused, as the migration is. */
    public function testRollbackRefusesInsideTheCallersTransaction(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $this->migrate($this->service($port, $ledger, $this->db));

        self::assertTrue($this->db->begin());
        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_caller_transaction', $result->code);
        self::assertTrue($this->db->inTransaction());
        $this->db->rollback();
    }

    // ===================================================== §4 re-migration

    /**
     * THE FINDING: migrate, roll back, migrate again — with correct history
     * each time and nothing deleted.
     */
    public function testAProductCanBeMigratedRolledBackAndMigratedAgain(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');

        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            $this->migrate($this->service($port, $ledger, $this->db))
        );
        self::assertTrue($this->service($port, $ledger, $this->db)->rollback(self::FIRST)->ok);
        $second = $this->migrate($this->service($port, $ledger, $this->db));

        self::assertSame(MinMaxHandoverRepositoryInterface::MIGRATED, $second, 'not already_decided');
        self::assertSame('4/10/2', $port->describe(self::FIRST));
        self::assertSame(2, $this->countRows(), 'two rows: the rolled-back one and the new one');
        $history = $ledger->history(self::FIRST);
        self::assertCount(2, $history);
        self::assertSame(MinMaxHandoverRepositoryInterface::MIGRATED, (string) $history[0]['status']);
        self::assertSame(MinMaxHandoverRepositoryInterface::ROLLED_BACK, (string) $history[1]['status']);
        self::assertSame(
            '2',
            (string) $history[1]['dest_min_before'],
            'and the first decision still remembers what it found'
        );
    }

    /** The second migration's rollback target is what the SECOND one found. */
    public function testTheSecondMigrationRecordsWhatItActuallyFound(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $this->migrate($this->service($port, $ledger, $this->db));
        $this->service($port, $ledger, $this->db)->rollback(self::FIRST);
        // Somebody changes the destination between the two migrations.
        $port->rows[self::FIRST] = ['min' => '3', 'max' => '9', 'step' => '3', 'record' => null];
        $this->migrate($this->service($port, $ledger, $this->db));

        self::assertTrue($this->service($port, $ledger, $this->db)->rollback(self::FIRST)->ok);
        self::assertSame('3/9/3', $port->describe(self::FIRST), 'back to what the second run found');
    }

    /** A refused product is released for a retry, and its record stays. */
    public function testARefusedProductCanBeReleasedForAnotherDecision(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        // `min=2 step=3` is the step-base difference: no shared quantity, so
        // the rule is refused by name rather than converted.
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST], '2/11/3');
        $service = new MigrateMinMax($reader, $ledger, $port, $this->db);
        $batch = $service->migrateBatch(1, 0);
        self::assertSame(1, $batch['counts'][MinMaxHandoverRepositoryInterface::REFUSED]);
        self::assertSame([], $port->writes, 'a refused rule writes nothing');

        $released = $service->release(self::FIRST);

        self::assertTrue($released->ok, $released->code);
        self::assertSame(MinMaxHandoverRepositoryInterface::REFUSED, $released->context['was']);
        self::assertNull($ledger->find(self::FIRST), 'claimable again');
        self::assertSame(1, $this->countRows(), 'and the refusal is still on record');
        self::assertSame(
            'step_base_differs',
            (string) $ledger->history(self::FIRST)[0]['problems'],
            'with the reason it was refused'
        );
    }

    /** A migrated product is NOT releasable: that transition is a rollback. */
    public function testAMigratedProductCannotBeReleased(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $this->migrate($this->service($port, $ledger, $this->db));

        $result = $this->service($port, $ledger, $this->db)->release(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_release_not_allowed', $result->code);
        self::assertSame('4/10/2', $port->describe(self::FIRST), 'and the rule is still applied');
    }

    /** A released refusal is counted apart from the live ones. */
    public function testAReleasedRefusalIsNotCountedAsStillBlocking(): void
    {
        $ledger = $this->ledger();
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST], '2/11/3');
        $service = new MigrateMinMax($reader, $ledger, $this->port(), $this->db);
        $service->migrateBatch(1, 0);
        self::assertSame(1, $ledger->tally()[MinMaxHandoverRepositoryInterface::REFUSED] ?? 0);

        $service->release(self::FIRST);

        $tally = $ledger->tally();
        self::assertSame(0, $tally[MinMaxHandoverRepositoryInterface::REFUSED] ?? 0);
        self::assertSame(1, $tally[MinMaxHandoverRepositoryInterface::REFUSED . '_released'] ?? 0);
    }

    // ===================================================== helpers

    private function ledger(): DbMinMaxHandoverRepository
    {
        return new DbMinMaxHandoverRepository($this->db);
    }

    private function port(): RecordingQuantityPort
    {
        $port = new RecordingQuantityPort();
        $port->present[self::FIRST] = true;
        $port->owned[self::FIRST] = true;
        return $port;
    }

    private function service(
        \Tecteb\Marketplace\Modules\Migration\Application\ShopQuantityPortInterface $port,
        MinMaxHandoverRepositoryInterface $ledger,
        ?\Tecteb\Marketplace\Contracts\TransactionInterface $transaction
    ): MigrateMinMax {
        return new MigrateMinMax(
            (new StubMinMaxReader())->withProducts([self::FIRST]),
            $ledger,
            $port,
            $transaction
        );
    }

    /** One product through the batch, and the outcome it produced. */
    private function migrate(MigrateMinMax $service): string
    {
        $batch = $service->migrateBatch(1, 0);
        foreach ($batch['counts'] as $outcome => $count) {
            if ($count > 0) {
                return (string) $outcome;
            }
        }
        return (string) ($batch['failed'][0]['outcome'] ?? '');
    }

    private function countRows(): int
    {
        return (int) ($this->db->getVar('SELECT COUNT(*) FROM `' . $this->table . '`') ?? -1);
    }
}
