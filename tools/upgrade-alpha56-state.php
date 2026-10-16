<?php
/**
 * The state a `0.1.0-alpha.56 → 0.1.0-alpha.57` upgrade must not damage, and
 * the two things the new build adds, written by whichever build is installed.
 *
 * This upgrade **is** structural: 28 → 29, two new tables
 * (`M0029SupplierPayments`). So there are two different claims to measure —
 * that what the old build recorded survives untouched, and that what the new
 * build adds actually works on a site that was upgraded rather than installed
 * fresh.
 *
 * **Everything here runs on BOTH builds** (`alpha.32`'s rule, which broke the
 * previous round's probe on the very bytes it existed to measure):
 *
 *  - the arrangement is pure WordPress, WooCommerce and two repositories that
 *    exist on both trees, called only through methods whose arity did not
 *    change;
 *  - every verb about **this round's** additions asks `class_exists()` or
 *    `method_exists()` first and prints `absent=1`, which is itself a
 *    measurement: on `alpha.56` there is no commission panel and no supplier
 *    payment, and the check script asserts exactly that.
 *
 * ONE key per line — the shell's reader takes the rest of the line as the
 * value (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha56-state.php arrange
 *   wp eval-file tools/upgrade-alpha56-state.php digest
 *   wp eval-file tools/upgrade-alpha56-state.php tables
 *   wp eval-file tools/upgrade-alpha56-state.php commission <type> <value>
 *   wp eval-file tools/upgrade-alpha56-state.php commission-read
 *   wp eval-file tools/upgrade-alpha56-state.php pay <amount> <reference> [token]
 *   wp eval-file tools/upgrade-alpha56-state.php payments
 *   wp eval-file tools/upgrade-alpha56-state.php reset
 */

use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Infrastructure\DbOrderItemRepository;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;

// --- refuses to run anywhere but a disposable install ---------------------
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not one of the disposable databases.\n");
    echo "refused=1\n";
    echo "reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local.\n");
    echo "refused=1\n";
    echo "reason=home_url_is_not_local\n";
    return;
}
if (!class_exists('WC_Product_Simple')) {
    echo "refused=1\n";
    echo "reason=woocommerce_absent\n";
    return;
}

const TMC_U56_SKU = 'WC-U56-UPGRADE';
const TMC_U56_VENDOR = 9566;
const TMC_U56_ORDER = 95660;
const TMC_U56_ITEM = 95661;

$verb = (string) ($args[0] ?? 'report');
$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$orderItems = new DbOrderItemRepository($db, $clock);
$ledger = new DbLedgerRepository($db, $clock);

/** The product this fixture owns, by SKU — found, never guessed. */
$findProduct = static function (): int {
    $id = (int) wc_get_product_id_by_sku(TMC_U56_SKU);
    return $id > 0 ? $id : 0;
};

if ($verb === 'arrange') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('کالای ارتقای ۵۷');
        $product->set_sku(TMC_U56_SKU);
        $product->set_regular_price('500000');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(12);
        $product->set_status('publish');
        $wcId = (int) $product->save();
        wp_update_post(['ID' => $wcId, 'post_author' => 1]);
    }

    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_U56_VENDOR, 'تأمین‌کنندهٔ ۵۷', true, false);

    // The sale, on the books, in a known unit — the money-unit registry reads
    // the ledger and nothing else can tell it which currency this supplier's
    // books are in.
    $base = Money::of(500000);
    $share = Money::of(450000);
    $event = 'order:' . TMC_U56_ITEM;
    if (!$ledger->hasEvent($event)) {
        $ledger->record(
            (new LedgerTransaction($event, TMC_U56_VENDOR, (string) TMC_U56_ORDER, (string) TMC_U56_ITEM))
                ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                ->add(LedgerAccount::Commission, Money::of(50000)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
        );
    }
    $line = $orderItems->findByOrderItem(TMC_U56_ITEM);
    $lineId = $line?->id ?? 0;
    if ($lineId === 0) {
        $lineId = $orderItems->record(new VendorOrderItem(
            0,
            TMC_U56_ORDER,
            TMC_U56_ITEM,
            0,
            TMC_U56_VENDOR,
            'کالای ارتقای ۵۷',
            TMC_U56_SKU,
            1,
            500000,
            500000,
            0,
            50000,
            450000,
            1000,
            'product',
            $event,
            OrderItemStatus::Delivered
        ));
    }

    echo 'wc=' . $wcId . "\n";
    echo 'line=' . $lineId . "\n";
    echo 'vendor=' . TMC_U56_VENDOR . "\n";
    echo 'share=' . ((int) ($orderItems->find($lineId)?->vendorShareMinor ?? 0)) . "\n";
    return;
}

