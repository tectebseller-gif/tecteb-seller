<?php
/**
 * The state verbs the `alpha.66 → alpha.67` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so everything this round
 * adds is reached through `class_exists()` / `array_key_exists()` and a verb
 * about a column the older build does not have answers with the column's own
 * absence — which is itself the measurement, not a failure.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha66-state.php <verb> [args]
 *
 * Verbs: arrange · digest · columns · unmigrate-31 · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    return;
}
// BOTH HALVES, and `DisposableOnlyToolsTest` is what says so: a guard with
// only the database half would run against a real site somebody had pointed
// at a copy of the disposable database (`alpha.22`).
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    return;
}

const TMC_U67_VENDOR = 9671;
const TMC_U67_ORDER = 96710;
const TMC_U67_ITEM = 96711;

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

$items = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
$products = $db->prefix() . M0005CreateProductTables::PRODUCTS;
$returns = $db->prefix() . 'tmc_returns';
$withdrawals = $db->prefix() . 'tmc_withdrawals';
$withdrawalLines = $db->prefix() . 'tmc_withdrawal_lines';

/** Whether a column is there — three answers, because a failed read is not «no». */
$col = static function (string $table, string $column) use ($db): string {
    $answer = $db->getVar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [$table, $column]
    );
    return $answer === null ? '__unknown__' : ((int) $answer > 0 ? 'yes' : 'no');
};
$lineId = static fn (): int => (int) ($orderItems->findByOrderItem(TMC_U67_ITEM)?->id ?? 0);

// =========================================================================
if ($verb === 'arrange') {
    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_U67_VENDOR, 'فروشگاه ارتقای ۶۷', true, false);
    // A LINE IN THE OLD SHAPE: ۴۵۰٬۰۰۰ تومان of goods at ten per cent, all
    // five money columns in the vendor's own unit and NO unit on the row,
    // which is exactly what every release up to `alpha.66` wrote.
    $event = 'order:' . TMC_U67_ITEM;
    if (!$ledger->hasEvent($event)) {
        $ledger->record(
            (new LedgerTransaction($event, TMC_U67_VENDOR, (string) TMC_U67_ORDER, (string) TMC_U67_ITEM))
                ->add(LedgerAccount::CentralPayment, Money::of(450000, 'IRT', 0), 'order_captured')
                ->add(LedgerAccount::Commission, Money::of(-45000, 'IRT', 0), 'commission')
                ->add(LedgerAccount::VendorEarning, Money::of(-405000, 'IRT', 0), 'vendor_share')
        );
    }
    if ($lineId() === 0) {
        $orderItems->record(new VendorOrderItem(
            id: 0,
            orderId: TMC_U67_ORDER,
            orderItemId: TMC_U67_ITEM,
            productId: 0,
            vendorUserId: TMC_U67_VENDOR,
            title: 'قلم ارتقای ۶۷',
            sku: 'TMCU67-1',
            quantity: 1,
            unitPriceMinor: 450000,
            baseMinor: 450000,
            taxMinor: 0,
            commissionMinor: 45000,
            vendorShareMinor: 405000,
            rateBasisPoints: 1000,
            rateSource: 'general',
            ledgerEvent: $event,
            status: OrderItemStatus::Placed
        ));
    }
    $say('line', $lineId());
    $say('vendor', TMC_U67_VENDOR);
    return;
}

// =========================================================================
if ($verb === 'columns') {
    // THE SHAPE, ASKED OF THE DATABASE — never inferred from the version.
    $say('schema_recorded', (int) get_option('tmc_schema_version', 0));
    $say('products_mirrored_status', $col($products, 'mirrored_status'));
    foreach ([$items, $withdrawals, $withdrawalLines, $returns] as $table) {
        $short = substr($table, strlen($db->prefix()));
        $say($short . '_currency', $col($table, 'tmc_currency'));
        $say($short . '_exponent', $col($table, 'tmc_exponent'));
    }
    return;
}

