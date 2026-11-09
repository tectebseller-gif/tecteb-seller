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
use Tecteb\Marketplace\Modules\Finance\Application\VendorWithdrawalPolicy;
use Tecteb\Marketplace\Modules\Finance\Application\ReviewWithdrawals;
use Tecteb\Marketplace\Modules\Finance\Application\SettlementGate;
use Tecteb\Marketplace\Modules\Order\Application\OrderPaymentStatusInterface;
use Tecteb\Marketplace\Modules\Order\Application\SyncOrderLifecycle;
use Tecteb\Marketplace\Modules\Order\Domain\SettlementEligibility;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
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
    /** Kept so `closedRequest()` can build the same service with the policy. */
    private StaffAccess $access;
    /**
     * The logger this fixture built.
     *
     * Named apart from the base class's own `$audit` (which is the audit
     * REPOSITORY, and `protected`): two fields called the same thing in a
     * hierarchy is how a test ends up asserting about the wrong object.
     */
    private AuditLogger $auditLog;

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
        $this->access = $access;
        $this->auditLog = $audit;
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
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            // The payment document and the status are one unit of work since
            // `alpha.39`; without it `recordPayment()` refuses rather than
            // doing the pair unsafely, which a test would see as
            // `payment_not_atomic`.
            $db
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

        // The sale put -۹۰۰٬۰۰۰ on `vendor_earning` and the payment puts
        // +۹۰۰٬۰۰۰ back: the liability is discharged to exactly zero, which is
        // what «discharged» means. The payout is the money that left.
        // Per account WITH its unit from `alpha.68` (§2), so the figures
        // are read out of `minor` and «the books balance» is summed over
        // those — one currency and one exponent here, which `exact` says.
        $balances = $this->ledger->balances(self::VENDOR);
        $minor = static fn (string $account): int => (int) ($balances[$account]['minor'] ?? -1);
        self::assertSame(0, $minor(LedgerAccount::VendorEarning->value), 'the liability is discharged');
        self::assertSame(-900000, $minor(LedgerAccount::VendorPayout->value), 'and the payout is recorded');
        $sum = 0;
        foreach ($balances as $figure) {
            self::assertTrue($figure['exact'], 'every account is in one unit');
            self::assertSame(0, (int) $figure['exponent'], 'and that unit is the order\'s own');
            $sum += (int) $figure['minor'];
        }
        self::assertSame(0, $sum, 'and the books still balance');

        // §2: the payout carries the unit of the sale it discharges, read off
        // the books rather than defaulted. A payout line stamped with another
        // unit would be a second currency in one vendor's ledger.
        $payout = $this->ledger->forEvent('withdrawal:' . $secondId . ':paid');
        self::assertCount(2, $payout);
        $sale = $this->ledger->forEvent('order:107');
        self::assertSame($sale[0]->amount->currency, $payout[0]->amount->currency);
        self::assertSame($sale[0]->amount->exponent, $payout[0]->amount->exponent);

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

    /**
     * ⛔ **Renamed and rewritten when the owner answered FIN-04**, because the
     * old name said something that stopped being true.
     *
     * It used to be `testWhileTheFinancialDecisionsAreOpenNoWithdrawalIsCreated`
     * and asserted `'DEC-02'` inside the refusal's `decisions` context. The
     * owner has now answered DEC-02 and FIN-04, and FIN-04's answer is «تسویهٔ
     * خودکار بسته بماند» — so the behaviour is identical and the REASON is
     * different, which is exactly the distinction worth pinning: nothing is
     * created, and the refusal no longer sends a manager to close a decision
     * they have already closed.
     */
    public function testWithAutomaticSettlementClosedByDecisionNoWithdrawalIsCreated(): void
    {
        $itemId = $this->sold(self::VENDOR, 109, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);

        $this->trial->active = false;
        $blocked = $this->request->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($blocked->ok);
        self::assertSame('settlement_blocked', $blocked->code);
        self::assertSame(
            SettlementGate::CLOSED_BY_OWNER,
            (string) $blocked->context['reason'],
            'shut by the owner\'s decision, not awaiting one'
        );
        self::assertSame(
            '',
            (string) $blocked->context['decisions'],
            'and no decision is named, because none is outstanding'
        );
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'nothing was created');

        // …and the balance itself is still computable, so the screens can say
        // what WOULD be withdrawable. Blocking the operation is not hiding
        // the number.
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    /**
     * WHAT THIS PROVES: with the owner's closure in force, no new request is
     * created — and the closure is NOT the open-decisions gate.
     *
     * §7: «فعلاً درخواست برداشت جدید برای فروشنده بسته شود. فقط مخفی‌کردن
     * دکمه کافی نیست و مسیرهای POST/API نیز باید همین سیاست را اعمال کنند.»
     * The trial switch is deliberately left ON here, so what is measured is
     * the closure rather than `settlement_blocked`: on a disposable site the
     * gate steps aside and every evidence run would otherwise be creating
     * requests the owner has forbidden.
     *
     * And the numbers stay readable — «گزارش‌های مالی، سوابق و پرداخت‌های
     * فروشنده قابل مشاهده بماند» — which is why the balance is asserted after
     * the refusal rather than before it.
     */
    public function testWithTheOwnersClosureInForceNoNewRequestIsCreated(): void
    {
        $itemId = $this->sold(self::VENDOR, 112, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $this->trial->active = true;        // the open-decisions gate is OPEN

        $closed = $this->closedRequest()->handle(self::VENDOR, self::VENDOR);

        self::assertFalse($closed->ok);
        self::assertSame(VendorWithdrawalPolicy::REQUESTS_CLOSED, $closed->code);
        self::assertNull($this->withdrawals->openFor(self::VENDOR), 'nothing was created');
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible'], 'the number is still readable');
        // …and the line is NOT claimed, so nothing has been quietly reserved.
        self::assertSame([$itemId], $this->balance->of(self::VENDOR)['eligible_line_ids']);
    }

    /**
     * WHAT THIS PROVES: a request made before the closure is neither
     * cancelled nor released by the vendor.
     *
     * «درخواست‌های قبلی حذف، لغو یا آزاد نشوند؛ مدیر بررسی کند.» Cancelling
     * frees the reserved lines, so the vendor's cancel is closed with the
     * requests; the MANAGER's path is untouched and the last assertion is
     * what says so.
     */
    public function testAnEarlierRequestIsNeitherCancelledNorReleasedByTheVendor(): void
    {
        $itemId = $this->sold(self::VENDOR, 113, 1000000, 900000);
        $this->orders->recordSettlementCompletion($itemId, true);
        $this->trial->active = true;
        // Made on a build before the closure — which is what the owner's
        // «درخواست‌های قبلی» are.
        $created = $this->request->handle(self::VENDOR, self::VENDOR);
        self::assertTrue($created->ok);
        $withdrawalId = (int) $created->context['withdrawal_id'];

        $refused = $this->closedRequest()->cancel(self::VENDOR, self::VENDOR, $withdrawalId);

        self::assertFalse($refused->ok);
        self::assertSame(VendorWithdrawalPolicy::CANCEL_CLOSED, $refused->code);
        $open = $this->withdrawals->openFor(self::VENDOR);
        self::assertNotNull($open, 'the request is still there');
        self::assertSame(WithdrawalStatus::Requested, $open->status);
        self::assertSame([$itemId], $this->withdrawals->lineIds($withdrawalId), 'and still holds its line');

        // THE MANAGER'S PATH IS NOT CLOSED, which is the other half of «مدیر
        // بررسی کند»: a closure that stopped the review as well would leave
        // every earlier request stranded for ever.
        self::assertTrue($this->review->startReview($withdrawalId)->ok);
        self::assertTrue($this->review->approve($withdrawalId, 'بررسی مدیر')->ok);
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
    /**
     * One sold line, the way a capture leaves it: the order row AND the ledger
     * event it points at.
     *
     * Until `alpha.39` this wrote only the order row, so the fixture produced
     * a vendor with an eligible balance and empty books — a state a capture
     * cannot produce, because `CaptureOrder` records the accrual first and
     * stores the line's `ledger_event` from it. That mattered the moment the
     * payout stopped assuming «IRR, 0»: the unit of a payout is read off the
     * vendor's own books, and a fixture with no books has no unit to read.
     *
     * The accrual is written through the real repository with the four lines
     * `RecordCommission` writes, so the unit on the payout line is the unit of
     * the sale it discharges.
     */
    /**
     * The same service, with the owner's closure policy wired in.
     *
     * A second instance rather than a flag on the first, because the point is
     * that a build WITHOUT the policy behaves exactly as `alpha.61` did —
     * which is what every other test in this file measures, and what the
     * evidence probes of earlier rounds rely on (`alpha.32`).
     */
    private function closedRequest(): RequestWithdrawal
    {
        return new RequestWithdrawal(
            $this->withdrawals,
            $this->balance,
            new SettlementGate($this->trial),
            $this->access,
            $this->stores,
            new WithdrawalStateMachine(),
            $this->auditLog,
            null,
            new VendorWithdrawalPolicy()
        );
    }

    /**
     * ⛔ DEC-02, THE WHOLE OF IT, in one multi-vendor order.
     *
     * The owner's answer is three sentences and this test is each of them:
     * «تکمیل مؤثر هر قلم سفارش با تأیید دستی مدیر ثبت می‌شود» · «در سفارش
     * چندفروشنده‌ای مستقل برای هر قلم» · «تغییر وضعیت والد تنها، قلم را قابل
     * تسویه نمی‌کند».
     *
     * The third is the one worth a test of its own, because it is the one a
     * reasonable implementation gets wrong: WooCommerce has a single status
     * per ORDER, and «completed» on the parent reads exactly like «both
     * vendors delivered». `SyncOrderLifecycle` is the class that watches those
     * status changes, and if it wrote a settlement completion the owner's rule
     * would be broken by the plugin's own hook rather than by anybody's
     * mistake. So the parent is driven to `completed` HERE, through the real
     * sync, and the assertion is that nothing became payable.
     *
     * Both lines sit on wc order 7000, which is what makes it one order rather
     * than two — `sold()` otherwise gives every line a parent of its own.
     */
    public function testInAMultiVendorOrderEachLineIsCompletedOnItsOwnAndTheParentStatusSettlesNothing(): void
    {
        $other = 72;
        $this->vendors->upsertProfile($other, 'داروخانه دو', true, false);
        $mine = $this->sold(self::VENDOR, 150, 1000000, 900000, 7000);
        $theirs = $this->sold($other, 151, 500000, 450000, 7000);

        // Nothing is payable yet: a recorded sale is not a settled one.
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
        self::assertSame(0, $this->balance->of($other)['eligible']);

        // ONE line is confirmed. The other is a different vendor's and a
        // different decision.
        self::assertTrue($this->orders->recordSettlementCompletion($mine, true)->ok);

        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible'], 'the confirmed line is payable');
        self::assertSame(
            0,
            $this->balance->of($other)['eligible'],
            'and the other vendor’s line on the SAME order is not — «مستقل برای هر قلم»'
        );
        self::assertSame(450000, $this->balance->of($other)['pending']);

        // ⛔ AND THE PARENT GOES TO `completed`, through the real sync.
        //
        // This is «تغییر وضعیت والد تنها، قلم را قابل تسویه نمی‌کند». The sync
        // mirrors the status onto both rows — which is its job, and what the
        // report filters read — and settles neither.
        $lifecycle = new SyncOrderLifecycle(
            $this->orderItems,
            $this->ledger,
            $this->auditLog,
            new WpDatabase($this->wpdb)
        );
        $lifecycle->sync(7000, 'completed');

        self::assertSame(
            0,
            $this->balance->of($other)['eligible'],
            'a parent marked completed did not settle a line no manager confirmed'
        );
        self::assertSame(450000, $this->balance->of($other)['pending']);
        // The one that WAS confirmed is unchanged too: the sync neither gives
        // nor takes away.
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
        // And the status really did reach the rows, so the assertion above is
        // about a sync that ran rather than one that did nothing.
        self::assertSame('completed', $this->orderItems->wcOrderStatusOf($theirs));
    }

    /**
     * ⛔ WHAT THIS PROVES: a manager cannot declare a line settleable while
     * the buyer's money has not arrived — and it proves it by RUNNING the
     * service.
     *
     * This test exists because of a measured hole in the first version of
     * the fix. `OwnerDecisionsAreEnforcedTest` asserts the pure rule
     * (`SettlementEligibility`) over every status, and deleting the guard
     * from `ManageOrderItems` left all seven of its assertions green — the
     * `alpha.80` trap exactly: a rule nothing calls is not a guard. So the
     * refusal is asserted here, through the same method the manager's button
     * reaches, and the share is read back out of `VendorBalance` afterwards
     * so that «refused» also means «still not payable».
     *
     * @dataProvider unpaidStatuses
     */
    public function testALineOfAnUnpaidOrderCannotBeDeclaredCompleteForSettlement(
        string $status,
        string $expectedReason
    ): void {
        $itemId = $this->sold(self::VENDOR, 140, 1000000, 900000);
        // The fixture says `processing`; this test is about what happens when
        // the order says something else, so it is overwritten rather than
        // skipped — a line whose order is on hold is the BACS case.
        $this->orderItems->recordWcOrderStatus(5140, $status);

        $refused = $this->orders->recordSettlementCompletion($itemId, true);

        self::assertFalse($refused->ok, $status . ' must not be settleable');
        self::assertSame($expectedReason, $refused->code, $status);
        // …and the money is where it was: pending, not eligible.
        $balance = $this->balance->of(self::VENDOR);
        self::assertSame(0, $balance['eligible'], 'nothing became payable');
        self::assertSame(900000, $balance['pending']);
        self::assertSame(1, $balance['awaiting_completion']);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function unpaidStatuses(): array
    {
        return [
            'pending — nobody has paid' => ['pending', SettlementEligibility::NOT_RECEIVED],
            'on-hold — the BACS case' => ['on-hold', SettlementEligibility::NOT_RECEIVED],
            'failed' => ['failed', SettlementEligibility::NOT_RECEIVED],
            'refunded — a person decides' => ['refunded', SettlementEligibility::REFUND_NEEDS_DECISION],
            'a status nobody mapped' => ['wc-awaiting-shipment', SettlementEligibility::STATUS_UNKNOWN],
        ];
    }

    /**
     * And a status NOBODY RECORDED refuses under its own name — which is not
     * the same answer as «not paid».
     *
     * The mirrored column has no backfill and the capture does not write it,
     * so this is the state of every line captured before schema 39 and of
     * every order that has not changed status since checkout. Reading it as
     * «unpaid» would be right today and wrong the moment it is not.
     */
    public function testALineWhoseOrderStatusWasNeverRecordedIsRefusedUnderItsOwnName(): void
    {
        $itemId = $this->sold(self::VENDOR, 141, 1000000, 900000);
        // Back to «nobody told us», which is what the row carried before the
        // fixture stated the precondition.
        $this->orderItems->recordWcOrderStatus(5141, '');

        $refused = $this->orders->recordSettlementCompletion($itemId, true);

        self::assertFalse($refused->ok);
        self::assertSame(SettlementEligibility::STATUS_UNRECORDED, $refused->code);
        self::assertNotSame(
            SettlementEligibility::NOT_RECEIVED,
            $refused->code,
            '«nobody told us» is not «not paid»'
        );
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
    }

    /**
     * ⛔ THE CONTROL, and it is the half that keeps the guard from becoming a
     * trap: clearing a completion is always allowed.
     *
     * A completion recorded while an order looked paid, on an order that then
     * failed, has to be undoable — and demanding that the order be paid first
     * would be the gate with no way out `alpha.97` describes. Clearing only
     * ever removes money from `eligible`, so nothing guards it.
     */
    public function testClearingACompletionIsAllowedEvenOnAnUnpaidOrder(): void
    {
        $itemId = $this->sold(self::VENDOR, 142, 1000000, 900000);
        self::assertTrue($this->orders->recordSettlementCompletion($itemId, true)->ok);

        // The order then fails — the money never arrived after all.
        $this->orderItems->recordWcOrderStatus(5142, 'failed');

        $cleared = $this->orders->recordSettlementCompletion($itemId, false);

        self::assertTrue($cleared->ok, 'taking money back out is never refused');
        self::assertSame('settlement_cleared', $cleared->code);
        self::assertSame(0, $this->balance->of(self::VENDOR)['eligible']);
    }

    /**
     * And the live reader beats a stale mirror.
     *
     * The mirror is written by `SyncOrderLifecycle`, which runs on a status
     * CHANGE — so an order paid in a way that did not fire the hook, or one
     * whose row predates schema 39, carries a stale or absent value while
     * WooCommerce itself says `processing`. The live answer is asked first for
     * exactly that case, and a build with no reader wired falls back to the
     * mirror rather than to «allow».
     */
    public function testTheLiveStatusBeatsTheMirror(): void
    {
        $itemId = $this->sold(self::VENDOR, 143, 1000000, 900000);
        $this->orderItems->recordWcOrderStatus(5143, 'pending');   // stale

        $withLive = new ManageOrderItems(
            $this->orderItems,
            $this->access,
            $this->auditLog,
            new SystemClock(),
            new OrderItemStateMachine(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new class implements OrderPaymentStatusInterface {
                public function statusOf(int $wcOrderId): ?string
                {
                    return 'processing';
                }
            }
        );

        self::assertTrue(
            $withLive->recordSettlementCompletion($itemId, true)->ok,
            'the live status is authoritative'
        );
        self::assertSame(900000, $this->balance->of(self::VENDOR)['eligible']);
    }

    /**
     * ⛔ …and a live reader that cannot answer does NOT become «allow».
     *
     * `null` from the port is «the question was asked and no answer came
     * back», so the mirror is consulted and, when that says nothing either,
     * the refusal stands. A reader whose failure opened the gate would be the
     * worst of the three sources.
     */
    public function testALiveReaderThatCannotAnswerFallsBackRatherThanAllowing(): void
    {
        $itemId = $this->sold(self::VENDOR, 144, 1000000, 900000);
        $this->orderItems->recordWcOrderStatus(5144, '');

        $silent = new ManageOrderItems(
            $this->orderItems,
            $this->access,
            $this->auditLog,
            new SystemClock(),
            new OrderItemStateMachine(),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new class implements OrderPaymentStatusInterface {
                public function statusOf(int $wcOrderId): ?string
                {
                    return null;
                }
            }
        );

        $refused = $silent->recordSettlementCompletion($itemId, true);
        self::assertFalse($refused->ok);
        self::assertSame(SettlementEligibility::STATUS_UNRECORDED, $refused->code);
    }

    /**
     * @param ?int $wcOrderId the WooCommerce order the line belongs to.
     *        Optional, so every call written before the multi-vendor test
     *        keeps its one-order-per-line shape; passed when two lines have to
     *        share a parent, which is the only way to express a multi-vendor
     *        order at all.
     */
    private function sold(int $vendorUserId, int $orderItemId, int $baseMinor, ?int $shareMinor, ?int $wcOrderId = null): int
    {
        $wcOrderId ??= 5000 + $orderItemId;
        if ($shareMinor !== null) {
            $base = Money::of($baseMinor);
            $commission = Money::of($baseMinor - $shareMinor);
            $share = Money::of($shareMinor);
            self::assertTrue($this->ledger->record(
                (new LedgerTransaction('order:' . $orderItemId, $vendorUserId, 'order:' . $orderItemId, (string) $orderItemId))
                    ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                    ->add(LedgerAccount::Commission, $commission->negate(), 'commission_due')
                    ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
                    ->add(LedgerAccount::TaxCollected, $base->zero(), 'tax_collected')
            ), 'the sale is on the books before anybody may withdraw it');
        }
        // ⛔ AND THE FIXTURE NOW SAYS THE ORDER WAS PAID.
        //
        // These tests are about settling a sale, and settling one needs the
        // buyer's money to have arrived: from this release
        // `ManageOrderItems::recordSettlementCompletion()` refuses a line
        // whose order WooCommerce does not call paid
        // (`SettlementEligibility`). Without this line the fixture described
        // an order nobody had announced a status for, so every test here
        // would have been asserting against `order_status_unrecorded` — a
        // true refusal about a fixture that was never meant to be unpaid.
        //
        // Stated rather than worked around: the alternative was to weaken the
        // guard, and «fixture که یک ضمانت را خرج می‌کند باید آن را پس بدهد»
        // cuts the other way too — a fixture that needs a precondition has to
        // state it.
        $id = $this->orderItems->record(new VendorOrderItem(
            0,
            $wcOrderId,
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
        $this->orderItems->recordWcOrderStatus($wcOrderId, 'processing');
        return $id;
    }
}
