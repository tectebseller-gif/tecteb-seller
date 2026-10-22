<?php

/**
 * MEASUREMENTS FOR THE `alpha.69` ROUND — loadable on BOTH trees.
 *
 * Run with `wp eval-file` against the DISPOSABLE install, on `alpha.68`'s
 * bytes first and on this checkout's second, so every sentence below is a
 * before/after pair rather than a reading of the new code.
 *
 * Everything this round adds is asked for with `method_exists()` /
 * `class_exists()` before it is used, because a probe that cannot LOAD on
 * the baseline reports nothing about it (`alpha.32`'s trap).
 *
 * Verbs:
 *   §1  split-arrange · split-one · split-two · split-totals
 *       split-divisible · split-unit-price · trial-on · trial-off
 *   §2  books-arrange · books-control · books-group-overflow
 *       books-sum-overflow · books-scale-overflow · books-most-negative
 *       books-two-currencies · books-report
 *   §3  rollback-hazard
 *   report · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;

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

/** This round's own namespace, so nothing collides with `alpha67-probe.php`. */
const TMC_P68_SPLIT = 'tmc-p68-split';
const TMC_P68_EVEN = 'tmc-p68-even';
const TMC_P68_BOOKS = 'tmc-p68-books';

/**
 * §2'S LEDGER SUBJECT — an id that owns NO ledger rows, on purpose.
 *
 * `balances()` sums every row of the account, so measuring the borrowed
 * shop would be measuring whatever earlier rounds left there: the first run
 * of this probe read `-60753416250` for a control whose own two rows add to
 * `1883750`, and it was RIGHT about that vendor. Writing to an id of our
 * own keeps the arithmetic the only variable, and `reset` owes nobody
 * anything because nothing else ever writes here.
 */
const TMC_P68_VENDOR = 990068;

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

// ⛔ NO REAL MAIL, SMS OR PAYMENT FROM A MEASUREMENT. WooCommerce sends an
// order email on `payment_complete()`; this disposable box has no sendmail,
// so nothing left it before either — but «nothing left» must be a property
// of the code, not of the box.
add_filter('pre_wp_mail', static fn (): bool => true, 9999);

global $wpdb;
$db = new WpDatabase($wpdb);
$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . ($value === '' || $value === null ? '__none__' : (string) $value) . "\n";
};

$items = $db->prefix() . 'tmc_order_items';
$ledger = $db->prefix() . 'tmc_ledger_entries';
$units = $db->prefix() . 'tmc_vendor_money_unit';
$returnsTable = $db->prefix() . 'tmc_returns';

/** An EXISTING approved, sellable shop — borrowed, so `reset` owes nothing. */
$vendorId = static function () use ($db, $say): int {
    $id = (int) ($db->getVar(
        'SELECT v.user_id FROM `' . $db->prefix() . 'tmc_vendor_profiles` v
          INNER JOIN `' . $db->prefix() . 'users` u ON u.ID = v.user_id
          INNER JOIN `' . $db->prefix() . 'tmc_vendor_applications` a
                  ON a.user_id = v.user_id AND a.status = %s
          WHERE v.can_sell = 1 ORDER BY v.id DESC LIMIT 1',
        ['approved']
    ) ?? 0);
    if ($id === 0) {
        $say('fixture', '__no_sellable_shop__');
    }
    return $id;
};

$ledgerRepo = $c->get(\Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface::class);

/**
 * `balances()` for one account, printed the same way on both trees.
 *
 * `alpha.68` answers with an array too, so the SHAPE is not what separates
 * the two; the figures are. A refused answer must carry no readable `minor`
 * and must never say `exact`.
 */
$reportAccount = static function (string $label, int $vendor, string $account) use ($ledgerRepo, $say): void {
    $figure = $ledgerRepo->balances($vendor)[$account] ?? null;
    if (!is_array($figure)) {
        $say($label . '_shape', $figure === null ? '__no_row__' : gettype($figure));
        if (is_int($figure)) {
            $say($label . '_minor', (string) $figure);
        }
        return;
    }
    $say($label . '_minor', array_key_exists('minor', $figure)
        ? ($figure['minor'] === null ? '__null__' : (string) $figure['minor'])
        : '__no_key__');
    $say($label . '_exponent', $figure['exponent'] === null ? '__null__' : (string) $figure['exponent']);
    $say($label . '_exact', array_key_exists('exact', $figure)
        ? ($figure['exact'] ? 'yes' : 'no')
        : '__no_key__');
    $say($label . '_refused', (string) ($figure['refused'] ?? ''));
    $say($label . '_groups', (string) count((array) ($figure['groups'] ?? [])));
};

