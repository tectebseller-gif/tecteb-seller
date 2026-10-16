<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpAuditRepository;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as T;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;

/**
 * WHAT THIS PROVES: a payment Tecteb already made to a supplier can be written
 * down once, with everything needed to find it again — and the marketplace
 * states nothing about money it has not been told how to interpret.
 *
 * Against real MariaDB, because the two guarantees that matter are both the
 * database's: the unique key that stops a refreshed page writing a second
 * payment, and the unique key that stops one order item being paid twice. A
 * check-then-insert would pass every one of these tests and fail in production
 * on a double click.
 */
final class SupplierPaymentFlowTest extends DatabaseTestCase
{
    private const SUPPLIER = 81;
    private const OTHER_SUPPLIER = 82;
    private const MANAGER = 9;

    private DbOrderItemRepository $orderItems;
    private DbLedgerRepository $ledger;
    private DbSupplierPaymentRepository $payments;
    private RecordSupplierPayment $service;
    private \Tecteb\Marketplace\Tests\Support\RecordingAuditRepository $trail;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new WpDatabase($this->wpdb);
        $this->resetSchema($db);

        $clock = new SystemClock();
        // The RECORDING repository, so the trail can be read back in-process.
        // The real one writes to `tmc_audit` and reading it back would be
        // testing the audit repository, which has its own suite.
        $this->trail = $this->audit;
        $logger = new AuditLogger($this->trail, new AuditEventSanitizer(), $clock);

        $this->orderItems = new DbOrderItemRepository($db, $clock);
        $this->ledger = new DbLedgerRepository($db, $clock);
        $this->payments = new DbSupplierPaymentRepository($db, $clock);

        $this->service = new RecordSupplierPayment(
            $this->payments,
            $this->orderItems,
            $this->ledger,
            new DbVendorMoneyUnitRegistry($db, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            $logger,
            $clock,
            // THE SAME gateway object the repository holds — nesting is counted
            // in the object, and a second instance over the same connection
            // would commit this unit of work from the inside (`alpha.56`).
            $db
        );
    }

    /**
     * WHAT THIS PROVES: the straightforward case — an amount that matches the
     * recorded share is written down AND posted to the existing ledger.
     *
     * «دفتر موازی نساز»: the money line goes into `tmc_ledger` through the
     * same `LedgerTransaction` as every other amount in the plugin.
     */
    public function testAPaymentThatMatchesTheRecordedShareIsRecordedAndPosted(): void
    {
        $a = $this->sale(301, 500000, 450000);
        $b = $this->sale(302, 300000, 270000);

        $result = $this->service->record(
            self::SUPPLIER,
            [$a, $b],
            '720000',          // 450,000 + 270,000
            '2026-10-14',
            'TRK-1001',
            'پرداخت دستی'
        );

        self::assertTrue($result->ok, $result->code);
        self::assertSame(RecordSupplierPayment::RECORDED, $result->code);

        $payment = $this->payments->find((int) $result->context['payment_id']);
        self::assertNotNull($payment);
        self::assertSame(720000, $payment->amount->minor);
        self::assertSame(720000, $payment->share->minor);
        self::assertTrue($payment->agreesWithShare());
        self::assertSame(T::POSTING_POSTED, $payment->posting);
        self::assertSame('2026-10-14', $payment->paidOn);
        self::assertSame('TRK-1001', $payment->reference);
        self::assertSame(self::MANAGER, $payment->recordedBy);
        self::assertSame([$a, $b], $payment->orderItemIds);

        // The money is on the existing books, under this payment's own event.
        $entries = $this->ledger->forEvent(RecordSupplierPayment::eventKey($payment->id));
        self::assertNotSame([], $entries, 'a matching payment posts a money line');

        // …and the trail says a financial change happened.
        $logged = array_filter(
            $this->trail->records,
            static fn (\Tecteb\Marketplace\Contracts\AuditRecord $r): bool
                => $r->eventType === AuditEventCatalog::SUPPLIER_PAYMENT_RECORDED
        );
        self::assertNotSame([], $logged, 'every financial change is audited');
    }

