<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\Settings;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables;
use Tecteb\Marketplace\Modules\Order\Application\ManageOrderItems;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStateMachine;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0010LinkOwnership;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStaffRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbStoreRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0002CreateVendorTables;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\Migrations\M0003CreateStoreAndStaffTables;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\FakeTrialUnlock;

/**
 * Settlement on real MariaDB: what a vendor may ask for, when, and what the
 * database does when two of them ask at once.
 *
 * The assertions that matter most are the negative ones. A balance that is
 * owed but not yet complete is not withdrawable; a second request while one
 * is open is not created; a line locked by one request cannot be locked by
 * another; and a transfer whose outcome is unknown does not retry itself.
 */
final class SettlementFlowTest extends DatabaseTestCase
{
    private const VENDOR = 71;
    private const OTHER = 72;
    private const MANAGER = 9;

    private DbOrderItemRepository $orderItems;
    private DbWithdrawalRepository $withdrawals;
    private DbLedgerRepository $ledger;
    private DbVendorRepository $vendors;
    private DbStoreRepository $stores;
    private VendorBalance $balance;
    private RequestWithdrawal $request;
    private ReviewWithdrawals $review;
    private ManageOrderItems $orders;
    private SettingsService $settings;
    private FakeTrialUnlock $trial;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        foreach ([
            ...M0002CreateVendorTables::TABLES,
            ...M0003CreateStoreAndStaffTables::TABLES,
            ...M0004CreateFinanceTables::TABLES,
            ...M0005CreateProductTables::TABLES,
            ...M0006CatalogAndOrders::TABLES,
            ...M0007SettlementTables::TABLES,
        ] as $suffix) {
            $this->wpdb->dropTable($this->wpdb->prefix . $suffix);
        }
        $this->resetSchema($db);