/** Writes one ledger row, at a named unit, under this round's event family. */
$writeRow = static function (
    string $suffix,
    int $vendor,
    string $account,
    string $amount,
    string $currency,
    int $exponent
) use ($db, $ledger): void {
    $db->execute(
        'INSERT INTO `' . $ledger . '`
         (event_key, vendor_user_id, account, amount_minor, currency, exponent,
          order_ref, item_ref, reason, reverses_entry_id, snapshot, created_at)
         VALUES (%s, %d, %s, ' . $amount . ', %s, %d, %s, %s, %s, NULL, %s, %s)',
        [
            TMC_P68_BOOKS . '-' . $suffix, $vendor, $account, $currency, $exponent,
            TMC_P68_BOOKS, '', 'probe', '{}', gmdate('Y-m-d H:i:s'),
        ]
    );
};

/**
 * TAKES BACK ONE FIXTURE ORDER — rows, returns, ledger lines and the post.
 *
 * ⛔ Needed because `reset` reads the order ids from OPTIONS, so a run that
 * dies before its reset leaves an order the next run cannot see: its item
 * row and three exponent-4 ledger lines stay, and the next capture on this
 * borrowed shop reads a mixed ledger. Measured once, in this round's own
 * browser pass — order 876 survived a crashed run and the sweep that is
 * supposed to catch strays only looks at orders whose POST is gone, which
 * that one was not. So the fixture gives an order back BEFORE it takes
 * another, as well as at the end.
 */
$forgetOrder = static function (int $orderId) use ($db, $items, $ledger, $returnsTable): void {
    if ($orderId <= 0) {
        return;
    }
    foreach ($db->getResults(
        'SELECT id, ledger_event FROM `' . $items . '` WHERE wc_order_id = %d',
        [$orderId]
    ) as $row) {
        $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_shipments` WHERE order_item_id = %d', [(int) $row['id']]);
        $db->execute('DELETE FROM `' . $returnsTable . '` WHERE order_item_id = %d', [(int) $row['id']]);
        $event = (string) $row['ledger_event'];
        if ($event !== '') {
            $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
            $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', [$event . ':%']);
        }
    }
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_id = %d', [$orderId]);
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', ['order:' . $orderId . ':%']);
    if (function_exists('wp_delete_post') && get_post_status($orderId) !== false) {
        wp_delete_post($orderId, true);
    }
};

/** Clears only this round's own ledger rows. */
$clearBooks = static function () use ($db, $ledger): void {
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', [TMC_P68_BOOKS . '-%']);
};

// =========================================================================
// §2 — OVERFLOW IN THE LEDGER SUM
// =========================================================================

if ($verb === 'books-arrange') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $say('vendor_user_id', $vendor);
    $say('pre_existing_rows', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $ledger . '` WHERE vendor_user_id = %d',
        [$vendor]
    ) ?? '__unreadable__'));
    $say('php_int_max', (string) PHP_INT_MAX);
    $say('php_int_min', (string) PHP_INT_MIN);
    $say('rows_cleared', 'yes');
    return;
}

/**
 * THE CONTROL, and it must agree on both trees: ۱۰۰ تومان at e0 plus
 * ۸۸٫۳۷۵ تومان at e4 is exactly ۱۸۸٫۳۷۵ — `1883750` at e4.
 */
if ($verb === 'books-control') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('c1', $vendor, 'vendor_earning', '100', 'IRT', 0);
    $writeRow('c2', $vendor, 'vendor_earning', '883750', 'IRT', 4);
    $reportAccount('control', $vendor, 'vendor_earning');
    $say('control_expected_minor', '1883750');
    $say('control_expected_exponent', '4');
    $clearBooks();
    return;
}

/**
 * ONE GROUP'S OWN `SUM()` WIDER THAN A PHP INTEGER.
 *
 * MySQL sums `BIGINT` into an exact DECIMAL, so the database answers
 * `18446744073709551614` truthfully. `alpha.68` cast that text to `int`,
 * and a cast SATURATES: it becomes `PHP_INT_MAX` and is then reported as an
 * `exact` balance — a figure that looks like an answer and is not one.
 */
if ($verb === 'books-group-overflow') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('g1', $vendor, 'vendor_earning', (string) PHP_INT_MAX, 'IRT', 4);
    $writeRow('g2', $vendor, 'vendor_earning', (string) PHP_INT_MAX, 'IRT', 4);
    $say('db_sum', (string) ($db->getVar(
        'SELECT CAST(SUM(amount_minor) AS CHAR) FROM `' . $ledger . '`
          WHERE vendor_user_id = %d AND account = %s AND event_key LIKE %s',
        [$vendor, 'vendor_earning', TMC_P68_BOOKS . '-%']
    ) ?? '__unreadable__'));
    $say('saturating_cast_would_say', (string) ((int) '18446744073709551614'));
    try {
        $reportAccount('group', $vendor, 'vendor_earning');
    } catch (\Throwable $e) {
        $say('group_error', $e::class . ':' . $e->getMessage());
    }
    $clearBooks();
    return;
}

/**
 * EACH GROUP FITS, THE TOTAL DOES NOT.
 *
 * `alpha.68` guarded the multiplication and not the addition of the
 * products, so two groups that each fit were added past the top of the
 * range and the sum became a float — printed as a figure with no warning.
 */
