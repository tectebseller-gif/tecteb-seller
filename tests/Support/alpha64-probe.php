<?php
/**
 * `alpha.64`: the read-before-lock in `RecordSupplierPayment::void()`.
 *
 * **Its own file, named for its round** (`alpha.54`, guarded by
 * `EvidenceToolsAreNotOverwrittenTest`). Everything this round adds is
 * reached through `class_exists()` / `method_exists()`, so the file LOADS on
 * the `alpha.64` tree as well as on a fixed one (`alpha.32`) — which is the
 * point: `void-after-reconcile` is meant to answer DIFFERENTLY.
 *
 * ### What the finding says
 *
 * `void()` reads the payment at line 664 and opens the transaction at line
 * 680, and line 684 is
 *
 *     $compensating = $payment->ledgerEvent !== '' ? self::voidEventKey($id) : '';
 *
 * so the decision «is a compensating entry owed» comes from a snapshot taken
 * before any lock. A payment whose share was unknown carries no money line,
 * and `reconcile()` is the documented path that gives it one. Both verbs are
 * POST actions on the SAME admin screen
 * (`SupplierPaymentsPage::handle()`, `'void'` and `'reconcile'`), so two
 * concurrent admin requests are the ordinary shape of this, not a contrived
 * one.
 *
 * ### The fixture
 *
 * A delivered, settlement-completed line whose share IS recorded — 900,000
 * with its own ledger event, so `reconcile()`'s `isRecorded()` bar is met —
 * plus an obligation with NO agreed amount (`agree($line, '')`, which is
 * «نامعلوم» and not nought). `record()` then answers
 * `supplier_payment_recorded_agreed_unknown` and writes NO money line, which
 * is exactly the state the finding needs.
 *
 * ### The interleaving
 *
 * Scheduled, not raced for, and not on this connection: a `TransactionInterface`
 * decorator around the SAME gateway object (`alpha.56` — nesting is counted
 * in the object) runs `tests/Support/concurrent-supplier-reconcile.php` in a
 * separate process on the outermost `begin()` and waits for it to exit. That
 * is the gap between the read and the guarded `UPDATE`, every run.
 *
 * Prints ONE key per line (`alpha.41`), and `reset` gives back what it
 * spends (`alpha.33`).
 *
 *   wp eval-file tests/Support/alpha64-probe.php <verb> [args]
 *     arrange · void-after-reconcile · reconcile-then-void · void-repeat
 *     · void-reversal-fails · report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbWithdrawalRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

// --- refuses to run anywhere but a disposable install ---------------------
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_P64_VENDOR = 9641;
const TMC_P64_ORDER = 96410;
const TMC_P64_ITEM = 96411;

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'report');
$c = Bootstrap::container();
$adminId = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
wp_set_current_user($adminId);
$c->bind(CapabilityCheckerInterface::class, static fn () => new class implements CapabilityCheckerInterface {
    public function can(string $capability): bool
    {
        return in_array($capability, Capabilities::all(), true);
    }

    public function currentUserId(): ?int
    {
        return (int) get_current_user_id();
    }
});

global $wpdb;
$db = new WpDatabase($wpdb);
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . ($value === '' || $value === null ? '__none__' : (string) $value) . "\n";
};
$paymentsTable = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS);
$itemsTable = M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS);
$orderItems = $db->prefix() . M0006CatalogAndOrders::ORDER_ITEMS;
$obligationsClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\ManageSupplierObligations';

/** The marketplace order line of this round's fixture, or nought. */
$lineOf = static fn (): int => (int) ($db->getVar(
    'SELECT id FROM `' . $orderItems . '` WHERE wc_order_item_id = %d',
    [TMC_P64_ITEM]
) ?? 0);

/** The one payment of this fixture that is not void, or nought. */
$openPayment = static fn (): int => (int) ($db->getVar(
    'SELECT id FROM `' . $paymentsTable . '`
      WHERE supplier_user_id = %d AND voided_at IS NULL ORDER BY id ASC LIMIT 1',
    [TMC_P64_VENDOR]
) ?? 0);

/**
 * THE FIXTURE: a recorded payment with no money line, by the documented path.
 *
 * Built through the repositories and services rather than with SQL, so the
 * columns are whatever the migrations say they are (`alpha.10`), and the
 * posting state is whatever `record()` really decides.
 */
