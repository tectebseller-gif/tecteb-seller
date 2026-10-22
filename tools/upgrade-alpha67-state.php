<?php
/**
 * The state verbs the `alpha.67 → alpha.68` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so everything this round
 * adds is reached through `class_exists()` / `method_exists()` and a verb
 * about something the older build does not have answers with that absence —
 * which is itself the measurement, not a failure.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha67-state.php <verb> [args]
 *
 * Verbs:
 *   arrange · digest · column · line-finer · gate · loss <taken_at>
 *   unmigrate-34 · reset
 */

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\SystemClock;
use Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0022VendorMoneyUnit;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders;

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

const TMC_U68_VENDOR = 9681;
const TMC_U68_ORDER = 96810;
const TMC_U68_ITEM = 96811;

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
$items = M0006CatalogAndOrders::table($db, M0006CatalogAndOrders::ORDER_ITEMS);
$units = M0022VendorMoneyUnit::table($db, M0022VendorMoneyUnit::UNITS);

/** The shape of one column, read from the catalogue. */
$columnShape = static function (string $table, string $column) use ($db): array {
    $row = $db->getRow(
        'SELECT IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default, COLUMN_TYPE AS col_type
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [$table, $column]
    );
    return $row ?? [];
};

// =========================================================================
if ($verb === 'arrange') {
    // A VENDOR WITH A REGISTRY UNIT AT EXPONENT 0 — the state every existing
    // site is in — and nothing finer yet.
    $db->execute('DELETE FROM `' . $units . '` WHERE vendor_user_id = %d', [TMC_U68_VENDOR]);
    $db->execute(
        'INSERT INTO `' . $units . '` (vendor_user_id, currency, exponent, source_event, created_at)
         VALUES (%d, %s, %d, %s, %s)',
        [TMC_U68_VENDOR, 'IRT', 0, 'upgrade-alpha68', gmdate('Y-m-d H:i:s')]
    );
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_id = %d', [TMC_U68_ORDER]);
    $say('registry_exponent', (string) ($db->getVar(
        'SELECT exponent FROM `' . $units . '` WHERE vendor_user_id = %d',
        [TMC_U68_VENDOR]
    ) ?? '__none__'));
    $say('lines', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE wc_order_id = %d',
        [TMC_U68_ORDER]
    ) ?? '__unreadable__'));
    $say('arranged', 'yes');
    return;
}

// =========================================================================
if ($verb === 'digest') {
    // ELEVEN FIELDS OF THE FIXTURE LINE, AND ONE IDENTITY — so «nothing moved»
    // is one number rather than eleven sentences. The unit-price column is
    // DELIBERATELY excluded from the identity and reported separately: it is
    // the column this round widens, and an identity that included it would
    // say «something moved» about the thing being measured.
    $row = $db->getRow(
        'SELECT id, wc_order_id, wc_order_item_id, vendor_user_id, quantity,
                base_minor, tax_minor, commission_minor, vendor_share_minor,
                rate_bp, ledger_event, status, unit_price_minor
           FROM `' . $items . '` WHERE wc_order_item_id = %d',
        [TMC_U68_ITEM]
    );
    if ($row === null) {
        $say('line', '__absent__');
        return;
    }
    $identity = [];
    foreach (['wc_order_id', 'wc_order_item_id', 'vendor_user_id', 'quantity', 'base_minor',
              'tax_minor', 'commission_minor', 'vendor_share_minor', 'rate_bp',
              'ledger_event', 'status'] as $field) {
        $identity[] = $field . '=' . (string) ($row[$field] ?? '__null__');
    }
    $say('identity', substr(hash('sha256', implode('|', $identity)), 0, 16));
    $say('base_minor', (string) $row['base_minor']);
    $say('share_minor', (string) $row['vendor_share_minor']);
    $say('unit_price_minor', $row['unit_price_minor'] === null ? '__null__' : (string) $row['unit_price_minor']);
    return;
}

// =========================================================================
if ($verb === 'column') {
    // ⛔ THE STRUCTURE NUMBER IS PRINTED FIRST, because it is not a fact
    // about the column. The first version of this printed it last and
    // returned early when the column could not be seen — so the one
    // measurement that matters when a step FAILS («the structure did not
    // move») came back as an empty string, and the check read that as a
    // failure about a build that was behaving exactly as intended.
    $say('schema_version', (string) get_option('tmc_schema_version', '__none__'));
    $shape = $columnShape($items, 'unit_price_minor');
    $say('column_present', $shape === [] ? 'no' : 'yes');
    if ($shape === []) {
        return;
    }
    $say('nullable', strtoupper((string) ($shape['nullable'] ?? 'NO')));
    // «No default» has TWO spellings and this was measured here, not read:
    // MariaDB 10.11.14 reports `COLUMN_DEFAULT` for a `DEFAULT NULL` column
    // as the four-character STRING `NULL`, MySQL 8 reports SQL NULL. Both
    // mean the same thing, so they get the same word — and `''` or `'0'`
    // report themselves, because those are defaults and the step refuses
    // them (`M0034::isShaped()`).
    $default = $shape['col_default'];
    $noDefault = $default === null || strtoupper(trim((string) $default)) === 'NULL';
    $say('col_default', $noDefault ? '__no_default__' : (string) $default);
    $say('col_type', (string) ($shape['col_type'] ?? '__none__'));
    return;
}