if ($verb === 'books-sum-overflow') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('s1', $vendor, 'vendor_earning', (string) (PHP_INT_MAX - 10), 'IRT', 4);
    $writeRow('s2', $vendor, 'vendor_earning', '5', 'IRT', 3);
    $say('group_e4', (string) (PHP_INT_MAX - 10));
    $say('group_e3_rescaled_to_e4', '50');
    try {
        $reportAccount('sum', $vendor, 'vendor_earning');
    } catch (\Throwable $e) {
        $say('sum_error', $e::class . ':' . $e->getMessage());
    }
    $clearBooks();
    return;
}

/** RESCALING ONE GROUP leaves the range — the multiplication, guarded. */
if ($verb === 'books-scale-overflow') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('x1', $vendor, 'vendor_earning', (string) PHP_INT_MAX, 'IRT', 0);
    $writeRow('x2', $vendor, 'vendor_earning', '1', 'IRT', 4);
    $say('coarse_group', (string) PHP_INT_MAX);
    $say('factor_to_finest', '10000');
    try {
        $reportAccount('scale', $vendor, 'vendor_earning');
    } catch (\Throwable $e) {
        $say('scale_error', $e::class . ':' . $e->getMessage());
    }
    $clearBooks();
    return;
}

/**
 * THE MOST NEGATIVE AMOUNT. `abs(PHP_INT_MIN)` is a FLOAT, so
 * `intdiv(int, abs(...))` is a `TypeError` — `alpha.68` does not refuse
 * here, it stops the page.
 */
if ($verb === 'books-most-negative') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('n1', $vendor, 'vendor_earning', (string) PHP_INT_MIN, 'IRT', 0);
    $writeRow('n2', $vendor, 'vendor_earning', '1', 'IRT', 4);
    try {
        $reportAccount('negative', $vendor, 'vendor_earning');
        $say('negative_error', '');
    } catch (\Throwable $e) {
        $say('negative_error', $e::class);
        $say('negative_message', $e->getMessage());
    }
    $clearBooks();
    return;
}

/** TWO CURRENCIES must not be summed at all — a control, in both trees. */
if ($verb === 'books-two-currencies') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('t1', $vendor, 'vendor_earning', '100', 'IRT', 0);
    $writeRow('t2', $vendor, 'vendor_earning', '500', 'USD', 2);
    $figure = $ledgerRepo->balances($vendor)['vendor_earning'] ?? null;
    $say('two_currencies_refused', is_array($figure) ? (string) ($figure['refused'] ?? '') : '__not_array__');
    $say('two_currencies_minor', is_array($figure)
        ? (($figure['minor'] ?? null) === null ? '__null__' : (string) $figure['minor'])
        : '__not_array__');
    $say('two_currencies_names', is_array($figure) ? (string) ($figure['currencies'] ?? '') : '');
    $clearBooks();
    return;
}

/**
 * THE CONSUMER. A refused total has to reach the human comparing two
 * columns, or the refusal only protects the repository.
 */
if ($verb === 'books-report') {
    $vendor = TMC_P68_VENDOR;
    $clearBooks();
    $writeRow('r1', $vendor, 'vendor_earning', (string) PHP_INT_MAX, 'IRT', 4);
    $writeRow('r2', $vendor, 'vendor_earning', (string) PHP_INT_MAX, 'IRT', 4);
    $reconcile = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ReconcileDokanFinance::class);
    $codes = [];
    $printed = '__none__';
    try {
        $report = $reconcile->reportFrom($reconcile->snapshotFor($vendor));
        foreach (($report['differences'] ?? []) as $difference) {
            $codes[] = (string) ($difference['code'] ?? '?');
        }
        $figure = (($report['marketplace'] ?? [])['ledger_balances'] ?? [])['vendor_earning'] ?? null;
        if (is_array($figure)) {
            $printed = ($figure['minor'] ?? null) === null ? '__null__' : (string) $figure['minor'];
        }
        $say('report_error', '');
    } catch (\Throwable $e) {
        $say('report_error', $e::class . ':' . $e->getMessage());
    }
    $say('report_codes', implode(',', $codes));
    $say('report_names_not_summable', (bool) in_array('marketplace_account_not_summable', $codes, true));
    $say('report_printed_minor', $printed);
    $clearBooks();
    return;
}

// =========================================================================
// §1 — THE INEXACT PARTIAL RETURN
// =========================================================================

$product = static function (string $slug, int $author, string $title): int {
    $existing = get_page_by_path($slug, OBJECT, 'product');
    if ($existing instanceof \WP_Post) {
        return (int) $existing->ID;
    }
    $id = wp_insert_post([
        'post_type' => 'product',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_author' => $author,
    ], true);
    if (is_wp_error($id)) {
        return 0;
    }
    $id = (int) $id;
    // THE PRODUCT TYPE IS A TERM, and the intake reads it: a post with no
    // `product_type` is not a product WooCommerce will hand over, so the
    // marketplace row is never written and the capture then refuses the
    // line as «not ours» — a true sentence about the wrong thing.
    wp_set_object_terms($id, 'simple', 'product_type');
    update_post_meta($id, '_sku', strtoupper($slug));
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '50');
    update_post_meta($id, '_stock_status', 'instock');
    update_post_meta($id, '_regular_price', '101');
    update_post_meta($id, '_price', '101');
    update_post_meta($id, '_tmc_commission_type', 'percent');
    update_post_meta($id, '_tmc_commission_value', '12.50');
    return $id;
};

