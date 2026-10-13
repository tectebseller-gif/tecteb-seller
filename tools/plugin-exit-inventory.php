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
 *   wp eval-file tools/plugin-exit-inventory.php minmax     # Min Max mode and rule counts
 *   wp eval-file tools/plugin-exit-inventory.php files      # path, header and hash of the active files
 *
 * **The honest limit of the static «read only» test.** What
 * `tests/Unit/ReadOnlyInventoryTest.php` proves is that THIS FILE contains no
 * writer: not one of the WordPress write functions, not one of wpdb's, and no
 * SQL verb that changes anything, with comments blanked first so the prose
 * explaining the rule cannot satisfy it. What it does NOT prove, and is not
 * claimed:
 *
 *  - that no PLUGIN writes while this runs. Loading WordPress fires
 *    `plugins_loaded`, `init` and `admin_init`, and some plugins migrate their
 *    own data on those hooks. That is true of any WP-CLI command — including
 *    `wp plugin list` — and it is not caused by this script, but «read only»
 *    should not be heard as «the site is frozen while it runs».
 *  - that WordPress itself writes nothing. Loading a site can refresh a
 *    transient or an object-cache row.
 *  - anything about a database user's permissions.
 *
 * It is safe to run in the sense that matters — it issues no write of its own
 * and asks for nothing privileged — and that is the whole of the claim.
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

// ------------------------------------------ the files that are actually live

if ($section === 'all' || $section === 'files') {
    $head('active plugin files: path, header version and hash');
    echo "# §5 asks for this because a header and a dashboard can disagree, and\n";
    echo "# the owner has seen them disagree: the submitted Dokan files say\n";
    echo "# 5.1.1 while the dashboard showed 5.0.1 and 5.0.12. The cause is NOT\n";
    echo "# guessed at here. What is printed is only what can be read: the file\n";
    echo "# WordPress is loading, the version in its header, the version a\n";
    echo "# constant holds, and the sha256 of the file. Whoever compares those\n";
    echo "# four has the evidence; this script does not draw the conclusion.\n";
    foreach ((array) get_option('active_plugins', []) as $plugin) {
        $path = WP_PLUGIN_DIR . '/' . $plugin;
        $data = function_exists('get_plugin_data') ? get_plugin_data($path, false, false) : [];
        $hash = is_readable($path) ? hash_file('sha256', $path) : 'unreadable';
        $line('file', $plugin
            . ' | header=' . (string) ($data['Version'] ?? '?')
            . ' | bytes=' . (is_readable($path) ? (string) filesize($path) : '?')
            . ' | sha256=' . (string) $hash);
    }
    echo "# And the version CONSTANTS, which is the other half of the comparison:\n";
    echo "# a constant and a header that differ mean two sets of files have been\n";
    echo "# mixed, which a hash of one file alone cannot show.\n";
    foreach ([
        'DOKAN_PLUGIN_VERSION', 'DOKAN_PRO_PLUGIN_VERSION', 'DOKAN_PRO_VERSION',
        'WC_MMQ_VERSION', 'WC_MMQ_PRO_VERSION', 'WC_VERSION', 'TMC_PLUGIN_VERSION',
    ] as $constant) {
        $line('constant_' . $constant, defined($constant) ? (string) constant($constant) : 'absent');
    }
    echo "# A plugin folder that carries a directory no upstream release has is a\n";
    echo "# modification, and it is named rather than summarised. Listed because\n";
    echo "# the submitted Dokan Lite and Pro both require `abzarwp/abzarwp.php`\n";
    echo "# from the FIRST LINE of their main file, and all 37 and 46 PHP files\n";
    echo "# under those directories are SourceGuardian-encrypted — so they run\n";
    echo "# before Dokan on every request and cannot be read by anybody.\n";
    foreach ((array) get_option('active_plugins', []) as $plugin) {
        $dir = dirname(WP_PLUGIN_DIR . '/' . $plugin);
        foreach (['abzarwp', 'license', 'licence', 'activation'] as $suspect) {
            if (is_dir($dir . '/' . $suspect)) {
                $files = glob($dir . '/' . $suspect . '/**/*.php', GLOB_BRACE) ?: [];
                $top = glob($dir . '/' . $suspect . '/*.php') ?: [];
                $line('extra_dir', dirname($plugin) . '/' . $suspect
                    . ' | php_files_at_top=' . (string) count($top)
                    . ' | php_files_nested=' . (string) count($files));
            }
        }
    }
}