// =========================================================================
if ($verb === 'line-finer') {
    // ONE LINE AT THE FINER UNIT — exactly what this release records and what
    // a release before `alpha.67` would read ten thousand times too large.
    // Written straight into the table: the point is the ROW, and a capture
    // would need the order gate open.
    $now = gmdate('Y-m-d H:i:s');
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_item_id = %d', [TMC_U68_ITEM]);
    $nullable = strtoupper((string) ($columnShape($items, 'unit_price_minor')['nullable'] ?? 'NO')) === 'YES';
    $unitPrice = $nullable ? 'NULL' : '0';
    $written = $db->execute(
        'INSERT INTO `' . $items . '`
         (wc_order_id, wc_order_item_id, wc_product_id, product_id, vendor_user_id, title, sku,
          quantity, unit_price_minor, base_minor, tax_minor, commission_minor, vendor_share_minor,
          rate_bp, rate_source, ledger_event, status, `tmc_currency`, `tmc_exponent`,
          created_at, updated_at)
         VALUES (%d, %d, 0, 0, %d, %s, %s, 3, ' . $unitPrice . ', 1010000, 0, 126250, 883750,
                 1250, %s, %s, %s, %s, 4, %s, %s)',
        [
            TMC_U68_ORDER,
            TMC_U68_ITEM,
            TMC_U68_VENDOR,
            'قلم ارتقا ۶۸',
            'SKU-U68',
            'product',
            'upgrade-alpha68:line',
            'placed',
            'IRT',
            $now,
            $now,
        ]
    );
    $say('written', $written === null ? '__failed__' : (string) $written);
    $say('row_exponent', (string) ($db->getVar(
        'SELECT `tmc_exponent` FROM `' . $items . '` WHERE wc_order_item_id = %d',
        [TMC_U68_ITEM]
    ) ?? '__null__'));
    $say('unit_price_minor', (string) ($db->getVar(
        'SELECT unit_price_minor FROM `' . $items . '` WHERE wc_order_item_id = %d',
        [TMC_U68_ITEM]
    ) ?? '__null__'));
    return;
}

// =========================================================================
if ($verb === 'gate') {
    // WHAT THE ROLLBACK GATE SAYS — asked for rather than assumed, because
    // this file loads on `alpha.67` too and that build has no gate.
    $gateClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\MoneyScaleRollbackGate';
    $say('has_gate', class_exists($gateClass) ? 'yes' : 'no');
    if (!class_exists($gateClass)) {
        return;
    }
    $answer = (new $gateClass($db))->check();
    $say('verdict', (string) ($answer['verdict'] ?? '__none__'));
    $say('safe', ($answer['safe'] ?? false) ? 'yes' : 'no');
    $say('readable', ($answer['readable'] ?? false) ? 'yes' : 'no');
    $total = 0;
    foreach ((array) ($answer['counts'] ?? []) as $table => $count) {
        $say('count_' . (string) $table, $count === null ? '__unreadable__' : (string) (int) $count);
        $total += (int) $count;
    }
    $say('finer_rows_total', (string) $total);
    return;
}

// =========================================================================
if ($verb === 'loss') {
    $gateClass = 'Tecteb\\Marketplace\\Modules\\Finance\\Application\\MoneyScaleRollbackGate';
    $say('has_gate', class_exists($gateClass) ? 'yes' : 'no');
    if (!class_exists($gateClass)) {
        return;
    }
    $takenAt = (string) ($args[1] ?? '2000-01-01 00:00:00');
    $answer = (new $gateClass($db))->lossSince($takenAt);
    $say('taken_at', (string) $answer['taken_at']);
    $say('readable', ($answer['readable'] ?? false) ? 'yes' : 'no');
    foreach ((array) ($answer['rows'] ?? []) as $table => $count) {
        $say('loss_' . (string) $table, $count === null ? '__unreadable__' : (string) (int) $count);
    }
    $say('loss_total', (string) (int) ($answer['total'] ?? 0));
    return;
}

// =========================================================================
if ($verb === 'unmigrate-34') {
    // THE COLUMN BACK TO `alpha.67`'s SHAPE, so the next step measures the
    // widening rather than the creation of a table. Without this the upgrade
    // check would report «already done» about a site that was never on 33.
    $before = $columnShape($items, 'unit_price_minor');
    $say('nullable_before', strtoupper((string) ($before['nullable'] ?? 'NO')));
    // Any `NULL` the new release wrote has to go first: the column is about to
    // become `NOT NULL` again and `0` is what `alpha.67` would have stored.
    $db->execute('UPDATE `' . $items . '` SET unit_price_minor = 0 WHERE unit_price_minor IS NULL');
    $done = $db->execute(
        'ALTER TABLE `' . $items . '` MODIFY COLUMN `unit_price_minor` BIGINT NOT NULL DEFAULT 0'
    );
    $say('narrowed', $done === null ? '__failed__' : 'yes');
    $after = $columnShape($items, 'unit_price_minor');
    $say('nullable_after', strtoupper((string) ($after['nullable'] ?? 'NO')));
    update_option('tmc_schema_version', '33', false);
    delete_option('tmc_migration_last_error');
    $say('schema_version', (string) get_option('tmc_schema_version', '__none__'));
    return;
}

// =========================================================================
if ($verb === 'reset') {
    $db->execute('DELETE FROM `' . $items . '` WHERE wc_order_id = %d', [TMC_U68_ORDER]);
    $db->execute('DELETE FROM `' . $units . '` WHERE vendor_user_id = %d', [TMC_U68_VENDOR]);
    $say('lines_left', (string) ($db->getVar(
        'SELECT COUNT(*) FROM `' . $items . '` WHERE wc_order_id = %d',
        [TMC_U68_ORDER]
    ) ?? '__unreadable__'));
    $say('reset', 'done');
    return;
}

$say('unknown_verb', $verb);