    /**
     * WHAT THIS PROVES: an amount that differs from the recorded share is
     * RECORDED and NOT posted — the conflict §6 asks to be identified.
     *
     * Both halves are the assertion. Recording it is what makes the payment
     * findable; refusing to post it is what stops the marketplace stating that
     * a cash purchase price settled an earned share, which is a definition
     * nobody has given. «فرض نکن قیمت خرید نقدی با سهم محاسبه‌شده برابر است.»
     */
    public function testAnAmountThatDiffersFromTheShareIsRecordedAndNotPosted(): void
    {
        $item = $this->sale(311, 500000, 450000);

        $result = $this->service->record(
            self::SUPPLIER,
            [$item],
            '400000',                  // the price Tecteb agreed, not the share
            '2026-10-14',
            'TRK-1002'
        );

        self::assertTrue($result->ok, $result->code);
        self::assertSame(RecordSupplierPayment::RECORDED_UNPOSTED, $result->code);

        $payment = $this->payments->find((int) $result->context['payment_id']);
        self::assertNotNull($payment);
        self::assertSame(400000, $payment->amount->minor);
        self::assertSame(450000, $payment->share->minor, 'both numbers are kept, side by side');
        self::assertFalse($payment->agreesWithShare());
        self::assertSame(T::POSTING_NEEDS_DEFINITION, $payment->posting);
        self::assertSame('', $payment->ledgerEvent);

        self::assertSame(
            [],
            $this->ledger->forEvent(RecordSupplierPayment::eventKey($payment->id)),
            'nothing is posted about an amount nobody has defined'
        );

        // And it is findable by the person who has to decide — a remedy
        // nobody can list is not a remedy (`alpha.39`).
        $waiting = $this->service->awaitingDefinition();
        self::assertCount(1, $waiting);
        self::assertSame($payment->id, $waiting[0]->id);
    }

    /**
     * WHAT THIS PROVES: the same submission twice writes ONE payment.
     *
     * «به‌روزرسانی صفحه یا ارسال دوبارهٔ درخواست نباید پرداخت دوم بسازد.» The
     * form's own token is what makes it the SAME submission, and the unique
     * key is what refuses it — not a read beforehand, which two tabs both pass.
     */
    public function testTheSameSubmissionTwiceWritesOnePayment(): void
    {
        $item = $this->sale(321, 500000, 450000);
        $token = 'form-token-xyz';

        $first = $this->service->record(self::SUPPLIER, [$item], '450000', '2026-10-14', 'TRK-1003', '', '', $token);
        self::assertTrue($first->ok, $first->code);

        $second = $this->service->record(self::SUPPLIER, [$item], '450000', '2026-10-14', 'TRK-1003', '', '', $token);
        self::assertFalse($second->ok);
        self::assertSame(RecordSupplierPayment::ALREADY_RECORDED, $second->code);
        self::assertSame(
            (int) $first->context['payment_id'],
            (int) $second->context['payment_id'],
            'the refusal points at the payment that exists'
        );

        self::assertCount(1, $this->payments->forSupplier(self::SUPPLIER));
    }

    /**
     * WHAT THIS PROVES: a refresh with no token of its own is still ONE
     * payment.
     *
     * The fallback key is derived from the submission's content, so an
     * identical form produces an identical key. The pair matters: a genuinely
     * different payment must still get through, and it does — the second
     * assertion changes one field.
     */
    public function testARefreshWithNoTokenIsStillOnePaymentAndADifferentOneGetsThrough(): void
    {
        $a = $this->sale(331, 500000, 450000);
        $b = $this->sale(332, 500000, 450000);

        self::assertTrue($this->service->record(self::SUPPLIER, [$a], '450000', '2026-10-14', 'TRK-A')->ok);
        $again = $this->service->record(self::SUPPLIER, [$a], '450000', '2026-10-14', 'TRK-A');
        self::assertSame(RecordSupplierPayment::ALREADY_RECORDED, $again->code);

        $other = $this->service->record(self::SUPPLIER, [$b], '450000', '2026-10-14', 'TRK-B');
        self::assertTrue($other->ok, $other->code);
        self::assertCount(2, $this->payments->forSupplier(self::SUPPLIER));
    }