// ------------------------------------------------------------------- min max

if ($section === 'all' || $section === 'minmax') {
    $head('min max control: the effective mode');
    echo "# The mode is derived the PLUGIN'S way and in the plugin's order,\n";
    echo "# read out of wcmmq.php:49-61. The test there is TRUTHINESS, not\n";
    echo "# existence: an option row that exists and holds '', 0 or array() is\n";
    echo "# falsy and the plugin falls through to the next branch. And the PRO\n";
    echo "# key wins even though it is read second.\n";
    $truthy = static function (mixed $value): bool {
        if ($value === false || $value === null) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }
        return (bool) $value;
    };
    $universal = get_option('wcmmq_universal_minmaxstep');
    $single = get_option('wcmmq_s_universal_minmaxstep');
    $line('wcmmq_universal_minmaxstep_present', $universal === false ? 'no' : 'yes');
    $line('wcmmq_universal_minmaxstep_truthy', $truthy($universal) ? 'yes' : 'no');
    $line('wcmmq_s_universal_minmaxstep_present', $single === false ? 'no' : 'yes');
    $line('wcmmq_s_universal_minmaxstep_truthy', $truthy($single) ? 'yes' : 'no');
    if ($truthy($universal)) {
        $prefix = '_wcmmq_';
        $optionKey = 'wcmmq_universal_minmaxstep';
        $mode = 'universal';
    } elseif ($truthy($single)) {
        $prefix = '_wcmmq_s_';
        $optionKey = 'wcmmq_s_universal_minmaxstep';
        $mode = 'single';
    } else {
        $prefix = '';
        $optionKey = 'wcmmq_minmaxstep';
        $mode = 'plain';
    }
    $line('effective_mode', $mode);
    $line('effective_prefix', $prefix === '' ? '(none)' : $prefix);
    $line('effective_option_key', $optionKey);
    $line('live_constant_prefix', defined('WC_MMQ_PREFIX')
        ? (WC_MMQ_PREFIX === '' ? '(none)' : WC_MMQ_PREFIX)
        : 'absent');
    $line('premium_active', defined('WC_MMQ_PRO_VERSION') ? 'yes' : 'no');
    echo "# When premium is absent the per-VARIATION level never fires: the\n";
    echo "# plugin returns before reading it and nulls the variation id. Rows\n";
    echo "# may still exist from an earlier premium install and are counted,\n";
    echo "# because installing premium would wake them up.\n";

    $head('min max control: rules per level');
    foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
        foreach (['product', 'product_variation'] as $postType) {
            $line('rows_' . $postType . '_' . $prefix . $field, (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                   JOIN {$wpdb->posts} p ON p.ID = m.post_id
                  WHERE m.meta_key = %s AND p.post_type = %s
                    AND TRIM(COALESCE(m.meta_value, '')) <> ''",
                $prefix . $field,
                $postType
            )));
        }
    }
    $options = get_option($optionKey);
    $options = is_array($options) ? $options : [];
    $line('global_option_present', $options === [] ? 'no' : 'yes');
    foreach (['min_quantity', 'max_quantity', 'product_step', 'default_quantity'] as $field) {
        // The global rule is a LIMIT, not personal data, so the value is
        // printed: the exit decision is impossible without it.
        $line('global_' . $field, array_key_exists($prefix . $field, $options)
            ? (string) $options[$prefix . $field]
            : '(absent, plugin default applies)');
    }
    $terms = $options['terms'] ?? null;
    $termCount = 0;
    if (is_array($terms)) {
        foreach ($terms as $taxonomy => $byTerm) {
            if (is_array($byTerm)) {
                $termCount += count($byTerm);
                $line('term_rules_in_' . (string) $taxonomy, (string) count($byTerm));
            }
        }
    }
    $line('term_rules_total', (string) $termCount);
    echo "# A term rule's MAXIMUM defaults to 1, not «unlimited»\n";
    echo "# (setTermwiseArgs()), so a term rule configured with only a minimum\n";
    echo "# enforces a maximum of ONE on every product in that term.\n";
    $line('cat_ids_set', array_key_exists('_cat_ids', $options) && is_array($options['_cat_ids'])
        ? (string) count($options['_cat_ids'])
        : 'no');
    $line('quantity_box_in_archive', (string) ($options['quantiy_box_archive'] ?? '(absent)'));
}

