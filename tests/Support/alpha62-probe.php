<?php
/**
 * `alpha.62`: the owner's seven decisions, measured on the disposable site.
 *
 * **Its own file, named for its round** — `alpha.54`'s rule, guarded by
 * `EvidenceToolsAreNotOverwrittenTest`. Everything this round adds is reached
 * through `class_exists()` / `method_exists()` so the file LOADS on the
 * `alpha.61` tree as well (`alpha.32`): a verb about a capability that build
 * does not have answers `absent=1`, and «absent» is itself a measurement.
 *
 * What it measures, verb by verb:
 *
 *   §1  `cart-*`     a fixed commission bigger than the line, in the CLASSIC
 *                    cart and through the Store API, before any payment —
 *                    and a control that an ordinary rule still sells.
 *   §3  `agree`,`pay`  the agreed amount, two partial payments, the balance,
 *                    an over-payment refused, a repeat writing once, and a
 *                    REAL two-process race on one balance.
 *   §5  `void-*`     the void, its reversal, the two injected failures, and
 *                    the repeat that makes no second effect.
 *   §6  `reconcile`  the same payment reviewed once its share is knowable.
 *   §2  `debt-*`     record, collect, offset, and the balance that refuses a
 *                    second collection of one amount.
 *   §7  `withdraw`   the closed request path, through the real container, on
 *                    a site whose trial switch is ON.
 *   §4  `shop-*`     the picker: its capability, its audit row, no shop
 *                    preselected, and what the page does not print.
 *
 * Its fixture is its OWN (`A62-*`, vendor 9621) and `reset` gives back
 * everything it spends (`alpha.33`). Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tests/Support/alpha62-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\WpTectebCommissionSource;
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

const TMC_P62_VENDOR = 9621;
const TMC_P62_ORDER = 96210;
const TMC_P62_ITEM_A = 96211;   // a sale with a recorded share
const TMC_P62_ITEM_B = 96212;   // a sale with NO recorded share
const TMC_P62_ITEM_C = 96213;   // a second sale with a share
const TMC_P62_SKU_CART = 'WC-A62-CART';

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
$clock = new SystemClock();
$orderItems = new DbOrderItemRepository($db, $clock);
$ledger = new DbLedgerRepository($db, $clock);

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = $value === [] ? '__empty__' : implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};
$say('probe', 'ran');

// The failure-injection gateway lives in `tests/Support/` and is NOT in the
// plugin's autoloader (it is dev-only, which is the point). Required by path
// so the two `void-fail-*` verbs can inject a real statement failure on the
// real connection.
if (!class_exists(\Tecteb\Marketplace\Tests\Support\FailingDatabase::class, false)) {
    $failingPath = __DIR__ . '/FailingDatabase.php';
    if (is_file($failingPath)) {
        require_once $failingPath;
    }
}

$payments = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::class);
$service = $c->get(RecordSupplierPayment::class);
$agreements = class_exists(\Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations::class)
    ? $c->get(\Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations::class)
    : null;
$debts = class_exists(\Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables::class)
    ? $c->get(\Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierReceivables::class)
    : null;

/** The line id of one of this fixture's WooCommerce order items. */
$lineOf = static function (int $wcItem) use ($orderItems): int {
    return (int) ($orderItems->findByOrderItem($wcItem)?->id ?? 0);
};

$ledgerCount = static function () use ($db): int {
    return (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables::table(
            $db,
            \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables::LEDGER
        ) . '`'
    ) ?? 0);
};

/**
 * The WooCommerce id of this fixture's cart product — from the MARKETPLACE
 * ROW, not from the SKU.
 *
 * Measured the hard way: an earlier version of `cart-arrange` made a plain
 * WooCommerce product with this SKU, and once the verb was corrected to build
 * a real marketplace product there were two products carrying it. The SKU
 * lookup then returned the plain one, the guard said `not_ours` — correctly —
 * and the measurement read as «the guard did not fire». `alpha.33`'s rule, in
 * a new place: ask for the thing by the identity the code under test uses.
 */
$cartProduct = static function () use ($c): int {
    $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
    foreach ($products->forVendor(TMC_P62_VENDOR, null, 50, 0) as $row) {
        if ($row->details->sku === TMC_P62_SKU_CART && (int) $row->wcProductId > 0) {
            return (int) $row->wcProductId;
        }
    }
    $id = (int) wc_get_product_id_by_sku(TMC_P62_SKU_CART);
    return $id > 0 ? $id : 0;
};

// =============================================================== fixtures ==