if ($verb === 'trial-on' || $verb === 'trial-off') {
    // THE OPERATIONAL KEY, LEFT IN A KNOWN STATE (`alpha.66`'s rule). This
    // is the plugin's own local key; no production gate is opened.
    update_option('tmc_order_trial_mode', $verb === 'trial-on' ? '1' : '', false);
    $say('trial_mode', (string) get_option('tmc_order_trial_mode', ''));
    $say('environment', (string) wp_get_environment_type());
    return;
}

/**
 * Builds a REAL WooCommerce order with the owner's own line: three units,
 * ۱۰۱ تومان in total, ۱۲٫۵٪ commission. `$slug === TMC_P68_EVEN` builds the
 * divisible control instead (three units, ۳۰۰ تومان).
 */
$arrange = static function (string $slug, string $total, string $optionPrefix)
    use ($c, $db, $say, $vendorId, $product, $adminId, $items, $forgetOrder): void {
    $gate = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate::class);
    $answer = $gate->check();
    $say('gate_ready', (bool) ($answer['ready'] ?? false));
    $say('gate_reason', (string) ($answer['reason'] ?? ''));
    if (($answer['ready'] ?? false) !== true) {
        $say('order_created', 'no');
        return;
    }
    $vendor = $vendorId();
    $wcId = $product($slug, $vendor, 'آزمون ' . $slug);
    if ($wcId === 0 || !function_exists('wc_create_order')) {
        $say('fixture', '__absent__');
        return;
    }
    $intake = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\ImportExistingProducts::class);
    $runId = $intake->newRunId();
    $intake->importBatch($runId, [$wcId], 0, 5);
    $rowId = (int) ($db->getVar(
        'SELECT id FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
        [$wcId]
    ) ?? 0);
    if ($rowId > 0) {
        $transfer = $c->get(\Tecteb\Marketplace\Modules\Migration\Application\TransferOwnership::class);
        $seen = [];
        foreach ($transfer->storefrontDrift($rowId) as $field => $pair) {
            $seen[$field] = (string) $pair['fingerprint'];
        }
        $say('take_code', $transfer->take($rowId, array_keys($transfer->risksFor($rowId)), $seen)->code);
        $c->get(\Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface::class)
            ->updateStatus($rowId, \Tecteb\Marketplace\Modules\Product\Domain\ProductStatus::Published);
    }
    $say('marketplace_row', $rowId === 0 ? '__none__' : $rowId);
    // THE PREVIOUS ORDER GOES BACK FIRST, and only then the ids: a failed
    // capture must not leave the previous run's item id behind for the next
    // verb to measure (`alpha.33`), and a crashed run must not leave its
    // order behind for the next RESET to miss.
    $forgetOrder((int) get_option($optionPrefix . '_order', '0'));
    delete_option($optionPrefix . '_order');
    delete_option($optionPrefix . '_item');
    $order = wc_create_order(['customer_id' => $adminId]);
    if (is_wp_error($order)) {
        $say('order_not_created', $order->get_error_code());
        return;
    }
    $order->add_product(wc_get_product($wcId), 3);
    $order->calculate_totals();
    foreach ($order->get_items() as $lineItem) {
        $lineItem->set_total($total);
        $lineItem->set_subtotal($total);
        $lineItem->save();
    }
    $order->set_total($total);
    $order->save();
    $orderId = (int) $order->get_id();
    update_option($optionPrefix . '_order', (string) $orderId, false);
    $say('order_id', $orderId);
    $say('order_total', (string) $order->get_total());
    $say('order_currency', (string) $order->get_currency());
    $order->payment_complete();
    do_action('woocommerce_checkout_order_processed', $orderId, [], $order);
    $repo = $c->get(\Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface::class);
    $captured = $repo->forOrder($orderId);
    if ($captured === []) {
        $say('item_id', '__none__');
        $say('capture', '__failed__');
        return;
    }
    foreach ($captured as $line) {
        update_option($optionPrefix . '_item', (string) $line->id, false);
        $say('item_id', $line->id);
        $say('item_quantity', $line->quantity);
        $say('item_base_minor', (string) $line->baseMinor);
        $say('item_commission_minor', (string) ($line->commissionMinor ?? '__none__'));
        $say('item_share_minor', (string) ($line->vendorShareMinor ?? '__none__'));
        $say('item_exponent', $line->exponent === null ? '__none__' : $line->exponent);
        $say('item_unit_price_minor', $line->unitPriceMinor === null ? '__null__' : (string) $line->unitPriceMinor);
        $say('base_divides_exactly', (bool) ($line->baseMinor % max(1, $line->quantity) === 0));
    }
};

