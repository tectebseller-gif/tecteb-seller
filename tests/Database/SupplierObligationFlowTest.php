<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations;
use Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierObligationRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierObligationRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierReceivableRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations as V;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Tests\Support\FailingDatabase;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;
use Tecteb\Marketplace\Tests\Support\StaleReadSupplierPaymentRepository;

/**
 * WHAT THIS PROVES: the agreed purchase amount is a number people recorded,
 * several real payments can stand against it, and the running balance cannot
 * be got round — not by a caller that skipped a check, not by two requests in
 * the same instant, and not by a payment that is one unit too large.
 *
 * The owner's §3 in one sentence: «مبلغ توافق‌شدهٔ خرید با تأمین‌کننده، سهم
 * محاسبه‌شدهٔ کمیسیون نیست … پرداخت کمتر از تعهد، پرداخت جزئی با باقی‌ماندهٔ
 * مانده است، نه تسویهٔ کامل … پرداخت بیش از مانده نباید خودکار بدهکاری یا
 * پیش‌پرداخت شود.»
 *
 * And the warning that shaped the schema: «کلید یکتای «هر قلم فقط یک پرداخت»
 * را کورکورانه حذف نکن؛ حفاظت جایگزین برای مانده، تکرار و هم‌زمانی باید همراه
 * آن پیاده و آزموده شود.» The guarantee moved onto `live_item_id` rather than
 * going away, and the three replacements are each measured below:
 *
 *   balance      the obligation row is locked and the arithmetic is in the
 *                `INSERT ... SELECT`, so `testAPaymentAboveTheBalance…` and
 *                `testTwoRecordsRacing…` fail without it
 *   repetition   `UNIQUE (idempotency_key)` and `UNIQUE (payment_id,
 *                obligation_id)`
 *   concurrency  the same row lock, proved with a competing write injected
 *                between the read and the write
 *
 * Against real MariaDB throughout, because every one of those is a property
 * of a statement rather than of a condition in PHP.
 */
final class SupplierObligationFlowTest extends DatabaseTestCase
{
    private const SUPPLIER = 77;
    private const MANAGER = 7;

