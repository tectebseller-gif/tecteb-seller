<?php
/**
 * `alpha.61`: the three findings of the code review on `alpha.60`'s bytes.
 *
 * **Its own file, named for its round** — `alpha.54`'s rule, guarded by
 * `EvidenceToolsAreNotOverwrittenTest`. It loads on BOTH trees (`alpha.32`):
 * everything this round adds is reached through `method_exists()` /
 * `class_exists()` / `defined()`, so a verb about a capability `alpha.60` does
 * not have answers `absent=1` rather than dying at load — and «absent» is
 * itself a measurement.
 *
 * What it measures, and how:
 *
 *  §1 the commission save. The TYPE write is allowed and the VALUE write is
 *     made to fail through WordPress's own short-circuit filter
 *     (`update_post_metadata`, the mechanism `alpha.47` used) — an injected
 *     failure of a collaborator, not a spontaneous one. Afterwards the RAW
 *     ROWS are read with one statement (`alpha.55`: `get_post_meta()` is not
 *     a row read), the rule is read through the plugin's own source, and the
 *     notice the manager would see is rendered from the transient.
 *
 *  §2 the manual supplier payment beside the vendor withdrawal cycle, in both
 *     orders, on the real tables.
 *
 *  §3 a payment over two items where one has NO recorded share.
 *
 * Its fixture is its OWN (`A61-*`, vendor 9611) and `reset` gives back
 * everything it spends (`alpha.33`).
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tests/Support/alpha61-probe.php <verb> [args]
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Application\RecordSupplierPayment;
use Tecteb\Marketplace\Modules\Finance\Application\SupplierPaymentRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\VendorBalance;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\DbLedgerRepository;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0004CreateFinanceTables;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0029SupplierPayments;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\WooCommerce\TectebCommissionPanel;
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

const TMC_P61_VENDOR = 9611;
const TMC_P61_SKU = 'WC-A61-COMMISSION';
const TMC_P61_ORDER = 96110;
/** Three WooCommerce order-item ids: a share, NO share, and a third. */
const TMC_P61_ITEM_A = 96111;
const TMC_P61_ITEM_B = 96112;
const TMC_P61_ITEM_C = 96113;

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

$itemsTable = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
$payTable = class_exists(M0029SupplierPayments::class)
    ? M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENTS)
    : '';
$payItemsTable = class_exists(M0029SupplierPayments::class)
    ? M0029SupplierPayments::table($db, M0029SupplierPayments::PAYMENT_ITEMS)
    : '';

// ------------------------------------------------------------------ helpers

/** The RAW rows of one meta key — value and row count, in one statement. */
$rawMeta = static function (int $postId, string $key) use ($wpdb): array {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS n, MIN(meta_value) AS v FROM {$wpdb->postmeta}
          WHERE post_id = %d AND meta_key = %s",
        $postId,
        $key
    ), ARRAY_A);
    $n = (int) ($row['n'] ?? 0);
    return ['rows' => $n, 'value' => $n === 0 ? '__absent__' : (string) ($row['v'] ?? '')];
};

/** The notice the manager would actually see, rendered from the transient. */
$notice = static function (): array {
    $key = 'tmc_commission_notice_' . (int) get_current_user_id();
    $data = get_transient($key);
    if (!is_array($data)) {
        return ['outcome' => '__none__', 'reason' => '', 'text' => '__none__'];
    }
    $m = new ReflectionMethod(TectebCommissionPanel::class, 'notice');
    $m->setAccessible(true);
    $html = (string) $m->invoke(null, $data);
    return [
        'outcome' => (string) ($data['outcome'] ?? ''),
        'reason' => (string) ($data['reason'] ?? ''),
        'text' => trim(wp_strip_all_tags($html)),
    ];
};

$findProduct = static function (): int {
    $id = (int) wc_get_product_id_by_sku(TMC_P61_SKU);
    return $id > 0 ? $id : 0;
};