if ($verb === 'split-arrange') {
    $arrange(TMC_P68_SPLIT, '101', 'tmc_p68_split');
    return;
}

if ($verb === 'even-arrange') {
    $arrange(TMC_P68_EVEN, '300', 'tmc_p68_even');
    return;
}

/**
 * ONE RETURN OF A NAMED QUANTITY, from `open` to the money — every step
 * reported separately, and the money step only ATTEMPTED when accepting the
 * goods passed, because «invalid_transition» afterwards would be a true
 * sentence about the wrong thing (`alpha.65`).
 */
$returnOne = static function (string $optionPrefix, int $quantity, string $label)
    use ($c, $db, $say, $vendorId, $adminId, $items, $returnsTable, $ledger): void {
    $itemId = (int) get_option($optionPrefix . '_item', '0');
    if ($itemId === 0) {
        $say($label . '_item', '__absent__');
        return;
    }
    $vendor = $vendorId();
    $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
    $opened = $returns->open($vendor, $vendor, $itemId, $quantity, 'آزمون تخصیص', '');
    $say($label . '_open_code', (string) $opened->code);
    $returnId = (int) ($opened->context['return_id'] ?? 0);
    if ($returnId === 0) {
        $say($label . '_return_id', '__none__');
        return;
    }
    $say($label . '_return_id', $returnId);
    $request_item_id = $itemId;
    $say($label . '_approve_code', (string) $returns->decide($adminId, $returnId, ReturnStatus::Approved)->code);
    $received = $returns->decide($adminId, $returnId, ReturnStatus::Received);
    $say($label . '_receive_code', (string) $received->code);
    if (!$received->ok) {
        foreach (['quantity', 'line_quantity', 'base_minor', 'commission_minor', 'parts'] as $key) {
            if (array_key_exists($key, $received->context)) {
                $say($label . '_refusal_' . $key, (string) $received->context[$key]);
            }
        }
        $say($label . '_refund_code', '__not_attempted__');
        $say($label . '_refusal_step', 'received');
        $row = $db->getRow('SELECT status FROM `' . $returnsTable . '` WHERE id = %d', [$returnId]);
        $say($label . '_status', $row === null ? '__absent__' : (string) $row['status']);
        return;
    }
    $say($label . '_refusal_step', '__none__');
    $result = $returns->refund($adminId, $returnId, null, 'آزمون');
    $say($label . '_refund_code', (string) $result->code);
    foreach (['refund_minor', 'tax_minor', 'currency', 'exponent'] as $key) {
        $say($label . '_declared_' . $key, (string) ($result->context[$key] ?? '__no_key__'));
    }
    $row = $db->getRow(
        'SELECT refund_minor, tax_refund_minor, commission_reversed_minor,
                vendor_share_reversed_minor, status, `tmc_exponent` AS e
           FROM `' . $returnsTable . '` WHERE id = %d',
        [$returnId]
    );
    if ($row === null) {
        $say($label . '_row', '__absent__');
        return;
    }
    $say($label . '_status', (string) $row['status']);
    $say($label . '_stored_exponent', $row['e'] === null ? '__null__' : (string) $row['e']);
    foreach (['refund_minor', 'tax_refund_minor', 'commission_reversed_minor', 'vendor_share_reversed_minor'] as $key) {
        $say($label . '_' . $key, $row[$key] === null ? '__null__' : (string) $row[$key]);
    }
    // THE SHARE IS DERIVED, never rounded on its own: base back minus
    // commission back, to the last digit.
    $say($label . '_share_is_derived', (bool) (
        (int) $row['vendor_share_reversed_minor']
        === (int) $row['refund_minor'] - (int) $row['commission_reversed_minor']
    ));
    // AND THE REVERSAL LINES, all in one unit and summing to nothing.
    // THE KEY IS `return:<item>:<return>` — read from the code that writes
    // it, not guessed: a prefix that matches nothing counts nought lines and
    // reads exactly like a reversal that was never written.
    $event = 'return:' . (string) $request_item_id . ':' . (string) $returnId;
    $lines = $db->getResults(
        'SELECT account, amount_minor, currency, exponent FROM `' . $ledger . '`
          WHERE event_key = %s ORDER BY account',
        [$event]
    );
    $say($label . '_ledger_lines', (string) count($lines));
    $sum = 0;
    $exponents = [];
    foreach ($lines as $line) {
        $sum += (int) $line['amount_minor'];
        $exponents[(string) $line['exponent']] = true;
    }
    $say($label . '_ledger_sum', (string) $sum);
    $say($label . '_ledger_exponents', implode(',', array_keys($exponents)));
    // THE SECOND PRESS: idempotent, and no duplicate document.
    $say($label . '_second_press', (string) $returns->refund($adminId, $returnId, null, 'آزمون')->code);
    $say($label . '_rows_for_return', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $ledger . '` WHERE event_key = %s',
        [$event]
    ) ?? '__unreadable__'));
};

