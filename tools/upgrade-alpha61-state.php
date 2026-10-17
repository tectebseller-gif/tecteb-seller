<?php
/**
 * The state verbs the `alpha.61 → alpha.62` upgrade check needs.
 *
 * It must LOAD AND RUN on both trees (`alpha.32`), so everything this round
 * adds is reached through `class_exists()` / `method_exists()` and a verb
 * about a capability the older build lacks answers `absent=1` — which is
 * itself the measurement.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha61-state.php <verb> [args]
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
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
// BOTH HALVES, and `DisposableOnlyToolsTest` is what says so: a guard with
// only the database half would run against a real site that somebody had
// pointed at a copy of the disposable database («گاردی که روی نیمی از
// مجموعه اعمال شود، از شل با نبودِ گارد یکی است» — `alpha.22`).
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_U62_VENDOR = 9631;
const TMC_U62_ORDER = 96310;
const TMC_U62_ITEM = 96311;

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
// NOT every base HAS supplier payments. `alpha.56` is schema 28 and the two
// tables — and these two services — arrive with step 29, so asking the
// container for them there is a fatal rather than a measurement. Asked for
// conditionally, and the verbs that need them answer `absent=1`, which is the
// `alpha.32` rule: a probe about a capability the tree does not have must
// LOAD and say so.
$hasPayments = interface_exists(
    \Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::class
) && class_exists(M0029SupplierPayments::class);
$payments = $hasPayments
    ? $c->get(\Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface::class)
    : null;
$service = $hasPayments && class_exists(RecordSupplierPayment::class)
    ? $c->get(RecordSupplierPayment::class)
    : null;

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        $value = $value === [] ? '__empty__' : implode('|', array_map('strval', $value));
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};

$lineId = static fn (): int => (int) ($orderItems->findByOrderItem(TMC_U62_ITEM)?->id ?? 0);
$col = static function (string $table, string $column) use ($db): string {
    $answer = $db->getVar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [$table, $column]
    );
    return $answer === null ? '__unknown__' : ((int) $answer > 0 ? 'yes' : 'no');
};
/** A single-column unique key over `$column`, whatever it is called. */
$uniqueOver = static function (string $table, string $column) use ($db): string {
    $answer = $db->getVar(
        'SELECT COUNT(*) FROM information_schema.STATISTICS s
         WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = %s
           AND s.NON_UNIQUE = 0 AND s.COLUMN_NAME = %s AND s.SEQ_IN_INDEX = 1
           AND (SELECT COUNT(*) FROM information_schema.STATISTICS t
                 WHERE t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME
                   AND t.INDEX_NAME = s.INDEX_NAME) = 1',
        [$table, $column]
    );
    return $answer === null ? '__unknown__' : ((int) $answer > 0 ? 'yes' : 'no');
};

$pay = $hasPayments ? M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS) : '';
$payItems = $hasPayments ? M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS) : '';