/** One save through the panel, with a REAL nonce and a real POST. */
$panelSave = static function (int $wcId, string $type, string $value, bool $withNonce = true) use ($c): void {
    $_POST = [];
    if ($withNonce) {
        $_POST[TectebCommissionPanel::NONCE] = wp_create_nonce(TectebCommissionPanel::NONCE);
    }
    $_POST['tmc_commission_type'] = $type;
    $_POST['tmc_commission_value'] = $value;
    delete_transient('tmc_commission_notice_' . (int) get_current_user_id());
    TectebCommissionPanel::save($wcId, $c);
    $_POST = [];
};

// ===================================================== §1 commission save ==

if ($verb === 'commission-arrange') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $product = new WC_Product_Simple();
        $product->set_name('کالای کمیسیون ۶۱');
        $product->set_sku(TMC_P61_SKU);
        $product->set_regular_price('500000');
        $product->set_status('publish');
        $wcId = (int) $product->save();
    }
    update_post_meta($wcId, WpTectebCommissionSource::TYPE_META, 'percent');
    update_post_meta($wcId, WpTectebCommissionSource::VALUE_META, '12.50');
    wp_cache_delete($wcId, 'post_meta');
    $say('product', $wcId);
    $say('cap_financial', current_user_can('tmc_review_withdrawals'));
    $say('row_type', $rawMeta($wcId, WpTectebCommissionSource::TYPE_META)['value']);
    $say('row_value', $rawMeta($wcId, WpTectebCommissionSource::VALUE_META)['value']);
    return;
}

if ($verb === 'commission-value-fails' || $verb === 'commission-type-fails') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $say('refused', 'no_product');
        return;
    }
    $target = $verb === 'commission-value-fails'
        ? WpTectebCommissionSource::VALUE_META
        : WpTectebCommissionSource::TYPE_META;
    $say('before_type', $rawMeta($wcId, WpTectebCommissionSource::TYPE_META)['value']);
    $say('before_value', $rawMeta($wcId, WpTectebCommissionSource::VALUE_META)['value']);
    $say('failing_write', $target);
    // WordPress's OWN short-circuit: `false` from here means «not written»,
    // which is exactly the storage failure being modelled.
    add_filter('update_post_metadata', static function (mixed $check, mixed $id, mixed $key) use ($target): mixed {
        return (string) $key === $target ? false : $check;
    }, 9, 3);
    add_filter('add_post_metadata', static function (mixed $check, mixed $id, mixed $key) use ($target): mixed {
        return (string) $key === $target ? false : $check;
    }, 9, 3);
    $panelSave($wcId, 'fixed', '20000');
    wp_cache_delete($wcId, 'post_meta');
    $type = $rawMeta($wcId, WpTectebCommissionSource::TYPE_META);
    $value = $rawMeta($wcId, WpTectebCommissionSource::VALUE_META);
    $say('db_type', $type['value']);
    $say('db_value', $value['value']);
    $say('db_type_rows', $type['rows']);
    $say('db_value_rows', $value['rows']);
    $rule = (new WpTectebCommissionSource())->forItem($wcId, 0)['rule'];
    $say('rule_type', $rule->type);
    $say('rule_value', $rule->displayValue());
    $n = $notice();
    $say('notice_outcome', $n['outcome']);
    $say('notice_reason', $n['reason'] === '' ? '__none__' : $n['reason']);
    $say('notice_text', $n['text']);
    return;
}

if ($verb === 'commission-regression') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $say('refused', 'no_product');
        return;
    }
    foreach ([['percent', '12.50'], ['percent', '12.50'], ['fixed', '20000'], ['none', '']] as $i => [$t, $v]) {
        $panelSave($wcId, $t, $v);
        wp_cache_delete($wcId, 'post_meta');
        $n = $notice();
        $say('save' . $i . '_asked', $t . '/' . ($v === '' ? '__empty__' : $v));
        $say('save' . $i . '_outcome', $n['outcome']);
        $say('save' . $i . '_db', $rawMeta($wcId, WpTectebCommissionSource::TYPE_META)['value']
            . '/' . $rawMeta($wcId, WpTectebCommissionSource::VALUE_META)['value']);
    }
    // The product's TITLE saved with no commission field in the POST at all.
    update_post_meta($wcId, WpTectebCommissionSource::TYPE_META, 'percent');
    update_post_meta($wcId, WpTectebCommissionSource::VALUE_META, '12.50');
    $panelSave($wcId, '', '', false);
    wp_cache_delete($wcId, 'post_meta');
    $say('title_only_db', $rawMeta($wcId, WpTectebCommissionSource::TYPE_META)['value']
        . '/' . $rawMeta($wcId, WpTectebCommissionSource::VALUE_META)['value']);
    $say('title_only_notice', $notice()['outcome']);
    return;
}

