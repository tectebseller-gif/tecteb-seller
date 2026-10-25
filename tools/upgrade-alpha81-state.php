<?php
/**
 * The state verbs the `alpha.81 → alpha.82` upgrade check needs.
 *
 * It must LOAD AND RUN on BOTH trees (`alpha.32`), so everything this round
 * adds is reached through `class_exists()` and a verb about something the
 * older build does not have answers with that absence — which is itself the
 * measurement, not a failure.
 *
 * Prints ONE key per line (`alpha.41`).
 *
 *   wp eval-file tools/upgrade-alpha81-state.php <verb> [args]
 *
 * Verbs:
 *   arrange · rows · sets · table · carried · unmigrate-35 · misshape · reset
 */

use Tecteb\Marketplace\Core\Migration\SchemaVersion;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDatabase;

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

const TMC_U82_VENDOR = 9821;
/**
 * The three fixture products, and the three are the point.
 *
 * The backfill restates what a row already says and invents nothing, so the
 * only way to measure that is to have all three shapes in the table before
 * the step runs: a numeric key (one row), an empty key (NO row, because «we
 * do not know» and «it has none» are different facts — `alpha.8`), and a
 * LEGACY SLUG from before `alpha.25` keyed the directory on term ids (no row
 * either, because a slug is not a term id and writing one would record a
 * relationship nobody has).
 */
const TMC_U82_FIXTURES = [
    'UPG82-NUMERIC' => '31',
    'UPG82-EMPTY' => '',
    'UPG82-SLUG' => 'medical-glue',
];

$args = $args ?? [];
$verb = (string) ($args[0] ?? 'rows');

global $wpdb;
$products = $wpdb->prefix . 'tmc_products';
$links = $wpdb->prefix . 'tmc_product_categories';