if ($verb === 'arrange') {
    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_P62_VENDOR, 'تأمین‌کنندهٔ ۶۲', true, false);
    $lines = [];
    foreach ([
        [TMC_P62_ITEM_A, 1000000, 100000, 900000],
        [TMC_P62_ITEM_B, 700000, 70000, null],
        [TMC_P62_ITEM_C, 600000, 60000, 540000],
    ] as [$wcItem, $base, $commission, $share]) {
        $event = 'order:' . $wcItem;
        if (!$ledger->hasEvent($event)) {
            // The event is written even for the share-less line, so the
            // supplier's MONEY UNIT is readable: what is missing on that line
            // is its own figures, which is the real shape the repair path
            // looks for (`alpha.61`).
            $ledger->record(
                (new LedgerTransaction($event, TMC_P62_VENDOR, (string) TMC_P62_ORDER, (string) $wcItem))
                    ->add(LedgerAccount::CentralPayment, Money::of($base), 'item_paid')
                    ->add(LedgerAccount::Commission, Money::of($commission)->negate(), 'commission_due')
                    ->add(LedgerAccount::VendorEarning, Money::of($base - $commission)->negate(), 'vendor_earned')
            );
        }
        $id = $lineOf($wcItem);
        if ($id === 0) {
            $id = $orderItems->record(new VendorOrderItem(
                0,
                TMC_P62_ORDER,
                $wcItem,
                0,
                TMC_P62_VENDOR,
                'قلم ' . $wcItem,
                'A62-' . $wcItem,
                1,
                $base,
                $base,
                0,
                $share === null ? null : $commission,
                $share,
                $share === null ? null : 1000,
                $share === null ? '' : 'product',
                $share === null ? '' : $event,
                OrderItemStatus::Delivered
            ));
        }
        $orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', $adminId);
        $lines[$wcItem] = $id;
    }
    $say('item_a', $lines[TMC_P62_ITEM_A]);
    $say('item_b', $lines[TMC_P62_ITEM_B]);
    $say('item_c', $lines[TMC_P62_ITEM_C]);
    $say('share_a', $orderItems->find($lines[TMC_P62_ITEM_A])?->vendorShareMinor ?? '__null__');
    $say('share_b', $orderItems->find($lines[TMC_P62_ITEM_B])?->vendorShareMinor ?? '__null__');
    // A REAL IBAN on the shop's record, so the no-leak check below is about
    // a value that EXISTS. `alpha.23`'s rule: a page that does not print an
    // empty field proves nothing about privacy.
    $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class)->saveBank(
        TMC_P62_VENDOR,
        'IR620000000000000000009621',
        'صاحب حساب ۶۲',
        0,
        'approved',
        false
    );
    $say('bank_iban_seeded', (string) ($c->get(
        \Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class
    )->bank(TMC_P62_VENDOR)['iban'] ?? ''));
    $say('obligations_exist', class_exists(
        \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations::class
    ));
    $say('schema_target', \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET);
    return;
}

// ========================================================= §3 the balance ==

if ($verb === 'agree') {
    if ($agreements === null) {
        $say('absent', 1);
        return;
    }
    $line = $lineOf((int) ($args[1] ?? TMC_P62_ITEM_A));
    $result = $agreements->agree($line, (string) ($args[2] ?? '900000'), 'توافق آزمایشی');
    $say('agree_code', $result->code);
    $obligation = $agreements->forOrderItem($line);
    $say('agreed_minor', $obligation?->agreed?->minor ?? '__null__');
    $say('paid_minor', $obligation?->paidMinor ?? '__null__');
    $say('balance_minor', $obligation?->balanceMinor() ?? '__null__');
    $say('history_rows', count($agreements->historyFor((int) ($obligation?->id ?? 0))));
    return;
}

if ($verb === 'pay') {
    $line = $lineOf((int) ($args[1] ?? TMC_P62_ITEM_A));
    $result = $service->record(
        TMC_P62_VENDOR,
        [$line],
        (string) ($args[2] ?? '500000'),
        '2026-10-17',
        (string) ($args[3] ?? ('TRK-' . bin2hex(random_bytes(3)))),
        '',
        '',
        (string) ($args[4] ?? '')
    );
    $say('pay_code', $result->code);
    $say('payment_id', $result->context['payment_id'] ?? '__none__');
    $say('balance_after', $result->context['balance_minor'] ?? '__null__');
    $say('db_payments', count($payments->forSupplier(TMC_P62_VENDOR, 50)));
    if ($agreements !== null) {
        $obligation = $agreements->forOrderItem($line);
        $say('db_paid_minor', $obligation?->paidMinor ?? '__null__');
        $say('db_balance_minor', $obligation?->balanceMinor() ?? '__null__');
    }
    // THE CLAIM, which is the thing the owner told us not to drop. ⛔ From
    // `alpha.63` the number to read is `live_claims`, not `claim_rows`: a
    // second partial payment now writes its own row with a NULL live key, so
    // that voiding the FIRST payment can hand the claim on instead of
    // freeing an item the second one paid for. One LIVE claim per item is
    // the invariant; the row count is however many payments stand against
    // it. `alpha.62` wrote one row and that was the defect.
    $say('claim_rows', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
        . '` WHERE order_item_id = %d',
        [$line]
    ) ?? 0));
    $say('live_claims', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
        . '` WHERE live_item_id = %d',
        [$line]
    ) ?? 0));
    return;
}