if ($verb === 'commission-no-permission') {
    $wcId = $findProduct();
    if ($wcId <= 0) {
        $say('refused', 'no_product');
        return;
    }
    update_post_meta($wcId, WpTectebCommissionSource::TYPE_META, 'percent');
    update_post_meta($wcId, WpTectebCommissionSource::VALUE_META, '12.50');
    $uid = (int) username_exists('tmc-guard-probe-manager');
    if ($uid <= 0) {
        $say('absent', 'no_probe_manager');
        return;
    }
    wp_set_current_user($uid);
    $say('actor_cap_financial', current_user_can('tmc_review_withdrawals'));
    $panelSave($wcId, 'fixed', '999999');
    wp_cache_delete($wcId, 'post_meta');
    $say('db_after', $rawMeta($wcId, WpTectebCommissionSource::TYPE_META)['value']
        . '/' . $rawMeta($wcId, WpTectebCommissionSource::VALUE_META)['value']);
    $say('notice_outcome', $notice()['outcome']);
    wp_set_current_user($adminId);
    return;
}

// ========================================== §2/§3 supplier payment fixture ==

if ($verb === 'pay-arrange') {
    (new DbVendorRepository($db, $clock))->upsertProfile(TMC_P61_VENDOR, 'تأمین‌کنندهٔ ۶۱', true, false);
    $lines = [];
    foreach ([
        [TMC_P61_ITEM_A, 1000000, 100000, 900000],
        [TMC_P61_ITEM_B, 700000, 70000, null],
        [TMC_P61_ITEM_C, 500000, 50000, 450000],
    ] as [$wcItem, $base, $commission, $share]) {
        $event = 'order:' . $wcItem;
        if ($share !== null && !$ledger->hasEvent($event)) {
            $ledger->record(
                (new LedgerTransaction($event, TMC_P61_VENDOR, (string) TMC_P61_ORDER, (string) $wcItem))
                    ->add(LedgerAccount::CentralPayment, Money::of($base), 'item_paid')
                    ->add(LedgerAccount::Commission, Money::of($commission)->negate(), 'commission_due')
                    ->add(LedgerAccount::VendorEarning, Money::of($share)->negate(), 'vendor_earned')
            );
        }
        $existing = $orderItems->findByOrderItem($wcItem);
        $id = $existing?->id ?? 0;
        if ($id === 0) {
            $id = $orderItems->record(new VendorOrderItem(
                0,
                TMC_P61_ORDER,
                $wcItem,
                0,
                TMC_P61_VENDOR,
                'قلم ' . $wcItem,
                'A61-' . $wcItem,
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
        // ORDER-01: a manager called the sale complete, long enough ago that
        // the approved waiting period has elapsed.
        $orderItems->recordSettlementCompletion($id, '2025-01-01 00:00:00', $adminId);
        $lines[$wcItem] = $id;
    }
    $say('item_a', $lines[TMC_P61_ITEM_A]);
    $say('item_b', $lines[TMC_P61_ITEM_B]);
    $say('item_c', $lines[TMC_P61_ITEM_C]);
    $say('share_a', $orderItems->find($lines[TMC_P61_ITEM_A])?->vendorShareMinor ?? '__null__');
    $say('share_b', $orderItems->find($lines[TMC_P61_ITEM_B])?->vendorShareMinor ?? '__null__');
    $say('share_c', $orderItems->find($lines[TMC_P61_ITEM_C])?->vendorShareMinor ?? '__null__');
    $unit = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface::class)
        ->unitOf(TMC_P61_VENDOR);
    $say('unit', is_array($unit) ? (($unit['currency'] ?? '?') . '/' . ($unit['exponent'] ?? '?')) : '__null__');
    return;
}

/** The line id of one of this fixture's WooCommerce order items. */
$lineOf = static function (int $wcItem) use ($orderItems): int {
    return (int) ($orderItems->findByOrderItem($wcItem)?->id ?? 0);
};

if ($verb === 'withdraw-and-pay' || $verb === 'reserve-and-pay') {
    $lineA = $lineOf(TMC_P61_ITEM_A);
    if ($lineA <= 0) {
        $say('refused', 'no_line');
        return;
    }
    $withdrawals = $c->get(WithdrawalRepositoryInterface::class);
    $wid = $withdrawals->reserve(TMC_P61_VENDOR, [$lineA], 900000, 'IR000000000000000000000000', 'تأمین‌کنندهٔ ۶۱');
    $say('withdrawal', $wid);
    if ($wid > 0 && $verb === 'withdraw-and-pay') {
        // The FIXTURE, not the path under test: the request reaching Paid is
        // what makes the item «already paid» for the measurement below.
        $withdrawals->updateStatus($wid, WithdrawalStatus::Paid, $adminId, '', 'A61-PAID');
    }
    $say('withdrawal_status', $wid > 0 ? ($withdrawals->find($wid)?->status->value ?? '?') : '__none__');
    $say('item_withdrawal_id', (string) ($wpdb->get_var($wpdb->prepare(
        "SELECT withdrawal_id FROM `{$itemsTable}` WHERE id = %d",
        $lineA
    )) ?? '__null__'));
    $result = $c->get(RecordSupplierPayment::class)->record(
        TMC_P61_VENDOR,
        [$lineA],
        '900000',
        '2026-10-16',
        'A61-MANUAL-' . $verb,
        '',
        '',
        'a61-' . $verb
    );
    $say('manual_code', $result->code);
    $say('manual_ok', $result->ok);
    $say('manual_context', array_map(
        static fn ($k, $v): string => $k . ':' . (is_scalar($v) ? (string) $v : '?'),
        array_keys($result->context),
        $result->context
    ));
    $say('payment_rows_for_item', (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$payItemsTable}` WHERE order_item_id = %d",
        $lineA
    )));
    return;
}

if ($verb === 'pay-then-withdraw') {
    $lineC = $lineOf(TMC_P61_ITEM_C);
    if ($lineC <= 0) {
        $say('refused', 'no_line');
        return;
    }
    $result = $c->get(RecordSupplierPayment::class)->record(
        TMC_P61_VENDOR,
        [$lineC],
        '450000',
        '2026-10-16',
        'A61-MANUAL-FIRST',
        '',
        '',
        'a61-pay-first'
    );
    $say('manual_code', $result->code);
    $say('manual_payment_id', $result->context['payment_id'] ?? '__none__');
    $say('manual_posting', (string) ($wpdb->get_var($wpdb->prepare(
        "SELECT posting FROM `{$payTable}` WHERE id = %d",
        (int) ($result->context['payment_id'] ?? 0)
    )) ?? '__none__'));
    // Now the vendor's own withdrawal cycle, on the SAME item.
    $balance = $c->get(VendorBalance::class)->of(TMC_P61_VENDOR);
    $say('balance_eligible', $balance['eligible'] ?? '?');
    $say('balance_paid', $balance['paid'] ?? '?');
    $say('balance_eligible_has_c', in_array($lineC, $balance['eligible_line_ids'] ?? [], true));
    $wid = $c->get(WithdrawalRepositoryInterface::class)
        ->reserve(TMC_P61_VENDOR, [$lineC], 450000, 'IR000000000000000000000000', 'تأمین‌کنندهٔ ۶۱');
    $say('reserve_result', $wid);
    $say('item_withdrawal_id', (string) ($wpdb->get_var($wpdb->prepare(
        "SELECT withdrawal_id FROM `{$itemsTable}` WHERE id = %d",
        $lineC
    )) ?? '__null__'));
    return;
}

if ($verb === 'pay-null-share') {
    $lineA = $lineOf(TMC_P61_ITEM_A);
    $lineB = $lineOf(TMC_P61_ITEM_B);
    if ($lineA <= 0 || $lineB <= 0) {
        $say('refused', 'no_line');
        return;
    }
    $say('share_a', $orderItems->find($lineA)?->vendorShareMinor ?? '__null__');
    $say('share_b', $orderItems->find($lineB)?->vendorShareMinor ?? '__null__');
    // The payment equals item A's share alone. Item B's share is UNKNOWN.
    $result = $c->get(RecordSupplierPayment::class)->record(
        TMC_P61_VENDOR,
        [$lineA, $lineB],
        '900000',
        '2026-10-16',
        'A61-NULL-SHARE',
        '',
        '',
        'a61-null-share'
    );
    $say('code', $result->code);
    $say('ok', $result->ok);
    $paymentId = (int) ($result->context['payment_id'] ?? 0);
    $say('payment_id', $paymentId > 0 ? $paymentId : '__none__');
    $say('share_minor_recorded', $result->context['share_minor'] ?? '__none__');
    $say('ledger_event', ($result->context['ledger_event'] ?? '') === '' ? '__none__' : $result->context['ledger_event']);
    if ($paymentId > 0) {
        $say('db_posting', (string) ($wpdb->get_var($wpdb->prepare(
            "SELECT posting FROM `{$payTable}` WHERE id = %d",
            $paymentId
        )) ?? '__none__'));
        $say('db_ledger_event', (string) ($wpdb->get_var($wpdb->prepare(
            "SELECT ledger_event FROM `{$payTable}` WHERE id = %d",
            $paymentId
        )) ?: '__none__'));
        $say('ledger_lines', (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '`
              WHERE event_key = %s',
            RecordSupplierPayment::eventKey($paymentId)
        )));
        $say('item_shares_stored', array_map(
            static fn (array $r): string => $r['order_item_id'] . ':' . ($r['share_minor'] ?? '__null__'),
            $wpdb->get_results($wpdb->prepare(
                "SELECT order_item_id, share_minor FROM `{$payItemsTable}` WHERE payment_id = %d ORDER BY order_item_id",
                $paymentId
            ), ARRAY_A)
        ));
    }
    return;
}