        $clock = new SystemClock();
        $options = new WpOptionStore();
        $audit = new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock);

        $this->orderItems = new DbOrderItemRepository($db, $clock);
        $this->withdrawals = new DbWithdrawalRepository($db, $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->vendors = new DbVendorRepository($db, $clock);
        $this->stores = new DbStoreRepository($db, $clock);
        $staff = new DbStaffRepository($db, $clock);
        $this->settings = new SettingsService($options);
        $this->trial = new FakeTrialUnlock(true);

        $access = new StaffAccess($staff, $this->vendors);
        $this->balance = new VendorBalance($this->orderItems, $this->settings, $clock);
        $gate = new SettlementGate($this->trial);
        $states = new WithdrawalStateMachine();

        $this->request = new RequestWithdrawal(
            $this->withdrawals,
            $this->balance,
            $gate,
            $access,
            $this->stores,
            $states,
            $audit
        );
        $this->review = new ReviewWithdrawals(
            $this->withdrawals,
            $this->ledger,
            $states,
            $audit,
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );
        $this->orders = new ManageOrderItems(
            $this->orderItems,
            $access,
            $audit,
            $clock,
            new OrderItemStateMachine(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS])
        );

        foreach ([self::VENDOR, self::OTHER] as $vendorId) {
            $this->vendors->upsertProfile($vendorId, 'داروخانه ' . $vendorId, true, false);
            $this->stores->saveBank($vendorId, 'IR000000000000000000000000', 'دارندهٔ حساب', 0, 'approved', false);
        }
        $this->settings->save($this->settings->load()->withSettlementDelayDays(0));
    }

    public function testAShareIsNotWithdrawableUntilAManagerCallsTheSaleComplete(): void
    {
        $itemId = $this->sold(self::VENDOR, 101, 1000000, 900000);

        $before = $this->balance->of(self::VENDOR);
        self::assertSame(900000, $before['earned']);
        self::assertSame(900000, $before['pending'], 'earned, and not yet askable');
        self::assertSame(0, $before['eligible']);
        self::assertSame(1, $before['awaiting_completion']);

        // Asking now gets a named refusal, not a silent zero.
        $refused = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertFalse($refused->ok);
        self::assertSame('nothing_eligible', $refused->code);

        // ORDER-01: the manager records completion; nothing infers it.
        self::assertTrue($this->orders->recordSettlementCompletion($itemId, true)->ok);

        $after = $this->balance->of(self::VENDOR);
        self::assertSame(900000, $after['eligible']);
        self::assertSame(0, $after['pending']);
    }

    public function testTheApprovedWaitingPeriodIsReadFromSettingsNotGuessed(): void
    {
        $itemId = $this->sold(self::VENDOR, 102, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);

        // Four days is the approved decision (Master §8.3, A.2, F-03). With it
        // in force, a sale completed today is not withdrawable today.
        $this->settings->save($this->settings->load()->withSettlementDelayDays(4));
        $waiting = $this->balance->of(self::VENDOR);
        self::assertSame(0, $waiting['eligible']);
        self::assertSame(900000, $waiting['pending']);
        self::assertSame(1, $waiting['awaiting_delay'], 'waiting on the period, not on completion');
        self::assertSame(4, $waiting['delay_days']);

        // Zero means "no extra delay" and nothing else (F-03).
        $this->settings->save($this->settings->load()->withSettlementDelayDays(0));
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    public function testAnUnrecordedShareIsNeverCountedAsZero(): void
    {
        $this->sold(self::VENDOR, 103, 1000000, null);
        $balance = $this->balance->of(self::VENDOR);

        self::assertSame(0, $balance['earned'], 'nothing was earned, because nothing was recorded');
        self::assertSame(1, $balance['unrecorded'], 'and the vendor is owed an answer about it');
        self::assertSame(0, $balance['eligible']);
    }

    public function testOneRequestTakesTheWholeBalanceAndASecondOneIsRefused(): void
    {
        $first = $this->sold(self::VENDOR, 104, 1000000, 900000);
        $second = $this->sold(self::VENDOR, 105, 500000, 450000);
        $this->orders->recordSettlementCompletion($first, true);
        $this->orders->recordSettlementCompletion($second, true);

        $result = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertTrue($result->ok, $result->code);
        self::assertSame(1350000, $result->context['amount_minor'], 'the whole eligible balance, in one go');
        self::assertSame(2, $result->context['lines']);

        // A double click, or a second tab.
        $again = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertFalse($again->ok);
        self::assertSame('withdrawal_already_open', $again->code);
        self::assertCount(1, $this->withdrawals->forVendor(self::VENDOR));

        // The locked money is no longer eligible, and is reported as reserved.
        $balance = $this->balance->of(self::VENDOR);
        self::assertSame(0, $balance['eligible']);
        self::assertSame(1350000, $balance['reserved']);
    }

    public function testALineLockedByOneRequestCannotBeLockedByAnother(): void
    {
        $itemId = $this->sold(self::VENDOR, 106, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        self::assertTrue($this->request->handle(self::VENDOR, self::VENDOR)->ok);

        // Straight at the repository, as two racing workers would arrive:
        // the open-request index and the line index both have to hold.
        self::assertSame(0, $this->withdrawals->reserve(self::VENDOR, [$itemId], 900000, 'IR1', 'x'));
        self::assertCount(1, $this->withdrawals->forVendor(self::VENDOR));
    }

    public function testRejectingGivesTheMoneyBackAndPayingRecordsABalancedLedgerEntry(): void
    {
        $itemId = $this->sold(self::VENDOR, 107, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];

        self::assertTrue($this->review->startReview($withdrawalId)->ok);
        self::assertTrue($this->review->reject($withdrawalId, 'مدارک ناقص')->ok);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible'], 'back in the balance');
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'and the vendor may ask again');

        // Second time through, to payment.
        $secondId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        self::assertTrue($this->review->startReview($secondId)->ok);
        self::assertTrue($this->review->approve($secondId)->ok);
        self::assertTrue($this->review->startPayment($secondId)->ok);

        // §4.4: paid means paid, with the reference that proves it.
        self::assertSame('reference_required', $this->review->recordPayment($secondId, '  ')->code);
        self::assertTrue($this->review->recordPayment($secondId, 'TRACE-9')->ok);

        $paid = $this->withdrawals->find($secondId);
        self::assertSame(WithdrawalStatus::Paid, $paid?->status);
        self::assertSame('TRACE-9', $paid?->reference);

        $balances = $this->ledger->balances(self::VENDOR);
        self::assertSame(900000, $balances[LedgerAccount::VendorEarning->value] ?? 0, 'the liability is discharged');
        self::assertSame(-900000, $balances[LedgerAccount::VendorPayout->value] ?? 0, 'and the payout is recorded');
        self::assertSame(0, array_sum($balances), 'the pair is balanced');

        // A paid request keeps its lines: they are the record of what the
        // payment covered, and the money must not become eligible again.
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['paid']);
    }

    public function testAnUnknownTransferOutcomeStopsAndIsNeverRetriedAutomatically(): void
    {
        $itemId = $this->sold(self::VENDOR, 108, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $withdrawalId = (int) $this->request->handle(self::VENDOR, self::VENDOR)->context['withdrawal_id'];
        $this->review->startReview($withdrawalId);
        $this->review->approve($withdrawalId);
        $this->review->startPayment($withdrawalId);

        self::assertSame('note_required', $this->review->needsReconciliation($withdrawalId, '')->code);
        self::assertTrue($this->review->needsReconciliation($withdrawalId, 'بانک پاسخ نداد')->ok);

        $stuck = $this->withdrawals->find($withdrawalId);
        self::assertSame(WithdrawalStatus::ReconciliationRequired, $stuck?->status);
        self::assertTrue($stuck->isOpen(), 'it still holds its lines');
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible'], 'the money is not free to ask for again');

        // The only two ways out are a person's decision, and neither is a
        // repeat of the transfer.
        $states = new WithdrawalStateMachine();
        self::assertSame(
            [WithdrawalStatus::Paid, WithdrawalStatus::Approved],
            $states->nextFrom(WithdrawalStatus::ReconciliationRequired)
        );
        self::assertFalse($states->canMove(WithdrawalStatus::ReconciliationRequired, WithdrawalStatus::PaymentInProgress));
    }

    public function testWhileTheFinancialDecisionsAreOpenNoWithdrawalIsCreated(): void
    {
        $itemId = $this->sold(self::VENDOR, 109, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);

        $this->trial->active = false;
        $blocked = $this->request->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($blocked->ok);
        self::assertSame('settlement_blocked', $blocked->code);
        self::assertStringContainsString('DEC-02', (string) $blocked->context['decisions']);
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'nothing was created');

        // …and the balance itself is still computable, so the screens can say
        // what WOULD be withdrawable. Blocking the operation is not hiding
        // the number.
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    public function testOneShopNeverSeesAnotherShopsMoney(): void
    {
        $mine = $this->sold(self::VENDOR, 110, 1000000, 900000);
        $theirs = $this->sold(self::OTHER, 111, 2000000, 1800000);
        $this->orders->recordSettlementCompletion($mine, true);
        $this->orders->recordSettlementCompletion($theirs, true);

        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame(1800000, $this->balance->of(self::OTHER)['eligible']);

        // A staff member of neither shop asking about one of them.
        self::assertSame('forbidden', $this->request->handle(self::OTHER, self::VENDOR)->code);
    }

    public function testABankAccountOnHoldStopsSettlementWithoutTouchingTheBalance(): void
    {
        $itemId = $this->sold(self::VENDOR, 112, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        // A.4: changing the IBAN puts settlement on hold until the manager approves.
        $this->stores->saveBank(self::VENDOR, 'IR999999999999999999999999', 'دارندهٔ حساب', 0, 'pending', true);

        $refused = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertFalse($refused->ok);
        self::assertSame('bank_account_on_hold', $refused->code);
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible'], 'the money is still theirs');
    }

    // -------------------------------------------------------------- helpers

    /** One recorded sale for a vendor. A null share is one FIN-02 refused to record. */
    private function sold(int $vendorUserId, int $orderItemId, int $baseMinor, ?int $shareMinor): int
    {
        return $this->orderItems->record(new VendorOrderItem(
            0,
            5000 + $orderItemId,
            $orderItemId,
            0,
            $vendorUserId,
            'کالای آزمایشی',
            'SKU-' . $orderItemId,
            1,
            $baseMinor,
            $baseMinor,
            0,
            $shareMinor === null ? null : $baseMinor - $shareMinor,
            $shareMinor,
            $shareMinor === null ? null : 1000,
            $shareMinor === null ? '' : 'general',
            'order:' . $orderItemId,
            OrderItemStatus::Delivered
        ));
    }
}