// ------------------------------------------- the handover state, per plugin

if ($section === 'all' || $section === 'dokan' || $section === 'minmax') {
    $head('handover state');
    echo "# What this marketplace has already decided about each plugin's data.\n";
    $handover = $wpdb->prefix . 'tmc_minmax_handover';
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $handover
    ));
    $line('minmax_handover_table', $exists === 1 ? 'present' : 'absent');
    if ($exists === 1) {
        foreach ((array) $wpdb->get_results(
            'SELECT status, COUNT(*) AS n FROM `' . $handover . '` GROUP BY status',
            ARRAY_A
        ) as $row) {
            $line('minmax_handover_' . (string) $row['status'], (string) $row['n']);
        }
        foreach ((array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT problems, COUNT(*) AS n FROM `' . $handover . '`
                  WHERE problems <> %s GROUP BY problems',
                ''
            ),
            ARRAY_A
        ) as $row) {
            $line('minmax_blocked_by_' . (string) $row['problems'], (string) $row['n']);
        }
    }
    $line('dokan_import_runs', (string) count((array) get_option('tmc_dokan_import_runs', [])));
    $handoverRows = $wpdb->prefix . 'tmc_dokan_handover';
    $hasHandover = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $handoverRows
    ));
    $line('dokan_handover_table', $hasHandover === 1 ? 'present' : 'absent');
    if ($hasHandover === 1) {
        $line('dokan_handover_rows', (string) $wpdb->get_var(
            'SELECT COUNT(*) FROM `' . $handoverRows . '`'
        ));
    }
    echo "# Dokan's purge-on-uninstall setting. THE SETTING, not an action:\n";
    echo "# dokan's own uninstall.php drops its tables, user metas, roles and\n";
    echo "# pages when dokan_general[data_clear_on_uninstall] is 'on'. No\n";
    echo "# uninstall is run by anything in this repository, and deactivating a\n";
    echo "# plugin does not run it either — but a manager who DELETES the plugin\n";
    echo "# with this setting on loses the data this migration reads.\n";
    $general = get_option('dokan_general');
    $general = is_array($general) ? $general : [];
    $line('dokan_data_clear_on_uninstall', array_key_exists('data_clear_on_uninstall', $general)
        ? (string) $general['data_clear_on_uninstall']
        : '(absent — Dokan treats this as off)');

    echo "# Dokan Pro's ACTIVE modules, and whether their directory is on disk.
";
    echo "# Asked because the Pro package submitted for review ships 10 module
";
    echo "# directories while its own loader knows 41 — vendor-staff among the
";
    echo "# absent ones. A module that is marked active with no directory is a
";
    echo "# feature the site believes it has; a directory with no mark is one it
";
    echo "# could turn on. Neither can be inferred from the files alone.
";
    $activeModules = get_option('dokan_pro_active_modules');
    $line('dokan_pro_active_modules_option', $activeModules === false ? 'absent' : 'present');
    $moduleDir = defined('DOKAN_PRO_MODULE_DIR') ? DOKAN_PRO_MODULE_DIR : '';
    foreach ((array) (is_array($activeModules) ? $activeModules : []) as $module) {
        $slug = str_replace('_', '-', (string) $module);
        $onDisk = $moduleDir !== '' && is_dir($moduleDir . '/' . $slug) ? 'yes' : 'no';
        $line('dokan_module_active', (string) $module . ' | directory_present=' . $onDisk);
    }
    if ($moduleDir !== '' && is_dir($moduleDir)) {
        $dirs = glob($moduleDir . '/*', GLOB_ONLYDIR) ?: [];
        $line('dokan_module_dirs_on_disk', (string) count($dirs));
        foreach ($dirs as $dir) {
            $line('dokan_module_dir', basename((string) $dir));
        }
    }
}

// -------------------------------------------------- this plugin's own modules

if ($section === 'all') {
    $head('marketplace modules');
    echo "# Which of this plugin's modules are loaded, so a report about a\n";
    echo "# migration path can say whether the module that owns it is even on.\n";
    $enabled = get_option('tmc_enabled_modules');
    $line('module_option_present', $enabled === false ? 'no' : 'yes');
    if (is_array($enabled)) {
        foreach ($enabled as $slug => $on) {
            $line('module_' . (string) $slug, $on ? 'on' : 'off');
        }
    }
}

echo "\n# end of inventory. Nothing was written.\n";