$arrange = static function () use (
    $c, $db, $say, $obligationsClass, $lineOf, $adminId, $paymentsTable
): array {
    if (!class_exists($obligationsClass)) {
        $say('absent', 1);
        return [];
    }
    (new DbVendorRepository($db, new SystemClock()))
        ->upsertProfile(TMC_P64_VENDOR, 'تأمین‌کنندهٔ ۶۴', true, false);
    $lines = new DbOrderItemRepository($db, new SystemClock());
    $event = 'a64:' . TMC_P64_ITEM;
    if (!$c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class)
            ->hasEvent($event)) {
        (new DbLedgerRepository($db, new SystemClock()))->record(
            (new LedgerTransaction($event, TMC_P64_VENDOR, $event, (string) TMC_P64_ITEM))
                ->add(LedgerAccount::CentralPayment, Money::of(1000000), 'item_paid')
                ->add(LedgerAccount::Commission, Money::of(100000)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, Money::of(900000)->negate(), 'vendor_earned')
        );
    }
    $itemId = $lineOf();
    if ($itemId <= 0) {
        $itemId = (int) $lines->record(new VendorOrderItem(
            0,
            TMC_P64_ORDER,
            TMC_P64_ITEM,
            0,
            TMC_P64_VENDOR,
            'کالای آزمایشی ۶۴',
            'SKU-A64',
            1,
            1000000,
            1000000,
            0,
            100000,
            900000,
            1000,
            'product',
            $event,
            OrderItemStatus::Delivered
        ));
        if ($itemId > 0) {
            $lines->recordSettlementCompletion($itemId, '2026-10-16 00:00:00', $adminId);
        }
    }
    $say('line', $itemId);
    if ($itemId <= 0) {
        return [];
    }
    // «نامعلوم», not nought: an obligation the manager has declared and whose
    // amount is not written. This is what makes `record()` answer
    // `agreed_unknown` and write no money line.
    $agreed = $c->get($obligationsClass)->agree($itemId, '', 'مبلغ توافق هنوز نوشته نشده');
    $say('agree', (string) $agreed->code);

    $recorded = $c->get(RecordSupplierPayment::class)->record(
        TMC_P64_VENDOR,
        [$itemId],
        '900000',
        '2026-10-17',
        'A64-' . bin2hex(random_bytes(4))
    );
    $say('record', (string) $recorded->code);
    $paymentId = (int) ($recorded->context['payment_id'] ?? 0);
    $say('payment_id', $paymentId);
    if ($paymentId <= 0) {
        return [];
    }
    $row = (new DbSupplierPaymentRepository($db, new SystemClock()))->find($paymentId);
    $say('posting', (string) ($row?->posting ?? ''));
    $say('ledger_event_before', (string) ($row?->ledgerEvent ?? ''));
    $say('reconcilable', in_array((string) ($row?->posting ?? ''), [
        'share_unknown',
        'agreed_unknown',
        'needs_definition',
    ], true));
    return ['line' => $itemId, 'payment' => $paymentId];
};

/** Everything a reader needs to judge the outcome, read from the rows. */
$aftermath = static function (int $paymentId, int $itemId) use ($db, $say, $itemsTable): void {
    $payments = new DbSupplierPaymentRepository($db, new SystemClock());
    $ledger = new DbLedgerRepository($db, new SystemClock());
    $row = $payments->find($paymentId);
    $say('row_is_void', $row !== null && $row->isVoid() ? 'yes' : 'no');
    $say('row_ledger_event', (string) ($row?->ledgerEvent ?? ''));
    $say('row_void_event', (string) ($row?->voidEvent ?? ''));
    $payout = (string) ($row?->ledgerEvent ?? '');
    $say('payout_entry_exists', $payout !== '' && $ledger->hasEvent($payout));
    $reversal = RecordSupplierPayment::voidEventKey($paymentId);
    $say('reversal_entry_exists', $ledger->hasEvent($reversal));
    // THE NUMBER THAT MATTERS: with a payout entry standing and no reversal,
    // the supplier's books say the money left the marketplace for a document
    // that is marked void.
    // `balances()` returns account => total, not a list of rows. The first
    // version of this line read `$row['account']` out of a map and printed
    // nought on BOTH trees — a measurement that cannot fail is not a
    // measurement (`alpha.46`).
    $balances = $ledger->balances(TMC_P64_VENDOR);
    $say('vendor_earning_balance', (int) ($balances[LedgerAccount::VendorEarning->value] ?? 0));
    $say('live_claims', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $itemsTable . '` WHERE live_item_id = %d',
        [$itemId]
    ) ?? 0));
    $say('claim_rows', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $itemsTable . '` WHERE order_item_id = %d',
        [$itemId]
    ) ?? 0));
    $withdrawals = new DbWithdrawalRepository($db, new SystemClock());
    $reserved = (int) $withdrawals->reserve(
        TMC_P64_VENDOR,
        [$itemId],
        900000,
        'IR000000000000000000000000',
        'a64-' . $paymentId . '-' . bin2hex(random_bytes(3))
    );
    $say('withdrawal_reserved', $reserved === 0 ? 'refused' : ('TAKEN:' . $reserved));
};

