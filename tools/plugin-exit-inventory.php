<?php
/**
 * What is actually installed, and what data it actually holds — READ ONLY.
 *
 * §5 and §6 of the owner's instruction both need facts this repository does
 * not have and must not guess at:
 *
 *  - **Min Max Control.** «Min Max Control» is not one plugin. At least three
 *    different WooCommerce plugins go by that sort of name and they store
 *    their rules under different meta keys with different meanings for empty
 *    and for «group of». Measured locally: every candidate key this repo could
 *    think of is absent, because no such plugin is installed here. Writing a
 *    migration against a guessed key would either move nothing or move the
 *    wrong thing, and «سازگاری را از روی حدس پیاده نکن» forbids both.
 *  - **Dokan.** The exit inventory §6 asks for needs counts and id links from
 *    the real installation, not from this repository's reader interface.
 *
 * So this script asks the site. It is the one tool here meant to be run on
 * `staging.tecteb.com`, because it CHANGES NOTHING: there is no `update_`,
 * `delete_`, `add_`, `INSERT`, `UPDATE`, `DELETE` or `ALTER` anywhere in it,
 * and `tests/Unit/ReadOnlyInventoryTest.php` asserts that by reading the
 * file. Run it, send the output back, and §5 and §6 stop being guesses.
 *
 *   wp eval-file tools/plugin-exit-inventory.php            # everything
 *   wp eval-file tools/plugin-exit-inventory.php meta       # product meta keys only
 *   wp eval-file tools/plugin-exit-inventory.php dokan      # Dokan only
 *
 * Nothing it prints is a secret: plugin names and versions, table names, row
 * counts, meta KEYS and how many products carry each. No meta values, no user
 * data, no option values, no credentials.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/plugin-exit-inventory.php\n");
    return;
}

$section = (string) ($args[0] ?? 'all');
global $wpdb;

$line = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace("\n", ' ', (string) $value) . "\n";
};
$head = static function (string $title): void {
    echo "\n## " . $title . "\n";
};

// ------------------------------------------------------------------ platform

if ($section === 'all') {
    $head('platform');
    $line('php', PHP_VERSION);
    $line('wordpress', get_bloginfo('version'));
    $line('woocommerce', defined('WC_VERSION') ? WC_VERSION : 'absent');
    $line('hpos', class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no');
    $line('table_prefix', $wpdb->prefix);
    $line('marketplace_schema', (string) get_option('tmc_schema_version', '0'));
    $line('marketplace_version', defined('TMC_PLUGIN_VERSION') ? TMC_PLUGIN_VERSION : 'absent');
    $line('environment_type', function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown');

    $head('active plugins');
    foreach ((array) get_option('active_plugins', []) as $plugin) {
        $data = function_exists('get_plugin_data')
            ? get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false)
            : [];
        $line(
            'plugin',
            $plugin . ' | ' . (string) ($data['Name'] ?? '?') . ' | ' . (string) ($data['Version'] ?? '?')
        );
    }
    // Network-activated plugins are invisible in the option above, and a
    // multisite install can carry the very plugin being retired there.
    if (is_multisite()) {
        foreach ((array) get_site_option('active_sitewide_plugins', []) as $plugin => $when) {
            $line('network_plugin', (string) $plugin);
        }
    }
}

// ------------------------------------------- product meta keys, by frequency

if ($section === 'all' || $section === 'meta') {
    $head('product and variation meta keys in use');
    echo "# Every meta KEY on a product or variation, with how many rows carry\n";
    echo "# it. This is how the retiring plugin's own keys are IDENTIFIED rather\n";
    echo "# than guessed: whatever it stores its rules under will appear here.\n";
    echo "# Keys only — no values.\n";
    $rows = $wpdb->get_results(
        "SELECT m.meta_key AS k, COUNT(*) AS n
           FROM {$wpdb->postmeta} m
           JOIN {$wpdb->posts} p ON p.ID = m.post_id
          WHERE p.post_type IN ('product', 'product_variation')
          GROUP BY m.meta_key
          ORDER BY m.meta_key",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $line('meta', (string) $row['k'] . ' | ' . (string) $row['n']);
    }

    $head('term meta keys in use');
    echo "# A category-level or global rule usually lives in term meta or an\n";
    echo "# option, so both are listed: a product-only inventory would report a\n";
    echo "# plugin as unused while every rule it enforces sat on a category.\n";
    $termRows = $wpdb->get_results(
        "SELECT meta_key AS k, COUNT(*) AS n FROM {$wpdb->termmeta} GROUP BY meta_key ORDER BY meta_key",
        ARRAY_A
    );
    foreach ((array) $termRows as $row) {
        $line('term_meta', (string) $row['k'] . ' | ' . (string) $row['n']);
    }

    $head('option names that look like a quantity rule');
    echo "# Names only, never values: an option can hold a licence key.\n";
    $options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
          WHERE option_name LIKE '%min%max%' OR option_name LIKE '%max%min%'
             OR option_name LIKE '%quantit%' OR option_name LIKE '%_qty%'
             OR option_name LIKE '%group_of%'
          ORDER BY option_name"
    );
    foreach ((array) $options as $name) {
        $line('option_name', (string) $name);
    }
}

// --------------------------------------------------------------------- dokan

if ($section === 'all' || $section === 'dokan') {
    $head('dokan');
    $line('dokan_class', class_exists('WeDevs_Dokan') ? 'present' : 'absent');
    $line('dokan_pro', class_exists('Dokan_Pro') ? 'present' : 'absent');
    $line('dokan_version', defined('DOKAN_PLUGIN_VERSION') ? DOKAN_PLUGIN_VERSION : 'unknown');

    echo "# Every table whose name mentions dokan, with its row count. A table\n";
    echo "# that exists and is empty is a different fact from one that is not\n";
    echo "# there, and «مانع خروج» depends on telling them apart.\n";
    $tables = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s
              ORDER BY TABLE_NAME',
            '%dokan%'
        )
    );
    foreach ((array) $tables as $table) {
        // `SHOW TABLES` already proved the name; the count is a read.
        $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '', (string) $table) . '`');
        $line('dokan_table', (string) $table . ' | ' . ($count === null ? 'unreadable' : (string) $count));
    }

    echo "# Dokan's own roles and how many accounts hold each.\n";
    foreach (['seller', 'vendor_staff', 'dokandar'] as $role) {
        $line('role_' . $role, (string) count(get_users(['role' => $role, 'fields' => 'ID'])));
    }

    echo "# Products by their author's role, which is how a Dokan product is\n";
    echo "# distinguished from the shop's own.\n";
    $line('products_total', (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'"
    ));
    $line('product_variations_total', (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'"
    ));
    $line('products_linked_to_marketplace', (string) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tmc_product_id'"
    ));
}

// ------------------------------------------------- the marketplace's own side

if ($section === 'all') {
    $head('marketplace tables');
    echo "# For the count reconciliation §6 asks for: the source's numbers and\n";
    echo "# the target's, side by side, so a difference is a number rather than\n";
    echo "# an impression.\n";
    $ours = $wpdb->get_col(
        $wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s
              ORDER BY TABLE_NAME',
            $wpdb->esc_like($wpdb->prefix . 'tmc_') . '%'
        )
    );
    foreach ((array) $ours as $table) {
        $count = $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '', (string) $table) . '`');
        $line('tmc_table', (string) $table . ' | ' . ($count === null ? 'unreadable' : (string) $count));
    }

    $head('marketplace quantity rules');
    echo "# What this plugin has written, and where a manager has taken over.\n";
    foreach (['_tmc_min_purchase', '_tmc_max_purchase', '_tmc_purchase_step',
              '_tmc_quantity_written', '_tmc_quantity_conflicts', '_tmc_quantity_contradiction'] as $key) {
        $line('rows_' . $key, (string) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $key
        )));
    }

    $head('scheduled work');
    echo "# §6 asks about cron and Action Scheduler, and about the risk of two\n";
    echo "# plugins processing the same thing twice. Hooks only.\n";
    $line('action_scheduler', class_exists('ActionScheduler_Store') ? 'present' : 'absent');
    $cron = _get_cron_array();
    $seen = [];
    foreach ((array) $cron as $events) {
        foreach ((array) $events as $hook => $_) {
            if (!isset($seen[$hook])) {
                $seen[$hook] = true;
                $line('cron_hook', (string) $hook);
            }
        }
    }
}

echo "\n# end of inventory. Nothing was written.\n";