if ($verb === 'race') {
    // A REAL two-process race, not a timed interleaving: the rival runs in
    // its own `wp` process on its own connection, so what is measured is
    // which of two committed transactions wins (`alpha.38`).
    $line = $lineOf((int) ($args[1] ?? TMC_P62_ITEM_A));
    $repo = getenv('TMC_REPO') ?: '';
    $wpPath = getenv('TMC_WP_PATH') ?: '';
    $wpCli = getenv('TMC_WPCLI') ?: '';
    if ($repo === '' || $wpPath === '' || $wpCli === '') {
        $say('race', 'needs_TMC_REPO_TMC_WP_PATH_TMC_WPCLI');
        return;
    }
    $fifo = sys_get_temp_dir() . '/tmc-a62-' . bin2hex(random_bytes(4));
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($wpCli)
        . ' --allow-root --path=' . escapeshellarg($wpPath)
        . ' eval-file ' . escapeshellarg($repo . '/tests/Support/concurrent-supplier-allocation.php')
        . ' ' . escapeshellarg((string) $line) . ' ' . escapeshellarg((string) ($args[2] ?? '400000'))
        . ' ' . escapeshellarg($fifo) . ' > ' . escapeshellarg($fifo . '.out') . ' 2>&1 &';
    shell_exec($cmd);
    // Wait for the rival to say it has COMMITTED, so the order is known
    // rather than hoped for.
    $waited = 0;
    while (!file_exists($fifo . '.done') && $waited < 200) {
        usleep(100000);
        $waited++;
    }
    $say('rival_committed', file_exists($fifo . '.done'));
    $say('rival_output', trim((string) @file_get_contents($fifo . '.out')));
    $mine = $service->record(
        TMC_P62_VENDOR,
        [$line],
        (string) ($args[2] ?? '400000'),
        '2026-10-18',
        'TRK-RACE-MINE'
    );
    $say('my_code', $mine->code);
    $say('my_balance', $mine->context['balance_minor'] ?? '__null__');
    if ($agreements !== null) {
        $obligation = $agreements->forOrderItem($line);
        $say('db_paid_minor', $obligation?->paidMinor ?? '__null__');
        $say('db_balance_minor', $obligation?->balanceMinor() ?? '__null__');
    }
    @unlink($fifo . '.done');
    @unlink($fifo . '.out');
    return;
}

// ============================================================ §5 the void ==

if ($verb === 'void' || $verb === 'void-repeat') {
    if (!method_exists($service, 'void')) {
        $say('absent', 1);
        return;
    }
    $paymentId = (int) ($args[1] ?? 0);
    $before = $ledgerCount();
    $result = $service->void($paymentId, (string) ($args[2] ?? 'دلیل آزمایشی'));
    $say('void_code', $result->code);
    $payment = $payments->find($paymentId);
    $say('row_exists', $payment !== null);
    $say('is_void', $payment !== null && $payment->isVoid());
    $say('void_reason', $payment?->voidReason ?? '__none__');
    $say('void_event', $payment?->voidEvent ?? '__none__');
    $say('ledger_rows_before', $before);
    $say('ledger_rows_after', $ledgerCount());
    $say('live_claims', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
        . '` WHERE payment_id = %d AND live_item_id IS NOT NULL',
        [$paymentId]
    ) ?? 0));
    $say('claim_rows', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
        . '` WHERE payment_id = %d',
        [$paymentId]
    ) ?? 0));
    return;
}