/**
 * OPEN AND APPROVE, AND LEAVE IT THERE — for the browser pass.
 *
 * The note and the two buttons live on an APPROVED return, so a browser
 * that is going to press them needs one waiting. This verb writes nothing
 * the money path has not already been measured doing, and `reset` takes it
 * back with the rest of the fixture.
 */
if ($verb === 'split-hold') {
    $itemId = (int) get_option('tmc_p68_split_item', '0');
    if ($itemId === 0) {
        $say('hold', '__no_item__');
        return;
    }
    $vendor = $vendorId();
    $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
    $returnId = (int) ($db->getVar(
        'SELECT id FROM `' . $returnsTable . '` WHERE order_item_id = %d AND status = %s
          ORDER BY id DESC LIMIT 1',
        [$itemId, 'approved']
    ) ?? 0);
    if ($returnId === 0) {
        $opened = $returns->open($vendor, $vendor, $itemId, 1, 'آزمون مرورگری', '');
        $say('hold_open_code', (string) $opened->code);
        $returnId = (int) ($opened->context['return_id'] ?? 0);
        if ($returnId === 0) {
            $say('hold', '__not_opened__');
            return;
        }
        $say('hold_approve_code', (string) $returns->decide($adminId, $returnId, ReturnStatus::Approved)->code);
    }
    $say('hold_return_id', $returnId);
    $say('hold_item_id', $itemId);
    $say('hold_status', (string) ($db->getVar(
        'SELECT status FROM `' . $returnsTable . '` WHERE id = %d',
        [$returnId]
    ) ?? '__absent__'));
    return;
}

if ($verb === 'split-one') {
    $returnOne('tmc_p68_split', 1, 'one');
    return;
}

if ($verb === 'split-two') {
    $returnOne('tmc_p68_split', 2, 'two');
    return;
}

if ($verb === 'even-one') {
    $returnOne('tmc_p68_even', 1, 'even');
    return;
}

/** The arithmetic the owner named: the parts must close on the document. */
$totalsFor = static function (string $optionPrefix, string $label)
    use ($db, $say, $items, $returnsTable): void {
    $itemId = (int) get_option($optionPrefix . '_item', '0');
    if ($itemId === 0) {
        $say($label . '_item', '__absent__');
        return;
    }
    $line = $db->getRow(
        'SELECT quantity, base_minor, tax_minor, commission_minor, vendor_share_minor
           FROM `' . $items . '` WHERE id = %d',
        [$itemId]
    );
    $totals = $db->getRow(
        'SELECT COALESCE(SUM(quantity), 0) AS quantity,
                COALESCE(SUM(refund_minor), 0) AS refund,
                COALESCE(SUM(tax_refund_minor), 0) AS tax,
                COALESCE(SUM(commission_reversed_minor), 0) AS commission,
                COALESCE(SUM(vendor_share_reversed_minor), 0) AS share
           FROM `' . $returnsTable . '` WHERE order_item_id = %d AND status = %s',
        [$itemId, 'refunded']
    );
    $say($label . '_line_quantity', (string) ($line['quantity'] ?? '__none__'));
    $say($label . '_line_base', (string) ($line['base_minor'] ?? '__none__'));
    $say($label . '_line_commission', (string) ($line['commission_minor'] ?? '__none__'));
    $say($label . '_line_share', (string) ($line['vendor_share_minor'] ?? '__none__'));
    $say($label . '_returned_quantity', (string) ($totals['quantity'] ?? '__none__'));
    $say($label . '_returned_base', (string) ($totals['refund'] ?? '__none__'));
    $say($label . '_returned_commission', (string) ($totals['commission'] ?? '__none__'));
    $say($label . '_returned_share', (string) ($totals['share'] ?? '__none__'));
    $closes = (int) ($totals['quantity'] ?? 0) === (int) ($line['quantity'] ?? -1);
    $say($label . '_whole_line_returned', (bool) $closes);
    if (!$closes) {
        return;
    }
    $say($label . '_base_closes', (bool) ((int) $totals['refund'] === (int) $line['base_minor']));
    $say($label . '_commission_closes', (bool) ((int) $totals['commission'] === (int) $line['commission_minor']));
    $say($label . '_share_closes', (bool) ((int) $totals['share'] === (int) $line['vendor_share_minor']));
};

if ($verb === 'split-totals') {
    $totalsFor('tmc_p68_split', 'split');
    return;
}

if ($verb === 'even-totals') {
    $totalsFor('tmc_p68_even', 'even');
    return;
}

/** The per-unit column, UNCHANGED this round — read so that is visible. */
if ($verb === 'split-unit-price') {
    $itemId = (int) get_option('tmc_p68_split_item', '0');
    $row = $itemId === 0 ? null : $db->getRow(
        'SELECT quantity, unit_price_minor, base_minor FROM `' . $items . '` WHERE id = %d',
        [$itemId]
    );
    if ($row === null) {
        $say('split_item', '__absent__');
        return;
    }
    $quantity = max(1, (int) $row['quantity']);
    $say('unit_price_minor', $row['unit_price_minor'] === null ? '__null__' : (string) $row['unit_price_minor']);
    $say('base_minor', (string) $row['base_minor']);
    $say('division_is_exact', (bool) ((int) $row['base_minor'] % $quantity === 0));
    return;
}

