<?php
/**
 * The state a `0.1.0-alpha.60 → 0.1.0-alpha.61` (and from `alpha.56`, two steps) upgrade must not damage, and
 * the two things the new build adds, written by whichever build is installed.
 *
 * This upgrade **is** structural: 29 → 30, one column made nullable
 * (`M0030SupplierPaymentShareUnknown`) — and from `alpha.56` it is two steps,
 * 28 → 30, because the owner's installed version is not confirmed and this
 * file must not assume it. So there are two different claims to measure —
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
 *   wp eval-file tools/upgrade-alpha60-state.php arrange
 *   wp eval-file tools/upgrade-alpha60-state.php digest
 *   wp eval-file tools/upgrade-alpha60-state.php tables
 *   wp eval-file tools/upgrade-alpha60-state.php commission <type> <value>
 *   wp eval-file tools/upgrade-alpha60-state.php commission-read
 *   wp eval-file tools/upgrade-alpha60-state.php pay <amount> <reference> [token]
 *   wp eval-file tools/upgrade-alpha60-state.php payments
 *   wp eval-file tools/upgrade-alpha60-state.php reset
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
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables;
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

const TMC_U60_SKU = 'WC-U60-UPGRADE';
const TMC_U60_VENDOR = 9606;
const TMC_U60_ORDER = 96060;
const TMC_U60_ITEM = 96061;
/** A second line whose share was never recorded — §3's real state. */
const TMC_U60_ITEM_NOSHARE = 96062;
/**
 * Two more lines, so each scenario spends its OWN one.
 *
 * A fixture that lets two scenarios settle the same line answers the second
 * one about the first (`alpha.33`), and from this build that is not a
 * theoretical worry: the whole point of §2 is that a settled line refuses the
 * other path.
 */
const TMC_U60_ITEM_THIRD = 96063;
const TMC_U60_ITEM_FOURTH = 96064;

$verb = (string) ($args[0] ?? 'report');
$db = new WpDatabase($GLOBALS['wpdb']);
$clock = new SystemClock();
$orderItems = new DbOrderItemRepository($db, $clock);
$ledger = new DbLedgerRepository($db, $clock);

/** The product this fixture owns, by SKU — found, never guessed. */
$findProduct = static function (): int {
    $id = (int) wc_get_product_id_by_sku(TMC_U60_SKU);
    return $id > 0 ? $id : 0;
};