if ($verb === 'digest') {
    $wcId = $findProduct();
    $line = $orderItems->findByOrderItem(TMC_U56_ITEM);
    $product = $wcId > 0 ? wc_get_product($wcId) : null;
    $fields = [
        'wc_id' => (string) $wcId,
        'url' => $wcId > 0 ? (string) get_permalink($wcId) : '',
        'status' => is_object($product) ? (string) $product->get_status() : '',
        'author' => $wcId > 0 ? (string) get_post_field('post_author', $wcId) : '',
        'price' => is_object($product) ? (string) $product->get_regular_price() : '',
        'stock' => is_object($product) ? (string) $product->get_stock_quantity() : '',
        'line_id' => (string) ($line?->id ?? 0),
        'line_base' => (string) ($line?->baseMinor ?? 0),
        'line_commission' => (string) ($line?->commissionMinor ?? 0),
        'line_share' => (string) ($line?->vendorShareMinor ?? 0),
        'line_event' => (string) ($line?->ledgerEvent ?? ''),
    ];
    foreach ($fields as $key => $value) {
        echo $key . '=' . ($value === '' ? '__none__' : $value) . "\n";
    }
    echo 'identity=' . hash('sha256', implode('|', $fields)) . "\n";
    return;
}

if ($verb === 'tables') {
    // Asked of the DATABASE, not of a class constant: «دو جدول تازه» is a
    // claim about disk.
    foreach (['tmc_supplier_payments' => 'idempotency_key', 'tmc_supplier_payment_items' => 'order_item_id'] as $suffix => $column) {
        $table = $GLOBALS['wpdb']->prefix . $suffix;
        $exists = (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        echo $suffix . '=' . ($exists > 0 ? 'present' : '__absent__') . "\n";
        // A single-column unique key over the column that makes the promise,
        // whatever the key is called (`alpha.54`).
        $unique = (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.STATISTICS s
             WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = %s
               AND s.NON_UNIQUE = 0 AND s.COLUMN_NAME = %s AND s.SEQ_IN_INDEX = 1
               AND (SELECT COUNT(*) FROM information_schema.STATISTICS t
                     WHERE t.TABLE_SCHEMA = s.TABLE_SCHEMA AND t.TABLE_NAME = s.TABLE_NAME
                       AND t.INDEX_NAME = s.INDEX_NAME) = 1',
            [$table, $column]
        );
        echo $suffix . '_unique_over_' . $column . '=' . ($unique > 0 ? 'yes' : 'no') . "\n";
    }
    return;
}

if ($verb === 'commission' || $verb === 'commission-read') {
    $panel = 'Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\WooCommerce\\TectebCommissionPanel';
    $source = 'Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\WooCommerce\\WpTectebCommissionSource';
    if (!class_exists($panel) || !class_exists($source)) {
        echo "absent=1\n";
        echo "reason=commission_not_in_this_build\n";
        return;
    }
    $wcId = $findProduct();
    if ($wcId <= 0) {
        echo "absent=1\n";
        echo "reason=no_product\n";
        return;
    }
    if ($verb === 'commission') {
        // THROUGH THE PANEL, with its own nonce — not by writing the meta.
        // A fixture that wrote the rows directly would prove the rows can be
        // written and nothing about the one code path allowed to write them.
        wp_set_current_user(1);
        $user = wp_get_current_user();
        foreach ([Capabilities::REVIEW_WITHDRAWALS, 'edit_post', 'edit_posts', 'edit_others_posts'] as $cap) {
            $user->add_cap($cap);
        }
        $_POST = [
            $panel::NONCE => wp_create_nonce($panel::NONCE),
            'tmc_commission_type' => (string) ($args[1] ?? 'percent'),
            'tmc_commission_value' => (string) ($args[2] ?? '10'),
        ];
        $panel::save($wcId, Bootstrap::container());
        $_POST = [];
    }
    ['rule' => $rule, 'source' => $from] = (new $source())->forItem($wcId);
    echo 'type=' . $rule->type . "\n";
    echo 'value=' . ($rule->displayValue() === '' ? '__none__' : $rule->displayValue()) . "\n";
    echo 'from=' . $from . "\n";
    return;
}

if ($verb === 'pay' || $verb === 'payments') {
    $service = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\RecordSupplierPayment';
    if (!class_exists($service)) {
        echo "absent=1\n";
        echo "reason=supplier_payment_not_in_this_build\n";
        return;
    }
    wp_set_current_user(1);
    $user = wp_get_current_user();
    $user->add_cap(Capabilities::REVIEW_WITHDRAWALS);
    $container = Bootstrap::container();
    /** @var object $record */
    $record = $container->get($service);

    if ($verb === 'payments') {
        $waiting = $record->awaitingDefinition(50);
        echo 'awaiting=' . count($waiting) . "\n";
        $table = $GLOBALS['wpdb']->prefix . 'tmc_supplier_payments';
        echo 'rows=' . (int) $db->getVar('SELECT COUNT(*) FROM `' . $table . '`') . "\n";
        // The ledger event too, so «one payment row» and «one payout line»
        // cannot drift apart unnoticed.
        echo 'payout_lines=' . (int) $db->getVar(
            'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
            . '` WHERE event_key LIKE %s',
            ['supplier-payment:%']
        ) . "\n";
        return;
    }

    $line = $orderItems->findByOrderItem(TMC_U56_ITEM);
    $result = $record->record(
        TMC_U56_VENDOR,
        [(int) ($line?->id ?? 0)],
        (string) ($args[1] ?? '450000'),
        '2026-10-16',
        (string) ($args[2] ?? 'TRK-U56'),
        '',
        '',
        (string) ($args[3] ?? '')
    );
    echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
    echo 'code=' . $result->code . "\n";
    echo 'payment=' . (int) ($result->context['payment_id'] ?? 0) . "\n";
    return;
}

if ($verb === 'drop-new-tables') {
    // A site that has NEVER seen `alpha.57` is what «a site genuinely on
    // alpha.56» means, and after one run of this script the two tables are
    // still there — correctly, because rolling back leaves them (stage 8f
    // asserts exactly that). So the pre-upgrade site is CONSTRUCTED rather
    // than assumed, the same way the schema option is walked back one step.
    //
    // Only ever reachable with the disposable-database guard above satisfied.
    foreach (['tmc_supplier_payment_items', 'tmc_supplier_payments'] as $suffix) {
        $db->execute('DROP TABLE IF EXISTS `' . $GLOBALS['wpdb']->prefix . $suffix . '`');
    }
    echo "dropped=1\n";
    return;
}

if ($verb === 'reset') {
    // Gives back everything it spent, including what the NEW build may have
    // written — a fixture that spends something must give it back
    // (`alpha.33`), and on the old build the extra tables simply are not
    // there, which this handles rather than fails on.
    $wcId = $findProduct();
    foreach (['tmc_supplier_payment_items', 'tmc_supplier_payments'] as $suffix) {
        $table = $GLOBALS['wpdb']->prefix . $suffix;
        $exists = (int) $db->getVar(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            [$table]
        );
        if ($exists > 0) {
            $db->execute('DELETE FROM `' . $table . '` WHERE supplier_user_id = %d', [TMC_U56_VENDOR]);
        }
    }
    // EVERY table name comes out of its own migration class, never typed —
    // the `alpha.10` rule, which the first version of this file broke: it
    // deleted from `tmc_ledger`, a table that does not exist (the real one is
    // `tmc_ledger_entries`), so the payout of the previous run survived, its
    // event key collided on the next run, and five checks reported
    // `storage_failed` about a build that worked.
    $db->execute(
        'DELETE FROM `' . M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS) . '` WHERE wc_order_id = %d',
        [TMC_U56_ORDER]
    );
    $db->execute(
        'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '` WHERE vendor_user_id = %d',
        [TMC_U56_VENDOR]
    );
    $db->execute(
        'DELETE FROM `' . M0022VendorMoneyUnit::table($db, M0022VendorMoneyUnit::UNITS) . '` WHERE vendor_user_id = %d',
        [TMC_U56_VENDOR]
    );
    if ($wcId > 0) {
        delete_post_meta($wcId, '_tmc_commission_type');
        delete_post_meta($wcId, '_tmc_commission_value');
        wp_delete_post($wcId, true);
    }
    echo "reset=1\n";
    return;
}

echo "usage: arrange|digest|tables|drop-new-tables|commission <type> <value>|commission-read|pay <amount> <ref> [token]|payments|reset\n";