if ($verb === 'arrange') {
    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_U62_VENDOR, 'تأمین‌کنندهٔ ارتقا', true, false);
    $event = 'order:' . TMC_U62_ITEM;
    if (!$ledger->hasEvent($event)) {
        $ledger->record(
            (new LedgerTransaction($event, TMC_U62_VENDOR, (string) TMC_U62_ORDER, (string) TMC_U62_ITEM))
                ->add(LedgerAccount::CentralPayment, Money::of(1000000), 'item_paid')
                ->add(LedgerAccount::Commission, Money::of(100000)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, Money::of(900000)->negate(), 'vendor_earned')
        );
    }
    $id = $lineId();
    if ($id === 0) {
        $id = $orderItems->record(new VendorOrderItem(
            0,
            TMC_U62_ORDER,
            TMC_U62_ITEM,
            0,
            TMC_U62_VENDOR,
            'قلم ارتقا',
            'U62-' . TMC_U62_ITEM,
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
    }
    $orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', $adminId);
    $say('line', $id);
    $say('share', $orderItems->find($id)?->vendorShareMinor ?? '__null__');
    return;
}

if ($verb === 'pay') {
    if ($service === null) {
        $say('absent', 1);
        return;
    }
    $result = $service->record(
        TMC_U62_VENDOR,
        [$lineId()],
        (string) ($args[1] ?? '900000'),
        '2026-10-17',
        (string) ($args[2] ?? 'TRK-U62'),
        '',
        '',
        (string) ($args[3] ?? '')
    );
    $say('pay_code', $result->code);
    $say('payment_id', $result->context['payment_id'] ?? '__none__');
    $say('balance_after', $result->context['balance_minor'] ?? '__null__');
    return;
}

if ($verb === 'agree') {
    $class = \Tecteb\Marketplace\Modules\Finance\Application\ManageSupplierObligations::class;
    if (!class_exists($class)) {
        $say('absent', 1);
        return;
    }
    $result = $c->get($class)->agree($lineId(), (string) ($args[1] ?? '900000'), 'توافق ارتقا');
    $say('agree_code', $result->code);
    $say('agreed_minor', $result->context['amount_minor'] ?? '__null__');
    return;
}

if ($verb === 'void') {
    if ($service === null || !method_exists($service, 'void')) {
        $say('absent', 1);
        return;
    }
    $result = $service->void((int) ($args[1] ?? 0), (string) ($args[2] ?? 'دلیل ارتقا'));
    $say('void_code', $result->code);
    $payment = $payments->find((int) ($args[1] ?? 0));
    $say('is_void', $payment !== null && method_exists($payment, 'isVoid') && $payment->isVoid());
    return;
}

if ($verb === 'tables') {
    if (!$hasPayments) {
        $say('absent', 1);
        return;
    }
    $say('payments_table', (int) ($db->getVar(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        [$pay]
    ) ?? 0));
    $say('voided_at', $col($pay, 'voided_at'));
    $say('void_reason', $col($pay, 'void_reason'));
    $say('share_minor_final', $col($pay, 'share_minor_final'));
    $say('reconciled_at', $col($pay, 'reconciled_at'));
    $say('live_item_id', $col($payItems, 'live_item_id'));
    $say('unique_live_item_id', $uniqueOver($payItems, 'live_item_id'));
    $say('unique_order_item_id', $uniqueOver($payItems, 'order_item_id'));
    foreach (['tmc_supplier_obligations', 'tmc_supplier_obligation_history',
        'tmc_supplier_payment_allocations', 'tmc_supplier_receivables',
        'tmc_supplier_receivable_settlements'] as $suffix) {
        $say($suffix, (int) ($db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$db->prefix() . $suffix]
        ) ?? 0));
    }
    // AND THE EARLIER STEPS STILL VERIFY, which is the trap `alpha.51` and
    // `alpha.54` both paid for: a `verify()` that insists on yesterday's
    // shape freezes the chain, and the NEXT step is what pays.
    $say('m0029_verifies', (new M0029SupplierPayments())->verify($db));
    if (class_exists(\Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0030SupplierPaymentShareUnknown::class)) {
        $say('m0030_verifies', (new \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0030SupplierPaymentShareUnknown())->verify($db));
    }
    if (class_exists(\Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations::class)) {
        $say('m0031_verifies', (new \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations())->verify($db));
    }
    return;
}

if ($verb === 'digest') {
    if (!$hasPayments) {
        $say('absent', 1);
        return;
    }
    // ONE DIGEST over what the OLD build recorded, so «nothing moved» is a
    // single comparison rather than a list somebody reads.
    $rows = $db->getResults(
        'SELECT id, supplier_user_id, amount_minor, currency, exponent, share_minor,
                paid_on, reference, posting, ledger_event
           FROM `' . $pay . '` WHERE supplier_user_id = %d ORDER BY id',
        [TMC_U62_VENDOR]
    );
    $items = $db->getResults(
        'SELECT payment_id, order_item_id, supplier_user_id, share_minor
           FROM `' . $payItems . '` WHERE supplier_user_id = %d ORDER BY payment_id, order_item_id',
        [TMC_U62_VENDOR]
    );
    $line = $orderItems->find($lineId());
    $fields = [
        'line' => $lineId(),
        'share' => $line?->vendorShareMinor,
        'commission' => $line?->commissionMinor,
        'withdrawal' => $line?->withdrawalId,
        'status' => $line?->status->value,
    ];
    $say('payment_rows', count($rows));
    $say('item_rows', count($items));
    $say('identity', hash('sha256', json_encode([$rows, $items, $fields], JSON_UNESCAPED_UNICODE)));
    $say('ledger_rows', (int) ($db->getVar(
        'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
        . '` WHERE vendor_user_id = %d',
        [TMC_U62_VENDOR]
    ) ?? 0));
    return;
}