if ($verb === 'arrange') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('کالای ارتقای ۶۱');
        $product->set_sku(TMC_U60_SKU);
        $product->set_regular_price('500000');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(12);
        $product->set_status('publish');
        $wcId = (int) $product->save();
        wp_update_post(['ID' => $wcId, 'post_author' => 1]);
    }

    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_U60_VENDOR, 'تأمین‌کنندهٔ ۶۱', true, false);

    // The sale, on the books, in a known unit — the money-unit registry reads
    // the ledger and nothing else can tell it which currency this supplier's
    // books are in.
    $base = Money::of(500000);
    $share = Money::of(450000);
    $event = 'order:' . TMC_U60_ITEM;
    if (!$ledger->hasEvent($event)) {
        $ledger->record(
            (new LedgerTransaction($event, TMC_U60_VENDOR, (string) TMC_U60_ORDER, (string) TMC_U60_ITEM))
                ->add(LedgerAccount::CentralPayment, $base, 'item_paid')
                ->add(LedgerAccount::Commission, Money::of(50000)->negate(), 'commission_due')
                ->add(LedgerAccount::VendorEarning, $share->negate(), 'vendor_earned')
        );
    }
    $line = $orderItems->findByOrderItem(TMC_U60_ITEM);
    $lineId = $line?->id ?? 0;
    if ($lineId === 0) {
        $lineId = $orderItems->record(new VendorOrderItem(
            0,
            TMC_U60_ORDER,
            TMC_U60_ITEM,
            0,
            TMC_U60_VENDOR,
            'کالای ارتقای ۶۱',
            TMC_U60_SKU,
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

    // ORDER-01: a manager called the sale complete, long enough ago that the
    // approved waiting period has elapsed — without it the withdrawal cycle
    // cannot claim the line and the §2 verbs would measure nothing.
    $orderItems->recordSettlementCompletion($lineId, '2025-01-01 00:00:00', 1);

    // AND three more lines, each for one scenario. The second has NO recorded
    // share — not contrived: it is what `incompleteCaptures()` exists to list,
    // and it is the state `alpha.60` counted as nought.
    $extra = [];
    foreach ([
        [TMC_U60_ITEM_NOSHARE, 700000, null, 'NS'],
        [TMC_U60_ITEM_THIRD, 350000, 300000, 'T3'],
        [TMC_U60_ITEM_FOURTH, 250000, 200000, 'T4'],
    ] as [$wcItem, $baseMinor, $shareMinor, $tag]) {
        $event = 'order:' . $wcItem;
        if ($shareMinor !== null && !$ledger->hasEvent($event)) {
            $ledger->record(
                (new LedgerTransaction($event, TMC_U60_VENDOR, (string) TMC_U60_ORDER, (string) $wcItem))
                    ->add(LedgerAccount::CentralPayment, Money::of($baseMinor), 'item_paid')
                    ->add(LedgerAccount::Commission, Money::of($baseMinor - $shareMinor)->negate(), 'commission_due')
                    ->add(LedgerAccount::VendorEarning, Money::of($shareMinor)->negate(), 'vendor_earned')
            );
        }
        $row = $orderItems->findByOrderItem($wcItem);
        $id = $row?->id ?? 0;
        if ($id === 0) {
            $id = $orderItems->record(new VendorOrderItem(
                0,
                TMC_U60_ORDER,
                $wcItem,
                0,
                TMC_U60_VENDOR,
                'قلم ' . $tag . ' ۶۱',
                TMC_U60_SKU . '-' . $tag,
                1,
                $baseMinor,
                $baseMinor,
                0,
                $shareMinor === null ? null : $baseMinor - $shareMinor,
                $shareMinor,
                $shareMinor === null ? null : 1000,
                $shareMinor === null ? '' : 'product',
                $shareMinor === null ? '' : $event,
                OrderItemStatus::Delivered
            ));
        }
        $orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', 1);
        $extra[$wcItem] = $id;
    }
    $noShareId = $extra[TMC_U60_ITEM_NOSHARE];

    echo 'wc=' . $wcId . "\n";
    echo 'line=' . $lineId . "\n";
    echo 'line_noshare=' . $noShareId . "\n";
    echo 'vendor=' . TMC_U60_VENDOR . "\n";
    echo 'share=' . ((int) ($orderItems->find($lineId)?->vendorShareMinor ?? 0)) . "\n";
    echo 'share_noshare=' . ($orderItems->find($noShareId)?->vendorShareMinor ?? '__null__') . "\n";
    echo 'line_third=' . $extra[TMC_U60_ITEM_THIRD] . "\n";
    echo 'line_fourth=' . $extra[TMC_U60_ITEM_FOURTH] . "\n";
    return;
}

if ($verb === 'digest') {
    $wcId = $findProduct();
    $line = $orderItems->findByOrderItem(TMC_U60_ITEM);
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
    // THE COLUMN THIS ROUND MOVES, asked of the database: an unknown share
    // has to be spellable, and `NOT NULL DEFAULT 0` cannot spell it.
    $items = $GLOBALS['wpdb']->prefix . 'tmc_supplier_payment_items';
    $nullable = $db->getVar(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [$items, 'share_minor']
    );
    echo 'share_minor_nullable=' . ($nullable === null ? '__absent__' : (strtoupper((string) $nullable) === 'YES' ? 'yes' : 'no')) . "\n";
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
        $payments = $GLOBALS['wpdb']->prefix . 'tmc_supplier_payments';
        echo 'rows=' . (int) $db->getVar(
            'SELECT COUNT(*) FROM `' . $payments . '` WHERE supplier_user_id = %d',
            [TMC_U60_VENDOR]
        ) . "\n";
        // The ledger event too, so «one payment row» and «one payout line»
        // cannot drift apart unnoticed — both scoped to this fixture's
        // supplier, because the site has other payments on it.
        echo 'payout_lines=' . (int) $db->getVar(
            'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
            . '` WHERE vendor_user_id = %d AND event_key LIKE %s',
            [TMC_U60_VENDOR, 'supplier-payment:%']
        ) . "\n";
        return;
    }

    // WHICH line, because one line can only be paid once from this build on —
    // and that is the whole of §2. A verb that always paid the same line would
    // report `item_already_paid` about a build that works.
    $wcItem = ((string) ($args[4] ?? '')) === 'fourth' ? TMC_U60_ITEM_FOURTH : TMC_U60_ITEM;
    $line = $orderItems->findByOrderItem($wcItem);
    $result = $record->record(
        TMC_U60_VENDOR,
        [(int) ($line?->id ?? 0)],
        (string) ($args[1] ?? '450000'),
        '2026-10-16',
        (string) ($args[2] ?? 'TRK-U60'),
        '',
        '',
        (string) ($args[3] ?? '')
    );
    echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
    echo 'code=' . $result->code . "\n";
    echo 'payment=' . (int) ($result->context['payment_id'] ?? 0) . "\n";
    return;
}

if ($verb === 'payment-rows') {
    // PURE SQL and SCOPED TO THIS FIXTURE, for two reasons the previous
    // round's `payments` verb had neither of:
    //
    //  - it must answer on the OLD build too, which has no service to ask;
    //  - and a count that is not scoped answers about somebody else's rows.
    //    Measured: with this file's own earlier runs and a separate probe's
    //    payments on the same disposable site, the unscoped count said `2`
    //    and `4` and two checks reported a failure about a build that worked
    //    (`alpha.34`'s rule, in a new place).
    $payments = $GLOBALS['wpdb']->prefix . 'tmc_supplier_payments';
    $exists = (int) $db->getVar(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        [$payments]
    );
    echo 'rows=' . ($exists === 0 ? 0 : (int) $db->getVar(
        'SELECT COUNT(*) FROM `' . $payments . '` WHERE supplier_user_id = %d',
        [TMC_U60_VENDOR]
    )) . "\n";
    echo 'payout_lines=' . (int) $db->getVar(
        'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
        . '` WHERE vendor_user_id = %d AND event_key LIKE %s',
        [TMC_U60_VENDOR, 'supplier-payment:%']
    ) . "\n";
    return;
}

if ($verb === 'commission-fail-value') {
    // §1 on an upgraded site: the AMOUNT write refused through WordPress's
    // own short-circuit, and then the RAW ROWS. On `alpha.60` this leaves
    // `fixed` beside the old `12.50` and says the previous value is intact;
    // from `alpha.61` the pair is restored and the answer says which.
    $panel = 'Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\WooCommerce\\TectebCommissionPanel';
    if (!class_exists($panel)) {
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
    wp_set_current_user(1);
    $user = wp_get_current_user();
    foreach ([Capabilities::REVIEW_WITHDRAWALS, 'edit_post', 'edit_posts', 'edit_others_posts'] as $cap) {
        $user->add_cap($cap);
    }
    $rows = static function (int $id) use ($db): array {
        $out = [];
        foreach (['_tmc_commission_type', '_tmc_commission_value'] as $key) {
            $row = $db->getRow(
                'SELECT COUNT(*) AS n, MIN(meta_value) AS v FROM `' . $GLOBALS['wpdb']->prefix . 'postmeta`
                  WHERE post_id = %d AND meta_key = %s',
                [$id, $key]
            );
            $out[] = ((int) ($row['n'] ?? 0)) === 0 ? '__absent__' : (string) ($row['v'] ?? '');
        }
        return $out;
    };

    // The baseline, written the ordinary way through the panel.
    $_POST = [
        $panel::NONCE => wp_create_nonce($panel::NONCE),
        'tmc_commission_type' => 'percent',
        'tmc_commission_value' => '12.50',
    ];
    $panel::save($wcId, Bootstrap::container());
    $_POST = [];
    wp_cache_delete($wcId, 'post_meta');
    echo 'before=' . implode('/', $rows($wcId)) . "\n";

    foreach (['update_post_metadata', 'add_post_metadata'] as $filter) {
        add_filter($filter, static function (mixed $check, mixed $id, mixed $key): mixed {
            return (string) $key === '_tmc_commission_value' ? false : $check;
        }, 9, 3);
    }
    delete_transient('tmc_commission_notice_' . (int) get_current_user_id());
    $_POST = [
        $panel::NONCE => wp_create_nonce($panel::NONCE),
        'tmc_commission_type' => 'fixed',
        'tmc_commission_value' => '20000',
    ];
    $panel::save($wcId, Bootstrap::container());
    $_POST = [];
    wp_cache_delete($wcId, 'post_meta');
    echo 'after=' . implode('/', $rows($wcId)) . "\n";
    $notice = get_transient('tmc_commission_notice_' . (int) get_current_user_id());
    echo 'outcome=' . (is_array($notice) ? (string) ($notice['outcome'] ?? '__none__') : '__none__') . "\n";
    return;
}

if ($verb === 'pay-unknown' || $verb === 'withdraw-then-pay' || $verb === 'unpaid-rows') {
    $service = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\RecordSupplierPayment';
    if (!class_exists($service)) {
        echo "absent=1\n";
        echo "reason=supplier_payment_not_in_this_build\n";
        return;
    }
    wp_set_current_user(1);
    wp_get_current_user()->add_cap(Capabilities::REVIEW_WITHDRAWALS);
    $container = Bootstrap::container();
    $record = $container->get($service);
    $lineId = (int) ($orderItems->findByOrderItem(TMC_U60_ITEM)?->id ?? 0);
    $noShareId = (int) ($orderItems->findByOrderItem(TMC_U60_ITEM_NOSHARE)?->id ?? 0);
    $thirdId = (int) ($orderItems->findByOrderItem(TMC_U60_ITEM_THIRD)?->id ?? 0);
    $fourthId = (int) ($orderItems->findByOrderItem(TMC_U60_ITEM_FOURTH)?->id ?? 0);

    if ($verb === 'unpaid-rows') {
        // What the screen that lists «not in the ledger» would show, and WHY
        // each row is there — the two reasons must not share a sentence.
        foreach ($record->awaitingDefinition(20) as $i => $payment) {
            echo 'row' . $i . '=' . $payment->posting
                . (method_exists($payment, 'shareIsKnown')
                    ? ($payment->shareIsKnown() ? '/known' : '/unknown:' . implode(',', $payment->unknownShareItemIds))
                    : '/unasked')
                . "\n";
        }
        echo 'awaiting=' . count($record->awaitingDefinition(20)) . "\n";
        return;
    }

    if ($verb === 'pay-unknown') {
        // The payment equals the KNOWN item's share alone, over two items.
        $result = $record->record(
            TMC_U60_VENDOR,
            [$thirdId, $noShareId],
            '300000',                  // exactly the KNOWN line's share
            '2026-10-16',
            'TRK-U60-UNKNOWN',
            '',
            '',
            (string) ($args[1] ?? 'u60-unknown')
        );
        echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
        echo 'code=' . $result->code . "\n";
        $paymentId = (int) ($result->context['payment_id'] ?? 0);
        echo 'payment=' . $paymentId . "\n";
        echo 'posting=' . ($paymentId > 0 ? (string) $db->getVar(
            'SELECT posting FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_supplier_payments` WHERE id = %d',
            [$paymentId]
        ) : '__none__') . "\n";
        echo 'payout_lines=' . ($paymentId > 0 ? (int) $db->getVar(
            'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER)
            . '` WHERE event_key = %s',
            ['supplier-payment:' . $paymentId]
        ) : 0) . "\n";
        echo 'known_line=' . $thirdId . "\n";
        echo 'unknown_line=' . $noShareId . "\n";
        echo 'item_shares=' . ($paymentId > 0 ? implode('|', array_map(
            static fn (array $r): string => $r['order_item_id'] . ':' . ($r['share_minor'] ?? '__null__'),
            $db->getResults(
                'SELECT order_item_id, share_minor FROM `' . $GLOBALS['wpdb']->prefix
                . 'tmc_supplier_payment_items` WHERE payment_id = %d ORDER BY order_item_id',
                [$paymentId]
            )
        )) : '__none__') . "\n";
        return;
    }

    // §2: the vendor's own cycle claims the line first, then a manual payment.
    $withdrawals = $container->get(
        'Tecteb\\Marketplace\\Modules\\Finance\\Application\\WithdrawalRepositoryInterface'
    );
    $wid = $withdrawals->reserve(TMC_U60_VENDOR, [$fourthId], 200000, 'IR000000000000000000000000', 'x');
    echo 'withdrawal=' . $wid . "\n";
    $result = $record->record(
        TMC_U60_VENDOR,
        [$fourthId],
        '200000',
        '2026-10-16',
        'TRK-U60-HELD',
        '',
        '',
        (string) ($args[1] ?? 'u60-held')
    );
    echo 'ok=' . ($result->ok ? 'yes' : 'no') . "\n";
    echo 'code=' . $result->code . "\n";
    echo 'named_withdrawal=' . (int) ($result->context['withdrawal_id'] ?? 0) . "\n";
    echo 'payment_rows_for_line=' . (int) $db->getVar(
        'SELECT COUNT(*) FROM `' . $GLOBALS['wpdb']->prefix . 'tmc_supplier_payment_items` WHERE order_item_id = %d',
        [$fourthId]
    ) . "\n";
    // Nothing released BY THE CODE: the request still holds what it claimed.
    echo 'still_claimed=' . (int) $db->getVar(
        'SELECT COALESCE(withdrawal_id, 0) FROM `'
        . M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS) . '` WHERE id = %d',
        [$fourthId]
    ) . "\n";
    // …and then the FIXTURE gives back what it spent, which is a different
    // actor from the code under test and is printed as such (`alpha.33`).
    echo 'fixture_released=' . ($wid > 0 && $withdrawals->release($wid) ? 'yes' : 'no') . "\n";
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

if ($verb === 'unmigrate-30') {
    // Put the ITEM TABLE's column back to what `alpha.60` created, so «a site
    // genuinely on alpha.60» is constructed rather than assumed. The tables
    // are NOT dropped: `alpha.60` has them, and dropping them would make
    // stage 3 measure the creation of a table instead of this round's one
    // step. `NULL`s are written away first, because the column is about to
    // stop accepting them.
    //
    // Only ever reachable with the disposable-database guard above satisfied.
    $items = $GLOBALS['wpdb']->prefix . 'tmc_supplier_payment_items';
    $exists = (int) $db->getVar(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        [$items]
    );
    if ($exists === 0) {
        echo "unmigrated=0\n";
        echo "reason=no_table\n";
        return;
    }
    $db->execute('UPDATE `' . $items . '` SET share_minor = 0 WHERE share_minor IS NULL');
    $db->execute('ALTER TABLE `' . $items . '` MODIFY `share_minor` BIGINT NOT NULL DEFAULT 0');
    echo "unmigrated=1\n";
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
            $db->execute('DELETE FROM `' . $table . '` WHERE supplier_user_id = %d', [TMC_U60_VENDOR]);
        }
    }
    // EVERY table name comes out of its own migration class, never typed —
    // the `alpha.10` rule, which the first version of this file broke: it
    // deleted from `tmc_ledger`, a table that does not exist (the real one is
    // `tmc_ledger_entries`), so the payout of the previous run survived, its
    // event key collided on the next run, and five checks reported
    // `storage_failed` about a build that worked.
    // The withdrawals this fixture made, and the claim they left on the line
    // — every table name out of its own migration class (`alpha.10`).
    $items = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
    $db->execute('UPDATE `' . $items . '` SET withdrawal_id = NULL WHERE vendor_user_id = %d', [TMC_U60_VENDOR]);
    foreach ([M0007SettlementTables::WITHDRAWAL_LINES, M0007SettlementTables::WITHDRAWALS] as $suffix) {
        $db->execute(
            'DELETE FROM `' . M0007SettlementTables::table($db, $suffix) . '` WHERE vendor_user_id = %d',
            [TMC_U60_VENDOR]
        );
    }
    $db->execute(
        'DELETE FROM `' . $items . '` WHERE wc_order_id = %d',
        [TMC_U60_ORDER]
    );
    $db->execute(
        'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '` WHERE vendor_user_id = %d',
        [TMC_U60_VENDOR]
    );
    $db->execute(
        'DELETE FROM `' . M0022VendorMoneyUnit::table($db, M0022VendorMoneyUnit::UNITS) . '` WHERE vendor_user_id = %d',
        [TMC_U60_VENDOR]
    );
    if ($wcId > 0) {
        delete_post_meta($wcId, '_tmc_commission_type');
        delete_post_meta($wcId, '_tmc_commission_value');
        wp_delete_post($wcId, true);
    }
    echo "reset=1\n";
    return;
}

echo "usage: arrange|digest|tables|drop-new-tables|commission <type> <value>|commission-read"
    . "|commission-fail-value|pay <amount> <ref> [token] [fourth]|pay-unknown|withdraw-then-pay|unpaid-rows|payments|payment-rows|unmigrate-30|reset\n";