// =========================================================================
if ($verb === 'digest') {
    // ONE DIGEST over what the OLD build recorded, so «nothing moved» is a
    // single comparison rather than a list somebody reads. The unit columns
    // are deliberately NOT in it: on the base they do not exist, and after
    // the upgrade they are NULL for this row — «this row does not say» — so
    // including them would make the digest differ for the one reason the
    // round is about. They are measured separately, by `columns` and by the
    // `legacy_*` keys below.
    $line = $orderItems->find($lineId());
    $rows = $db->getResults(
        'SELECT account, amount_minor, currency, exponent, event_key
           FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '`
          WHERE vendor_user_id = %d ORDER BY id',
        [TMC_U67_VENDOR]
    );
    $fields = [
        'line' => $lineId(),
        'base' => $line?->baseMinor,
        'tax' => $line?->taxMinor,
        'commission' => $line?->commissionMinor,
        'share' => $line?->vendorShareMinor,
        'rate_bp' => $line?->rateBasisPoints,
        'rate_source' => $line?->rateSource,
        'event' => $line?->ledgerEvent,
        'status' => $line?->status->value,
        'withdrawal' => $line?->withdrawalId,
    ];
    $say('ledger_rows', count($rows));
    $say('identity', hash('sha256', json_encode([$rows, $fields], JSON_UNESCAPED_UNICODE)));
    // What the row SAYS about its own unit. `__absent__` on the base (no
    // column) and `__none__` after the upgrade (a column that says nothing)
    // are DIFFERENT answers and are printed differently on purpose.
    $unit = $db->getRow(
        'SELECT * FROM `' . $items . '` WHERE wc_order_item_id = %d',
        [TMC_U67_ITEM]
    );
    $say('legacy_currency', $unit === null || !array_key_exists('tmc_currency', $unit)
        ? '__absent__'
        : ($unit['tmc_currency'] === null ? '__none__' : (string) $unit['tmc_currency']));
    $say('legacy_exponent', $unit === null || !array_key_exists('tmc_exponent', $unit)
        ? '__absent__'
        : ($unit['tmc_exponent'] === null ? '__none__' : (int) $unit['tmc_exponent']));
    // AND WHAT THE BALANCE MAKES OF IT. A line with no recorded unit is at
    // the vendor's single money unit, which is the ONE reading that does not
    // reinterpret it — and the number has to be the same on both trees.
    $balance = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorBalance::class)
        ->of(TMC_U67_VENDOR);
    $say('balance_earned', (string) ($balance['earned'] ?? '__no_key__'));
    $say('balance_exponent', array_key_exists('exponent', $balance)
        ? (int) $balance['exponent']
        : '__no_key__');
    return;
}

// =========================================================================
if ($verb === 'unmigrate-31') {
    // Puts the tables back into the shape `alpha.66` left them in, so «a site
    // genuinely on the base» can be BUILT rather than assumed. Without it the
    // forward step would be measuring the creation of columns on a site that
    // never had the older shape — which is an upgrade of nothing.
    $dropped = [];
    foreach ([[$products, 'mirrored_status']] as [$table, $column]) {
        if ($col($table, $column) === 'yes') {
            $db->execute('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
            $dropped[] = $column;
        }
    }
    foreach ([$items, $withdrawals, $withdrawalLines, $returns] as $table) {
        foreach (['tmc_currency', 'tmc_exponent'] as $column) {
            if ($col($table, $column) === 'yes') {
                $db->execute('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
                $dropped[] = substr($table, strlen($db->prefix())) . '.' . $column;
            }
        }
    }
    update_option('tmc_schema_version', 31);
    $say('unmigrated', $dropped);
    $say('schema_recorded', (int) get_option('tmc_schema_version', 0));
    return;
}

// =========================================================================
if ($verb === 'reset') {
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_item_id = %d', [TMC_U67_ITEM]);
    $db->execute(
        'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
        . '` WHERE vendor_user_id = %d',
        [TMC_U67_VENDOR]
    );
    $db->execute(
        'DELETE FROM `' . $db->prefix() . 'tmc_vendor_money_unit` WHERE vendor_user_id = %d',
        [TMC_U67_VENDOR]
    );
    $say('reset', 'done');
    return;
}

$say('unknown_verb', $verb);