// =================================================== §1 the interleaved void

if ($verb === 'void-after-reconcile' || $verb === 'reconcile-after-void') {
    // BOTH ORDERINGS, as the owner asked. The gap is the same gap in each
    // case — between the state the method read and the row it writes — and
    // the other request is the other button on the same screen.
    $mirror = $verb === 'reconcile-after-void';
    $f = $arrange();
    if ($f === []) {
        return;
    }
    $repo = (string) (getenv('TMC_REPO') ?: getcwd());
    $wpPath = (string) getenv('TMC_WP_PATH');
    $wpCli = (string) (getenv('TMC_WPCLI') ?: 'wp');
    if ($wpPath === '') {
        $say('absent', 'no_wp_path');
        return;
    }
    $child = $repo . '/tests/Support/concurrent-supplier-reconcile.php';
    if (!is_file($child)) {
        $say('absent', 'no_child');
        return;
    }
    $cmd = 'php ' . escapeshellarg($wpCli) . ' --allow-root --path=' . escapeshellarg($wpPath)
        . ' eval-file ' . escapeshellarg($child) . ' ' . $f['payment']
        . ' ' . ($mirror ? 'void' : 'reconcile') . ' 2>&1';

    // The decorator. It wraps the SAME gateway object the repository holds,
    // so the nesting counter stays where it belongs (`alpha.56`); all it adds
    // is «let the other request finish here». It fires on the outermost
    // `begin()`, which on both methods is the statement after the state they
    // decided from was read.
    $gate = new class ($db, $cmd) implements TransactionInterface {
        public string $childOutput = '';
        public bool $fired = false;

        public function __construct(
            private readonly TransactionInterface $inner,
            private readonly string $command
        ) {
        }

        public function begin(): bool
        {
            $ok = $this->inner->begin();
            if ($ok && !$this->fired) {
                $this->fired = true;
                // SYNCHRONOUS on purpose: no sleep, no clock. The child owns
                // its own connection and commits before this returns.
                $this->childOutput = (string) shell_exec($this->command);
            }
            return $ok;
        }

        public function commit(): bool
        {
            return $this->inner->commit();
        }

        public function rollback(): bool
        {
            return $this->inner->rollback();
        }

        public function inTransaction(): bool
        {
            return $this->inner->inTransaction();
        }
    };

    $service = new RecordSupplierPayment(
        $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::class),
        $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class),
        $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class),
        $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface::class),
        $c->get(CapabilityCheckerInterface::class),
        $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class),
        $c->get(\Tecteb\Marketplace\Contracts\ClockInterface::class),
        $gate,
        $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SupplierObligationRepositoryInterface::class)
    );
    $result = $mirror
        ? $service->reconcile($f['payment'])
        : $service->void($f['payment'], 'شمارهٔ حوالهٔ این سند اشتباه بود');

    // What the OTHER request did, verbatim, so that «the gap was really
    // used» is a measurement and not an assumption.
    foreach (explode("\n", trim($gate->childOutput)) as $childLine) {
        if (trim($childLine) === '' || !str_contains($childLine, '=')) {
            continue;
        }
        $say('child_' . (string) strstr($childLine, '=', true), substr((string) strstr($childLine, '='), 1));
    }
    $say('interleaved', $gate->fired);
    $say($mirror ? 'reconcile_code' : 'void_code', (string) $result->code);
    if ($mirror) {
        $say('reconcile_event', (string) ($result->context['ledger_event'] ?? ''));
    } else {
        $say('compensating_event', (string) ($result->context['compensating_event'] ?? ''));
        $say('items_released_reported', (int) ($result->context['items_released'] ?? 0));
    }
    $aftermath($f['payment'], $f['line']);
    return;
}

// ========================================== §2 the other order, and the rest

if ($verb === 'reconcile-then-void') {
    // THE CONTROL: the same two requests, one after the other, with nothing
    // in the gap. A build that is right about the interleaving must still be
    // right here — and this is the ordering that already worked.
    $f = $arrange();
    if ($f === []) {
        return;
    }
    $service = $c->get(RecordSupplierPayment::class);
    $reconciled = $service->reconcile($f['payment']);
    $say('reconcile_code', (string) $reconciled->code);
    $say('reconcile_event', (string) ($reconciled->context['ledger_event'] ?? ''));
    $result = $service->void($f['payment'], 'پس از تطبیق هم اشتباه بود');
    $say('void_code', (string) $result->code);
    $say('compensating_event', (string) ($result->context['compensating_event'] ?? ''));
    $aftermath($f['payment'], $f['line']);
    return;
}