if ($verb === 'void-fail-ledger' || $verb === 'void-fail-doc') {
    if (!method_exists($service, 'void')) {
        $say('absent', 1);
        return;
    }
    $paymentId = (int) ($args[1] ?? 0);
    $before = $ledgerCount();
    // AN INJECTED FAILURE OF A COLLABORATOR, not a spontaneous one. The
    // target statement is made to fail on the real connection, and the
    // question is whether anything is left half done.
    $failing = new \Tecteb\Marketplace\Tests\Support\FailingDatabase($db);
    $failing->failWhen($verb === 'void-fail-ledger'
        ? ['INSERT INTO', 'tmc_ledger_entries']
        : ['UPDATE', 'tmc_supplier_payments', 'voided_at']);
    $result = (new ReflectionClass(RecordSupplierPayment::class))->newInstance(
        new \Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierPaymentRepository($failing, $clock),
        new DbOrderItemRepository($failing, $clock),
        new DbLedgerRepository($failing, $clock),
        new \Tecteb\Marketplace\Modules\Finance\Infrastructure\DbVendorMoneyUnitRegistry($failing, $clock),
        $c->get(CapabilityCheckerInterface::class),
        $c->get(\Tecteb\Marketplace\Core\Audit\AuditLogger::class),
        $clock,
        $failing,
        new \Tecteb\Marketplace\Modules\Finance\Infrastructure\DbSupplierObligationRepository($failing, $clock)
    )->void($paymentId, 'دلیل آزمایشی');
    $say('void_code', $result->code);
    $payment = $payments->find($paymentId);
    $say('is_void', $payment !== null && $payment->isVoid());
    $say('ledger_rows_before', $before);
    $say('ledger_rows_after', $ledgerCount());
    $say('live_claims', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
        . '` WHERE payment_id = %d AND live_item_id IS NOT NULL',
        [$paymentId]
    ) ?? 0));
    return;
}

// ===================================================== §6 the reconcile ====

if ($verb === 'fill-share') {
    $line = $lineOf((int) ($args[1] ?? TMC_P62_ITEM_B));
    $say('filled', $orderItems->completeFinancials($line, 70000, 630000, 1000, 'product', 'order:' . TMC_P62_ITEM_B));
    $say('share_now', $orderItems->find($line)?->vendorShareMinor ?? '__null__');
    return;
}

if ($verb === 'reconcile') {
    if (!method_exists($service, 'reconcile')) {
        $say('absent', 1);
        return;
    }
    $paymentId = (int) ($args[1] ?? 0);
    $countBefore = count($payments->forSupplier(TMC_P62_VENDOR, 50));
    $result = $service->reconcile($paymentId);
    $say('reconcile_code', $result->code);
    $say('share_at_the_time', $result->context['share_minor'] ?? '__null__');
    $say('share_final', $result->context['share_minor_final'] ?? '__null__');
    $say('unknown_items', $result->context['unknown_share_items'] ?? '__empty__');
    $payment = $payments->find($paymentId);
    $say('db_share_minor', $payment?->share->minor ?? '__null__');
    $say('db_share_final', $payment?->shareMinorFinal ?? '__null__');
    $say('db_posting', $payment?->posting ?? '__none__');
    $say('payments_before', $countBefore);
    $say('payments_after', count($payments->forSupplier(TMC_P62_VENDOR, 50)));
    return;
}

// ===================================================== §2 the receivable ===

if ($verb === 'debt') {
    if ($debts === null) {
        $say('absent', 1);
        return;
    }
    $line = $lineOf((int) ($args[1] ?? TMC_P62_ITEM_A));
    $result = $debts->record($line, (string) ($args[2] ?? '300000'), (int) ($args[3] ?? 62), 1, 'مرجوعی آزمایشی');
    $say('debt_code', $result->code);
    $say('receivable_id', $result->context['receivable_id'] ?? '__none__');
    return;
}

if ($verb === 'collect' || $verb === 'offset') {
    if ($debts === null) {
        $say('absent', 1);
        return;
    }
    $id = (int) ($args[1] ?? 0);
    $amount = (string) ($args[2] ?? '100000');
    $token = (string) ($args[3] ?? ('t-' . bin2hex(random_bytes(3))));
    $result = $verb === 'collect'
        ? $debts->collect($id, $amount, 'TRK-BACK', '', $token)
        : $debts->offset($id, $amount, 0, '', $token);
    $say('settle_code', $result->code);
    $say('balance_minor', $result->context['balance_minor'] ?? '__null__');
    $say('settlements', count($debts->settlementsOf($id)));
    $kinds = array_column($debts->settlementsOf($id), 'kind');
    sort($kinds);
    $say('kinds', $kinds);
    return;
}

// ===================================================== §7 the withdrawal ===

if ($verb === 'withdraw-seed') {
    // A request made on a build BEFORE the closure — which is what the
    // owner's «درخواست‌های قبلی» are. Written through the repository rather
    // than through the service, because the service is what refuses now, and
    // the point is to have a row the closure must leave alone.
    $withdrawals = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface::class);
    $balance = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)->of(TMC_P62_VENDOR);
    $id = $withdrawals->reserve(
        TMC_P62_VENDOR,
        $balance['eligible_line_ids'],
        (int) $balance['eligible'],
        'IR000000000000000000000001',
        'صاحب حساب آزمایشی',
        (string) ($balance['eligible_until'] ?? '')
    );
    $say('seeded_withdrawal', $id);
    $say('eligible_lines', $balance['eligible_line_ids']);
    return;
}