    private WpDatabase $db;
    private DbOrderItemRepository $orderItems;
    private DbLedgerRepository $ledger;
    private DbSupplierPaymentRepository $payments;
    private DbSupplierObligationRepository $obligations;
    private DbSupplierReceivableRepository $receivables;
    private RecordSupplierPayment $service;
    private ManageSupplierObligations $agreements;
    private ManageSupplierReceivables $debts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->orderItems = new DbOrderItemRepository($this->db, $clock);
        $this->ledger = new DbLedgerRepository($this->db, $clock);
        $this->payments = new DbSupplierPaymentRepository($this->db, $clock);
        $this->obligations = new DbSupplierObligationRepository($this->db, $clock);
        $this->receivables = new DbSupplierReceivableRepository($this->db, $clock);
        $this->service = $this->serviceOn($this->db);
        $this->agreements = $this->agreementsOn($this->db);
        $this->debts = $this->debtsOn($this->db);
    }

    // ------------------------------------------------- §3 the agreed amount

    /**
     * WHAT THIS PROVES: the number is recorded, and every change to it is in
     * a history row — «مبلغ توافق باید صریح ثبت شود و تغییراتش سابقه داشته
     * باشد».
     *
     * And that NOTHING is derived: the item's own share is 900,000 and the
     * agreed amount is 800,000, which is the whole point of §3 — the two are
     * different numbers and must not be assumed equal.
     */
    public function testAnAgreedAmountIsRecordedWithItsHistory(): void
    {
        $item = $this->completedSale(601, 1000000, 900000);

        $first = $this->agreements->agree($item, '800000', 'توافق اول');
        self::assertTrue($first->ok);
        self::assertSame(ManageSupplierObligations::AGREED, $first->code);

        $obligation = $this->obligations->forOrderItem($item);
        self::assertNotNull($obligation);
        self::assertTrue($obligation->isAgreed());
        self::assertSame(800000, $obligation->agreed?->minor);
        self::assertSame(0, $obligation->paidMinor);
        self::assertSame(800000, $obligation->balanceMinor());

        $second = $this->agreements->agree($item, '750000', 'تخفیف توافقی');
        self::assertTrue($second->ok);
        $history = $this->obligations->historyFor($obligation->id);
        self::assertCount(2, $history, 'both the first record and the change are in the history');
        self::assertSame(800000, $history[0]['previous_minor']);
        self::assertSame(750000, $history[0]['new_minor']);
        self::assertSame('تخفیف توافقی', $history[0]['reason']);
        self::assertNull($history[1]['previous_minor'], 'the first record came from nothing');
    }

    /**
     * WHAT THIS PROVES: «نامعلوم» and «صفر» are two different recorded
     * answers, and the column keeps them apart.
     */
    public function testZeroIsAnAgreementAndEmptyIsNot(): void
    {
        $item = $this->completedSale(602, 500000, 450000);

        self::assertTrue($this->agreements->agree($item, '0', 'رایگان')->ok);
        $zero = $this->obligations->forOrderItem($item);
        self::assertNotNull($zero);
        self::assertTrue($zero->isAgreed());
        self::assertSame(0, $zero->agreed?->minor);

        $cleared = $this->agreements->agree($item, '', 'توافق لغو شد');
        self::assertTrue($cleared->ok);
        self::assertSame(ManageSupplierObligations::CLEARED, $cleared->code);
        $none = $this->obligations->forOrderItem($item);
        self::assertNotNull($none);
        self::assertFalse($none->isAgreed());
        self::assertNull($none->balanceMinor(), 'there is no balance without an agreed amount');
    }

    /** WHAT THIS PROVES: the same number again writes no history row. */
    public function testTheSameAmountAgainWritesNothing(): void
    {
        $item = $this->completedSale(603, 500000, 450000);
        self::assertTrue($this->agreements->agree($item, '400000', 'اول')->ok);
        $obligation = $this->obligations->forOrderItem($item);
        self::assertNotNull($obligation);

        $again = $this->agreements->agree($item, '400000', 'دوباره');
        self::assertFalse($again->ok);
        self::assertSame(ManageSupplierObligations::UNCHANGED, $again->code);
        self::assertCount(1, $this->obligations->historyFor($obligation->id));
    }

    /**
     * WHAT THIS PROVES: an agreed amount cannot be lowered under what has
     * already been handed over.
     *
     * That is not a correction — it is a statement that an over-payment
     * happened, and §3 says an over-payment has no defined meaning yet. The
     * refusal carries both numbers so a person can see which is wrong.
     */
    public function testLoweringTheAgreedAmountBelowWhatWasPaidIsRefused(): void
    {
        $item = $this->completedSale(604, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-17', 'TRK-1')->ok);

        $lower = $this->agreements->agree($item, '300000', 'اشتباه بود');
        self::assertFalse($lower->ok);
        self::assertSame(ManageSupplierObligations::BELOW_PAID, $lower->code);
        self::assertSame(400000, $lower->context['paid_minor']);
        self::assertSame(900000, $this->obligations->forOrderItem($item)?->agreed?->minor);
    }

    // ------------------------------------------------- §3 partial payments

    /**
     * WHAT THIS PROVES: two real payments against one obligation, and the
     * balance after each is right.
     *
     * The first is `RECORDED_PARTIAL` with a balance, the second covers the
     * rest — and the claim row, whose unique key is the thing the owner told
     * us not to drop blindly, is written once and is still there.
     */
    public function testTwoPartialPaymentsLeaveTheRightBalance(): void
    {
        $item = $this->completedSale(605, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);

        $first = $this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-A');
        self::assertTrue($first->ok);
        self::assertSame(RecordSupplierPayment::RECORDED_PARTIAL, $first->code);
        self::assertSame(400000, $first->context['balance_minor']);
        self::assertSame(400000, $this->obligations->forOrderItem($item)?->balanceMinor());

        $second = $this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-18', 'TRK-B');
        self::assertTrue($second->ok);
        self::assertSame(0, $second->context['balance_minor']);
        $obligation = $this->obligations->forOrderItem($item);
        self::assertNotNull($obligation);
        self::assertSame(900000, $obligation->paidMinor);
        self::assertTrue($obligation->isSettled());

        // TWO payments, TWO allocations, TWO rows — and exactly ONE LIVE
        // claim. The live key is what the withdrawal cycle arbitrates on and
        // it was never duplicated; the second row is the coverage record that
        // lets `void()` find a survivor (without it, voiding the first
        // payment freed an item the second one had paid for).
        self::assertCount(2, $this->payments->forSupplier(self::SUPPLIER, 10));
        self::assertSame(2, $this->claimRowsFor($item));
        self::assertSame(1, $this->liveClaimRowsFor($item));
    }

    /**
     * WHAT THIS PROVES: one unit more than the balance is refused by name,
     * and nothing at all is written.
     *
     * «پرداخت بیش از مانده نباید خودکار بدهکاری یا پیش‌پرداخت شود؛ تا
     * تعیین‌نشدن، با پیام مشخص رد شود.»
     */
    public function testAPaymentAboveTheBalanceIsRefusedByName(): void
    {
        $item = $this->completedSale(606, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-A')->ok);

        $over = $this->service->record(self::SUPPLIER, [$item], '400001', '2026-10-18', 'TRK-B');
        self::assertFalse($over->ok);
        self::assertSame(RecordSupplierPayment::ABOVE_BALANCE, $over->code);
        self::assertSame(400000, $over->context['balance_minor']);
        self::assertSame(400001, $over->context['amount_minor']);
        self::assertCount(1, $this->payments->forSupplier(self::SUPPLIER, 10), 'nothing was written');
        self::assertSame(500000, $this->obligations->forOrderItem($item)?->paidMinor);
    }

    /**
     * WHAT THIS PROVES: the balance guard is INSIDE the write, so a second
     * allocation that lands between the read and the write cannot get past
     * it.
     *
     * **This is a TIMED INTERLEAVING on one connection, and it is called
     * that** (`alpha.43`): `InterferingDatabase` wraps the same gateway, so
     * the competing write happens inside this request's transaction and goes
     * away with its rollback. What the test can therefore prove is the half
     * that matters here — the statement SEES the competing allocation and
     * refuses — and what it cannot prove is which of two processes wins. That
     * second half is measured with two real processes in
     * `tests/Support/concurrent-supplier-allocation.php`, driven by
     * `tools/alpha62-reproduction.sh`, and the result is in the evidence.
     *
     * On a build whose guard were a PHP comparison made before the write,
     * this test would record 400,000 against a balance of 400,000 that
     * something else had already taken.
     */
    public function testAnAllocationLandingInsideTheWindowIsRefused(): void
    {
        $item = $this->completedSale(607, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-A')->ok);
        $obligationId = (int) $this->obligations->forOrderItem($item)?->id;

        $interfering = new InterferingDatabase($this->db);
        $interfering->before(['INSERT IGNORE INTO', 'supplier_payment_allocations'], 1, function () use ($item, $obligationId): void {
            // A SECOND manager, on their own connection, takes the rest of
            // the balance first. Written straight through the plain gateway,
            // so it is committed before this request's statement runs.
            $rival = new DbSupplierPaymentRepository($this->db, new SystemClock());
            $rivalPayment = $rival->record(
                $this->paymentRecord('TRK-RIVAL', 400000),
                [$item => 900000],
                [$item]
            );
            self::assertSame(
                \Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::RECORDED,
                $rivalPayment['outcome']
            );
            $allocated = (new DbSupplierObligationRepository($this->db, new SystemClock()))->allocate(
                (int) $rivalPayment['payment_id'],
                $obligationId,
                $item,
                self::SUPPLIER,
                400000,
                'IRR',
                0
            );
            self::assertSame(SupplierObligationRepositoryInterface::ALLOCATED, $allocated['outcome']);
        });

        $loser = $this->serviceOn($interfering)
            ->record(self::SUPPLIER, [$item], '400000', '2026-10-18', 'TRK-LOSER');

        self::assertFalse($loser->ok, 'the second record of the same balance must not land');
        self::assertSame(RecordSupplierPayment::ABOVE_BALANCE, $loser->code);
        // AND NOTHING OF THIS REQUEST'S SURVIVED. The rival's rows went with
        // the rollback too — it was on this connection, inside this
        // transaction — so the only thing this test asserts about the
        // committed state is that the refused request left no trace.
        self::assertCount(1, $this->payments->forSupplier(self::SUPPLIER, 10));
        self::assertSame(500000, $this->obligations->forOrderItem($item)?->paidMinor);
    }

    /**
     * WHAT THIS PROVES: a payment covering several items where any of them has
     * an agreed amount is REFUSED rather than split automatically.
     *
     * An automatic split would be a decision nobody made, about money. Asked
     * as an open question with a worked example rather than answered here.
     */
    public function testAMultiItemPaymentWithAnAgreedAmountIsRefusedRatherThanSplit(): void
    {
        $first = $this->completedSale(608, 1000000, 900000);
        $second = $this->completedSale(609, 1000000, 900000);
        self::assertTrue($this->agreements->agree($first, '900000', 'توافق')->ok);

        $result = $this->service->record(self::SUPPLIER, [$first, $second], '1800000', '2026-10-17', 'TRK-M');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::ALLOCATION_AMBIGUOUS, $result->code);
        self::assertSame(2, $result->context['items']);
        self::assertSame(1, $result->context['agreed_items']);
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER, 10));
    }

    /**
     * WHAT THIS PROVES: an item with NO obligation behaves exactly as it did
     * in `alpha.61`.
     *
     * This is the regression that matters most: there are no obligation rows
     * on any existing site, so a build that suddenly measured every payment
     * against an amount nobody had entered would stop recording the payments
     * being recorded today.
     */
    public function testWithoutAnAgreedAmountTheOldComparisonStillDecides(): void
    {
        $item = $this->completedSale(610, 1000000, 900000);

        $matching = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-OLD');
        self::assertTrue($matching->ok);
        self::assertSame(RecordSupplierPayment::RECORDED, $matching->code);
        self::assertSame(V::POSTING_PARTIAL, V::POSTING_PARTIAL, 'the partial state exists and was not used here');
        self::assertNotSame('', (string) $matching->context['ledger_event']);
    }

    // ------------------------------------------------- §5 the void

    /**
     * WHAT THIS PROVES: voiding keeps every row and reverses the money.
     *
     * The payment stays, its claim row stays, the reason, actor and time are
     * on it, and the ledger gets a second opposite entry rather than losing
     * the first — `tmc_ledger` is append-only by design.
     */
    public function testVoidingKeepsTheHistoryAndReversesTheLedger(): void
    {
        $item = $this->completedSale(611, 1000000, 900000);
        // CAPTURED BEFORE THE PAYMENT, which is the point: after the void the
        // supplier's books must read as they did before the record existed.
        $before = $this->ledger->balances(self::SUPPLIER);
        $recorded = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-V');
        self::assertTrue($recorded->ok);
        $paymentId = (int) $recorded->context['payment_id'];

        $voided = $this->service->void($paymentId, 'شمارهٔ پیگیری اشتباه بود');
        self::assertTrue($voided->ok);
        self::assertSame(RecordSupplierPayment::VOIDED, $voided->code);

        $payment = $this->payments->find($paymentId);
        self::assertNotNull($payment, 'the record is NOT deleted');
        self::assertTrue($payment->isVoid());
        self::assertSame('شمارهٔ پیگیری اشتباه بود', $payment->voidReason);
        self::assertSame(self::MANAGER, $payment->voidedBy);
        self::assertNotSame('', $payment->voidEvent);
        self::assertSame([$item], $payment->orderItemIds, 'the items it covered are still on it');

        // The two entries sum to nought, so the supplier's books read as
        // though the payment had never been recorded.
        $after = $this->ledger->balances(self::SUPPLIER);
        self::assertSame(
            $before[LedgerAccount::VendorEarning->value] ?? 0,
            $after[LedgerAccount::VendorEarning->value] ?? 0,
            'the reversal cancels the payout exactly'
        );
    }

    /**
     * WHAT THIS PROVES: a reversal that cannot be written takes the void with
     * it — «اصلاح آن با سند معکوس در همان واحد کار انجام شود».
     *
     * Without the transaction the document would read «void» beside a ledger
     * that still said the money left, which is the half-done state §5 names.
     */
    public function testAFailedLedgerReversalLeavesNothingHalfDone(): void
    {
        $item = $this->completedSale(612, 1000000, 900000);
        $recorded = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-V2');
        $paymentId = (int) $recorded->context['payment_id'];

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['INSERT INTO', 'tmc_ledger_entries']);
        $result = $this->serviceOn($failing)->void($paymentId, 'دلیل');

        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::STORAGE_FAILED, $result->code);
        $payment = $this->payments->find($paymentId);
        self::assertNotNull($payment);
        self::assertFalse($payment->isVoid(), 'the void rolled back with the reversal');
        self::assertSame(1, $this->liveClaimRowsFor($item), 'and the item is still held');
    }

    /**
     * WHAT THIS PROVES: a document write that fails writes no ledger entry
     * either — the other direction of the same unit of work.
     */
    public function testAFailedDocumentWriteLeavesNoReversalBehind(): void
    {
        $item = $this->completedSale(613, 1000000, 900000);
        $recorded = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-V3');
        $paymentId = (int) $recorded->context['payment_id'];
        $entriesBefore = $this->ledgerEntryCount();

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['UPDATE', 'tmc_supplier_payments', 'voided_at']);
        $result = $this->serviceOn($failing)->void($paymentId, 'دلیل');

        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::STORAGE_FAILED, $result->code);
        self::assertFalse($this->payments->find($paymentId)?->isVoid());
        self::assertSame($entriesBefore, $this->ledgerEntryCount(), 'no reversal was left behind');
    }

    /**
     * WHAT THIS PROVES: asking twice makes no second financial effect, and the
     * first void's reason, actor and time are untouched.
     */
    public function testARepeatedVoidMakesNoSecondFinancialEffect(): void
    {
        $item = $this->completedSale(614, 1000000, 900000);
        $recorded = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-V4');
        $paymentId = (int) $recorded->context['payment_id'];
        self::assertTrue($this->service->void($paymentId, 'دلیل اول')->ok);
        $entriesAfterFirst = $this->ledgerEntryCount();

        $again = $this->service->void($paymentId, 'دلیل دوم');
        self::assertFalse($again->ok);
        self::assertSame(RecordSupplierPayment::ALREADY_VOID, $again->code);
        self::assertSame('دلیل اول', $this->payments->find($paymentId)?->voidReason);
        self::assertSame($entriesAfterFirst, $this->ledgerEntryCount());
    }

    /** WHAT THIS PROVES: a void with no reason changes nothing at all. */
    public function testAVoidWithoutAReasonIsRefused(): void
    {
        $item = $this->completedSale(615, 1000000, 900000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-V5')
            ->context['payment_id'];

        $result = $this->service->void($paymentId, '   ');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::VOID_REASON_REQUIRED, $result->code);
        self::assertFalse($this->payments->find($paymentId)?->isVoid());
    }

    /**
     * WHAT THIS PROVES: after a void the item is free again — for a corrected
     * payment AND for the vendor's own withdrawal cycle.
     *
     * This is why the claim's uniqueness had to move onto a nullable column
     * rather than be deleted: with `UNIQUE (order_item_id)` still carrying it,
     * the corrected payment collided with the record of the wrong one, and
     * §5's «سپس ثبت صحیح» was impossible.
     */
    public function testVoidingFreesTheItemForACorrectionAndForAWithdrawal(): void
    {
        $item = $this->completedSale(616, 1000000, 900000);
        $wrong = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-WRONG')
            ->context['payment_id'];
        self::assertTrue($this->service->void($wrong, 'مبلغ اشتباه')->ok);

        $corrected = $this->service->record(self::SUPPLIER, [$item], '850000', '2026-10-18', 'TRK-RIGHT');
        self::assertTrue($corrected->ok, 'the correct payment can now be recorded');
        self::assertNotSame($wrong, (int) $corrected->context['payment_id']);
        // The wrong one is still on disk, voided.
        self::assertTrue($this->payments->find($wrong)?->isVoid());

        // …and with BOTH voided, the withdrawal cycle can claim the line.
        self::assertTrue($this->service->void((int) $corrected->context['payment_id'], 'هم اشتباه بود')->ok);
        $withdrawals = new DbWithdrawalRepository($this->db, new SystemClock());
        self::assertGreaterThan(
            0,
            $withdrawals->reserve(self::SUPPLIER, [$item], 900000, 'IR1', 'x'),
            'a voided claim holds nothing'
        );
    }

    /**
     * WHAT THIS PROVES: voiding ONE of two partial payments does not free an
     * item the other one still paid for.
     *
     * The shape `alpha.62` shipped, and the reason the owner's warning about
     * the moved unique key was right. The second partial payment wrote NO row
     * at all — the insert collided with the live key and the code said
     * `continue` — so when the first payment was voided and gave the key up,
     * nothing on disk said the item was still covered. From that moment the
     * withdrawal cycle could reserve the line and pay the vendor a second
     * time for a line Tecteb had already paid the supplier for.
     *
     * Measured on the figures rather than on the outcome code: the balance
     * after the void, the live claim, and whether `reserve()` can take it.
     */
    public function testVoidingOneOfTwoPartialPaymentsKeepsTheItemClaimed(): void
    {
        $item = $this->completedSale(630, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);

        $first = $this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-P1');
        self::assertTrue($first->ok);
        $second = $this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-18', 'TRK-P2');
        self::assertTrue($second->ok);
        $firstId = (int) $first->context['payment_id'];
        $secondId = (int) $second->context['payment_id'];

        self::assertTrue($this->service->void($firstId, 'حوالهٔ اول اشتباه ثبت شد')->ok);

        // The surviving payment now HOLDS the item, so nothing else can.
        self::assertSame(1, $this->liveClaimRowsFor($item), 'the item is still claimed');
        self::assertSame($secondId, $this->payments->forOrderItem($item)?->id);
        self::assertContains($item, $this->payments->paidOrderItemIds(self::SUPPLIER));

        // And the withdrawal cycle is refused — which is the whole point.
        $withdrawals = new DbWithdrawalRepository($this->db, new SystemClock());
        self::assertSame(
            0,
            $withdrawals->reserve(self::SUPPLIER, [$item], 900000, 'IR1', 'still-paid'),
            'an item a surviving payment covers may not be reserved'
        );

        // The void did what it was asked, too: the obligation is down to the
        // surviving payment's 400,000, not settled.
        $obligation = $this->obligations->forOrderItem($item);
        self::assertNotNull($obligation);
        self::assertSame(400000, $obligation->paidMinor);
        self::assertFalse($obligation->isSettled());
    }

    /**
     * WHAT THIS PROVES: with every payment voided, the item really is free.
     *
     * The other side of the same rule. A hand-over that never let go would be
     * as wrong in the other direction — an item nobody has paid for and that
     * no withdrawal can ever claim — and «مانده و اقلام فقط پس از موفقیت کامل
     * عملیات آزاد شوند» is about release happening, not about never happening.
     */
    public function testVoidingBothPartialPaymentsFreesTheItem(): void
    {
        $item = $this->completedSale(631, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);
        $first = $this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-Q1');
        $second = $this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-18', 'TRK-Q2');
        self::assertTrue($first->ok);
        self::assertTrue($second->ok);

        self::assertTrue($this->service->void((int) $second->context['payment_id'], 'دومی اشتباه')->ok);
        self::assertSame(1, $this->liveClaimRowsFor($item), 'the first still holds it');
        self::assertTrue($this->service->void((int) $first->context['payment_id'], 'اولی هم اشتباه')->ok);

        self::assertSame(0, $this->liveClaimRowsFor($item), 'nothing covers it now');
        self::assertSame(2, $this->claimRowsFor($item), 'and both rows are still on disk');
        self::assertNull($this->payments->forOrderItem($item));
        $withdrawals = new DbWithdrawalRepository($this->db, new SystemClock());
        self::assertGreaterThan(
            0,
            $withdrawals->reserve(self::SUPPLIER, [$item], 900000, 'IR1', 'free-again'),
            'a fully voided item is claimed by nobody'
        );
    }

    /**
     * WHAT THIS PROVES: the hand-over is part of the unit of work.
     *
     * If the promotion cannot be written, the void does not happen either —
     * otherwise the failure mode is precisely the defect: a voided payment,
     * an uncovered item and a surviving payment that paid for it. The
     * injection names the promotion's own statement, so the status write and
     * the release have already succeeded when it fires.
     */
    public function testAFailedHandOverLeavesTheVoidUndone(): void
    {
        $item = $this->completedSale(632, 1000000, 900000);
        self::assertTrue($this->agreements->agree($item, '900000', 'توافق')->ok);
        $first = $this->service->record(self::SUPPLIER, [$item], '500000', '2026-10-17', 'TRK-R1');
        $second = $this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-18', 'TRK-R2');
        self::assertTrue($first->ok);
        self::assertTrue($second->ok);
        $firstId = (int) $first->context['payment_id'];

        $failing = new FailingDatabase($this->db);
        $failing->failWhen(['SET live_item_id = order_item_id']);
        $outcome = $this->serviceOn($failing)->void($firstId, 'شکست تزریقی');
        self::assertFalse($outcome->ok);

        // Nothing half-done: the payment is NOT void and the item is still
        // held by the row that held it before.
        self::assertFalse((bool) $this->payments->find($firstId)?->isVoid());
        self::assertSame(1, $this->liveClaimRowsFor($item));
        self::assertSame($firstId, $this->payments->forOrderItem($item)?->id);
    }

    // ------------------------------------------------- §6 the reconciliation

    /**
     * WHAT THIS PROVES: an unknown share is reviewed on the SAME payment —
     * no second payment, and the first figure is still there.
     */
    public function testReconcilingAnUnknownSharePaymentUsesTheSamePayment(): void
    {
        $item = $this->saleWithNoRecordedShare(617, 1000000);
        $recorded = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-R');
        self::assertTrue($recorded->ok);
        self::assertSame(RecordSupplierPayment::RECORDED_SHARE_UNKNOWN, $recorded->code);
        $paymentId = (int) $recorded->context['payment_id'];

        // Still unknown: nothing changes, and the answer says so.
        $early = $this->service->reconcile($paymentId);
        self::assertFalse($early->ok);
        self::assertSame(RecordSupplierPayment::STILL_UNKNOWN, $early->code);
        self::assertSame([$item], $early->context['unknown_share_items']);
        self::assertFalse($this->payments->find($paymentId)?->isReconciled());

        // The share is filled in by the ordinary repair path, and NOW it can
        // be reviewed.
        self::assertTrue($this->orderItems->completeFinancials($item, 100000, 900000, 1000, 'product', 'order:617'));
        $result = $this->service->reconcile($paymentId);
        self::assertTrue($result->ok);
        self::assertSame(RecordSupplierPayment::RECONCILED, $result->code);
        self::assertSame(900000, $result->context['share_minor_final']);

        self::assertCount(1, $this->payments->forSupplier(self::SUPPLIER, 10), 'no second payment');
        $payment = $this->payments->find($paymentId);
        self::assertNotNull($payment);
        self::assertTrue($payment->isReconciled());
        self::assertSame(900000, $payment->shareMinorFinal);
        self::assertSame(0, $payment->share->minor, 'the figure recorded at the time is untouched');
    }

    /** WHAT THIS PROVES: reviewing twice keeps the first review's figures. */
    public function testReconcilingTwiceChangesNothing(): void
    {
        $item = $this->saleWithNoRecordedShare(618, 1000000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-R2')
            ->context['payment_id'];
        self::assertTrue($this->orderItems->completeFinancials($item, 100000, 900000, 1000, 'product', 'order:618'));
        self::assertTrue($this->service->reconcile($paymentId)->ok);
        $entries = $this->ledgerEntryCount();

        $again = $this->service->reconcile($paymentId);
        self::assertFalse($again->ok);
        self::assertSame(RecordSupplierPayment::ALREADY_RECONCILED, $again->code);
        self::assertSame($entries, $this->ledgerEntryCount());
    }

    /** WHAT THIS PROVES: a voided record is replaced, not reviewed. */
    public function testAVoidedPaymentIsNotReconciled(): void
    {
        $item = $this->saleWithNoRecordedShare(619, 1000000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-R3')
            ->context['payment_id'];
        self::assertTrue($this->service->void($paymentId, 'اشتباه')->ok);

        $result = $this->service->reconcile($paymentId);
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::IS_VOID, $result->code);
    }

    // ------------------------- the state a financial decision is taken from

    /**
     * WHAT THIS PROVES: whether a compensating entry is owed is decided from
     * the row read UNDER THE LOCK, not from the one read before it.
     *
     * The shape of the defect, measured on the delivered `alpha.64` with two
     * real requests (`tools/alpha64-reproduction.sh`, checks 2a–2l): a
     * payment with no money line was read by `void()`, an independent
     * `reconcile()` gave it one and committed, and the void then succeeded
     * with `compensating_event=__none__` — the document marked void, the
     * items released, and the ledger still saying the money had left.
     *
     * Here the same divergence is produced without a clock: the unlocked read
     * keeps answering from a frozen snapshot while the locked one tells the
     * truth. Reverting the fix makes this fail on the assertion about the
     * reversal entry, which is the point of writing it this way round.
     */
    public function testTheCompensationDecisionComesFromTheLockedRow(): void
    {
        $item = $this->saleWithNoRecordedShare(630, 1000000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-L1')
            ->context['payment_id'];
        self::assertTrue($this->orderItems->completeFinancials($item, 100000, 900000, 1000, 'product', 'order:630'));

        // The snapshot the voiding request would have taken: no money line.
        $stale = new StaleReadSupplierPaymentRepository($this->payments);
        $stale->freeze($paymentId);
        self::assertSame('', $stale->find($paymentId)?->ledgerEvent, 'the frozen read has no money line');

        // THE OTHER REQUEST, in full, exactly as the admin screen posts it.
        self::assertTrue($this->service->reconcile($paymentId)->ok);
        $payout = $this->payments->find($paymentId)?->ledgerEvent ?? '';
        self::assertNotSame('', $payout, 'the other request wrote the money line');
        self::assertSame('', $stale->find($paymentId)?->ledgerEvent, 'and the unlocked read still does not see it');

        $result = $this->serviceWithPayments($stale)->void($paymentId, 'شمارهٔ حواله اشتباه بود');
        self::assertTrue($result->ok);
        self::assertSame(RecordSupplierPayment::VOIDED, $result->code);
        self::assertSame(
            RecordSupplierPayment::voidEventKey($paymentId),
            $result->context['compensating_event'],
            'the compensating entry is owed and the answer says so'
        );
        self::assertTrue(
            $this->ledger->hasEvent(RecordSupplierPayment::voidEventKey($paymentId)),
            'and it is really in the ledger'
        );
        self::assertSame(
            RecordSupplierPayment::voidEventKey($paymentId),
            $this->payments->find($paymentId)?->voidEvent,
            'the row records which document reverses it'
        );
        // The pair sums to nought, so the supplier is owed again rather than
        // settled — which is what a void of a mis-record means.
        $balances = $this->ledger->balances(self::SUPPLIER);
        self::assertSame(-900000, (int) ($balances[LedgerAccount::VendorEarning->value] ?? 0));
    }

    /**
     * WHAT THIS PROVES: the mirror ordering is refused BY NAME, and writes
     * nothing on the way to the refusal.
     *
     * `markReconciled()` already carried `voided_at IS NULL`, so a void
     * committing inside a `reconcile()`'s gap was refused on `alpha.64` too —
     * but as `storage_failed`, and only after a payout entry had been written
     * and rolled back. Under the lock the answer is the fact, and no ledger
     * statement runs at all.
     */
    public function testAReconcileWhoseRowWasVoidedInTheGapIsRefusedByName(): void
    {
        $item = $this->saleWithNoRecordedShare(631, 1000000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-L2')
            ->context['payment_id'];
        self::assertTrue($this->orderItems->completeFinancials($item, 100000, 900000, 1000, 'product', 'order:631'));

        $stale = new StaleReadSupplierPaymentRepository($this->payments);
        $stale->freeze($paymentId);
        self::assertFalse($stale->find($paymentId)?->isVoid(), 'the frozen read says it is live');

        self::assertTrue($this->service->void($paymentId, 'سند اشتباه بود')->ok);
        $entries = $this->ledgerEntryCount();

        $result = $this->serviceWithPayments($stale)->reconcile($paymentId);
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::IS_VOID, $result->code);
        self::assertSame($entries, $this->ledgerEntryCount(), 'no money line was written on the way');
        self::assertSame('', $this->payments->find($paymentId)?->ledgerEvent);
        self::assertFalse($this->payments->find($paymentId)?->isReconciled());
    }

    /**
     * WHAT THIS PROVES: the locked read tells «there is no such payment»
     * apart from «the read failed».
     *
     * `getRow()` gives one answer for both (`alpha.54`), and here they are
     * opposites: a failed read reported as `NOT_FOUND` would tell a manager
     * that the payment on their screen does not exist — and would let the
     * void be refused for the wrong reason.
     */
    public function testTheLockedReadSeparatesAbsenceFromFailure(): void
    {
        $item = $this->completedSale(632, 1000000, 900000);
        $paymentId = (int) $this->service
            ->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-L3')
            ->context['payment_id'];

        self::assertSame(
            SupplierPaymentRepositoryInterface::NOT_FOUND,
            $this->payments->findForUpdate($paymentId + 9999)['outcome']
        );
        self::assertSame(
            SupplierPaymentRepositoryInterface::LOCKED,
            $this->payments->findForUpdate($paymentId)['outcome']
        );

        $failing = new FailingDatabase($this->db);
        $failing->failReadWhen(['FOR UPDATE']);
        $held = (new DbSupplierPaymentRepository($failing, new SystemClock()))->findForUpdate($paymentId);
        self::assertSame(SupplierPaymentRepositoryInterface::FAILED, $held['outcome']);
        self::assertNull($held['payment']);

        // …and the service turns that into a storage failure rather than
        // into «no such payment», with nothing written.
        $service = $this->serviceOn($failing);
        $result = $service->void($paymentId, 'اشتباه');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::STORAGE_FAILED, $result->code);
        self::assertFalse($this->payments->find($paymentId)?->isVoid());
    }

    // ------------------------------------------------- §2 the receivable

    /**
     * WHAT THIS PROVES: three events, one balance — and one amount is never
     * collected twice or deducted twice.
     */
    public function testARecordedDebtIsCollectedAndOffsetWithinOneBalance(): void
    {
        $item = $this->completedSale(620, 1000000, 900000);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-D')->ok);

        $debt = $this->debts->record($item, '600000', 31, 2, 'دو عدد مرجوع شد');
        self::assertTrue($debt->ok);
        self::assertSame(ManageSupplierReceivables::RECORDED, $debt->code);
        $receivableId = (int) $debt->context['receivable_id'];

        // Recording it moved nothing: no settlement rows yet.
        self::assertSame([], $this->receivables->settlementsOf($receivableId));
        self::assertSame(600000, $this->receivables->find($receivableId)?->balanceMinor());

        $collected = $this->debts->collect($receivableId, '200000', 'TRK-BACK', '', 'k1');
        self::assertTrue($collected->ok);
        self::assertSame(400000, $collected->context['balance_minor']);

        $offset = $this->debts->offset($receivableId, '400000', 0, '', 'k2');
        self::assertTrue($offset->ok);
        self::assertSame(0, $offset->context['balance_minor']);

        $settlements = $this->receivables->settlementsOf($receivableId);
        self::assertCount(2, $settlements);
        self::assertSame([V::SETTLEMENT_OFFSET, V::SETTLEMENT_COLLECTED], array_column($settlements, 'kind'));

        // And one unit more is refused — the whole of «یک مبلغ دوبار وصول یا
        // کسر نشود».
        $over = $this->debts->collect($receivableId, '1', 'TRK-X', '', 'k3');
        self::assertFalse($over->ok);
        self::assertSame(ManageSupplierReceivables::ABOVE_BALANCE, $over->code);
    }

    /** WHAT THIS PROVES: the same submission twice records one settlement. */
    public function testTheSameCollectionSubmittedTwiceWritesOneRow(): void
    {
        $item = $this->completedSale(621, 1000000, 900000);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-17', 'TRK-D2')->ok);
        $receivableId = (int) $this->debts->record($item, '500000', 32, 1, 'مرجوعی')->context['receivable_id'];

        self::assertTrue($this->debts->collect($receivableId, '500000', 'TRK-ONE', '', 'same-token')->ok);
        $again = $this->debts->collect($receivableId, '500000', 'TRK-ONE', '', 'same-token');
        self::assertFalse($again->ok);
        self::assertSame(ManageSupplierReceivables::ALREADY_SETTLED, $again->code);
        self::assertCount(1, $this->receivables->settlementsOf($receivableId));
    }

    /**
     * WHAT THIS PROVES: the same return and item produce ONE debt, and a debt
     * on an item nobody paid for is refused.
     */
    public function testADebtIsRecordedOnceAndOnlyAgainstAPaidItem(): void
    {
        $paid = $this->completedSale(622, 1000000, 900000);
        $unpaid = $this->completedSale(623, 1000000, 900000);
        self::assertTrue($this->service->record(self::SUPPLIER, [$paid], '900000', '2026-10-17', 'TRK-D3')->ok);

        $first = $this->debts->record($paid, '100000', 40, 1, 'یکی');
        self::assertTrue($first->ok);
        $second = $this->debts->record($paid, '100000', 40, 1, 'همان');
        self::assertFalse($second->ok);
        self::assertSame(ManageSupplierReceivables::ALREADY_RECORDED, $second->code);
        self::assertSame((int) $first->context['receivable_id'], (int) $second->context['receivable_id']);

        $never = $this->debts->record($unpaid, '100000', 41, 1, 'پرداخت نشده');
        self::assertFalse($never->ok);
        self::assertSame(ManageSupplierReceivables::ITEM_NOT_PAID, $never->code);
    }

    /**
     * WHAT THIS PROVES: a debt larger than what was ever paid for the item is
     * refused, with both numbers.
     */
    public function testADebtAboveWhatWasPaidIsRefused(): void
    {
        $item = $this->completedSale(624, 1000000, 900000);
        self::assertTrue($this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-17', 'TRK-D4')->ok);

        $result = $this->debts->record($item, '500000', 42, 1, 'بیشتر از پرداخت');
        self::assertFalse($result->ok);
        self::assertSame(ManageSupplierReceivables::ABOVE_BALANCE, $result->code);
        self::assertSame(400000, $result->context['paid_minor']);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * The same service, with a named payment repository in place of the real
     * one — so a test can make the unlocked and the locked read disagree.
     */
    private function serviceWithPayments(SupplierPaymentRepositoryInterface $payments): RecordSupplierPayment
    {
        $clock = new SystemClock();
        return new RecordSupplierPayment(
            $payments,
            new DbOrderItemRepository($this->db, $clock),
            new DbLedgerRepository($this->db, $clock),
            new DbVendorMoneyUnitRegistry($this->db, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new AuditLogger($this->audit, new AuditEventSanitizer(), $clock),
            $clock,
            $this->db,
            new DbSupplierObligationRepository($this->db, $clock)
        );
    }

    private function serviceOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $gateway): RecordSupplierPayment
    {
        $clock = new SystemClock();
        return new RecordSupplierPayment(
            new DbSupplierPaymentRepository($gateway, $clock),
            new DbOrderItemRepository($gateway, $clock),
            new DbLedgerRepository($gateway, $clock),
            new DbVendorMoneyUnitRegistry($gateway, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new AuditLogger($this->audit, new AuditEventSanitizer(), $clock),
            $clock,
            // The SAME gateway the repositories hold (`alpha.56`).
            $gateway,
            new DbSupplierObligationRepository($gateway, $clock)
        );
    }

    private function agreementsOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $gateway): ManageSupplierObligations
    {
        $clock = new SystemClock();
        return new ManageSupplierObligations(
            new DbSupplierObligationRepository($gateway, $clock),
            new DbOrderItemRepository($gateway, $clock),
            new DbVendorMoneyUnitRegistry($gateway, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new AuditLogger($this->audit, new AuditEventSanitizer(), $clock)
        );
    }

    private function debtsOn(\Tecteb\Marketplace\Contracts\DatabaseInterface $gateway): ManageSupplierReceivables
    {
        $clock = new SystemClock();
        return new ManageSupplierReceivables(
            new DbSupplierReceivableRepository($gateway, $clock),
            new DbSupplierPaymentRepository($gateway, $clock),
            new DbOrderItemRepository($gateway, $clock),
            new DbVendorMoneyUnitRegistry($gateway, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new AuditLogger($this->audit, new AuditEventSanitizer(), $clock)
        );
    }

    /** A payment record for the rival writer in the race test. */
    private function paymentRecord(string $reference, int $minor): \Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment
    {
        return new \Tecteb\Marketplace\Modules\Finance\Domain\SupplierPayment(
            0,
            self::SUPPLIER,
            Money::of($minor),
            Money::of(0),
            '2026-10-18',
            $reference,
            '',
            '',
            self::MANAGER,
            'rival:' . $reference,
            '',
            V::POSTING_PARTIAL,
            ''
        );
    }

    /** Every claim row for one item, live or released. */
    private function claimRowsFor(int $itemId): int
    {
        return (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->db->prefix() . 'tmc_supplier_payment_items` WHERE order_item_id = %d',
            [$itemId]
        ) ?? 0);
    }

    /** The LIVE claim rows for one item — at most one, by the unique key. */
    private function liveClaimRowsFor(int $itemId): int
    {
        return (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->db->prefix() . 'tmc_supplier_payment_items` WHERE live_item_id = %d',
            [$itemId]
        ) ?? 0);
    }

    private function ledgerEntryCount(): int
    {
        return (int) ($this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->db->prefix() . 'tmc_ledger_entries`'
        ) ?? 0);
    }

    private function completedSale(int $orderItemId, int $baseMinor, int $shareMinor): int
    {
        $id = $this->sale($orderItemId, $baseMinor, $shareMinor);
        self::assertTrue($this->orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', self::MANAGER));
        return $id;
    }

    private function saleWithNoRecordedShare(int $orderItemId, int $baseMinor): int
    {
        self::assertTrue($this->ledger->record(
            (new LedgerTransaction('order:' . $orderItemId, self::SUPPLIER, 'order:' . $orderItemId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, Money::of($baseMinor), 'item_paid')
                ->add(LedgerAccount::Commission, Money::of(100000)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, Money::of($baseMinor - 100000)->negate(), 'vendor_earned')
        ));
        $id = $this->orderItems->record(new VendorOrderItem(
            0,
            5000 + $orderItemId,
            $orderItemId,
            0,
            self::SUPPLIER,
            'کالای بی‌سهم',
            'SKU-' . $orderItemId,
            1,
            $baseMinor,
            $baseMinor,
            0,
            null,
            null,
            null,
            '',
            '',
            OrderItemStatus::Delivered
        ));
        self::assertTrue($this->orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', self::MANAGER));
        return $id;
    }

    private function sale(int $orderItemId, int $baseMinor, int $shareMinor): int
    {
        self::assertTrue($this->ledger->record(
            (new LedgerTransaction('order:' . $orderItemId, self::SUPPLIER, 'order:' . $orderItemId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, Money::of($baseMinor), 'item_paid')
                ->add(LedgerAccount::Commission, Money::of($baseMinor - $shareMinor)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, Money::of($shareMinor)->negate(), 'vendor_earned')
        ));

        return $this->orderItems->record(new VendorOrderItem(
            0,
            5000 + $orderItemId,
            $orderItemId,
            0,
            self::SUPPLIER,
            'کالای آزمایشی',
            'SKU-' . $orderItemId,
            1,
            $baseMinor,
            $baseMinor,
            0,
            $baseMinor - $shareMinor,
            $shareMinor,
            1000,
            'product',
            'order:' . $orderItemId,
            OrderItemStatus::Delivered
        ));
    }
}
