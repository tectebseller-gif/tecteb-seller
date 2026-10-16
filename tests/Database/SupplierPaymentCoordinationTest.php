<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Database;

use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Infrastructure\WordPress\WpOptionStore;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments as T;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0030SupplierPaymentShareUnknown as U;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Tests\Support\FakeCapabilityChecker;
use Tecteb\Marketplace\Tests\Support\InterferingDatabase;

/**
 * WHAT THIS PROVES: one obligation for one order item is settled ONCE, from
 * whichever of the two paths gets there first — and the other path refuses by
 * name instead of settling it again.
 *
 * There are two paths and they were built apart on purpose: a **withdrawal**
 * is a vendor asking for money the marketplace owes them, and a **supplier
 * payment** is Tecteb buying stock by hand. The review's §2 found that neither
 * could see the other's claim, and all four of its scenarios reproduced on the
 * delivered `alpha.60` bytes (`docs/evidence/alpha61-reproduction/`):
 *
 *   item paid through withdrawal #4  → manual payment recorded AND posted
 *   item reserved in open request #5 → manual payment recorded AND posted
 *   item paid by hand first          → still `eligible`, and `reserve()` took it
 *
 * Against real MariaDB, because the guard is not a condition in PHP: it is
 * inside each path's own write — `INSERT ... SELECT` on the order line for one
 * direction, a `NOT EXISTS` in the claim `UPDATE` for the other. A
 * check-then-insert would pass every test here and fail in production the
 * first time the two landed in the same second.
 */
final class SupplierPaymentCoordinationTest extends DatabaseTestCase
{
    private const SUPPLIER = 91;
    private const MANAGER = 9;

    private DbOrderItemRepository $orderItems;
    private DbLedgerRepository $ledger;
    private DbSupplierPaymentRepository $payments;
    private DbWithdrawalRepository $withdrawals;
    private RecordSupplierPayment $service;
    private VendorBalance $balance;
    private WpDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new WpDatabase($this->wpdb);
        $this->resetSchema($this->db);