if ($verb === 'withdraw') {
    // THE TRIAL SWITCH IS TURNED ON FIRST, on purpose: the owner's closure is
    // not an open decision and a trial site may not waive it. With the gate
    // open, anything this prints is about the closure itself.
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $trial->request(true);
    $gate = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SettlementGate::class)->check();
    $say('settlement_gate_ready', (bool) $gate['ready']);
    $request = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\RequestWithdrawal::class);
    $result = $request->handle(TMC_P62_VENDOR, TMC_P62_VENDOR);
    $say('request_code', $result->code);
    $withdrawals = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface::class);
    $say('open_request', $withdrawals->openFor(TMC_P62_VENDOR)?->id ?? '__none__');
    $say('history_rows', count($withdrawals->forVendor(TMC_P62_VENDOR, 20)));
    // …and the FIGURES are still readable, which is the other half of §7.
    $balance = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)->of(TMC_P62_VENDOR);
    $say('eligible_minor', $balance['eligible']);
    $say('paid_minor', $balance['paid']);
    $say('policy_open', method_exists(
        \Tecteb\Marketplace\Modules\Finance\Application\VendorWithdrawalPolicy::class,
        'requestsAreOpen'
    )
        ? $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorWithdrawalPolicy::class)->requestsAreOpen()
        : '__absent__');
    $trial->request(false);
    return;
}

// ======================================================= §4 the picker =====

if ($verb === 'shop-picker') {
    $page = \Tecteb\Marketplace\Modules\Vendor\Presentation\Admin\VendorShopsPage::class;
    if (!class_exists($page)) {
        $say('absent', 1);
        return;
    }
    $say('capability', $page::CAPABILITY);
    $say('url', $page::url());
    $say('url_has_shop', str_contains($page::url(), 'shop='));
    // RENDERED, with the capability, and with no shop in the address.
    $_GET = [];
    $_REQUEST = [];
    ob_start();
    (new $page($c))->render();
    $list = (string) ob_get_clean();
    $say('list_renders', $list !== '');
    $say('list_mentions_readonly', str_contains($list, 'فقط برای مشاهده'));
    $say('list_has_links', substr_count($list, 'shop=') > 0);
    // …and one shop, which must write an audit row and leak nothing.
    // The table name comes from its MIGRATION class rather than from memory
    // (`alpha.10`): the first run of this verb asked for `tmc_audit_log`,
    // which does not exist, and reported `audit_rows_after=0` about a page
    // that was writing its row correctly.
    $auditTable = \Tecteb\Marketplace\Core\Migration\Migrations\M0001CreateAuditTable::tableName($db);
    $auditBefore = (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $auditTable . '` WHERE event_type = %s',
        ['vendor.shop_viewed']
    ) ?? 0);
    $_GET = ['shop' => (string) TMC_P62_VENDOR];
    $_REQUEST = $_GET;
    ob_start();
    (new $page($c))->render();
    $shop = (string) ob_get_clean();
    $_GET = [];
    $_REQUEST = [];
    $say('shop_renders', $shop !== '');
    $say('audit_rows_before', $auditBefore);
    $say('audit_rows_after', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $auditTable . '` WHERE event_type = %s',
        ['vendor.shop_viewed']
    ) ?? 0));
    // WHAT IT MUST NOT PRINT. A permission check alone does not satisfy
    // «بدون نشت اطلاعات»: a page that shows a manager a shop's buyers has
    // leaked them whatever the capability said.
    $bank = $c->get(\Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface::class)
        ->bank(TMC_P62_VENDOR);
    $iban = trim((string) ($bank['iban'] ?? ''));
    $say('iban_on_record', $iban !== '');
    $say('prints_iban', $iban !== '' && str_contains($shop, $iban));
    $say('prints_form', str_contains($shop, '<form method="post"'));
    $say('prints_nonce', str_contains($shop, 'tmc_vendor_nonce'));
    $say('identity_unchanged', (int) get_current_user_id() === $adminId);
    return;
}

// =========================================================== §1 the cart ===

