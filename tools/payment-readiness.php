<?php
/**
 * Is this site ready to take a real payment? — READ ONLY, and NO REQUESTS.
 *
 * §4 of the owner's instruction asks fourteen things about the gateway, and
 * §5 forbids a real transaction without explicit permission. This script
 * answers what can be answered by LOOKING, and says `unknown` for the rest
 * rather than inventing it.
 *
 *   wp eval-file tools/payment-readiness.php
 *
 * **Three refusals, each deliberate.**
 *
 *  1. ⛔ **It sends nothing.** Not one `wp_remote_get`, not one curl. The
 *     marketplace plugin makes zero outbound requests in its whole source
 *     (measured), and a readiness probe that opens a connection from the
 *     owner's production site is a different act from reading its settings.
 *     What it does instead is print the two constants that would BLOCK such a
 *     request and hand the owner one explicit command to run if they choose.
 *  2. ⛔ **It prints no secret.** The merchant id is a credential. What is
 *     printed is whether one is set, how long it is, and whether it has the
 *     SHAPE ZarinPal uses — never the value, never a prefix of the value.
 *     «از اطلاعات بانکی … استفاده نشود» covers this.
 *  3. ⛔ **It does not guess the currency factor.** «هیچ تبدیل ×۱۰ یا ÷۱۰ را
 *     از روی برچسب ظاهری حدس نزن.» It prints what WooCommerce stores, what
 *     this marketplace stores, and what the gateway's own settings say — three
 *     facts side by side — and leaves the comparison to a person, because the
 *     wrong answer here moves money by a factor of ten.
 *
 * `tests/Unit/ReadOnlyInventoryTest.php` reads this file and refuses every
 * writer, with the comments blanked first.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/payment-readiness.php\n");
    return;
}

global $wpdb;

$line = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace("\n", ' ', (string) $value) . "\n";
};
$head = static function (string $title): void {
    echo "\n## " . $title . "\n";
};
$yn = static fn (bool $value): string => $value ? 'yes' : 'no';

// --------------------------------------------------------------- the platform

$head('platform');
$line('php_version', PHP_VERSION);
$line('wp_version', get_bloginfo('version'));
$line('wc_version', defined('WC_VERSION') ? WC_VERSION : 'unknown');
$line('is_ssl_front', $yn(str_starts_with((string) get_option('home'), 'https://')));
$line('is_ssl_admin', $yn(str_starts_with((string) get_option('siteurl'), 'https://')));
$line('hpos_enabled', (string) get_option('woocommerce_feature_custom_order_tables_enabled', 'no'));
$line('block_checkout_page', $yn((bool) get_option('woocommerce_checkout_page_id')));

// ⛔ Outbound: the constants, not a request.
$head('outbound — the constants, no request made');
$line('WP_HTTP_BLOCK_EXTERNAL', defined('WP_HTTP_BLOCK_EXTERNAL') ? ($yn((bool) WP_HTTP_BLOCK_EXTERNAL)) : 'not defined');
$line('WP_ACCESSIBLE_HOSTS', defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : 'not defined');
$line('curl_available', $yn(function_exists('curl_init')));
$line('openssl_available', $yn(extension_loaded('openssl')));
echo "# To test reachability, run this ONE command yourself, from the server:\n";
echo "#   curl -sS -o /dev/null -w '%{http_code} %{time_total}s\\n' https://api.zarinpal.com/\n";
echo "# It sends no payload and starts no transaction. A 403 or a timeout here\n";
echo "# is an infrastructure finding, not a marketplace defect — see\n";
echo "# docs/ENVIRONMENT-SEPARATION-FA.md section 4.\n";

// ----------------------------------------------------------------- the gateway

$head('gateways woocommerce knows about');
$installed = [];
if (function_exists('WC') && is_object(WC()) && property_exists(WC(), 'payment_gateways')) {
    $registry = WC()->payment_gateways();
    if (is_object($registry) && method_exists($registry, 'payment_gateways')) {
        foreach ((array) $registry->payment_gateways() as $gateway) {
            if (!is_object($gateway) || !property_exists($gateway, 'id')) {
                continue;
            }
            $id = (string) $gateway->id;
            $installed[$id] = $gateway;
            $line(
                'gateway.' . $id,
                'enabled=' . (property_exists($gateway, 'enabled') ? (string) $gateway->enabled : '?')
                . ' title=' . (method_exists($gateway, 'get_title') ? (string) $gateway->get_title() : '?')
                . ' refunds=' . (method_exists($gateway, 'supports') ? $yn((bool) $gateway->supports('refunds')) : '?')
            );
        }
    }
}
if ($installed === []) {
    $line('gateways', 'none readable — WooCommerce may not be loaded in this context');
}

// Which of them is ZarinPal? Matched on the id, not on the display title: a
// title is translated and renamed, an id is not.
$head('zarinpal');
$candidates = [];
foreach (array_keys($installed) as $id) {
    if (stripos($id, 'zarinpal') !== false || stripos($id, 'zarin') !== false) {
        $candidates[] = $id;
    }
}
$line('zarinpal_gateway_ids', $candidates === [] ? '(none found)' : implode(' ', $candidates));

$plugins = get_option('active_plugins', []);
$zarinPlugins = [];
foreach ((array) $plugins as $plugin) {
    if (stripos((string) $plugin, 'zarin') !== false) {
        $zarinPlugins[] = (string) $plugin;
    }
}
$line('zarinpal_plugin_files', $zarinPlugins === [] ? '(none active)' : implode(' ', $zarinPlugins));
foreach ($zarinPlugins as $plugin) {
    $path = WP_PLUGIN_DIR . '/' . $plugin;
    if (!is_readable($path)) {
        $line('plugin.' . $plugin, 'unreadable');
        continue;
    }
    $data = function_exists('get_plugin_data') ? get_plugin_data($path, false, false) : [];
    $line(
        'plugin.' . $plugin,
        'name=' . (string) ($data['Name'] ?? '?')
        . ' version=' . (string) ($data['Version'] ?? '?')
        . ' requires_php=' . (string) ($data['RequiresPHP'] ?? '?')
        . ' sha256=' . substr((string) hash_file('sha256', $path), 0, 16)
    );
}

// ⛔ Settings, with the credential described and never shown.
$head('zarinpal settings — the credential is DESCRIBED, never printed');
foreach ($candidates as $id) {
    $settings = get_option('woocommerce_' . $id . '_settings', []);
    if (!is_array($settings)) {
        $line('settings.' . $id, 'unreadable');
        continue;
    }
    foreach ($settings as $key => $value) {
        $name = (string) $key;
        $isSecret = preg_match('/(merchant|key|secret|token|pass|api)/i', $name) === 1;
        if ($isSecret) {
            $text = is_scalar($value) ? (string) $value : '';
            // Length and shape only. ZarinPal's merchant id is a 36-character
            // UUID; a 32-character value or an empty one is a finding on its
            // own, and neither needs the value to be shown.
            $line(
                'settings.' . $id . '.' . $name,
                'set=' . $yn($text !== '')
                . ' length=' . strlen($text)
                . ' looks_like_uuid=' . $yn(preg_match('/^[0-9a-f-]{36}$/i', $text) === 1)
            );
            continue;
        }
        if (is_scalar($value)) {
            $line('settings.' . $id . '.' . $name, (string) $value);
            continue;
        }
        $line('settings.' . $id . '.' . $name, '(' . gettype($value) . ')');
    }
    if ($settings === []) {
        $line('settings.' . $id, '(empty)');
    }
}

// ------------------------------------------------------------------- currency

$head('currency — three facts, side by side, and NO factor derived');
echo "# ⛔ This section does not compute a conversion. «هیچ تبدیل ×۱۰ یا ÷۱۰ را\n";
echo "# از روی برچسب ظاهری حدس نزن» — so what follows is what each layer\n";
echo "# STORES, and the comparison is a person's to make.\n";
$line('wc_currency', (string) get_option('woocommerce_currency'));
$line('wc_price_decimals', (string) get_option('woocommerce_price_num_decimals'));
$line('wc_currency_pos', (string) get_option('woocommerce_currency_pos'));
$line('wc_get_price_decimals', function_exists('wc_get_price_decimals') ? (string) wc_get_price_decimals() : 'unknown');

// What THIS marketplace recorded, per vendor, from its own registry.
$unitTable = $wpdb->prefix . 'tmc_vendor_money_unit';
$unitExists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $unitTable)) === $unitTable;
if (!$unitExists) {
    $line('tmc_money_units', 'table absent');
} else {
    $units = $wpdb->get_results(
        'SELECT vendor_user_id, currency, exponent FROM `' . $unitTable . '` ORDER BY vendor_user_id ASC',
        ARRAY_A
    );
    if (!is_array($units)) {
        $line('tmc_money_units', '__unreadable__');
    } elseif ($units === []) {
        $line('tmc_money_units', 'none recorded — no sale has been captured yet');
    } else {
        foreach ($units as $unit) {
            $line(
                'tmc_money_unit.' . (int) $unit['vendor_user_id'],
                (string) $unit['currency'] . '/' . (int) $unit['exponent']
            );
        }
    }
}

// And what the ledger actually holds, which is the only answer that can
// contradict the registry.
$ledgerTable = $wpdb->prefix . 'tmc_ledger_entries';
$ledgerExists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledgerTable)) === $ledgerTable;
if (!$ledgerExists) {
    $line('tmc_ledger_units', 'table absent');
} else {
    $mix = $wpdb->get_results(
        'SELECT currency, exponent, COUNT(*) AS n FROM `' . $ledgerTable . '`
         GROUP BY currency, exponent ORDER BY n DESC',
        ARRAY_A
    );
    if (!is_array($mix)) {
        $line('tmc_ledger_units', '__unreadable__');
    } elseif ($mix === []) {
        $line('tmc_ledger_units', 'empty');
    } else {
        foreach ($mix as $bucket) {
            $line(
                'tmc_ledger_unit.' . (string) $bucket['currency'] . '.' . (int) $bucket['exponent'],
                (int) $bucket['n'] . ' lines'
            );
        }
        $line('tmc_ledger_distinct_units', count($mix));
        // ⛔ More than one unit in one book is a refusal elsewhere
        // (`books_mixed`), and it is a blocker here too.
        $line('tmc_ledger_single_unit', $yn(count($mix) === 1));
    }
}

$head('what cannot be answered by looking');
foreach ([
    'zarinpal_expects_which_unit' => 'the gateway plugin decides this; read its own settings and its docs',
    'one_order_amount_matches_in_three_places' => 'needs ONE real payment, with explicit owner permission (§5)',
    'callback_verify_round_trip' => 'needs a real callback',
    'transaction_id_and_verify_time_recorded' => 'needs a real payment',
    'four_outcome_states' => 'success, cancelled, failed and unknown each need their own run',
    'duplicate_callback_makes_no_second_record' => 'needs a real callback, replayed',
    'refund_capability_end_to_end' => 'needs the gateway to support refunds AND a real refund; §5 asks separately',
    'test_amount_limits' => 'the gateway account decides; ask ZarinPal',
] as $key => $why) {
    $line($key, 'unknown — ' . $why);
}

echo "\n# ⛔ Every `unknown` above is a `Not Run` row in the GO/NO-GO table,\n";
echo "# not a pass. The runbook that closes them is\n";
echo "# docs/ZARINPAL-PAYMENT-TEST-FA.md, and it does not run itself.\n";