/**
 * WHAT THE SCREEN IS TOLD BEFORE THE GOODS ARE ACCEPTED.
 *
 * The note sits on an APPROVED return, next to the «دریافت‌شده» button, so
 * this verb opens one, approves it, reads the sentence's figures and then
 * CANCELS it — `approved → cancelled` is the panel's own way back, and
 * taking it here means the verb spends nothing the later ones need.
 */
if ($verb === 'split-screen') {
    $itemId = (int) get_option('tmc_p68_split_item', '0');
    if ($itemId === 0) {
        $say('screen', '__no_item__');
        return;
    }
    $vendor = $vendorId();
    $returns = $c->get(\Tecteb\Marketplace\Modules\Order\Application\ManageReturns::class);
    $returnId = (int) ($db->getVar(
        'SELECT id FROM `' . $returnsTable . '` WHERE order_item_id = %d
          AND status NOT IN (%s, %s, %s) ORDER BY id DESC LIMIT 1',
        [$itemId, 'refunded', 'rejected', 'cancelled']
    ) ?? 0);
    $mine = false;
    if ($returnId === 0) {
        $opened = $returns->open($vendor, $vendor, $itemId, 1, 'آزمون جملهٔ صفحه', '');
        $say('screen_open_code', (string) $opened->code);
        $returnId = (int) ($opened->context['return_id'] ?? 0);
        $mine = $returnId > 0;
    }
    if ($returnId === 0) {
        $say('screen', '__no_open_return__');
        return;
    }
    $say('screen_approve_code', (string) $returns->decide($adminId, $returnId, ReturnStatus::Approved)->code);
    $answer = $returns->splitExactness($returnId);
    foreach (['exact', 'quantity', 'line_quantity', 'exponent', 'base_minor', 'commission_minor'] as $key) {
        $say('screen_' . $key, array_key_exists($key, $answer)
            ? (is_bool($answer[$key]) ? ($answer[$key] ? 'yes' : 'no') : (string) ($answer[$key] ?? '__null__'))
            : '__no_key__');
    }
    foreach ((array) ($answer['remainder'] ?? []) as $part => $value) {
        $say('screen_remainder_' . (string) $part, (string) $value);
    }
    $say('screen_has_remainder_key', (bool) array_key_exists('remainder', $answer));
    $say('screen_last_of_line', array_key_exists('last_of_line', $answer)
        ? ($answer['last_of_line'] ? 'yes' : 'no')
        : '__no_key__');
    if ($mine) {
        // GIVEN BACK: the quantity this held is released, so the verbs that
        // follow measure the line and not this verb's leftovers.
        $say('screen_cancelled', (string) $returns->decide($adminId, $returnId, ReturnStatus::Cancelled)->code);
    }
    return;
}

// =========================================================================
// §3 — THE ROLLBACK GATE, UNCHANGED IN BEHAVIOUR
// =========================================================================

if ($verb === 'rollback-hazard') {
    $gateClass = \Tecteb\Marketplace\Modules\Finance\Application\MoneyScaleRollbackGate::class;
    $say('has_rollback_gate', (bool) class_exists($gateClass));
    if (!class_exists($gateClass)) {
        $say('verdict', '__nothing_answers_this__');
        return;
    }
    $gate = new $gateClass($db);
    $answer = $gate->check();
    foreach (['safe', 'verdict', 'reason'] as $key) {
        if (array_key_exists($key, $answer)) {
            $say('gate_' . $key, $answer[$key]);
        }
    }
    foreach (($answer['counts'] ?? []) as $table => $count) {
        $say('finer_rows_' . (string) $table, (string) $count);
    }
    $say('gate_counts_tables', (string) count((array) ($answer['counts'] ?? [])));
    $say('has_loss_since', (bool) method_exists($gateClass, 'lossSince'));
    return;
}

// =========================================================================
if ($verb === 'report') {
    $vendor = $vendorId();
    $say('vendor_user_id', $vendor);
    $say('schema_version', (string) get_option('tmc_schema_version', '__none__'));
    $say('plugin_version', (string) (get_file_data(
        WP_PLUGIN_DIR . '/tecteb-marketplace-core/tecteb-marketplace-core.php',
        ['Version' => 'Version']
    )['Version'] ?? '__none__'));
    $say('has_return_allocation', (bool) class_exists(
        \Tecteb\Marketplace\Modules\Order\Domain\ReturnAllocation::class
    ));
    $say('fixture_item_rows', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE wc_order_id IN (%d, %d)',
        [(int) get_option('tmc_p68_split_order', '0'), (int) get_option('tmc_p68_even_order', '0')]
    ) ?? '__unreadable__'));
    $say('fixture_ledger_rows', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $ledger . '` WHERE event_key LIKE %s',
        [TMC_P68_BOOKS . '-%']
    ) ?? '__unreadable__'));
    return;
}