    /** An order item already covered by a payment is refused BY NAME, and nothing is written. */
    public function testAnItemAlreadyPaidIsRefusedByNameAndNothingIsWritten(): void
    {
        $a = $this->sale(341, 500000, 450000);
        $b = $this->sale(342, 300000, 270000);
        self::assertTrue($this->service->record(self::SUPPLIER, [$a], '450000', '2026-10-14', 'TRK-C')->ok);

        $again = $this->service->record(self::SUPPLIER, [$a, $b], '720000', '2026-10-15', 'TRK-D');
        self::assertFalse($again->ok);
        self::assertSame(RecordSupplierPayment::ITEM_ALREADY_PAID, $again->code);
        self::assertSame($a, (int) $again->context['order_item_id'], 'the refusal names the item');

        // The second item is NOT quietly half-written: one payment on disk.
        self::assertCount(1, $this->payments->forSupplier(self::SUPPLIER));
        self::assertNull($this->payments->forOrderItem($b));
    }

    /**
     * WHAT THIS PROVES: one payment cannot cover two suppliers' items.
     *
     * «سفارش چندتأمین‌کننده‌ای اقلام و دسترسی جدا دارد» — and the refusal names
     * the item rather than the supplier, because the item is what the manager
     * has to take out of the list.
     */
    public function testAPaymentCannotCoverAnotherSuppliersItem(): void
    {
        $mine = $this->sale(351, 500000, 450000);
        $theirs = $this->sale(352, 300000, 270000, self::OTHER_SUPPLIER);

        $result = $this->service->record(self::SUPPLIER, [$mine, $theirs], '720000', '2026-10-14', 'TRK-E');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::WRONG_SUPPLIER, $result->code);
        self::assertSame($theirs, (int) $result->context['order_item_id']);
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
    }

    /**
     * WHAT THIS PROVES: the amount comes from a person, and nothing generates
     * one.
     *
     * «مبلغ خرید از تأمین‌کننده را از صفر یا نرخ حدسی تولید نکن.» Zero, blank
     * and a value with more precision than this money has are all refused by
     * name, and none of them falls back to the computed share — which is the
     * number that was right there and would have been the easy default.
     */
    public function testAnAmountIsNeverGeneratedFromZeroOrFromTheShare(): void
    {
        $item = $this->sale(361, 500000, 450000);

        foreach (['0', '', '  ', 'خیلی', '-5', '450000.75'] as $typed) {
            $result = $this->service->record(self::SUPPLIER, [$item], $typed, '2026-10-14', 'TRK-F');
            self::assertFalse($result->ok, 'accepted: ' . $typed);
            self::assertSame(RecordSupplierPayment::AMOUNT_REQUIRED, $result->code, 'for: ' . $typed);
        }
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
    }

    /** A tracking number and a date are required, and their absence writes nothing. */
    public function testAReferenceAndADateAreRequired(): void
    {
        $item = $this->sale(371, 500000, 450000);

        $noRef = $this->service->record(self::SUPPLIER, [$item], '450000', '2026-10-14', '   ');
        self::assertSame(RecordSupplierPayment::REFERENCE_REQUIRED, $noRef->code);

        foreach (['', '14/10/2026', '2026-10', 'دیروز'] as $date) {
            $bad = $this->service->record(self::SUPPLIER, [$item], '450000', $date, 'TRK-G');
            self::assertSame(RecordSupplierPayment::DATE_REQUIRED, $bad->code, 'for: ' . $date);
        }
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
    }

    /**
     * WHAT THIS PROVES: a supplier whose money unit is not on record is
     * refused rather than recorded in a guessed one.
     *
     * The `alpha.39` rule, one object out: «با واحد حدسی ثبت نشود». The unit
     * is asked BEFORE the amount is read, because the amount cannot be
     * converted without it.
     */
    public function testASupplierWithNoMoneyUnitOnRecordIsRefused(): void
    {
        // An item with no ledger event behind it, so the registry has nothing
        // to read and nothing to claim from.
        $item = $this->orderItems->record(new VendorOrderItem(
            0,
            5999,
            399,
            0,
            self::SUPPLIER,
            'کالای بی‌دفتر',
            'SKU-399',
            1,
            500000,
            500000,
            0,
            50000,
            450000,
            1000,
            'product',
            '',
            OrderItemStatus::Delivered
        ));

        $result = $this->service->record(self::SUPPLIER, [$item], '450000', '2026-10-14', 'TRK-H');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::UNIT_UNKNOWN, $result->code);
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
    }

    /** Without the financial permission, nothing is recorded and nothing is read. */
    public function testWithoutTheFinancialPermissionNothingIsRecordedOrListed(): void
    {
        $item = $this->sale(381, 500000, 450000);
        $db = new WpDatabase($this->wpdb);
        $clock = new SystemClock();
        $blind = new RecordSupplierPayment(
            $this->payments,
            $this->orderItems,
            $this->ledger,
            new DbVendorMoneyUnitRegistry($db, $clock),
            new FakeCapabilityChecker(42, ['read']),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock,
            $db
        );

        $result = $blind->record(self::SUPPLIER, [$item], '450000', '2026-10-14', 'TRK-I');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::FORBIDDEN, $result->code);
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
        self::assertSame([], $blind->awaitingDefinition(), 'the list is not readable either');
    }

    /**
     * WHAT THIS PROVES: a build that cannot make the record and its money line
     * one unit of work records NEITHER.
     *
     * The same posture as `payment_not_atomic` in `ReviewWithdrawals`: a
     * payment document is not a place to find out afterwards that half of it
     * landed.
     */
    public function testWithoutAUnitOfWorkNothingIsRecordedAtAll(): void
    {
        $item = $this->sale(391, 500000, 450000);
        $db = new WpDatabase($this->wpdb);
        $clock = new SystemClock();
        $loose = new RecordSupplierPayment(
            $this->payments,
            $this->orderItems,
            $this->ledger,
            new DbVendorMoneyUnitRegistry($db, $clock),
            new FakeCapabilityChecker(self::MANAGER, [Capabilities::REVIEW_WITHDRAWALS]),
            new AuditLogger(new WpAuditRepository($this->wpdb), new AuditEventSanitizer(), $clock),
            $clock
            // …and no transaction.
        );

        $result = $loose->record(self::SUPPLIER, [$item], '450000', '2026-10-14', 'TRK-J');
        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::NOT_ATOMIC, $result->code);
        self::assertSame([], $this->payments->forSupplier(self::SUPPLIER));
    }

    /**
     * WHAT THIS PROVES: there is no way to transfer money from here.
     *
     * Checked by the ABSENCE of a method, which is the strongest guard this
     * repository knows (`alpha.9`, `alpha.55`): a service with no transfer
     * cannot be made to transfer by forgetting a flag. The reflection is the
     * test rather than a sentence in a docblock.
     */
    public function testTheServiceHasNoWayToMoveMoney(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => strtolower($m->getName()),
            (new \ReflectionClass(RecordSupplierPayment::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        foreach (['pay', 'transfer', 'send', 'payout', 'settle'] as $forbidden) {
            foreach ($methods as $method) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $method,
                    'this service records payments; it must not be able to make one: ' . $method
                );
            }
        }
    }

    /**
     * One recorded sale for a supplier: a ledger event in a known unit, and an
     * order-item row that carries the share.
     *
     * The ledger event comes first because the money unit is read off it — the
     * registry is the only thing that says which currency a supplier's books
     * are in, and it reads the ledger.
     */
    private function sale(int $orderItemId, int $baseMinor, int $shareMinor, ?int $supplier = null): int
    {
        $supplier ??= self::SUPPLIER;
        $base = Money::of($baseMinor);
        $commission = Money::of($baseMinor - $shareMinor);
        $share = Money::of($shareMinor);
        self::assertTrue($this->ledger->record(
            (new LedgerTransaction('order:' . $orderItemId, $supplier, 'order:' . $orderItemId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                ->add(LedgerAccount::Commission, $commission->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
        ), 'the sale is on the books before anybody records a payment for it');

        return $this->orderItems->record(new VendorOrderItem(
            0,
            5000 + $orderItemId,
            $orderItemId,
            0,
            $supplier,
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
