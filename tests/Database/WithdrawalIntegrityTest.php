<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnRequest;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbShipmentRepository;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStoreRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * What the settlement path does when a write fails, and when somebody else
 * writes first (§3 and §4 of the `alpha.39` order).
 *
 * `SettlementFlowTest` is about the rules — who may ask, for how much, and
 * when. This file is about the two things that file cannot see: a statement
 * that fails half-way through a reservation or a release, and a decision taken
 * against a status that has already moved. Both are measured on real MariaDB,
 * and the four things the order asks to be measured are read before and after
 * every injected failure: the REQUEST, the RESERVE LINES, the ORDER ITEMS and
 * the VENDOR BALANCE.
 *
 * Two of these tests use a second OS process with its own connection
 * (`tests/Support/concurrent-withdrawal.php`), because a guard that lives in a
 * unique index or a `WHERE` clause cannot be measured on one connection: there
 * is nothing to contend for. Neither of them sleeps — the second process is
 * launched at a named point and waited for, so the statement under test always
 * runs against work another connection has already committed.
 */
final class WithdrawalIntegrityTest extends DatabaseTestCase
{
    private const VENDOR = 81;
    private const MANAGER = 9;

    private WpDatabase $db;
    private DbOrderItemRepository $orderItems;
    private DbWithdrawalRepository $withdrawals;
    private DbLedgerRepository $ledger;
    private DbStoreRepository $stores;
    private DbVendorRepository $vendors;
    private VendorBalance $balance;
    private DbShipmentRepository $returns;
    private RequestWithdrawal $request;
    private ReviewWithdrawals $review;
    private ManageOrderItems $orders;
    private SettingsService $settings;
    private SystemClock $clock;
    private AuditLogger $logger;
    private StaffAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $this->clock = new SystemClock();
        $this->logger = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $this->clock);
        $this->settings = new SettingsService(new WpOptionStore());

        $this->orderItems = new DbOrderItemRepository($this->db, $this->clock);
        $this->withdrawals = new DbWithdrawalRepository($this->db, $this->clock);
        $this->ledger = new DbLedgerRepository($this->db, $this->clock);
        $this->stores = new DbStoreRepository($this->db, $this->clock);
        $this->vendors = new DbVendorRepository($this->db, $this->clock);
        $this->access = new StaffAccess(new DbStaffRepository($this->db, $this->clock), $this->vendors);

        $this->returns = new DbShipmentRepository($this->db, $this->clock);
        // WITH the returns repository, the way `FinanceModule` wires it: a
        // balance that cannot see returns reports money the claim will refuse,
        // and then the two tests disagree about which one is wrong.
        $this->balance = new VendorBalance($this->orderItems, $this->settings, $this->clock, $this->returns);
        $this->request = $this->requestOver($this->db);
        $this->review = $this->reviewOver($this->db);
        $this->orders = new ManageOrderItems(
            $this->orderItems,
            $this->access,
            $this->logger,
            $this->clock,
            new OrderItemStateMachine(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );

        $this->vendors->upsertProfile(self::VENDOR, 'داروخانهٔ آزمون', true, false);
        $this->stores->saveBank(self::VENDOR, 'IR000000000000000000000000', 'دارندهٔ حساب', 0, 'approved', false);
        $this->settings->save($this->settings->load()->withSettlementDelayDays(0));
    }

    // ---------------------------------------------------------------- §3

    public function testAFailedRequestInsertLeavesNothingAtAll(): void
    {
        $itemId = $this->sold(201, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $before = $this->census();

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_withdrawals']);
        $result = $this->requestOver($failing)->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($result->ok);
        self::assertSame('reservation_lost', $result->code);
        self::assertSame($before, $this->census(), 'nothing moved');
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
    }

    public function testAFailedClaimLeavesNoRequestAndNoHeldLine(): void
    {
        $itemId = $this->sold(202, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $before = $this->census();

        // The claim is the statement that takes the money out of the balance.
        // Before `alpha.39` its result was never read, so this produced the
        // exact shape its own comment forbade: an open request blocking any
        // new one, with its lines still counted as withdrawable.
        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = %d']);
        $result = $this->requestOver($failing)->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($result->ok);
        self::assertSame('reservation_lost', $result->code);
        self::assertSame($before, $this->census());
        self::assertNull($this->itemRow($itemId)['withdrawal_id'], 'the line was never claimed');
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'and nothing blocks the next request');

        // And the vendor can still ask, for the same money.
        $retry = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertTrue($retry->ok, $retry->code);
        self::assertSame(900000, $retry->context['amount_minor']);
    }

    public function testAFailedLineInsertLeavesNoRequestAndNoHeldLine(): void
    {
        $itemId = $this->sold(203, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $before = $this->census();

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_withdrawal_lines']);
        $result = $this->requestOver($failing)->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($result->ok);
        self::assertSame('reservation_lost', $result->code);
        self::assertSame($before, $this->census());
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
    }

    public function testTheSecondLineFailingTakesTheFirstOneWithIt(): void
    {
        $first = $this->sold(204, 1000000, 900000);
        $second = $this->sold(205, 500000, 450000);
        $this->orders->recordSettlementCompletion($first, true);
        $this->orders->recordSettlementCompletion($second, true);
        $before = $this->census();

        // The half-written case: one line in, the next refused. A loop that
        // wrote without a transaction would leave a request whose amount no
        // set of lines backs.
        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_withdrawal_lines'], 2);
        $result = $this->requestOver($failing)->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($result->ok);
        self::assertSame($before, $this->census(), 'the first line went back too');
        self::assertNull($this->itemRow($first)['withdrawal_id']);
        self::assertNull($this->itemRow($second)['withdrawal_id']);
    }

    public function testAStaleAmountIsRefusedRatherThanReserved(): void
    {
        $itemId = $this->sold(206, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $before = $this->census();

        // «اگر بین محاسبهٔ مانده و رزرو، مرجوعی یا تغییر مؤثر دیگری رخ دهد،
        // مبلغ کهنه رزرو نشود.» Straight at the repository with yesterday's
        // figure: the sum is recomputed from the rows actually claimed, inside
        // the transaction, and a mismatch is refused.
        self::assertSame(0, $this->withdrawals->reserve(self::VENDOR, [$itemId], 950000, 'IR1', 'x'));
        self::assertSame($before, $this->census());
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);

        // The right figure still works, so the refusal was about the amount.
        self::assertGreaterThan(0, $this->withdrawals->reserve(self::VENDOR, [$itemId], 900000, 'IR1', 'x'));
    }

    public function testALineThatStoppedBeingEligibleIsNotReservedAtTheOldAmount(): void
    {
        $kept = $this->sold(207, 1000000, 900000);
        $returned = $this->sold(208, 500000, 450000);
        $this->orders->recordSettlementCompletion($kept, true);
        $this->orders->recordSettlementCompletion($returned, true);

        $balance = $this->balance->of(self::VENDOR);
        self::assertSame(1350000, $balance['eligible']);
        $ids = $balance['eligible_line_ids'];

        // The manager takes the completion off one line — the shape a return
        // leaves behind — AFTER the balance was read. The claim's `WHERE` sees
        // it, so the row count no longer matches and the stale 1,350,000 is
        // never written.
        self::assertTrue($this->orders->recordSettlementCompletion($returned, false)->ok);

        self::assertSame(0, $this->withdrawals->reserve(self::VENDOR, $ids, $balance['eligible'], 'IR1', 'x'));
        self::assertNull($this->withdrawals->openFor(self::VENDOR));
        self::assertNull($this->itemRow($kept)['withdrawal_id'], 'and the eligible line was not left claimed');

        // Asking again sees the smaller balance, which is the point.
        $retry = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertTrue($retry->ok, $retry->code);
        self::assertSame(900000, $retry->context['amount_minor']);
    }

    public function testAFailedReleaseIsReportedAndTheLinesAreStillHeld(): void
    {
        $itemId = $this->sold(209, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_order_items', 'SET withdrawal_id = NULL']);
        $review = $this->reviewOver($failing);
        self::assertTrue($review->startReview($withdrawalId)->ok);

        $rejected = $review->reject($withdrawalId, 'مدارک ناقص');
        self::assertFalse($rejected->ok, 'a release that failed is not a successful rejection');
        self::assertSame('release_failed', $rejected->code);
        self::assertSame($withdrawalId, $rejected->context['withdrawal_id']);

        // THE WHOLE THING WENT BACK, and this is what `alpha.40` changed.
        //
        // `alpha.39` let the status write stand and reported `release_failed`:
        // a REJECTED request still holding the vendor's money, which is
        // invisible to their eligible balance and invisible to any later
        // release, because the next one looks for `withdrawal_id = <id>` and
        // nothing else ever does. A true message about that state is not a
        // fix. Now the status change and the release are one unit of work, so
        // the request is still exactly where it was.
        self::assertSame(
            WithdrawalStatus::Reviewing,
            $this->withdrawals->find($withdrawalId)?->status,
            'the status change went back with the release'
        );
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id'], 'the lines are still reserved');
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId));
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible'], 'and still not askable');
        self::assertNotNull($this->withdrawals->openFor(self::VENDOR), 'the request is still OPEN');

        // So the retry is a plain retry, on the real service and a working
        // database — not a repair somebody has to know to perform.
        $again = $this->review->reject($withdrawalId, 'مدارک ناقص');
        self::assertTrue($again->ok, $again->code);
        self::assertSame(WithdrawalStatus::Rejected, $this->withdrawals->find($withdrawalId)?->status);
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertSame([], $this->withdrawals->lineIds($withdrawalId));
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);

        // And nothing was left for the stranded-reservation report to find.
        self::assertSame([], $this->withdrawals->strandedReservations());
    }

    public function testAFailedCancelReleaseIsReportedToTheVendor(): void
    {
        $itemId = $this->sold(210, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['DELETE FROM', 'tmc_withdrawal_lines']);
        $cancelled = $this->requestOver($failing)->cancel(self::VENDOR, self::VENDOR, $withdrawalId);

        self::assertFalse($cancelled->ok);
        self::assertSame('release_failed', $cancelled->code);
        // The status it reports is where the request STILL IS, not where the
        // cancel wanted to put it: nothing was committed.
        self::assertSame(WithdrawalStatus::Requested->value, $cancelled->context['status']);
        self::assertSame(
            WithdrawalStatus::Requested,
            $this->withdrawals->find($withdrawalId)?->status,
            'the cancel went back whole'
        );
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId), 'the lines are still on the request');
        self::assertNotNull($this->withdrawals->openFor(self::VENDOR), 'and the request is still open');
        self::assertSame([], $this->withdrawals->strandedReservations(), 'nothing was stranded');

        // The vendor tries again on a working database and it just works.
        $retry = $this->request->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertTrue($retry->ok, $retry->code);
        self::assertSame(WithdrawalStatus::Cancelled, $this->withdrawals->find($withdrawalId)?->status);
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    public function testACancelWithNoUnitOfWorkIsRefusedRatherThanDoneInTwoHalves(): void
    {
        $itemId = $this->sold(220, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        // Constructed the way every caller before `alpha.40` constructed it.
        $without = new RequestWithdrawal(
            $this->withdrawals,
            $this->balance,
            new SettlementGate(new FakeTrialUnlock(true)),
            $this->access,
            $this->stores,
            new WithdrawalStateMachine(),
            $this->logger
        );
        $refused = $without->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertFalse($refused->ok);
        self::assertSame('cancel_not_atomic', $refused->code);
        self::assertSame(WithdrawalStatus::Requested, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId));
    }

    public function testARejectionWithNoUnitOfWorkIsRefusedRatherThanDoneInTwoHalves(): void
    {
        $itemId = $this->sold(221, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        $without = new ReviewWithdrawals(
            $this->withdrawals,
            $this->ledger,
            new WithdrawalStateMachine(),
            $this->logger,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );
        self::assertTrue($without->startReview($withdrawalId)->ok, 'a one-row transition needs no unit of work');
        $refused = $without->reject($withdrawalId, 'مدارک ناقص');
        self::assertFalse($refused->ok);
        self::assertSame('release_not_atomic', $refused->code);
        self::assertSame(WithdrawalStatus::Reviewing, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId));
    }

    // -------------- §3: every eligibility condition, enforced at claim time

    /**
     * WHAT THIS PROVES: a condition that changes between the balance and the
     * reservation refuses the reservation — for each of the three conditions
     * `alpha.39`'s claim did not carry.
     *
     * **Why the amount comparison was not enough.** `alpha.39` put four of
     * `VendorBalance`'s six conditions in the claim's `WHERE` and leaned on
     * «the sum of the claimed shares equals the amount asked for» for the
     * rest. That proves a SUM, which is a different claim: a line that has
     * been cancelled, or whose waiting period has not elapsed, or that has
     * picked up a blocking return, carries exactly the `vendor_share_minor` it
     * carried a moment earlier. The sum matches and the reservation is
     * invalid.
     *
     * Each case changes the state AFTER the balance is read — with SQL where
     * no service produces it — and then asks the repository to reserve the
     * figure the balance gave. All three must refuse with nothing written.
     *
     * @dataProvider conditionsThatCanChange
     */
    public function testAConditionThatChangesAfterTheBalanceRefusesTheReservation(string $case): void
    {
        $itemId = $this->sold(240, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);

        $balance = $this->balance->of(self::VENDOR);
        self::assertSame(900000, $balance['eligible'], 'the line is eligible when the balance is read');
        self::assertSame([$itemId], $balance['eligible_line_ids']);
        // The WRITE side only. `eligible` is expected to change in every one
        // of these cases — that is the scenario — so comparing the whole
        // census would be comparing the thing under test with itself.
        $before = $this->writeCensus();

        // …and then the world moves.
        $items = $this->wpdb->prefix . 'tmc_order_items';
        switch ($case) {
            case 'cancelled':
                // `settlementView()` filters `status <> 'cancelled'`, so the
                // balance never sees such a line — and the claim must not
                // take one.
                $this->wpdb->pdo()->exec("UPDATE `{$items}` SET status = 'cancelled' WHERE id = {$itemId}");
                break;
            case 'waiting_period':
                // The completion moves forward, past the cutoff the balance
                // used. On the real site this is a manager re-recording it.
                $this->wpdb->pdo()->exec(
                    "UPDATE `{$items}` SET settlement_completed_at = '2099-01-01 00:00:00' WHERE id = {$itemId}"
                );
                break;
            case 'blocking_return':
                // UX §10.2: a return still being decided takes the share out
                // of what may be asked for. Opened through the real
                // repository, so the status list this claim derives from
                // `ReturnStatus::reservesQuantity()` is the one being measured.
                $returnId = $this->returns->openReturn(new ReturnRequest(
                    0,
                    $itemId,
                    self::VENDOR,
                    1,
                    ReturnStatus::Requested,
                    'آزمون',
                    '',
                    requestedBy: self::MANAGER,
                    requestedAt: '2030-06-01 09:00:00'
                ));
                self::assertGreaterThan(0, $returnId);
                self::assertSame(1, $this->returns->returnedQuantity($itemId), 'the return really holds the line');
                break;
            default:
                self::fail('unknown case ' . $case);
        }

        $reserved = $this->withdrawals->reserve(
            self::VENDOR,
            $balance['eligible_line_ids'],
            $balance['eligible'],
            'IR000000000000000000000000',
            'دارندهٔ حساب',
            $balance['eligible_until']
        );

        self::assertSame(0, $reserved, 'the reservation must be refused: ' . $case);
        self::assertSame($before, $this->writeCensus(), 'and nothing at all was written');
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'no half-made request is left behind');
        // And the balance agrees the line stopped qualifying, so the refusal
        // and the read tell one story rather than two.
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
    }

    /** @return array<string,array{0:string}> */
    public static function conditionsThatCanChange(): array
    {
        return [
            'the line is cancelled' => ['cancelled'],
            'the waiting period has not elapsed' => ['waiting_period'],
            'a return is still counted against it' => ['blocking_return'],
        ];
    }

    /**
     * The positive half, which the three refusals above need: on an unchanged
     * eligible line the same call reserves. Without this, a claim whose
     * `WHERE` had become impossible would pass all three.
     */
    public function testAnUnchangedEligibleLineIsStillReserved(): void
    {
        $itemId = $this->sold(241, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $balance = $this->balance->of(self::VENDOR);

        $withdrawalId = $this->withdrawals->reserve(
            self::VENDOR,
            $balance['eligible_line_ids'],
            $balance['eligible'],
            'IR000000000000000000000000',
            'دارندهٔ حساب',
            $balance['eligible_until']
        );

        self::assertGreaterThan(0, $withdrawalId);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId));
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['reserved']);
    }

    /**
     * WHAT THIS PROVES: a rejected return puts the line straight back, with no
     * further action — so the claim's new condition blocks the right set and
     * not a wider one.
     *
     * `VendorBalance` says exactly this («a rejected return puts the line
     * straight back into `eligible`»), and a `NOT EXISTS` over the wrong
     * status list would quietly keep the money unaskable for ever.
     */
    public function testAReturnThatWasRejectedStopsBlockingTheLine(): void
    {
        $itemId = $this->sold(242, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $returnId = $this->returns->openReturn(new ReturnRequest(
            0,
            $itemId,
            self::VENDOR,
            1,
            ReturnStatus::Requested,
            'آزمون',
            '',
            requestedBy: self::MANAGER,
            requestedAt: '2030-06-01 09:00:00'
        ));
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible'], 'blocked while undecided');

        self::assertTrue($this->returns->updateReturnStatus($returnId, ReturnStatus::Rejected, self::MANAGER, 'رد شد', null, null));
        self::assertSame(0, $this->returns->returnedQuantity($itemId), 'a rejected return holds nothing');

        $balance = $this->balance->of(self::VENDOR);
        self::assertSame(900000, $balance['eligible'], 'and the money is askable again');
        self::assertGreaterThan(0, $this->withdrawals->reserve(
            self::VENDOR,
            $balance['eligible_line_ids'],
            $balance['eligible'],
            'IR000000000000000000000000',
            'دارندهٔ حساب',
            $balance['eligible_until']
        ));
    }

    // ---------------------------------------------------------------- §4

    /**
     * The owner's scenario, and it needs a SECOND CONNECTION now.
     *
     * `alpha.39` scheduled the manager's move with `InterferingDatabase`, in
     * one process, on one connection — fine then, because the vendor's cancel
     * was not transactional. It is one unit of work in `alpha.40`, and that
     * changes what a same-connection interleaving can model: the manager's
     * write would land INSIDE the vendor's open transaction, and the vendor's
     * rollback would take it with it. A third party on your own connection is
     * not a third party.
     *
     * So the manager moves the request from a process of its own
     * (`tests/Support/concurrent-withdrawal.php`), launched at the named point
     * and waited for, so its work is COMMITTED before the statement under
     * test runs. Nothing sleeps. This is a scheduled two-connection
     * interleaving and is not presented as two statements in one instant.
     */
    public function testAVendorsStaleCancelCannotOverwriteAManagersNewerStatus(): void
    {
        $itemId = $this->sold(211, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertTrue($this->review->startReview($withdrawalId)->ok);
        self::assertTrue($this->review->approve($withdrawalId)->ok);

        // The vendor's cancel has read «Approved» and validated against it.
        // The manager's move to «PaymentInProgress» lands in the gap before
        // the vendor's own UPDATE.
        $child = null;
        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, function () use (&$child, $withdrawalId): void {
            $child = $this->secondConnection(
                'move',
                (string) $withdrawalId,
                WithdrawalStatus::PaymentInProgress->value,
                WithdrawalStatus::Approved->value
            );
        });

        $cancelled = $this->requestOver($interfering)->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertSame('moved', $child, 'the other connection has to have moved it');

        self::assertFalse($cancelled->ok, 'the stale cancel must not win');
        self::assertSame('withdrawal_moved_on', $cancelled->code);
        self::assertSame(WithdrawalStatus::Approved->value, $cancelled->context['from']);
        self::assertSame(WithdrawalStatus::PaymentInProgress->value, $cancelled->context['now']);

        // The manager's status stands, and the money is still reserved for the
        // transfer being prepared.
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame([], $this->withdrawals->strandedReservations(), 'and nothing was stranded');
    }

    public function testAStaleCancelCannotOverwriteAPaidRequestOrFreeItsLines(): void
    {
        $itemId = $this->sold(222, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);

        // The vendor's page still reads «Approved». The transfer happens — on
        // ANOTHER CONNECTION, because a payment is a status and a ledger
        // document in one transaction, and doing it on this connection would
        // put it inside the vendor's own unit of work for the vendor's
        // rollback to undo. The first version of this test did exactly that
        // and reported «Approved» for a request that had been paid.
        $child = null;
        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, function () use (&$child, $withdrawalId): void {
            $child = $this->secondConnection('pay', (string) $withdrawalId, 'TRACE-STALE');
        });

        $cancelled = $this->requestOver($interfering)->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertSame('paid', $child, 'the payment has to have happened');

        self::assertFalse($cancelled->ok, 'a paid request is not cancellable by a stale click');
        self::assertSame('withdrawal_moved_on', $cancelled->code);
        self::assertSame(WithdrawalStatus::Paid, $this->withdrawals->find($withdrawalId)?->status);
        // Paid keeps its lines for ever: they are the record of what the
        // payment covered, and «freed» would mean paying for them twice.
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId));
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['paid']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        // And a paid request is NOT a stranded reservation, by design.
        self::assertSame([], $this->withdrawals->strandedReservations());
    }

    public function testTwoManagersCannotBothDecideTheSameRequest(): void
    {
        $itemId = $this->sold(212, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertTrue($this->review->startReview($withdrawalId)->ok);

        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, function () use ($withdrawalId): void {
            self::assertTrue($this->review->reject($withdrawalId, 'مدارک ناقص')->ok);
        });

        // The second manager validated «approve» against «Reviewing»; by the
        // time the write lands the request is rejected and its lines are back.
        $approved = $this->reviewOver($interfering)->approve($withdrawalId);
        self::assertFalse($approved->ok);
        self::assertSame('withdrawal_moved_on', $approved->code);
        self::assertSame(WithdrawalStatus::Rejected, $this->withdrawals->find($withdrawalId)?->status);
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
    }

    public function testAPaidRequestIsNeverMovedAgainAndIsNotPaidTwice(): void
    {
        $itemId = $this->sold(213, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);
        self::assertTrue($this->review->recordPayment($withdrawalId, 'TRACE-1')->ok);

        $entries = count($this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid'));
        self::assertSame(2, $entries, 'one balanced pair');

        // Confirming the same payment again: no second document, and no
        // second status write either.
        $again = $this->review->recordPayment($withdrawalId, 'TRACE-1');
        self::assertFalse($again->ok, 'nothing validates a transition out of Paid');
        self::assertSame('invalid_transition', $again->code);
        self::assertSame($entries, count($this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid')));

        // And nothing takes it back: not the vendor, not a manager.
        self::assertSame('invalid_transition', $this->request->cancel(self::VENDOR, self::VENDOR, $withdrawalId)->code);
        self::assertSame('invalid_transition', $this->review->reject($withdrawalId, 'پشیمان شدم')->code);
        self::assertSame(WithdrawalStatus::Paid, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['paid']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible'], 'paid money never becomes eligible again');
    }

    public function testAFailedStatusWriteLeavesNoPaymentDocumentBehind(): void
    {
        $itemId = $this->sold(214, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);

        // The ledger write succeeds and the status write fails — the pair
        // `alpha.38` could leave desynchronised, with a payout on the books
        // against a request that still read «PaymentInProgress» and was
        // therefore payable again.
        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_withdrawals', 'SET status = %s']);
        $paid = $this->reviewOver($failing)->recordPayment($withdrawalId, 'TRACE-2');

        self::assertFalse($paid->ok);
        self::assertSame('withdrawal_moved_on', $paid->code);
        self::assertSame([], $this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid'), 'no document');
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);

        // Which means the payment can still be recorded properly afterwards.
        self::assertTrue($this->review->recordPayment($withdrawalId, 'TRACE-2')->ok);
        self::assertCount(2, $this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid'));
    }

    public function testAFailedLedgerWriteLeavesTheRequestPayable(): void
    {
        $itemId = $this->sold(215, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_ledger']);
        $paid = $this->reviewOver($failing)->recordPayment($withdrawalId, 'TRACE-3');

        self::assertFalse($paid->ok);
        self::assertSame('ledger_unwritable', $paid->code);
        self::assertSame(
            WithdrawalStatus::PaymentInProgress,
            $this->withdrawals->find($withdrawalId)?->status,
            'a status saying «paid» with no entry behind it is the failure FIN-03 exists to prevent'
        );
        self::assertSame('', (string) ($this->withdrawals->find($withdrawalId)?->reference ?? 'x'));
    }

    public function testAPayoutIsRefusedWhenTheVendorsBooksNameNoUnit(): void
    {
        // A line with a share and no ledger event: the shape `alpha.38` could
        // leave behind when the item write succeeded and the accrual did not.
        // The payout used to be stamped `Money::of($minor)` — «IRR», exponent
        // 0 — whatever the vendor's books said. Now the unit is read off them,
        // and books that name none are refused by name.
        $itemId = $this->sold(216, 1000000, 900000, false);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);

        $refused = $this->review->recordPayment($withdrawalId, 'TRACE-4');
        self::assertFalse($refused->ok);
        self::assertSame('payout_unit_unknown', $refused->code);
        self::assertSame(self::VENDOR, $refused->context['vendor_id']);
        self::assertSame([], $this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid'));
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);
    }

    public function testRecordingAPaymentWithoutAUnitOfWorkIsRefusedRatherThanDoneUnsafely(): void
    {
        $itemId = $this->sold(217, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);

        // Constructed the way every caller before `alpha.39` constructed it.
        // The two writes cannot be made atomic, so the payment is refused
        // rather than attempted — and a payment document is not a place to
        // find that out afterwards.
        $without = new ReviewWithdrawals(
            $this->withdrawals,
            $this->ledger,
            new WithdrawalStateMachine(),
            $this->logger,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );
        self::assertSame('payment_not_atomic', $without->recordPayment($withdrawalId, 'TRACE-5')->code);
        self::assertSame([], $this->ledger->forEvent('withdrawal:' . $withdrawalId . ':paid'));
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);
    }

    // -------------------------------- §2: the rows alpha.39 may have stranded

    /**
     * WHAT THIS PROVES: a closed request that still holds money is findable,
     * and freeing it is a named act with three refusals in front of it.
     *
     * The state is built the way `alpha.39` built it — the status write
     * standing while the release lost — which `alpha.40` cannot produce any
     * more, so it is written with SQL. The point is to MEET it.
     */
    public function testAClosedRequestStillHoldingMoneyIsListedAndCanBeFreedOnPurpose(): void
    {
        $itemId = $this->sold(230, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertSame([], $this->withdrawals->strandedReservations(), 'an OPEN request is not stranded');

        // `alpha.39`'s end state: rejected on paper, still holding the lines.
        $table = $this->wpdb->prefix . 'tmc_withdrawals';
        $this->wpdb->pdo()->exec(
            "UPDATE `{$table}` SET status = 'rejected', open_marker = NULL WHERE id = {$withdrawalId}"
        );

        $stranded = $this->withdrawals->strandedReservations();
        self::assertCount(1, $stranded);
        self::assertSame($withdrawalId, $stranded[0]['withdrawal_id']);
        self::assertSame(self::VENDOR, $stranded[0]['vendor_user_id']);
        self::assertSame('rejected', $stranded[0]['status']);
        self::assertSame(1, $stranded[0]['claimed_items']);
        self::assertSame(1, $stranded[0]['reserve_lines']);
        // The money really is invisible: not eligible, and not reported as
        // reserved against anything a vendor can see finishing.
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);

        // A manager sees it, and somebody without the capability does not.
        $report = $this->review->strandedReservations();
        self::assertTrue($report['allowed']);
        self::assertCount(1, $report['rows']);
        self::assertFalse($this->reviewAs(0, [])->strandedReservations()['allowed']);

        // Freed on purpose, by id.
        $freed = $this->review->releaseStranded($withdrawalId);
        self::assertTrue($freed->ok, $freed->code);
        self::assertSame([], $this->withdrawals->strandedReservations());
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
        // The DECISION is untouched: this repairs a reservation, it does not
        // reopen a rejection.
        self::assertSame(WithdrawalStatus::Rejected, $this->withdrawals->find($withdrawalId)?->status);
    }

    public function testTheOtherHalfOfTheStrandedShapeIsFoundToo(): void
    {
        // `alpha.39`'s `release()` had no transaction: the `DELETE` on the
        // reserve lines could succeed while the `UPDATE` that unclaims the
        // items failed. That leaves the money held with NO record of which
        // request holds it — measured in `tools/alpha38-reproduction.sh` as
        // `reserve_lines=0 claimed_items=1`. The report has to see that too.
        $itemId = $this->sold(231, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        $requests = $this->wpdb->prefix . 'tmc_withdrawals';
        $lines = $this->wpdb->prefix . 'tmc_withdrawal_lines';
        $this->wpdb->pdo()->exec("UPDATE `{$requests}` SET status = 'cancelled', open_marker = NULL WHERE id = {$withdrawalId}");
        $this->wpdb->pdo()->exec("DELETE FROM `{$lines}` WHERE withdrawal_id = {$withdrawalId}");

        $stranded = $this->withdrawals->strandedReservations();
        self::assertCount(1, $stranded);
        self::assertSame(1, $stranded[0]['claimed_items']);
        self::assertSame(0, $stranded[0]['reserve_lines'], 'the record of who holds it is gone');

        self::assertTrue($this->review->releaseStranded($withdrawalId)->ok);
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    public function testFreeingAStrandedReservationRefusesAnOpenOrPaidRequest(): void
    {
        $itemId = $this->sold(232, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        // OPEN: its lines are reserved on purpose. Freeing them would leave an
        // open request backed by nothing.
        $open = $this->review->releaseStranded($withdrawalId);
        self::assertFalse($open->ok);
        self::assertSame('withdrawal_still_open', $open->code);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);

        // PAID: the lines are the record of what the payment covered, and
        // freeing them would make the same money askable twice.
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);
        self::assertTrue($this->review->recordPayment($withdrawalId, 'TRACE-S')->ok);

        $paid = $this->review->releaseStranded($withdrawalId);
        self::assertFalse($paid->ok);
        self::assertSame('withdrawal_paid', $paid->code);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['paid']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);

        // And a request nobody has is not freed either.
        self::assertSame('not_found', $this->review->releaseStranded(999999)->code);
    }

    // ------------------------------------------- two independent connections

    public function testASecondConnectionTakingTheRequestFirstGetsTheOnlyOne(): void
    {
        $itemId = $this->sold(218, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $balance = $this->balance->of(self::VENDOR);

        // A second process, with a connection of its own, reserves the same
        // lines in the gap before this one's first write. There is nothing
        // locked yet, so it runs to completion and COMMITS — and this
        // reservation then has to lose on the unique index, under InnoDB,
        // rather than on a check in PHP.
        $child = null;
        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['INSERT INTO', 'tmc_withdrawals'], 1, function () use (&$child, $balance): void {
            $child = $this->secondConnection(
                'reserve',
                (string) self::VENDOR,
                (string) $balance['eligible'],
                implode(',', $balance['eligible_line_ids'])
            );
        });

        $mine = $this->requestOver($interfering)->handle(self::VENDOR, self::VENDOR);
        self::assertNotSame([], $interfering->fired, 'the second connection has to have run');
        self::assertIsString($child);
        self::assertGreaterThan(0, (int) $child, 'the other connection got its request');

        self::assertFalse($mine->ok, 'and this one did not');
        self::assertSame('reservation_lost', $mine->code);
        self::assertCount(1, $this->withdrawals->forVendor(self::VENDOR), 'exactly one request exists');
        self::assertSame((int) $child, $this->itemRow($itemId)['withdrawal_id'], 'held by the one that won');
        self::assertSame([$itemId], $this->withdrawals->lineIds((int) $child));
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['reserved']);
    }

    public function testAStatusMovedByAnotherConnectionRefusesTheOlderDecision(): void
    {
        $itemId = $this->sold(219, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        self::assertTrue($this->review->approve($withdrawalId)->ok);

        $child = null;
        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, function () use (&$child, $withdrawalId): void {
            $child = $this->secondConnection(
                'move',
                (string) $withdrawalId,
                WithdrawalStatus::PaymentInProgress->value,
                WithdrawalStatus::Approved->value
            );
        });

        $cancelled = $this->requestOver($interfering)->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertSame('moved', $child, 'the other connection moved it');
        self::assertFalse($cancelled->ok);
        self::assertSame('withdrawal_moved_on', $cancelled->code);
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
    }

    // ------------------------------------------------------------- fixture

    private function requestOver(DatabaseInterface $db): RequestWithdrawal
    {
        $withdrawals = new DbWithdrawalRepository($db, $this->clock);
        return new RequestWithdrawal(
            $withdrawals,
            $this->balance,
            new SettlementGate(new FakeTrialUnlock(true)),
            $this->access,
            $this->stores,
            new WithdrawalStateMachine(),
            $this->logger,
            // The same gateway as the unit of work: a cancel is the status
            // change and the release together or neither, and a build without
            // one refuses by name (`cancel_not_atomic`).
            $db
        );
    }

    /** @param list<string> $caps */
    private function reviewAs(int $userId, array $caps): ReviewWithdrawals
    {
        return new ReviewWithdrawals(
            $this->withdrawals,
            $this->ledger,
            new WithdrawalStateMachine(),
            $this->logger,
            new FakeCapabilityChecker($userId, $caps),
            $this->db
        );
    }

    private function reviewOver(DatabaseInterface $db): ReviewWithdrawals
    {
        return new ReviewWithdrawals(
            new DbWithdrawalRepository($db, $this->clock),
            new DbLedgerRepository($db, $this->clock),
            new WithdrawalStateMachine(),
            $this->logger,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            $db
        );
    }

    /**
     * The four things §3 asks to be measured, in one value.
     *
     * Compared as a whole rather than one assertion per table, so a failure
     * names everything that moved instead of the first thing.
     *
     * @return array<string,mixed>
     */
    private function census(): array
    {
        $balance = $this->balance->of(self::VENDOR);
        return [
            'requests' => count($this->withdrawals->forVendor(self::VENDOR, 500)),
            'open' => $this->withdrawals->openFor(self::VENDOR)?->id,
            'lines' => (int) $this->wpdb->pdo()
                ->query('SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_withdrawal_lines`')
                ->fetchColumn(),
            'claimed_items' => (int) $this->wpdb->pdo()
                ->query('SELECT COUNT(*) FROM `' . $this->wpdb->prefix . 'tmc_order_items` WHERE withdrawal_id IS NOT NULL')
                ->fetchColumn(),
            'eligible' => $balance['eligible'],
            'reserved' => $balance['reserved'],
            'paid' => $balance['paid'],
        ];
    }

    /**
     * The four write-side facts, without the balance.
     *
     * `census()` includes `eligible`, which is right for an injected-failure
     * test — nothing should move — and wrong for an eligibility test, where
     * the balance changing IS the premise.
     *
     * @return array<string,mixed>
     */
    private function writeCensus(): array
    {
        $census = $this->census();
        unset($census['eligible']);
        return $census;
    }

    /** @return array<string,mixed> */
    private function itemRow(int $id): array
    {
        $stmt = $this->wpdb->pdo()->prepare(
            'SELECT withdrawal_id, vendor_share_minor, ledger_event FROM `'
            . $this->wpdb->prefix . 'tmc_order_items` WHERE id = ?'
        );
        $stmt->execute([$id]);
        /** @var array<string,mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'order item ' . $id . ' should exist');
        return [
            'withdrawal_id' => $row['withdrawal_id'] === null ? null : (int) $row['withdrawal_id'],
            'ledger_event' => (string) $row['ledger_event'],
        ];
    }

    private function openId(): int
    {
        $open = $this->withdrawals->openFor(self::VENDOR);
        self::assertNotNull($open);
        return $open->id;
    }

    /**
     * Run one verb on a connection this process does not own.
     *
     * The child inherits the three `TMC_TEST_DB_*` variables and refuses any
     * DSN that is not the disposable `tmc_test`, the same as the suite itself.
     */
    private function secondConnection(string ...$args): string
    {
        $command = escapeshellcmd(PHP_BINARY)
            . ' ' . escapeshellarg(dirname(__DIR__) . '/Support/concurrent-withdrawal.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $out = [];
        $status = 0;
        exec($command . ' 2>&1', $out, $status);
        self::assertSame(0, $status, 'second connection failed: ' . implode("\n", $out));
        return trim(implode('', $out));
    }

    private function sold(int $orderItemId, int $baseMinor, int $shareMinor, bool $onTheBooks = true): int
    {
        if ($onTheBooks) {
            $base = Money::of($baseMinor);
            $share = Money::of($shareMinor);
            self::assertTrue($this->ledger->record(
                (new LedgerTransaction('order:' . $orderItemId, self::VENDOR, 'order:' . $orderItemId, (string) $orderItemId))
                    ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                    ->add(LedgerAccount::Commission, Money::of($baseMinor - $shareMinor)->negate(), 'commission_due')
                    ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
                    ->add(LedgerAccount::TaxCollected, $base->zero(), 'tax_collected')
            ));
        }
        return $this->orderItems->record(new VendorOrderItem(
            0,
            6000 + $orderItemId,
            $orderItemId,
            0,
            self::VENDOR,
            'کالای آزمایشی',
            'SKU-' . $orderItemId,
            1,
            $baseMinor,
            $baseMinor,
            0,
            $baseMinor - $shareMinor,
            $shareMinor,
            1000,
            'general',
            $onTheBooks ? 'order:' . $orderItemId : '',
            OrderItemStatus::Delivered
        ));
    }
}