// =========================================================================
if ($verb === 'reset') {
    /** @var callable(int):void $forgetOrder — defined above, used here too. */
    // GIVES BACK EVERYTHING THIS FILE SPENT — rows first, then the ledger
    // lines that hang off them, then the posts. `alpha.68`'s own lesson,
    // paid for twice: one leftover exponent-4 row makes every later capture
    // on this borrowed shop `books_mixed`, and an orphaned return makes the
    // rollback gate say `blocked` — both of them TRUE answers about a
    // database this fixture dirtied.
    $vendor = $vendorId();
    $orders = [
        (int) get_option('tmc_p68_split_order', '0'),
        (int) get_option('tmc_p68_even_order', '0'),
    ];
    $orders = array_values(array_filter($orders, static fn (int $id): bool => $id > 0));
    $clearBooks();
    foreach ($orders as $orderId) {
        $forgetOrder($orderId);
    }
    if ($orders !== []) {
        $placeholders = implode(',', array_fill(0, count($orders), '%d'));
        $events = array_map(
            static fn (array $r): string => (string) $r['ledger_event'],
            $db->getResults(
                'SELECT ledger_event FROM `' . $items . '`
                  WHERE wc_order_id IN (' . $placeholders . ') AND ledger_event <> %s',
                [...$orders, '']
            )
        );
        foreach (array_map(
            static fn (array $r): int => (int) $r['id'],
            $db->getResults('SELECT id FROM `' . $items . '` WHERE wc_order_id IN (' . $placeholders . ')', $orders)
        ) as $id) {
            $db->execute('DELETE FROM `' . $db->prefix() . 'tmc_shipments` WHERE order_item_id = %d', [$id]);
            $db->execute('DELETE FROM `' . $returnsTable . '` WHERE order_item_id = %d', [$id]);
        }
        $say('item_rows_removed', (string) ($db->execute(
            'DELETE FROM `' . $items . '` WHERE wc_order_id IN (' . $placeholders . ')',
            $orders
        ) ?? '__failed__'));
        foreach ($events as $event) {
            $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key = %s', [$event]);
            $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', [$event . ':%']);
        }
    }
    $db->execute('DELETE FROM `' . $ledger . '` WHERE event_key LIKE %s', ['return:%']);
    // A RETURN WHOSE ORDER ITEM IS ALREADY GONE — deleted BY `order_item_id`
    // above, from a list read a moment earlier, so an earlier cycle's return
    // is not in it and survives carrying `tmc_exponent = 4`.
    $say('orphan_returns_removed', (string) ($db->execute(
        'DELETE r FROM `' . $returnsTable . '` r
           LEFT JOIN `' . $items . '` i ON i.id = r.order_item_id
          WHERE r.vendor_user_id = %d AND i.id IS NULL',
        [$vendor]
    ) ?? '__failed__'));
    // AND THE LEDGER ROWS OF ORDERS THAT NO LONGER EXIST — only where the
    // order POST is gone, so nothing of a live order is touched.
    foreach ($db->getResults(
        'SELECT DISTINCT order_ref FROM `' . $ledger . '`
          WHERE vendor_user_id = %d AND event_key LIKE %s',
        [$vendor, 'order:%']
    ) as $row) {
        $orderRef = (int) $row['order_ref'];
        if ($orderRef <= 0 || get_post_status($orderRef) !== false) {
            continue;
        }
        $db->execute(
            'DELETE FROM `' . $ledger . '` WHERE vendor_user_id = %d AND order_ref = %s',
            [$vendor, (string) $orderRef]
        );
        $say('swept_ledger_for_order', (string) $orderRef);
    }
    foreach ($orders as $orderId) {
        if (function_exists('wp_delete_post')) {
            wp_delete_post($orderId, true);
        }
    }
    foreach ([TMC_P68_SPLIT, TMC_P68_EVEN] as $slug) {
        $post = get_page_by_path($slug, OBJECT, 'product');
        if ($post instanceof \WP_Post) {
            $db->execute(
                'DELETE FROM `' . $db->prefix() . 'tmc_products` WHERE wc_product_id = %d',
                [(int) $post->ID]
            );
            wp_delete_post((int) $post->ID, true);
            $say('product_deleted', (int) $post->ID);
        }
    }
    delete_option('tmc_p68_split_order');
    delete_option('tmc_p68_split_item');
    delete_option('tmc_p68_even_order');
    delete_option('tmc_p68_even_item');
    $say('exponents_left_for_vendor', implode(',', array_map(
        static fn (array $r): string => (string) $r['exponent'],
        $db->getResults(
            'SELECT DISTINCT exponent FROM `' . $ledger . '` WHERE vendor_user_id = %d ORDER BY exponent',
            [$vendor]
        )
    )));
    $say('reset', 'done');
    return;
}

$say('unknown_verb', $verb);
