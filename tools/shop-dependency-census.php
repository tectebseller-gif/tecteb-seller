<?php
/**
 * What one shop id actually owns, across every table that carries one — READ ONLY.
 *
 * The owner's instruction asks two questions this repository cannot answer
 * from its own source, and says so itself: «آیا فروشگاه واقعاً بدون محصول
 * است؟ این موضوع را از سورس به‌تنهایی قابل اثبات ندان» — and, of account 2,
 * «وابستگی‌های آن به محصولات، سفارش‌ها، سوابق مالی و سایر اطلاعات را بررسی
 * کن. اگر بدون وابستگی بود، راهکار حذف ایمن یا غیرفعال‌سازی آن را ارائه بده».
 *
 * «Has no dependencies» is a statement about rows, and no amount of reading
 * PHP produces it. What reading PHP DOES produce is the complete list of
 * places a vendor id can appear, and that is what `TABLES` below is: every
 * table this plugin's migrations create with a column holding a vendor's user
 * id, derived from the `CREATE TABLE` statements themselves rather than from
 * memory. Twenty-one of the fifty-nine tables carry one.
 *
 * So this script counts them, for whichever ids you name, and prints the
 * counts. It decides nothing and deletes nothing — the decision is the
 * owner's, and «هیچ داده‌ای را بدون تأیید مالک حذف نکن» is honoured here by
 * the absence of any way to.
 *
 * It is the second tool in this repository meant to be run on the owner's own
 * site, for the same reason as the first and under the same guard: there is no
 * `update_`, `delete_`, `add_`, `INSERT`, `UPDATE`, `DELETE` or `ALTER`
 * anywhere in it, and `tests/Unit/ShopDependencyCensusIsReadOnlyTest.php`
 * asserts that by reading the file with comments blanked.
 *
 *   wp eval-file tools/shop-dependency-census.php              # every shop this plugin knows
 *   wp eval-file tools/shop-dependency-census.php 2            # one id
 *   wp eval-file tools/shop-dependency-census.php 2 40 102     # several
 *
 * ### ⛔ What a zero here does and does not mean
 *
 * A zero on every row means **this plugin holds nothing for that id**. It does
 * NOT mean the id is safe to delete, and three things it cannot see are
 * printed beside it so nobody reads it as though it could:
 *
 *  - **WooCommerce orders.** An order's vendor link is a row in
 *    `tmc_order_items`, which IS counted — but an order placed before this
 *    plugin existed, or through Dokan, is a WooCommerce order with Dokan's own
 *    meta on it. The `dokan` section counts those separately.
 *  - **Authorship.** `post_author` on a product or an order is a WordPress
 *    fact, not one of ours, and deleting a user reassigns or orphans it. The
 *    `wordpress` section counts what the id authored.
 *  - **Other plugins.** Nothing here can enumerate a table this plugin did not
 *    create. A site with a membership, invoicing or CRM plugin may hold rows
 *    for that user that no section below counts.
 *
 * Nothing it prints is a secret: table names, row counts, role names and
 * whether a user exists. No emails, no amounts, no option values, no meta
 * values.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/shop-dependency-census.php [id ...]\n");
    return;
}

/**
 * Every table this plugin creates that carries a vendor's user id, and the
 * column it carries it in.
 *
 * ⛔ **Derived from the migrations, not remembered.** The list was produced by
 * walking every `CREATE TABLE` in `src/**` and keeping the ones with a
 * vendor-ish column, which is why it includes tables an audit by recollection
 * would miss — `tmc_brand_proposals`, `tmc_price_tiers`,
 * `tmc_vendor_money_unit`, `tmc_event_outbox`. A census that asks the wrong
 * set of tables answers «no dependencies» about the tables it thought of.
 */