if ($verb === 'cart-reset') {
    // Gives back the WooCommerce products AND the marketplace rows, so the
    // next `cart-arrange` starts from nothing rather than from whatever an
    // earlier run of a different shape left behind.
    $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
    $removed = [];
    foreach ($products->forVendor(TMC_P62_VENDOR, null, 100, 0) as $row) {
        if ($row->details->sku !== TMC_P62_SKU_CART) {
            continue;
        }
        if ((int) $row->wcProductId > 0) {
            wp_delete_post((int) $row->wcProductId, true);
            $removed[] = 'wc:' . $row->wcProductId;
        }
        $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE id = %d', [$row->id]);
        $removed[] = 'row:' . $row->id;
    }
    // …and any stray WooCommerce product left holding the SKU.
    $stray = (int) wc_get_product_id_by_sku(TMC_P62_SKU_CART);
    if ($stray > 0) {
        wp_delete_post($stray, true);
        $removed[] = 'stray:' . $stray;
    }
    $say('cart_reset', $removed);
    return;
}

if ($verb === 'cart-arrange') {
    // A REAL MARKETPLACE PRODUCT, published through the plugin's own
    // projector — which is what makes it «ours» and therefore the only kind
    // of product the guard says anything about at all. The first run of this
    // verb made a plain WooCommerce product and measured `marketplace_row=0`:
    // a true sentence about a product the guard correctly ignores.
    $products = $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class);
    $catalog = $c->get(\Tecteb\Marketplace\Modules\Product\Application\SyncCatalog::class);
    $named = [
        'title' => 'کالای سبد ۶۲',
        'type' => 'simple',
        'categoryKey' => (string) ($args[1] ?? '1'),
        'brand' => '',
        'shortDescription' => 'یک جمله',
        'priceMinor' => 100000,
        'sku' => TMC_P62_SKU_CART,
        'stock' => 50,
        'minPurchase' => 1,
        'maxPurchase' => null,
        'description' => null,
    ];
    if (property_exists(\Tecteb\Marketplace\Modules\Product\Domain\ProductDetails::class, 'purchaseStep')) {
        $named['purchaseStep'] = 1;
    }
    $details = new \Tecteb\Marketplace\Modules\Product\Domain\ProductDetails(...$named);
    $existingWc = $cartProduct();
    $rowId = (int) ($existingWc > 0 ? ($products->findByWcProduct($existingWc)?->id ?? 0) : 0);
    if ($rowId === 0) {
        $rowId = $products->create(TMC_P62_VENDOR, $details, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Draft);
    } else {
        $products->updateDetails($rowId, $details, $products->find($rowId)?->rowVersion ?? 0);
    }
    $row = $products->find($rowId);
    if ($row !== null && $row->status !== \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published) {
        $products->updateStatus($rowId, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published, $row->rowVersion);
    }
    $published = $catalog->publish($rowId);
    $wcId = (int) ($products->find($rowId)?->wcProductId ?? 0);
    $say('marketplace_row', $rowId);
    $say('publish_code', is_object($published) && property_exists($published, 'code') ? (string) $published->code : (is_array($published) ? (string) ($published['code'] ?? '') : '__unknown__'));
    $say('product', $wcId);
    $say('is_ours', $wcId > 0 && $c->get(
        \Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class
    )->isOurs($wcId));
    $say('commission_type', (string) get_post_meta($wcId, WpTectebCommissionSource::TYPE_META, true));
    $say('commission_value', (string) get_post_meta($wcId, WpTectebCommissionSource::VALUE_META, true));
    return;
}

if ($verb === 'cart-rule') {
    $wcId = $cartProduct();
    update_post_meta($wcId, WpTectebCommissionSource::TYPE_META, (string) ($args[1] ?? 'fixed'));
    update_post_meta($wcId, WpTectebCommissionSource::VALUE_META, (string) ($args[2] ?? '150000'));
    wp_cache_delete($wcId, 'post_meta');
    $say('row_type', (string) get_post_meta($wcId, WpTectebCommissionSource::TYPE_META, true));
    $say('row_value', (string) get_post_meta($wcId, WpTectebCommissionSource::VALUE_META, true));
    return;
}