if ($verb === 'void-repeat') {
    $f = $arrange();
    if ($f === []) {
        return;
    }
    $service = $c->get(RecordSupplierPayment::class);
    $say('first_void', (string) $service->void($f['payment'], 'اشتباه بود')->code);
    $second = $service->void($f['payment'], 'دوباره');
    $say('second_void', (string) $second->code);
    $say('second_compensating', (string) ($second->context['compensating_event'] ?? ''));
    $aftermath($f['payment'], $f['line']);
    return;
}

if ($verb === 'void-reversal-fails') {
    // The reversal write refused, with the payment ALREADY carrying a money
    // line — so the compensating entry is owed and cannot be written. The
    // void must not happen at all.
    $f = $arrange();
    if ($f === []) {
        return;
    }
    $service = $c->get(RecordSupplierPayment::class);
    $say('reconcile_code', (string) $service->reconcile($f['payment'])->code);
    $reversal = RecordSupplierPayment::voidEventKey($f['payment']);
    // The ledger refuses a duplicate event key, so writing the reversal's key
    // first is a refusal of the reversal itself — through the ledger's own
    // guard rather than through a stub.
    (new DbLedgerRepository($db, new SystemClock()))->record(
        (new LedgerTransaction($reversal, TMC_P64_VENDOR, 'squatter', (string) $f['payment']))
            ->add(LedgerAccount::CentralPayment, Money::of(1), 'squatter')
            ->add(LedgerAccount::VendorEarning, Money::of(1)->negate(), 'squatter')
    );
    $say('key_taken', (new DbLedgerRepository($db, new SystemClock()))->hasEvent($reversal));
    $result = $service->void($f['payment'], 'ابطال با شکست ثبت سند جبرانی');
    $say('void_code', (string) $result->code);
    $aftermath($f['payment'], $f['line']);
    return;
}

if ($verb === 'arrange') {
    $arrange();
    return;
}

if ($verb === 'report') {
    $line = $lineOf();
    $say('line', $line === 0 ? '__none__' : $line);
    $say('open_payment', $openPayment() ?: '__none__');
    $say('payments', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $paymentsTable . '` WHERE supplier_user_id = %d',
        [TMC_P64_VENDOR]
    ) ?? 0));
    return;
}

if ($verb === 'reset') {
    // GIVES BACK WHAT IT SPENT (`alpha.33`): the line, its obligation and
    // history, every payment of this supplier and their claim rows, the
    // withdrawal requests, and the ledger entries written under this
    // fixture's own event keys.
    $itemId = $lineOf();
    if ($itemId > 0) {
        $ids = array_map(
            static fn (array $r): int => (int) $r['id'],
            $db->getResults(
                'SELECT id FROM `' . $paymentsTable . '` WHERE supplier_user_id = %d',
                [TMC_P64_VENDOR]
            )
        );
        foreach ($ids as $id) {
            $db->execute(
                'DELETE FROM `' . $db->prefix() . 'tmc_supplier_payment_allocations` WHERE payment_id = %d',
                [$id]
            );
        }
        $db->execute('DELETE FROM `' . $itemsTable . '` WHERE order_item_id = %d', [$itemId]);
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'tmc_supplier_obligation_history`
              WHERE obligation_id IN (SELECT id FROM `' . $db->prefix() . 'tmc_supplier_obligations`
                                       WHERE order_item_id = %d)',
            [$itemId]
        );
        $db->execute(
            'DELETE FROM `' . $db->prefix() . 'tmc_supplier_obligations` WHERE order_item_id = %d',
            [$itemId]
        );
        $db->execute('DELETE FROM `' . $orderItems . '` WHERE id = %d', [$itemId]);
    }
    $db->execute('DELETE FROM `' . $paymentsTable . '` WHERE supplier_user_id = %d', [TMC_P64_VENDOR]);
    $db->execute(
        'DELETE FROM `' . $db->prefix() . 'tmc_withdrawals` WHERE vendor_user_id = %d',
        [TMC_P64_VENDOR]
    );
    // The ledger is append-only by design (FIN-03) and this is the one
    // place that may undo it: the entries this fixture wrote, named by its
    // own event keys, on a disposable database. Nothing else is touched.
    $ledgerTable = $db->prefix() . \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables::LEDGER;
    $db->execute(
        'DELETE FROM `' . $ledgerTable . '` WHERE vendor_user_id = %d',
        [TMC_P64_VENDOR]
    );
    $say('reset', 'done');
    return;
}

$say('usage', 'arrange|void-after-reconcile|reconcile-after-void|reconcile-then-void|void-repeat|void-reversal-fails|report|reset');