if ($verb === 'unmigrate-31') {
    if (!$hasPayments) {
        // Nothing of this round's shape can be here: the tables it extends
        // do not exist on this base at all.
        $say('absent', 1);
        return;
    }
    // Puts the tables back into the shape `alpha.61` left them in, so a
    // «site genuinely on the base» can be built rather than assumed. Without
    // this, the forward step would be measuring the CREATION of a table on a
    // site that never had the older shape at all.
    $dropped = [];
    foreach (['tmc_supplier_receivable_settlements', 'tmc_supplier_receivables',
        'tmc_supplier_payment_allocations', 'tmc_supplier_obligation_history',
        'tmc_supplier_obligations'] as $suffix) {
        $dropped[] = $suffix . ':' . (string) $db->execute('DROP TABLE IF EXISTS `' . $db->prefix() . $suffix . '`');
    }
    foreach (['voided_at', 'voided_by', 'void_reason', 'void_event',
        'share_minor_final', 'reconciled_at', 'reconciled_by'] as $column) {
        if ($col($pay, $column) === 'yes') {
            $db->execute('ALTER TABLE `' . $pay . '` DROP COLUMN `' . $column . '`');
        }
    }
    // The claim key goes back onto `order_item_id` alone, which is what
    // `alpha.61` had — and the live rows are what it had, too.
    if ($col($payItems, 'live_item_id') === 'yes') {
        $db->execute('DELETE FROM `' . $payItems . '` WHERE live_item_id IS NULL');
        $db->execute('ALTER TABLE `' . $payItems . '` DROP INDEX `tmc_supplier_payment_live`');
        $db->execute('ALTER TABLE `' . $payItems . '` DROP COLUMN `live_item_id`');
    }
    if ($uniqueOver($payItems, 'order_item_id') === 'no') {
        $db->execute('ALTER TABLE `' . $payItems . '` DROP INDEX `tmc_supplier_payment_item_of`');
        $db->execute('ALTER TABLE `' . $payItems . '` ADD UNIQUE KEY `tmc_supplier_payment_item` (`order_item_id`)');
    }
    update_option('tmc_schema_version', 30);
    $say('unmigrated', $dropped);
    $say('schema_recorded', (int) get_option('tmc_schema_version', 0));
    $say('voided_at', $col($pay, 'voided_at'));
    $say('live_item_id', $col($payItems, 'live_item_id'));
    $say('unique_order_item_id', $uniqueOver($payItems, 'order_item_id'));
    return;
}

if ($verb === 'reset') {
    $removed = [];
    if (!$hasPayments) {
        $db->execute(
            'DELETE FROM `' . M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS)
            . '` WHERE vendor_user_id = %d',
            [TMC_U62_VENDOR]
        );
        $say('reset', 'order_items_only');
        return;
    }
    $V = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0031SupplierObligations::class;
    if (class_exists($V)) {
        foreach ([$V::ALLOCATIONS, $V::RECEIVABLES, $V::OBLIGATIONS] as $suffix) {
            if ((int) ($db->getVar(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                [$V::table($db, $suffix)]
            ) ?? 0) > 0) {
                $removed[] = $suffix . ':' . (string) $db->execute(
                    'DELETE FROM `' . $V::table($db, $suffix) . '` WHERE supplier_user_id = %d',
                    [TMC_U62_VENDOR]
                );
            }
        }
    }
    foreach ([$payItems, $pay] as $table) {
        $removed[] = $table . ':' . (string) $db->execute(
            'DELETE FROM `' . $table . '` WHERE supplier_user_id = %d',
            [TMC_U62_VENDOR]
        );
    }
    $db->execute(
        'DELETE FROM `' . M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS)
        . '` WHERE vendor_user_id = %d',
        [TMC_U62_VENDOR]
    );
    $db->execute(
        'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
        . '` WHERE vendor_user_id = %d',
        [TMC_U62_VENDOR]
    );
    $say('reset', $removed);
    return;
}

$say('schema_target', \Tecteb\Marketplace\Core\Migration\SchemaVersion::TARGET);
$say('schema_recorded', (int) get_option('tmc_schema_version', 0));
$say('plugin_version', defined('TMC_PLUGIN_VERSION') ? TMC_PLUGIN_VERSION : '__none__');