if ($verb === 'cart-discount') {
    // THE OWNER'S ACTUAL SCENARIO: «کمیسیون ثابت پس از تخفیف از مبلغ قلم
    // بیشتر شود». The rule is UNDER the full price and OVER the discounted
    // one, so what is measured is the discount turning a sellable line into
    // a refused one — on a real WooCommerce coupon, not on an argument.
    $check = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck::class);
    $wcId = $cartProduct();
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $trial->request(true);
    $c->get(\Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface::class)->setRate(
        \Tecteb\Marketplace\Modules\Finance\Domain\RateScope::General,
        'general',
        \Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate::ofBasisPoints(1000)
    );
    update_post_meta($wcId, WpTectebCommissionSource::TYPE_META, 'fixed');
    update_post_meta($wcId, WpTectebCommissionSource::VALUE_META, (string) ($args[1] ?? '90000'));
    wp_cache_delete($wcId, 'post_meta');

    $code = 'a62-half';
    $existing = wc_get_coupon_id_by_code($code);
    if ($existing <= 0) {
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(50);
        $coupon->save();
    }

    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($wcId, 1);
    WC()->cart->calculate_totals();
    $full = (string) (WC()->cart->get_cart()[array_key_first(WC()->cart->get_cart())]['line_total'] ?? '');
    wc_clear_notices();
    do_action('woocommerce_check_cart_items');
    $say('full_price_line_total', $full);
    $say('full_price_errors', count(wc_get_notices('error')));

    wc_clear_notices();
    WC()->cart->apply_coupon($code);
    WC()->cart->calculate_totals();
    $discounted = (string) (WC()->cart->get_cart()[array_key_first(WC()->cart->get_cart())]['line_total'] ?? '');
    wc_clear_notices();
    do_action('woocommerce_check_cart_items');
    $errors = wc_get_notices('error');
    $say('discounted_line_total', $discounted);
    $say('discounted_errors', count($errors));
    $say('discounted_message', $errors === [] ? '__none__' : trim(wp_strip_all_tags((string) ($errors[0]['notice'] ?? ''))));

    WC()->cart->remove_coupon($code);
    WC()->cart->empty_cart();
    wc_clear_notices();
    $trial->request(false);
    return;
}

if ($verb === 'cart-classic' || $verb === 'cart-store-api') {
    $check = class_exists(\Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck::class)
        ? $c->get(\Tecteb\Marketplace\Modules\Finance\Application\CommissionLineCheck::class)
        : null;
    if ($check === null) {
        $say('absent', 1);
        return;
    }
    $wcId = $cartProduct();
    $quantity = max(1, (int) ($args[1] ?? 1));
    $trial = $c->get(\Tecteb\Marketplace\Modules\Order\Application\TrialUnlock::class);
    $trial->request(true);
    $c->get(\Tecteb\Marketplace\Modules\Finance\Application\CommissionRuleRepositoryInterface::class)->setRate(
        \Tecteb\Marketplace\Modules\Finance\Domain\RateScope::General,
        'general',
        \Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate::ofBasisPoints(1000)
    );
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class)->check();
    $say('sale_gate_ready', (bool) $gate['ready']);
    $say('sale_gate_reason', (string) ($gate['reason'] ?? ''));
    $say('trial_environment', $trial->environmentName());
    $say('trial_permitted', $trial->isPermitted());
    // WHICH refusal the catalogue gives, if any. A closed sale gate removes
    // the item from the basket before this round's guard ever sees it, so a
    // measurement that did not print this would report «no error» about a
    // guard that was never reached — the shape `alpha.53` paid for.
    $policy = $c->get(\Tecteb\Marketplace\Modules\Product\Application\PurchasePolicy::class);
    $say('catalogue_decision', (string) $policy->decide($wcId)['decision']);

    wc_load_cart();
    WC()->cart->empty_cart();
    $added = WC()->cart->add_to_cart($wcId, $quantity);
    $say('added_to_cart', $added !== false);
    WC()->cart->calculate_totals();
    $say('cart_line_total', (string) (WC()->cart->get_cart()[array_key_first(WC()->cart->get_cart())]['line_total'] ?? ''));

    // THE VALIDATION BOTH BASKETS RUN. The Store API calls this same action
    // (`CartController::validate_cart()`), converts the notices it raises
    // into `WP_Error`s and answers 409 — which is why one callback is what
    // makes the two paths identical rather than two callbacks kept in step.
    wc_clear_notices();
    if ($verb === 'cart-classic') {
        do_action('woocommerce_check_cart_items');
        $errors = wc_get_notices('error');
        $say('path', 'classic');
        $say('cart_items_after', count(WC()->cart->get_cart()));
        $say('errors', count($errors));
        $say('message', $errors === [] ? '__none__' : trim(wp_strip_all_tags((string) ($errors[0]['notice'] ?? ''))));
    } else {
        $controller = new \Automattic\WooCommerce\StoreApi\Utilities\CartController();
        $code = '__none__';
        $message = '__none__';
        try {
            $controller->validate_cart();
        } catch (\Throwable $e) {
            $code = $e instanceof \Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException
                ? 'woocommerce_cart_error'
                : $e::class;
            $errors = $e instanceof \Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException
                ? $e->getError()->get_error_messages()
                : [$e->getMessage()];
            $message = trim(wp_strip_all_tags((string) ($errors[0] ?? '')));
        }
        $say('path', 'store_api');
        $say('code', $code);
        $say('message', $message);
    }
    $say('names_rate', str_contains($message ?? '', '150000') || str_contains($message ?? '', 'کمیسیون'));
    WC()->cart->empty_cart();
    wc_clear_notices();
    $trial->request(false);
    return;
}