final class TmcShopCensusTables
{
    /** @var array<string,string> table suffix => the column holding the id */
    public const TABLES = [
        // the shop itself
        'tmc_vendor_profiles' => 'user_id',
        'tmc_vendor_applications' => 'user_id',
        'tmc_vendor_stores' => 'user_id',
        'tmc_vendor_staff' => 'vendor_user_id',
        'tmc_vendor_change_requests' => 'vendor_user_id',
        'tmc_vendor_ratings' => 'vendor_user_id',
        'tmc_vendor_money_unit' => 'vendor_user_id',
        // its catalogue
        'tmc_products' => 'vendor_user_id',
        'tmc_product_revisions' => 'vendor_user_id',
        // ⛔ Found by the guard, not by this list: `M0019BaselineAndDecisions`
        // declares its suffix WITHOUT the `tmc_` prefix and prepends it in its
        // own `table()`, so the first version of the coverage test could not
        // see it and passed over a missing table (`alpha.80`).
        'tmc_product_decisions' => 'vendor_user_id',
        'tmc_brand_proposals' => 'vendor_user_id',
        // its trade
        'tmc_order_items' => 'vendor_user_id',
        'tmc_shipments' => 'vendor_user_id',
        'tmc_returns' => 'vendor_user_id',
        'tmc_coupons' => 'vendor_user_id',
        'tmc_price_tiers' => 'vendor_user_id',
        'tmc_tickets' => 'vendor_user_id',
        // its money
        'tmc_ledger_entries' => 'vendor_user_id',
        'tmc_withdrawals' => 'vendor_user_id',
        'tmc_withdrawal_lines' => 'vendor_user_id',
        // the imported past, and the decisions about it
        'tmc_dokan_staff_history' => 'vendor_user_id',
        'tmc_dokan_balance_history' => 'vendor_user_id',
        'tmc_dokan_withdraw_history' => 'vendor_user_id',
        'tmc_dokan_order_history' => 'vendor_user_id',
        'tmc_dokan_handover' => 'vendor_user_id',
        'tmc_dokan_shop_version' => 'vendor_user_id',
        'tmc_dokan_record_confirmation' => 'vendor_user_id',
        // the queue and the outbox, which can name a shop
        'tmc_jobs' => 'vendor_user_id',
        'tmc_event_outbox' => 'vendor_user_id',
        'tmc_notifications' => 'vendor_user_id',
    ];
}

global $wpdb;

$line = static function (string $key, mixed $value): void {
    echo $key . '=' . str_replace("\n", ' ', (string) $value) . "\n";
};
$head = static function (string $title): void {
    echo "\n## " . $title . "\n";
};

/** Whether a table exists at all, so «absent» is told from «empty». */
$present = static function (string $table) use ($wpdb): bool {
    return (string) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table)
    ) === $table;
};

/**
 * Rows for one id in one table — `null` when the read did not happen.
 *
 * ⛔ `get_var()` answers `null` for «no such row» and `null` for «the
 * statement failed», and here those are opposite answers: nought means «this
 * shop owns nothing in this table» and a failed read means «nobody knows».
 * So the table's existence is asked first, and after that a `null` can only
 * be a failure (`alpha.8`).
 */
$rows = static function (string $table, string $column, int $id) use ($wpdb): ?int {
    $answer = $wpdb->get_var(
        $wpdb->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = %d', $id)
    );
    return $answer === null ? null : (int) $answer;
};

$ids = [];
foreach ((array) ($args ?? []) as $arg) {
    if ((int) $arg > 0) {
        $ids[] = (int) $arg;
    }
}

