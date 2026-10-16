<?php
// The two spellings PHP cannot judge, asked on the real site from the final ZIP.
global $wpdb;
$wcId = (int) wc_get_product_id_by_sku('WC-U56-UPGRADE');
if ($wcId <= 0) { $ids = get_posts(['post_type'=>'product','numberposts'=>1,'fields'=>'ids','post_status'=>'any']); $wcId = (int) ($ids[0] ?? 0); }
echo "product={$wcId}\n";
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);
update_post_meta($wcId, '_tmc_commission_type', 'percent');
update_post_meta($wcId, '_tmc_commission_value', '12.50');
echo "baseline=" . get_post_meta($wcId, '_tmc_commission_type', true) . "/" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";

$uid = username_exists('tmc-guard-probe-manager');
wp_set_current_user((int) $uid);
echo "manager_has_financial_cap=" . (current_user_can('tmc_review_withdrawals') ? 'yes' : 'no') . "\n";

$zwsp = "_tmc_commission\u{200B}_type";
$wide = "_\u{FF34}MC_COMMISSION_VALUE";
echo "collation_calls_zwsp_ours=" . (string) $wpdb->get_var($wpdb->prepare("SELECT CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci = CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci", $zwsp, '_tmc_commission_type')) . "\n";
echo "collation_calls_wide_ours=" . (string) $wpdb->get_var($wpdb->prepare("SELECT CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci = CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_520_ci", $wide, '_tmc_commission_value')) . "\n";
echo "zwsp_write=" . (update_post_meta($wcId, $zwsp, 'fixed') ? 'landed' : 'refused') . "\n";
echo "wide_write=" . (update_post_meta($wcId, $wide, '999999') ? 'landed' : 'refused') . "\n";
wp_cache_delete($wcId, 'post_meta');
echo "type_after=" . get_post_meta($wcId, '_tmc_commission_type', true) . "\n";
echo "value_after=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
// Control: WooCommerce's own percent-encoded variation key still saves.
echo "variation_key_write=" . (update_post_meta($wcId, 'attribute_%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87', 'بزرگ') ? 'landed' : 'refused') . "\n";
delete_post_meta($wcId, 'attribute_%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87');