// ================================================================ report ===

if ($verb === 'report') {
    $say('schema_target', \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET);
    $say('schema_recorded', (int) get_option('tmc_schema_version', 0));
    $say('payments', count($payments->forSupplier(TMC_P62_VENDOR, 50)));
    if ($agreements !== null) {
        $say('obligations', count($agreements->forSupplier(TMC_P62_VENDOR, 50)));
    }
    if ($debts !== null) {
        $say('open_debts', count($debts->openFor(TMC_P62_VENDOR, 50)));
    }
    $say('line_a', $lineOf(TMC_P62_ITEM_A));
    $say('line_b', $lineOf(TMC_P62_ITEM_B));
    $say('line_c', $lineOf(TMC_P62_ITEM_C));
    return;
}

// ================================================================= reset ===

if ($verb === 'reset') {
    // GIVES BACK EVERYTHING IT SPENT (`alpha.33`), and the table names come
    // from their migration classes rather than from memory (`alpha.10`).
    $removed = [];
    $V = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations::class;
    if (class_exists($V)) {
        // CHILDREN FIRST, and each by a column it actually has: the history
        // and the settlement tables carry no supplier id, so they are cleared
        // through their parent rather than with a condition that matches
        // everything. A `DELETE` whose `WHERE` is «or true» is not a reset of
        // this fixture, it is a reset of the site.
        $obligations = $V::table($db, $V::OBLIGATIONS);
        $receivables = $V::table($db, $V::RECEIVABLES);
        $removed[] = 'history:' . (string) $db->execute(
            'DELETE h FROM `' . $V::table($db, $V::OBLIGATION_HISTORY) . '` h
              INNER JOIN `' . $obligations . '` o ON o.id = h.obligation_id
              WHERE o.supplier_user_id = %d',
            [TMC_P62_VENDOR]
        );
        $removed[] = 'settlements:' . (string) $db->execute(
            'DELETE s FROM `' . $V::table($db, $V::RECEIVABLE_SETTLEMENTS) . '` s
              INNER JOIN `' . $receivables . '` r ON r.id = s.receivable_id
              WHERE r.supplier_user_id = %d',
            [TMC_P62_VENDOR]
        );
        foreach ([$V::ALLOCATIONS, $V::RECEIVABLES, $V::OBLIGATIONS] as $suffix) {
            $table = $V::table($db, $suffix);
            $removed[] = $suffix . ':' . (string) $db->execute(
                'DELETE FROM `' . $table . '` WHERE supplier_user_id = %d',
                [TMC_P62_VENDOR]
            );
        }
    }
    foreach ([M0029SupplierPayments::PAYMENT_ITEMS, M0029SupplierPayments::PAYMENTS] as $suffix) {
        $table = M0029SupplierPayments::table($db, $suffix);
        $removed[] = $suffix . ':' . (string) $db->execute(
            'DELETE FROM `' . $table . '` WHERE supplier_user_id = %d',
            [TMC_P62_VENDOR]
        );
    }
    // THE WITHDRAWALS THIS FIXTURE SPENDS, given back too (`alpha.33`).
    //
    // Measured the hard way: without this, `withdraw-seed` from an earlier run
    // left request #18 open, and the §7 verb then reported «a request already
    // exists» and a smaller eligible balance — three true sentences about the
    // previous run, in the clothing of this one's result.
    $W = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables::class;
    $removed[] = 'withdrawal_lines:' . (string) $db->execute(
        'DELETE FROM `' . $W::table($db, $W::WITHDRAWAL_LINES) . '` WHERE vendor_user_id = %d',
        [TMC_P62_VENDOR]
    );
    $removed[] = 'withdrawals:' . (string) $db->execute(
        'DELETE FROM `' . $W::table($db, $W::WITHDRAWALS) . '` WHERE vendor_user_id = %d',
        [TMC_P62_VENDOR]
    );
    $itemsTable = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
    $db->execute('DELETE FROM `' . $itemsTable . '` WHERE vendor_user_id = %d', [TMC_P62_VENDOR]);
    $db->execute(
        'DELETE FROM `' . \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables::table(
            $db,
            \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables::LEDGER
        ) . '` WHERE vendor_user_id = %d',
        [TMC_P62_VENDOR]
    );
    $say('reset', implode('|', $removed));
    $say('lines_left', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $itemsTable . '` WHERE vendor_user_id = %d',
        [TMC_P62_VENDOR]
    ) ?? 0));
    return;
}

$say('unknown_verb', $verb);