// No id given: every shop this plugin has a trace of, so a census of «all»
// cannot miss the shop somebody forgot to name.
if ($ids === []) {
    $profiles = $wpdb->prefix . 'tmc_vendor_profiles';
    if ($present($profiles)) {
        foreach ((array) $wpdb->get_col('SELECT user_id FROM `' . $profiles . '` ORDER BY user_id ASC') as $value) {
            $ids[] = (int) $value;
        }
    }
    $products = $wpdb->prefix . 'tmc_products';
    if ($present($products)) {
        foreach ((array) $wpdb->get_col(
            'SELECT DISTINCT vendor_user_id FROM `' . $products . '` ORDER BY vendor_user_id ASC'
        ) as $value) {
            $ids[] = (int) $value;
        }
    }
    foreach (['tmc_dokan_balance_history', 'tmc_dokan_withdraw_history', 'tmc_dokan_staff_history'] as $suffix) {
        $table = $wpdb->prefix . $suffix;
        if ($present($table)) {
            foreach ((array) $wpdb->get_col(
                'SELECT DISTINCT vendor_user_id FROM `' . $table . '` ORDER BY vendor_user_id ASC'
            ) as $value) {
                $ids[] = (int) $value;
            }
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    sort($ids);
}

$line('census_tables_asked', count(TmcShopCensusTables::TABLES));
$line('ids', implode(',', $ids) ?: '__none__');

foreach ($ids as $id) {
    $head('shop ' . $id);

    // ---------------------------------------------------- this plugin's rows
    $total = 0;
    $unreadable = [];
    $absent = [];
    foreach (TmcShopCensusTables::TABLES as $suffix => $column) {
        $table = $wpdb->prefix . $suffix;
        if (!$present($table)) {
            $absent[] = $suffix;
            continue;
        }
        $count = $rows($table, $column, $id);
        if ($count === null) {
            $unreadable[] = $suffix;
            $line($suffix, '__unreadable__');
            continue;
        }
        $total += $count;
        // Only non-zero rows are printed, so the interesting lines are not
        // buried under twenty-eight noughts; the total below covers the rest.
        if ($count > 0) {
            $line($suffix, $count);
        }
    }
    $line('plugin_rows_total', $total);
    $line('plugin_tables_unreadable', implode(',', $unreadable) ?: '0');
    $line('plugin_tables_absent', implode(',', $absent) ?: '0');
    // ⛔ The one sentence this script is for, and it is deliberately narrow.
    $line(
        'this_plugin_holds_nothing_for_this_id',
        $unreadable === [] ? ($total === 0 ? 'yes' : 'no') : '__unknown__'
    );

    // ---------------------------------------------------------- WordPress
    $user = get_userdata($id);
    $line('wp_user_exists', $user ? 'yes' : 'no');
    $line('wp_user_roles', $user ? implode(',', (array) $user->roles) : '__none__');
    $line('wp_products_authored', (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM `' . $wpdb->posts . "` WHERE post_author = %d AND post_type = 'product'",
        $id
    )));
    $line('wp_posts_authored_any_type', (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM `' . $wpdb->posts . '` WHERE post_author = %d',
        $id
    )));
    // A product that NAMES this shop in our stamp, whoever authored it.
    $line('wc_products_stamped_to_this_shop', (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM `' . $wpdb->postmeta . "` WHERE meta_key = '_tmc_vendor_user_id' AND meta_value = %s",
        (string) $id
    )));

    // -------------------------------------------------------------- Dokan
    // Only while it answers. After it is gone these counts are not «nought»,
    // they are unaskable, and the line says which.
    foreach ([
        'dokan_orders' => 'seller_id',
        'dokan_vendor_balance' => 'vendor_id',
        'dokan_withdraw' => 'user_id',
        'dokan_announcement' => 'sender_id',
        'dokan_refund' => 'vendor_id',
    ] as $suffix => $column) {
        $table = $wpdb->prefix . $suffix;
        if (!$present($table)) {
            $line($suffix, '__table_absent__');
            continue;
        }
        $count = $rows($table, $column, $id);
        $line($suffix, $count === null ? '__unreadable__' : $count);
    }
    // Dokan puts the shop on the ORDER as meta too, and an order is the row
    // that matters most when somebody asks whether an account can go.
    $hpos = $wpdb->prefix . 'wc_orders_meta';
    if ($present($hpos)) {
        $line('wc_orders_meta_naming_this_shop', (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM `' . $hpos . "` WHERE meta_key = '_dokan_vendor_id' AND meta_value = %s",
            (string) $id
        )));
    } else {
        $line('wc_orders_meta_naming_this_shop', '__table_absent__');
    }
    $line('wp_postmeta_orders_naming_this_shop', (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM `' . $wpdb->postmeta . "` WHERE meta_key = '_dokan_vendor_id' AND meta_value = %s",
        (string) $id
    )));
}

$head('what this cannot see');
echo "any table this plugin did not create; a membership, invoicing or CRM plugin may hold rows for these ids\n";
echo "post_author reassignment consequences; deleting a WordPress user is a WordPress decision, not ours\n";
echo "whether a zero is safe: that is the owner's call, and nothing here deletes or disables anything\n";
