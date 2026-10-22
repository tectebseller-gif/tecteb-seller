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

    // ============================ §5 the grant that makes a rule apply

    /**
     * A rule written onto a product the marketplace does not own can be
     * GRANTED, and the grant moves the meta key and the ledger status
     * together.
     *
     * The state it fixes was measured twice: `alpha.53` found that
     * `QuantityGuard::ruleFor()` returns before reading anything when the
     * product is not ours, so a non-marketplace product's `4|6|8|10` became
     * «any quantity allowed» the moment Min Max was switched off. The owner's
     * own preview reports twelve products in exactly that state.
     */
    public function testAnUnenforcedRuleCanBeGrantedAndTheGrantIsRecorded(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;      // not the marketplace's
        self::assertSame(
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            $this->migrate($this->service($port, $ledger, $this->db))
        );

        $result = $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        self::assertTrue($result->ok, $result->code);
        self::assertSame('minmax_rule_enforced', $result->code);
        self::assertTrue($port->enforcementGranted(self::FIRST), 'the product carries the grant');
        self::assertSame(
            MinMaxHandoverRepositoryInterface::ENFORCED,
            (string) ($ledger->find(self::FIRST)['status'] ?? '')
        );
        self::assertSame('4/10/2', $port->describe(self::FIRST), 'and the rule itself did not move');
    }

    /** The grant can be withdrawn, and withdrawing it is not a rollback. */
    public function testTheGrantCanBeWithdrawnWithoutTouchingTheRule(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $this->migrate($this->service($port, $ledger, $this->db));
        $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        $result = $this->service($port, $ledger, $this->db)->stopEnforcing(self::FIRST);

        self::assertTrue($result->ok, $result->code);
        self::assertFalse($port->enforcementGranted(self::FIRST));
        self::assertSame(
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            (string) ($ledger->find(self::FIRST)['status'] ?? '')
        );
        self::assertSame('4/10/2', $port->describe(self::FIRST), 'the three rows are untouched');
    }

    /**
     * A product the marketplace DOES own cannot be granted, because its rule
     * is already applied — and offering the grant would imply it was not.
     */
    public function testAMigratedProductIsNotGrantable(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();                  // owned by default
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            $this->migrate($this->service($port, $ledger, $this->db))
        );

        $result = $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_enforce_not_allowed', $result->code);
        self::assertSame(MinMaxHandoverRepositoryInterface::MIGRATED, $result->context['status']);
        self::assertFalse($port->enforcementGranted(self::FIRST));
    }

    /**
     * A rule somebody changed after the migration is not the rule the manager
     * is granting, so the grant is refused and names the field.
     */
    public function testTheGrantIsRefusedWhenTheRuleHasMovedSinceTheMigration(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $this->migrate($this->service($port, $ledger, $this->db));

        $port->rows[self::FIRST]['max'] = '8';  // somebody else's newer value

        $result = $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_enforce_changed', $result->code);
        self::assertSame('max', $result->context['field']);
        self::assertSame('10', $result->context['wrote']);
        self::assertSame('8', $result->context['now']);
        self::assertFalse($port->enforcementGranted(self::FIRST));
        self::assertSame(
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            (string) ($ledger->find(self::FIRST)['status'] ?? ''),
            'and the ledger did not move either'
        );
    }

    /** The grant and the ledger status are one unit: refuse one, get neither. */
    public function testAGrantThatCannotBeStoredRecordsNothing(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $this->migrate($this->service($port, $ledger, $this->db));

        $port->refuseGrant = true;
        $result = $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_enforce_not_stored', $result->code);
        self::assertSame(
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            (string) ($ledger->find(self::FIRST)['status'] ?? '')
        );
    }

    /**
     * The grant records the rule it was granted FOR.
     *
     * Stored as `'1'` it would mean «enforce whatever these rows hold next
     * week», which is the `alpha.49` rule broken one object down. The port
     * turns these three values into the fingerprint the cart compares
     * against; this double records them as they came, so the test can see
     * WHICH rule was granted rather than only that something was.
     */
    public function testTheGrantCarriesTheRuleItWasGrantedFor(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $this->migrate($this->service($port, $ledger, $this->db));

        $this->service($port, $ledger, $this->db)->enforce(self::FIRST);

        self::assertSame('4/10/2', $port->granted[self::FIRST] ?? '__none__');
        self::assertContains('grant:' . self::FIRST . ':4/10/2', $port->writes);
    }

    /**
     * AND IT CAN ALWAYS BE WITHDRAWN — including after the rule moved.
     *
     * The first cut of this round ran the drift check on both directions, so a
     * rule changed after the grant left the grant STUCK ON: the manager was
     * told «the rule changed» and the marketplace went on enforcing it.
     * Refusing to GIVE a permission on drift is conservative; refusing to TAKE
     * IT BACK is a lock on the wrong door.
     */
    public function testTheGrantCanBeWithdrawnEvenAfterTheRuleMoved(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $this->migrate($this->service($port, $ledger, $this->db));
        self::assertTrue($this->service($port, $ledger, $this->db)->enforce(self::FIRST)->ok);

        $port->rows[self::FIRST]['max'] = '8';  // somebody else's newer value

        $result = $this->service($port, $ledger, $this->db)->stopEnforcing(self::FIRST);

        self::assertTrue($result->ok, $result->code);
        self::assertSame('minmax_rule_unenforced', $result->code);
        self::assertFalse($port->enforcementGranted(self::FIRST), 'the grant came back');
        self::assertSame(
            MinMaxHandoverRepositoryInterface::UNENFORCED,
            (string) ($ledger->find(self::FIRST)['status'] ?? '')
        );
        self::assertSame('4/8/2', $port->describe(self::FIRST), 'and the newer value is untouched');
    }

    /** Rolling a granted product back takes the grant with it. */
    public function testRollingBackAGrantedProductRemovesTheGrantToo(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->owned[self::FIRST] = false;
        $port->seed(self::FIRST, '2', null, '2');
        $port->owned[self::FIRST] = false;      // `seed()` defaults it to owned
        $this->migrate($this->service($port, $ledger, $this->db));
        $this->service($port, $ledger, $this->db)->enforce(self::FIRST);
        self::assertTrue($port->enforcementGranted(self::FIRST));

        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertTrue($result->ok, $result->code);
        self::assertFalse($port->enforcementGranted(self::FIRST), 'the grant went with the rule');
        self::assertSame('2/__absent__/2', $port->describe(self::FIRST));
        self::assertNull($ledger->find(self::FIRST));
    }

    // =============================== §6 the lock and the read under it

    /**
     * THE FINDING (§6-الف): the destination is LOCKED BEFORE IT IS READ.
     *
     * `alpha.54` read the baseline, judged ownership and wrote, with the row
     * lock taken only on the rollback path. The ledger claim arbitrates two
     * migrations racing — that is the unique key — and has nothing to say
     * about a manager editing the purchase limits in wp-admin in between.
     *
     * Falsifiable in the one way that matters: with the lock refused, nothing
     * at all may happen. On `alpha.54`'s order this test cannot even run —
     * `decide()` never asked for a lock, so a refusal was unreachable.
     */
    public function testWithoutTheRowLockTheMigrationRefusesBeforeReadingAnything(): void
    {
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $port->refuseLock = true;

        $outcome = $this->migrate($this->service($port, $this->ledger(), $this->db));

        self::assertSame('not_locked', $outcome);
        self::assertSame([], $port->writes, 'the destination was never touched');
        self::assertSame('2/__absent__/2', $port->describe(self::FIRST));
        self::assertSame(0, $this->countRows(), 'and nothing was claimed');
    }

    /** The lock is taken, and it is taken before the write. */
    public function testTheLockComesBeforeTheWrite(): void
    {
        $port = $this->port();
        $this->migrate($this->service($port, $this->ledger(), $this->db));

        self::assertSame(['lock:' . self::FIRST], $port->locks);
        self::assertSame(['write:' . self::FIRST], $port->writes);
    }

    /**
     * A destination read that FAILED is not a destination that is empty.
     *
     * Taken for absence it would record `null/null/null` as «what was there
     * before», and the first rollback would delete three rows the migration
     * never wrote.
     */
    public function testAFailedDestinationReadRefusesInsteadOfReadingItAsAbsent(): void
    {
        $port = $this->port();
        $port->seed(self::FIRST, '2', '8', '2');
        $port->refuseRowRead = true;

        $outcome = $this->migrate($this->service($port, $this->ledger(), $this->db));

        self::assertSame('destination_unreadable', $outcome);
        self::assertSame([], $port->writes);
        self::assertSame(0, $this->countRows());
    }

    /**
     * Two rows for one meta key: refused by name, nothing written.
     *
     * `wp_postmeta` has no unique index on `(post_id, meta_key)` — measured
     * on WordPress 7.1 — so this is a state the real table can be in. A
     * restore's `update_post_meta()` rewrites every duplicate, so migrating
     * such a product would make its own rollback erase the duplicate's value.
     */
    public function testADuplicatedDestinationRowIsRefusedByName(): void
    {
        $port = $this->port();
        $port->seed(self::FIRST, '2', '8', '2');
        $port->duplicates[self::FIRST] = ['_tmc_max_purchase' => 1];

        $outcome = $this->migrate($this->service($port, $this->ledger(), $this->db));

        self::assertSame(MinMaxHandoverRepositoryInterface::REFUSED, $outcome);
        self::assertSame([], $port->writes, 'a refusal writes nothing');
        self::assertStringContainsString(
            'destination_duplicated',
            (string) ($this->ledger()->find(self::FIRST)['problems'] ?? ''),
            'and the reason is named in the ledger'
        );
    }

    /**
     * THE FINDING (§6-ب): the rollback compares what it LOCKED, so a read it
     * could not do is a refusal rather than «the rows are gone».
     *
     * On real WordPress the stale half comes from the object cache:
     * `get_metadata_raw()` answers from `wp_cache_get($id, 'post_meta')` and
     * only fills it when cold, so a copy warmed earlier in the request is
     * what `alpha.54` compared, lock or no lock. The double has no cache to
     * be stale, so what it checks is the caller's side — and the cache half
     * is measured on real WordPress by the probe, with the cache warmed on
     * purpose first.
     */
    public function testARollbackWhoseReadFailedRefusesRatherThanRestoring(): void
    {
        $ledger = $this->ledger();
        $port = $this->port();
        $port->seed(self::FIRST, '2', null, '2');
        $this->migrate($this->service($port, $ledger, $this->db));
        self::assertSame('4/10/2', $port->describe(self::FIRST));

        $port->refuseRowRead = true;
        $result = $this->service($port, $ledger, $this->db)->rollback(self::FIRST);

        self::assertFalse($result->ok);
        self::assertSame('minmax_rollback_unreadable', $result->code);
        self::assertSame('4/10/2', $port->describe(self::FIRST), 'nothing was restored');
        self::assertSame(
            MinMaxHandoverRepositoryInterface::MIGRATED,
            (string) ($ledger->find(self::FIRST)['status'] ?? ''),
            'so the rollback can be tried again once the read works'
        );
    }

    // ===================================================== helpers

    private function ledger(): DbMinMaxHandoverRepository
    {
        return new DbMinMaxHandoverRepository($this->db);
    }

    // ================================================ §5 the exit verdict

    /**
     * A LIVE, CONVERTIBLE RULE BLOCKS THE EXIT — and the blocker is named.
     *
     * The owner's §5 wants an explicit permission, and permission has to be
     * refused while a rule we have not taken is still being enforced by the
     * plugin we are about to switch off.
     */
    public function testAConvertibleRuleThatWasNotTakenBlocksTheExit(): void
    {
        $service = $this->service($this->port(), $this->ledger(), $this->db);
        $answer = $service->exitVerdict();

        self::assertSame('blocked', $answer['verdict']);
        self::assertFalse($answer['permitted']);
        self::assertSame(1, $answer['counts']['convertible']);
        self::assertSame(0, $answer['counts']['unknown']);
        self::assertSame(
            ['convertible_rules_not_migrated'],
            array_column($answer['blockers'], 'code')
        );
    }

    /**
     * AND A ROW THE SCAN DID NOT REACH IS `unknown`, NOT `convertible`.
     *
     * `preview()` is bounded, so the honest answer about what it did not look
     * at is a third number — the same rule as `alpha.68` §1: a failed or
     * missing read is not the safe answer with a different label.
     */
    public function testRowsTheScanDidNotReachAreCountedAsUnknown(): void
    {
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST]);
        // The source holds twelve rows and the walk reached one of them —
        // which is what a bounded scan on a real catalogue looks like.
        $reader->totalOverride = 12;
        $service = new MigrateMinMax($reader, $this->ledger(), $this->port(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame(12, $answer['counts']['source_rows']);
        self::assertSame(1, $answer['counts']['scanned']);
        self::assertSame(11, $answer['counts']['unknown']);
        self::assertContains('source_rows_unclassified', array_column($answer['blockers'], 'code'));
    }

    /**
     * AND A WALK THAT STOPPED EARLY IS ITS OWN BLOCKER, even when the two
     * counts agree.
     *
     * `preview()` also stops when a page reports no progress. The numbers
     * then look complete and are not, so the fact is reported separately
     * rather than folded into `unknown` — a count that is right about the
     * rows it saw cannot speak for the rows it did not.
     */
    public function testAWalkThatStoppedEarlyIsSaidEvenWhenTheCountsAgree(): void
    {
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST]);
        $reader->done = false;          // more pages, and the cursor stalls
        $service = new MigrateMinMax($reader, $this->ledger(), $this->port(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame(0, $answer['counts']['unknown']);
        self::assertContains('scan_did_not_finish', array_column($answer['blockers'], 'code'));
        self::assertFalse($answer['permitted']);
    }

    /**
     * AN INCOMPATIBLE RULE BLOCKS, AND THE PROBLEM IS NAMED.
     *
     * `step_base_differs` is the one measured on the real plugin: Min Max
     * counts multiples from the MINIMUM and this marketplace counts them
     * from zero, so `min=2 max=11 step=3` allows `2|5|8|11` there and
     * `3|6|9` here — no quantity in common. Nothing loosens it to reach a
     * yes: «هیچ قاعده‌ای برای تمام‌کردن انتقال خودکار سست یا حذف نشود».
     */
    public function testAnIncompatibleRuleBlocksAndItsProblemIsNamed(): void
    {
        $reader = (new StubMinMaxReader())->withProducts([self::FIRST], '2/11/3');
        $service = new MigrateMinMax($reader, $this->ledger(), $this->port(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame(0, $answer['counts']['convertible']);
        self::assertSame(1, $answer['counts']['incompatible']);
        self::assertArrayHasKey('step_base_differs', $answer['problems']);
        $blocker = $this->blocker($answer, 'incompatible_rules_present');
        self::assertStringContainsString('step_base_differs', (string) $blocker['detail']);
    }

    /**
     * A RULE ON A PRODUCT THAT IS NOT OURS BLOCKS TOO, under its own name.
     *
     * Taking it would write three rows nothing reads, so the limit that is
     * live today disappears when the plugin goes. That is a decision — grant
     * enforcement, or accept the loss — and it is kept APART from the
     * already-migrated-and-unenforced count, because they are answered on
     * different screens.
     */
    public function testARuleOnAProductWeDoNotOwnBlocksUnderItsOwnName(): void
    {
        $port = new RecordingQuantityPort();
        $port->present[self::FIRST] = true;
        $port->owned[self::FIRST] = false;
        $service = $this->service($port, $this->ledger(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame(0, $answer['counts']['convertible']);
        self::assertSame(1, $answer['counts']['would_be_unenforceable']);
        self::assertSame(0, $answer['counts']['migrated_unenforceable']);
        self::assertContains('rule_would_not_be_enforced', array_column($answer['blockers'], 'code'));
    }

    /**
     * AND WITH NOTHING LEFT, THE ANSWER IS A YES — otherwise the verdict
     * would be a guard that cannot be satisfied, which is the shape
     * `alpha.60` calls worse than the defect.
     */
    public function testWithNoRulesLeftTheExitIsPermitted(): void
    {
        $reader = (new StubMinMaxReader())->withProducts([]);
        $service = new MigrateMinMax($reader, $this->ledger(), $this->port(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame('permitted', $answer['verdict']);
        self::assertTrue($answer['permitted']);
        self::assertSame([], $answer['blockers']);
    }

    /**
     * «NOT INSTALLED» IS NOT «PERMITTED».
     *
     * A reader that cannot see the plugin cannot say its rules are dealt
     * with, and the third answer is what keeps a failed read from reading as
     * a clean bill — `alpha.68` §1, one module over.
     */
    public function testAnUnreadableSourceIsItsOwnAnswerAndNotAPermission(): void
    {
        $reader = new StubMinMaxReader();
        $reader->available = false;
        $service = new MigrateMinMax($reader, $this->ledger(), $this->port(), $this->db);

        $answer = $service->exitVerdict();

        self::assertSame('not_installed', $answer['verdict']);
        self::assertFalse($answer['permitted']);
        self::assertSame(['source_unreadable'], array_column($answer['blockers'], 'code'));
    }

    /** @param array<string,mixed> $answer @return array<string,mixed> */
    private function blocker(array $answer, string $code): array
    {
        foreach ((array) $answer['blockers'] as $item) {
            if (($item['code'] ?? '') === $code) {
                return (array) $item;
            }
        }
        self::fail('no blocker named ' . $code);
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
