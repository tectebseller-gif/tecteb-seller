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
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
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

        $this->balance = new VendorBalance($this->orderItems, $this->settings, $this->clock);
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

        // The status DID move — that write succeeded — and the lines are still
        // held, which is exactly why the caller has to be told. A success
        // message here would have hidden money inside a closed request.
        self::assertSame(WithdrawalStatus::Rejected, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);

        // And a person can finish it: the second release, on a working
        // database, frees them.
        self::assertTrue($this->withdrawals->release($withdrawalId));
        self::assertNull($this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
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
        self::assertSame(WithdrawalStatus::Cancelled->value, $cancelled->context['status']);
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId), 'the lines are still on the request');
    }

    // ---------------------------------------------------------------- §4

    public function testAVendorsStaleCancelCannotOverwriteAManagersNewerStatus(): void
    {
        $itemId = $this->sold(211, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertTrue($this->review->startReview($withdrawalId)->ok);
        self::assertTrue($this->review->approve($withdrawalId)->ok);

        // The owner's scenario, scheduled rather than raced for: the vendor's
        // cancel has read «Approved» and validated against it; the manager's
        // move to «PaymentInProgress» lands in the gap before the vendor's
        // UPDATE. The manager goes through a service on the plain gateway, so
        // it cannot re-trigger the rule.
        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['UPDATE', 'tmc_withdrawals', 'SET status = %s'], 1, function (): void {
            self::assertTrue($this->review->startPayment($this->openId())->ok);
        });

        $cancelled = $this->requestOver($interfering)->cancel(self::VENDOR, self::VENDOR, $withdrawalId);
        self::assertNotSame([], $interfering->fired, 'the interference has to have run');

        self::assertFalse($cancelled->ok, 'the stale cancel must not win');
        self::assertSame('withdrawal_moved_on', $cancelled->code);
        self::assertSame(WithdrawalStatus::Approved->value, $cancelled->context['from']);
        self::assertSame(WithdrawalStatus::PaymentInProgress->value, $cancelled->context['now']);

        // The manager's status stands, and the money is still reserved for the
        // transfer being prepared.
        self::assertSame(WithdrawalStatus::PaymentInProgress, $this->withdrawals->find($withdrawalId)?->status);
        self::assertSame($withdrawalId, $this->itemRow($itemId)['withdrawal_id']);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
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
            $this->logger
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