        $clock = new SystemClock();
        $this->orderItems = new DbOrderItemRepository($this->db, $clock);
        $this->ledger = new DbLedgerRepository($this->db, $clock);
        $this->payments = new DbSupplierPaymentRepository($this->db, $clock);
        $this->withdrawals = new DbWithdrawalRepository($this->db, $clock);
        $this->service = $this->serviceOn($this->db);
        $this->balance = new VendorBalance(
            $this->orderItems,
            new SettingsService(new WpOptionStore()),
            $clock,
            null,
            $this->payments
        );
    }

    // ------------------------------------------------- the withdrawal first

    /**
     * WHAT THIS PROVES: an item a PAID withdrawal covers cannot also be paid
     * by hand, and the refusal names the request rather than releasing it.
     *
     * A paid request keeps its lines for ever, as the record of what the
     * payment covered. On `alpha.60` this exact sequence produced
     * `supplier_payment_recorded` with a second payout line in `tmc_ledger`.
     */
    public function testAnItemPaidThroughAWithdrawalIsNotPaidByHandAsWell(): void
    {
        $item = $this->completedSale(401, 1000000, 900000);
        $withdrawalId = $this->withdrawals->reserve(self::SUPPLIER, [$item], 900000, 'IR1', 'x');
        self::assertGreaterThan(0, $withdrawalId);
        self::assertTrue($this->withdrawals->updateStatus(
            $withdrawalId,
            WithdrawalStatus::Paid,
            self::MANAGER,
            '',
            'TRK-W-1'
        ));

        $result = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-16', 'TRK-M-1');

        self::assertFalse($result->ok);
        self::assertSame(RecordSupplierPayment::ITEM_HELD_BY_WITHDRAWAL, $result->code);
        self::assertSame($item, $result->context['order_item_id']);
        self::assertSame($withdrawalId, $result->context['withdrawal_id']);
        self::assertSame(1, $result->context['withdrawal_paid']);

        // NOTHING written, and nothing released: the request still holds the
        // line it was paid for. «رزرو را خودکار آزاد نکن».
        self::assertNull($this->payments->forOrderItem($item));
        self::assertSame([$item], $this->withdrawals->lineIds($withdrawalId));
        self::assertSame($withdrawalId, $this->claimOf($item));
    }

    /**
     * WHAT THIS PROVES: a line reserved in an OPEN request is held too.
     *
     * The money has not left yet, but the vendor has asked for it and the
     * marketplace has locked it — so recording a purchase for the same line
     * would put the same sale into two settlements, one of which a person is
     * still deciding.
     */
    public function testAnItemReservedInAnOpenRequestIsNotPaidByHand(): void
    {
        $item = $this->completedSale(402, 1000000, 900000);
        $withdrawalId = $this->withdrawals->reserve(self::SUPPLIER, [$item], 900000, 'IR1', 'x');
        self::assertGreaterThan(0, $withdrawalId);
        self::assertSame(WithdrawalStatus::Requested, $this->withdrawals->find($withdrawalId)?->status);

        $result = $this->service->record(self::SUPPLIER, [$item], '900000', '2026-10-16', 'TRK-M-2');

        self::assertSame(RecordSupplierPayment::ITEM_HELD_BY_WITHDRAWAL, $result->code);
        self::assertSame(0, $result->context['withdrawal_paid'], 'open, not paid — a different sentence');
        self::assertNull($this->payments->forOrderItem($item));
    }

    // ------------------------------------------------- the payment first

    /**
     * WHAT THIS PROVES: once Tecteb has paid for a line by hand, the vendor's
     * own cycle neither offers it nor claims it.
     *
     * Both halves, because they fail differently: the balance is what a vendor
     * and `RequestWithdrawal` read, and the claim is what actually writes. On
     * `alpha.60` the balance said `eligible` AND the claim succeeded
     * (`reserve_result=6`).
     */
    public function testAnItemPaidByHandIsNeitherOfferedNorClaimed(): void
    {
        $item = $this->completedSale(403, 500000, 450000);
        $other = $this->completedSale(404, 200000, 180000);

        $result = $this->service->record(self::SUPPLIER, [$item], '450000', '2026-10-16', 'TRK-M-3');
        self::assertSame(RecordSupplierPayment::RECORDED, $result->code);

        $balance = $this->balance->of(self::SUPPLIER);
        self::assertSame([$other], $balance['eligible_line_ids'], 'the paid line is not on offer');
        self::assertSame(180000, $balance['eligible']);
        self::assertSame(450000, $balance['paid']);
        self::assertSame(450000, $balance['paid_direct'], 'and which path paid it is answerable');

        // And the claim itself refuses, because a balance read is not a lock.
        self::assertSame(0, $this->withdrawals->reserve(self::SUPPLIER, [$item], 450000, 'IR1', 'x'));
        self::assertNull($this->claimOf($item), 'the line was not claimed by the losing request');
    }

    /**
     * WHAT THIS PROVES: a payment that is recorded and NOT in the ledger blocks
     * just as firmly as one that is.
     *
     * §2 asks for this in so many words — «پرداختی که ثبت شده ولی در دفترکل
     * نیست … نباید اجازهٔ پرداخت دوباره بدهد». The obligation was settled when
     * the money left; whether the marketplace could write a payout line about
     * it is a different question, and the owner's open one.
     */
    public function testARecordedButUnpostedPaymentStillBlocksTheWithdrawalCycle(): void
    {
        $item = $this->completedSale(405, 500000, 450000);

        // An amount that differs from the share: recorded, not posted.
        $result = $this->service->record(self::SUPPLIER, [$item], '400000', '2026-10-16', 'TRK-M-4');
        self::assertSame(RecordSupplierPayment::RECORDED_UNPOSTED, $result->code);
        self::assertSame(T::POSTING_NEEDS_DEFINITION, $this->payments->find((int) $result->context['payment_id'])?->posting);

        self::assertSame(0, $this->withdrawals->reserve(self::SUPPLIER, [$item], 450000, 'IR1', 'x'));
        self::assertSame(0, $this->balance->of(self::SUPPLIER)['eligible']);
    }

    /**
     * WHAT THIS PROVES: the guard is in the WRITE, not in the read before it.
     *
     * A TIMED INTERLEAVING, and named as one (`alpha.43`): the withdrawal's
     * claim is made in the gap between the service's own pre-read and the
     * statement that inserts the payment's items, in one process on one
     * connection. It is not a race — `tests/Support/alpha61-probe.php
     * pay-concurrent` measures that with two processes — and it answers the
     * question this one is for: if the claim lands after the check, does the
     * write still refuse?
     */
    public function testAClaimThatLandsAfterTheCheckIsStillRefusedByTheStatement(): void
    {
        $item = $this->completedSale(406, 500000, 450000);
        $claimed = 0;

        $interfering = new InterferingDatabase($this->db);
        $interfering->before(
            ['INSERT IGNORE INTO', 'tmc_supplier_payment_items'],
            1,
            function () use ($item, &$claimed): void {
                // Somebody else's withdrawal takes the line. Written through
                // the same connection on purpose: what is being measured is
                // «the row says claimed when the INSERT runs», not isolation.
                $claimed = $this->withdrawals->reserve(self::SUPPLIER, [$item], 450000, 'IR2', 'y');
            }
        );

        $result = $this->serviceOn($interfering)
            ->record(self::SUPPLIER, [$item], '450000', '2026-10-16', 'TRK-M-5');

        self::assertNotSame([], $interfering->fired, 'the interleaving really happened');
        self::assertGreaterThan(0, $claimed, 'and the withdrawal really claimed the line');
        self::assertFalse($result->ok, $result->code);
        self::assertSame(RecordSupplierPayment::ITEM_HELD_BY_WITHDRAWAL, $result->code);
        self::assertSame($item, $result->context['order_item_id']);
        self::assertNull($this->payments->forOrderItem($item), 'and no half-written payment survived');
    }

    // ------------------------------------------------- §3, the unknown share

    /**
     * WHAT THIS PROVES: an item whose share was never recorded does not count
     * as a share of nought, and a payment covering it is not declared settled.
     *
     * Measured on `alpha.60`: two items, the second with `vendor_share_minor`
     * NULL, and a payment equal to the FIRST item's share alone —
     * `db_posting=posted`, `ledger_lines=2`. The unknown item hid inside the
     * arithmetic of the other one.
     */
    public function testAnUnknownShareIsNotCountedAsZero(): void
    {
        $known = $this->completedSale(411, 1000000, 900000);
        $unknown = $this->saleWithNoRecordedShare(412, 700000);

        $result = $this->service->record(
            self::SUPPLIER,
            [$known, $unknown],
            '900000',                  // exactly the known item's share
            '2026-10-16',
            'TRK-M-6'
        );

        self::assertTrue($result->ok, $result->code);
        self::assertSame(RecordSupplierPayment::RECORDED_SHARE_UNKNOWN, $result->code);
        self::assertSame([$unknown], $result->context['unknown_share_items'], 'named, not counted');

        $payment = $this->payments->find((int) $result->context['payment_id']);
        self::assertNotNull($payment);
        self::assertSame(U::POSTING_SHARE_UNKNOWN, $payment->posting);
        self::assertFalse($payment->shareIsKnown());
        self::assertFalse($payment->agreesWithShare(), 'equal numbers are not a match when one is missing');
        self::assertSame([$unknown], $payment->unknownShareItemIds, 'and it is on the record, not re-derived');
        self::assertSame('', $payment->ledgerEvent);
        self::assertSame(
            [],
            $this->ledger->forEvent(RecordSupplierPayment::eventKey($payment->id)),
            'nothing is posted about a comparison that could not be made'
        );

        // The paperwork is still there — the payment happened, after all — and
        // the screen that lists what is waiting on a person can find it.
        self::assertSame(
            [$payment->id],
            array_map(static fn ($p): int => $p->id, $this->payments->awaitingDefinition(10))
        );
    }

    /**
     * WHAT THIS PROVES: a share of zero that WAS recorded is a number, and
     * still adds nothing.
     *
     * The other side of the same rule, and the reason it cannot be written as
     * «falsy means unknown»: a line whose commission took the whole amount has
     * a share of nought on the books, and a payment that matches the rest of
     * the items is a match.
     */
    public function testARecordedZeroShareIsKnownAndAddsNothing(): void
    {
        $paid = $this->completedSale(413, 1000000, 900000);
        $zero = $this->completedSale(414, 500000, 0);

        $result = $this->service->record(self::SUPPLIER, [$paid, $zero], '900000', '2026-10-16', 'TRK-M-7');

        self::assertSame(RecordSupplierPayment::RECORDED, $result->code);
        $payment = $this->payments->find((int) $result->context['payment_id']);
        self::assertNotNull($payment);
        self::assertTrue($payment->shareIsKnown());
        self::assertSame([], $payment->unknownShareItemIds);
        self::assertSame(T::POSTING_POSTED, $payment->posting);
    }

    /**
     * WHAT THIS PROVES: the per-item shares are SNAPSHOTTED, each on its own
     * row, with `NULL` where the share was not known.
     *
     * `alpha.60` wrote the literal `0` on every item row, so the record could
     * not say which item it could not compute — and re-deriving it later would
     * answer about today's rows rather than about the payment.
     */
    public function testEachItemCarriesTheShareItContributedOrNull(): void
    {
        $known = $this->completedSale(415, 1000000, 900000);
        $unknown = $this->saleWithNoRecordedShare(416, 700000);

        $result = $this->service->record(self::SUPPLIER, [$known, $unknown], '900000', '2026-10-16', 'TRK-M-8');
        $paymentId = (int) $result->context['payment_id'];

        $rows = $this->db->getResults(
            'SELECT order_item_id, share_minor FROM `'
            . T::table($this->db, T::PAYMENT_ITEMS) . '` WHERE payment_id = %d ORDER BY order_item_id',
            [$paymentId]
        );
        $stored = [];
        foreach ($rows as $row) {
            $stored[(int) $row['order_item_id']] = $row['share_minor'] === null ? null : (int) $row['share_minor'];
        }
        self::assertSame([$known => 900000, $unknown => null], $stored);
    }

    // ------------------------------------------------------------- helpers

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
            // The SAME gateway the repository holds (`alpha.56`).
            $gateway
        );
    }

    /** The withdrawal claim on one line, read from the row. */
    private function claimOf(int $itemId): ?int
    {
        $row = $this->db->getRow(
            'SELECT withdrawal_id FROM `'
            . \Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders::table(
                $this->db,
                \Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders::ORDER_ITEMS
            ) . '` WHERE id = %d',
            [$itemId]
        );
        $value = $row['withdrawal_id'] ?? null;
        return $value === null ? null : (int) $value;
    }

    /** A sale on the books, with a share, that a manager has called complete. */
    private function completedSale(int $orderItemId, int $baseMinor, int $shareMinor): int
    {
        $id = $this->sale($orderItemId, $baseMinor, $shareMinor);
        self::assertTrue($this->orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', self::MANAGER));
        return $id;
    }

    /**
     * A sale whose money never reached the ledger — the state `alpha.60`
     * counted as nought.
     *
     * Real rather than contrived: `incompleteCaptures()` exists because this
     * happens (an unreadable money unit, a refused ledger write, a commission
     * bigger than the line). The LEDGER event is still written, so the money
     * unit of the supplier is readable; what is missing is this line's own
     * figures, which is exactly the shape the repair path looks for.
     */
    private function saleWithNoRecordedShare(int $orderItemId, int $baseMinor): int
    {
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
        $base = Money::of($baseMinor);
        $commission = Money::of($baseMinor - $shareMinor);
        $share = Money::of($shareMinor);
        self::assertTrue($this->ledger->record(
            (new LedgerTransaction('order:' . $orderItemId, self::SUPPLIER, 'order:' . $orderItemId, (string) $orderItemId))
                ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                ->add(LedgerAccount::Commission, $commission->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
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