if ($verb === 'pay-concurrent') {
    // TWO PROCESSES, one line. The child takes the order line `FOR UPDATE`
    // and holds it; this process then records a manual payment, whose own
    // `INSERT ... SELECT` has to read that very row — so it waits, and what
    // it does when it stops waiting is the measurement. No `sleep`: the child
    // lets go when a line arrives on the FIFO.
    $lineA = $lineOf(TMC_P61_ITEM_A);
    if ($lineA <= 0) {
        $say('refused', 'no_line');
        return;
    }
    $repo = (string) (getenv('TMC_REPO') ?: getcwd());
    $wpPath = (string) getenv('TMC_WP_PATH');
    $wpCli = (string) (getenv('TMC_WPCLI') ?: 'wp');
    if ($wpPath === '') {
        $say('absent', 'no_wp_path');
        return;
    }
    $fifo = sys_get_temp_dir() . '/tmc-a61-' . getmypid();
    @unlink($fifo);
    posix_mkfifo($fifo, 0600);
    $child = $repo . '/tests/Support/concurrent-supplier-payment.php';
    $cmd = 'php ' . escapeshellarg($wpCli) . ' --allow-root --path=' . escapeshellarg($wpPath)
        . ' eval-file ' . escapeshellarg($child) . ' hold ' . $lineA . ' ' . escapeshellarg($fifo)
        . ' > ' . escapeshellarg($fifo . '.out') . ' 2>&1 &';
    exec($cmd);
    // Wait for the child to SAY it is holding, rather than for a clock.
    $waited = 0;
    while ($waited < 200 && !str_contains((string) @file_get_contents($fifo . '.out'), 'holding=')) {
        usleep(50000);
        $waited++;
    }
    $say('child_holding', str_contains((string) @file_get_contents($fifo . '.out'), 'holding=') ? 'yes' : 'no');

    $start = microtime(true);
    $result = $c->get(RecordSupplierPayment::class)->record(
        TMC_P61_VENDOR,
        [$lineA],
        '900000',
        '2026-10-16',
        'A61-RACE',
        '',
        '',
        'a61-race'
    );
    $say('waited_ms', (int) round((microtime(true) - $start) * 1000));
    $say('code', $result->code);
    $say('ok', $result->ok);
    // Let the child go and read what it saw.
    $w = @fopen($fifo, 'w');
    if ($w !== false) {
        fwrite($w, "go\n");
        fclose($w);
    }
    usleep(300000);
    $say('child', str_replace("\n", '|', trim((string) @file_get_contents($fifo . '.out'))));
    @unlink($fifo);
    @unlink($fifo . '.out');
    $say('payment_rows_for_item', (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$payItemsTable}` WHERE order_item_id = %d",
        $lineA
    )));
    return;
}

if ($verb === 'report') {
    $say('schema', (int) get_option('tmc_schema_version', 0));
    $say('product', $findProduct());
    foreach ([TMC_P61_ITEM_A, TMC_P61_ITEM_B, TMC_P61_ITEM_C] as $wcItem) {
        $say('line_' . $wcItem, $lineOf($wcItem));
    }
    $say('payments', $payTable === '' ? '__absent__' : (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$payTable}`"
    ));
    return;
}