/** @return array<string,int> sku => product id */
$fixtureIds = static function () use ($wpdb, $products): array {
    $out = [];
    foreach (array_keys(TMC_U82_FIXTURES) as $sku) {
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$products}` WHERE vendor_user_id = %d AND sku = %s",
            TMC_U82_VENDOR,
            $sku
        ));
        if ($id !== null) {
            $out[$sku] = (int) $id;
        }
    }
    return $out;
};

$tablePresent = static function () use ($wpdb, $links): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $links
    )) === 1;
};

switch ($verb) {
    case 'arrange':
        $now = current_time('mysql', true);
        foreach (TMC_U82_FIXTURES as $sku => $key) {
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$products}` WHERE vendor_user_id = %d AND sku = %s",
                TMC_U82_VENDOR,
                $sku
            )) !== null) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$products}`
                   (vendor_user_id, title, type, category_key, sku, status, created_at, updated_at)
                 VALUES (%d, %s, 'simple', %s, %s, 'draft', %s, %s)",
                TMC_U82_VENDOR,
                'آزمون ارتقا ' . $sku,
                $key,
                $sku,
                $now,
                $now
            ));
        }
        $ids = $fixtureIds();
        echo 'arranged=' . (count($ids) === count(TMC_U82_FIXTURES) ? 'yes' : 'no') . "\n";
        foreach ($ids as $sku => $id) {
            echo 'id_' . strtolower(str_replace('UPG82-', '', $sku)) . '=' . $id . "\n";
        }
        break;

    case 'rows':
        foreach (TMC_U82_FIXTURES as $sku => $expected) {
            $slug = strtolower(str_replace('UPG82-', '', $sku));
            // ⛔ NOT `get_var()`. Measured on this WordPress: it maps the
            // EMPTY STRING to `null` by construction —
            // `( isset($values[$x]) && '' !== $values[$x] ) ? … : null` — so
            // «this row's key is empty» and «there is no such row» come back
            // as the same answer, and those are the two halves this verb
            // exists to tell apart (`alpha.8`, in WordPress's own API).
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT category_key FROM `{$products}` WHERE vendor_user_id = %d AND sku = %s",
                TMC_U82_VENDOR,
                $sku
            ), ARRAY_A);
            $key = is_array($rows) && $rows !== [] ? (string) $rows[0]['category_key'] : null;
            echo 'key_' . $slug . '=' . ($key === null ? '__absent__' : ($key === '' ? '__empty__' : $key)) . "\n";
        }
        echo 'schema_version=' . ((int) get_option(SchemaVersion::OPTION, 0)) . "\n";
        break;

    case 'sets':
        // Read the link table DIRECTLY, not through the repository: this verb
        // has to answer on a tree where the repository method does not exist,
        // and the question is about the rows, not about the reader.
        if (!$tablePresent()) {
            echo "table_present=no\n";
            break;
        }
        echo "table_present=yes\n";
        foreach ($fixtureIds() as $sku => $id) {
            $slug = strtolower(str_replace('UPG82-', '', $sku));
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT term_id FROM `{$links}` WHERE product_id = %d ORDER BY term_id",
                $id
            ));
            $rows = array_map('intval', (array) $rows);
            echo 'set_' . $slug . '=' . ($rows === [] ? '__none__' : implode(',', $rows)) . "\n";
        }
        echo 'rows_total=' . ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$links}`")) . "\n";
        break;

    case 'table':
        echo 'table_present=' . ($tablePresent() ? 'yes' : 'no') . "\n";
        echo 'schema_version=' . ((int) get_option(SchemaVersion::OPTION, 0)) . "\n";
        if (!$tablePresent()) {
            break;
        }
        $cols = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            $links
        ));
        echo 'columns=' . implode(',', array_map('strval', (array) $cols)) . "\n";
        // ⛔ `COUNT(*)` on `STATISTICS` counts COLUMNS, not indexes
        // (`alpha.41`): a two-column key is two rows. So the PAIR is asked for
        // as the columns of one unique index, in order.
        $pk = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX',
            $links,
            'PRIMARY'
        ));
        echo 'primary_key=' . implode(',', array_map('strval', (array) $pk)) . "\n";
        break;

    case 'carried':
        // The three answers, asked of the step itself — and on `alpha.81` the
        // class is not there, which is the measurement (`alpha.32`).
        $class = 'Tecteb\\Marketplace\\Modules\\Product\\Infrastructure\\Migrations\\M0035ProductCategorySet';
        if (!class_exists($class)) {
            echo "step_class=absent\n";
            break;
        }
        echo "step_class=present\n";
        $answer = $class::carriedBy(new WpDatabase($wpdb));
        echo 'carried=' . ($answer === null ? '__null__' : ($answer ? 'yes' : 'no')) . "\n";
        break;

    case 'unmigrate-35':
        // A site GENUINELY on 34, so the step is measured doing its work
        // rather than reporting «already done» (`alpha.70`).
        $wpdb->query("DROP TABLE IF EXISTS `{$links}`");
        update_option(SchemaVersion::OPTION, 34, false);
        echo 'table_present=' . ($tablePresent() ? 'yes' : 'no') . "\n";
        echo 'schema_version=' . ((int) get_option(SchemaVersion::OPTION, 0)) . "\n";
        break;

    case 'misshape':
        // THE INJECTION. A table of the same NAME and the wrong SHAPE:
        // `CREATE TABLE IF NOT EXISTS` is satisfied by it, so only `verify()`
        // can refuse — which is exactly the trap `alpha.51` is about, from the
        // other side.
        $wpdb->query("DROP TABLE IF EXISTS `{$links}`");
        $wpdb->query("CREATE TABLE `{$links}` (`product_id` BIGINT UNSIGNED NOT NULL)");
        update_option(SchemaVersion::OPTION, 34, false);
        $cols = $wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $links
        ));
        echo 'columns=' . implode(',', array_map('strval', (array) $cols)) . "\n";
        echo 'schema_version=' . ((int) get_option(SchemaVersion::OPTION, 0)) . "\n";
        break;

    case 'reset':
        // Give back exactly what this fixture spent, and nothing else: the
        // three rows and their link rows. The TABLE is left alone — it is the
        // migration's, not this tool's.
        foreach ($fixtureIds() as $id) {
            if ($tablePresent()) {
                $wpdb->query($wpdb->prepare("DELETE FROM `{$links}` WHERE product_id = %d", $id));
            }
            $wpdb->query($wpdb->prepare("DELETE FROM `{$products}` WHERE id = %d", $id));
        }
        echo 'left=' . count($fixtureIds()) . "\n";
        break;

    default:
        fwrite(STDERR, "usage: arrange | rows | sets | table | carried | unmigrate-35 | misshape | reset\n");
        echo "refused=1\n";
}
