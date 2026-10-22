<?php
/**
 * The state verbs the DIRECT `alpha.65 → this package` upgrade check needs.
 *
 * WHY THIS EXISTS AT ALL. The owner has stated what staging actually runs:
 * **`alpha.65`, data structure 31, PHP 8.1.34.** Every upgrade script in this
 * repository so far measured one step from the round before it, so the path
 * the owner will actually take — THREE steps, 31 → 32 → 33 → 34 — had never
 * been run end to end. `MigrationRunner` runs the chain, but «the chain runs»
 * is a sentence about code; this puts the database genuinely on 31 and then
 * asks what the gate did.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so anything a later
 * package added is reached through `class_exists()` and a verb about
 * something `alpha.65` does not have answers with that absence — which is
 * itself the measurement. The clearest case: `MoneyScaleRollbackGate` does
 * not exist in `alpha.65`, so the compatibility verdict for a rollback can
 * only be read on the NEW package, BEFORE rolling back. That is why the
 * checklist's rollback step was corrected this round.
 *
 * ⛔ IT WRITES ONLY TO THE DISPOSABLE DATABASE, both halves guarded, and
 * `DisposableOnlyToolsTest` is what keeps that true.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha65-state.php <verb>
 *
 * Verbs: shape · unmigrate-to-31 · reset
 */

use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;

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

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'shape');
$db = new WpDatabase($GLOBALS['wpdb']);
$prefix = $db->prefix();

$say = static function (string $key, mixed $value): void {
    if (is_bool($value)) {
        $value = $value ? 'yes' : 'no';
    }
    echo $key . '=' . str_replace(["\n", ' '], ['', '_'], (string) ($value ?? '__null__')) . "\n";
};

/**
 * The three structures this path crosses, named here rather than imported.
 *
 * The constants live in classes `alpha.65` does not ship, so naming the
 * columns as strings is what lets one file describe both ends of the upgrade.
 * They are asserted against the classes by the check script when it runs on
 * the new package, so a rename cannot make this file quietly wrong.
 */
$steps = [
    32 => [['table' => 'tmc_products', 'column' => 'mirrored_status']],
    33 => (static function (): array {
        $out = [];
        foreach (['tmc_order_items', 'tmc_withdrawals', 'tmc_withdrawal_lines', 'tmc_returns'] as $table) {
            foreach (['tmc_currency', 'tmc_exponent'] as $column) {
                $out[] = ['table' => $table, 'column' => $column];
            }
        }
        return $out;
    })(),
];

/** Whether one column is there — and `null` when the catalogue read failed. */
$columnState = static function (string $table, string $column) use ($db, $prefix): ?array {
    $row = $db->getRow(
        'SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        [$prefix . $table, $column]
    );
    if ($row === null) {
        // `getRow()` answers `null` for «no such column» and for «the read
        // failed» alike (`alpha.38`), so the count is asked separately.
        $count = $db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$prefix . $table, $column]
        );
        return $count === null ? null : ['present' => false];
    }
    // MEASURED, not assumed: on this MariaDB a nullable column whose default
    // is NULL answers the four-letter STRING `NULL` in `COLUMN_DEFAULT`, not
    // SQL `NULL`. Both spellings mean «nothing stands in for unknown», so
    // both are reported the same way — a check that expected one of them
    // would be a check about this server's catalogue, not about the column.
    $default = $row['COLUMN_DEFAULT'];
    $noDefault = $default === null || strtoupper(trim((string) $default)) === 'NULL';
    return [
        'present' => true,
        'nullable' => (string) $row['IS_NULLABLE'],
        'default' => $noDefault ? '__no_default__' : (string) $default,
        'type' => (string) $row['COLUMN_TYPE'],
    ];
};

if ($verb === 'shape') {
    $say('schema_version', (string) (int) get_option('tmc_schema_version', 0));
    $present = 0;
    $absent = 0;
    $unreadable = 0;
    foreach ($steps as $step => $columns) {
        $stepPresent = 0;
        foreach ($columns as $spec) {
            $state = $columnState($spec['table'], $spec['column']);
            if ($state === null) {
                $unreadable++;
                continue;
            }
            if (($state['present'] ?? false) === true) {
                $present++;
                $stepPresent++;
            } else {
                $absent++;
            }
        }
        $say('step' . $step . '_columns_present', $stepPresent . '/' . count($columns));
    }
    $say('added_columns_present', (string) $present);
    $say('added_columns_absent', (string) $absent);
    $say('added_columns_unreadable', (string) $unreadable);
    // Step 34 is a MODIFY, not an ADD: the column is there either way and
    // what changes is whether it may say «there is no whole unit price».
    $unit = $columnState('tmc_order_items', 'unit_price_minor');
    $say('unit_price_present', $unit === null ? '__unreadable__' : (($unit['present'] ?? false) ? 'yes' : 'no'));
    $say('unit_price_nullable', $unit === null ? '__unreadable__' : (string) ($unit['nullable'] ?? '__absent__'));
    $say('unit_price_default', $unit === null ? '__unreadable__' : (string) ($unit['default'] ?? '__absent__'));
    $say('unit_price_type', $unit === null ? '__unreadable__' : (string) ($unit['type'] ?? '__absent__'));
    $say('gate_class_exists', class_exists(
        'Tecteb\\Marketplace\\Modules\\Finance\\Application\\MoneyScaleRollbackGate'
    ));
    // The recorded reason is an ARRAY option, and `(string)` on it prints
    // «Array» — which is a measurement that says nothing (`alpha.34`).
    $error = get_option('tmc_migration_last_error', '');
    if (is_array($error)) {
        $error = (string) ($error['message'] ?? ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE)));
    }
    $say('last_error', ((string) $error) === '' ? '__none__' : (string) $error);
    return;
}

if ($verb === 'unmigrate-to-31') {
    // A SITE GENUINELY ON 31, so the three steps are measured doing their
    // work rather than reporting «already done» (`alpha.67`'s rule).
    //
    // ⛔ This DROPS columns, which loses what they held. That is acceptable
    // here and nowhere else: the guard above refuses any database but the
    // disposable one, and the whole point is to rebuild the owner's starting
    // state rather than describe it.
    $dropped = 0;
    $failed = 0;
    foreach ($steps as $columns) {
        foreach ($columns as $spec) {
            $state = $columnState($spec['table'], $spec['column']);
            if ($state === null || ($state['present'] ?? false) !== true) {
                continue;
            }
            $result = $db->execute(
                'ALTER TABLE `' . $prefix . $spec['table'] . '` DROP COLUMN `' . $spec['column'] . '`'
            );
            if ($result === null) {
                $failed++;
                continue;
            }
            $dropped++;
        }
    }
    // Step 34's inverse: back to `alpha.65`'s shape, where a line with no
    // whole unit price has no way to say so.
    $narrowed = $db->execute(
        'ALTER TABLE `' . $prefix . 'tmc_order_items`
            MODIFY COLUMN `unit_price_minor` BIGINT NOT NULL DEFAULT 0'
    );
    update_option('tmc_schema_version', 31, false);
    delete_option('tmc_migration_last_error');
    $say('columns_dropped', (string) $dropped);
    $say('columns_that_would_not_drop', (string) $failed);
    $say('unit_price_narrowed', $narrowed === null ? 'no' : 'yes');
    $say('schema_version', (string) (int) get_option('tmc_schema_version', 0));
    return;
}

if ($verb === 'reset') {
    delete_option('tmc_migration_last_error');
    $say('reset', 'yes');
    return;
}

$say('unknown_verb', $verb);