if ($verb === 'reset') {
    $wcId = $findProduct();
    if ($wcId > 0) {
        delete_post_meta($wcId, WpTectebCommissionSource::TYPE_META);
        delete_post_meta($wcId, WpTectebCommissionSource::VALUE_META);
    }
    $ids = [];
    foreach ([TMC_P61_ITEM_A, TMC_P61_ITEM_B, TMC_P61_ITEM_C] as $wcItem) {
        $id = $lineOf($wcItem);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if ($ids !== [] && $payItemsTable !== '') {
        $in = implode(',', array_map('intval', $ids));
        $payments = $wpdb->get_col("SELECT payment_id FROM `{$payItemsTable}` WHERE order_item_id IN ({$in})");
        $wpdb->query("DELETE FROM `{$payItemsTable}` WHERE order_item_id IN ({$in})");
        foreach (array_map('intval', $payments) as $pid) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '`
                  WHERE event_key = %s',
                RecordSupplierPayment::eventKey($pid)
            ));
            $wpdb->query($wpdb->prepare("DELETE FROM `{$payTable}` WHERE id = %d", $pid));
        }
    }
    // The withdrawals this fixture made, and the claim they left on the items.
    $wTable = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables::table(
        $db,
        \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables::WITHDRAWALS
    );
    $wLines = \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables::table(
        $db,
        \Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables::WITHDRAWAL_LINES
    );
    $wpdb->query($wpdb->prepare("UPDATE `{$itemsTable}` SET withdrawal_id = NULL WHERE vendor_user_id = %d", TMC_P61_VENDOR));
    $wids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `{$wTable}` WHERE vendor_user_id = %d", TMC_P61_VENDOR));
    foreach (array_map('intval', $wids) as $wid) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wLines}` WHERE withdrawal_id = %d", $wid));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wTable}` WHERE id = %d", $wid));
    }
    // The ledger and the order lines, so the next run starts where this one did.
    foreach ([TMC_P61_ITEM_A, TMC_P61_ITEM_B, TMC_P61_ITEM_C] as $wcItem) {
        $wpdb->query($wpdb->prepare(
            'DELETE FROM `' . M0004CreateFinanceTables::table($db, M0004CreateFinanceTables::LEDGER) . '`
              WHERE event_key = %s',
            'order:' . $wcItem
        ));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM `{$itemsTable}` WHERE vendor_user_id = %d", TMC_P61_VENDOR));
    $wpdb->query($wpdb->prepare(
        'DELETE FROM `' . M0022VendorMoneyUnit::table($db, M0022VendorMoneyUnit::UNITS) . '`
          WHERE vendor_user_id = %d',
        TMC_P61_VENDOR
    ));
    $say('reset', 'done');
    return;
}

$say('unknown_verb', $verb);
