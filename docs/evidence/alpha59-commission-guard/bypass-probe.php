<?php
// Two bypasses of the alpha.58 guard, measured on the installed bytes.
global $wpdb;
$wcId = (int) wc_get_product_id_by_sku('WC-U56-UPGRADE');
if ($wcId <= 0) {
    $ids = get_posts(['post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any']);
    $wcId = (int) ($ids[0] ?? 0);
}
echo "product={$wcId}\n";
echo "meta_key_collation=" . (string) $wpdb->get_var("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME='{$wpdb->postmeta}' AND COLUMN_NAME='meta_key'") . "\n";

// A manager who may decide money lays down the rule.
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);
update_post_meta($wcId, '_tmc_commission_type', 'percent');
update_post_meta($wcId, '_tmc_commission_value', '12.50');
echo "baseline_type=" . get_post_meta($wcId, '_tmc_commission_type', true) . "\n";
echo "baseline_value=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";

$login = 'tmc-guard-probe-manager';
$uid = username_exists($login);
if (!$uid) {
    $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'role' => 'shop_manager']);
}
wp_set_current_user((int) $uid);
echo "manager_has_financial_cap=" . (current_user_can('tmc_review_withdrawals') ? 'yes' : 'no') . "\n";

// ---- BYPASS 1: the path WooCommerce itself takes for an EXISTING meta row.
// WC_Data_Store_WP::update_meta() calls update_metadata_by_mid(), which fires
// `update_post_metadata_by_mid` — a filter the alpha.58 guard does not hook.
// This is what a REST `meta_data` entry carrying an `id` becomes.
$product = wc_get_product($wcId);
$product->update_meta_data('_tmc_commission_value', '999999');
$product->save();
wp_cache_delete($wcId, 'post_meta');
echo "after_wc_object_save=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
echo "row_value=" . (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_tmc_commission_value'", $wcId)) . "\n";

// …and the same object path deleting the type outright.
$product = wc_get_product($wcId);
$product->delete_meta_data('_tmc_commission_type');
$product->save();
wp_cache_delete($wcId, 'post_meta');
echo "type_after_wc_object_delete=[" . get_post_meta($wcId, '_tmc_commission_type', true) . "]\n";

// ---- BYPASS 2: the key MySQL calls the same and PHP `===` calls different.
$admins2 = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins2[0]);
update_post_meta($wcId, '_tmc_commission_type', 'percent');
update_post_meta($wcId, '_tmc_commission_value', '12.50');
wp_set_current_user((int) $uid);
echo "re_baseline_value=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
echo "uppercase_write=" . (update_post_meta($wcId, '_TMC_COMMISSION_VALUE', '777777') ? 'landed' : 'refused') . "\n";
wp_cache_delete($wcId, 'post_meta');
echo "value_after_uppercase=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
echo "rows_for_value=" . (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_tmc_commission_value'", $wcId)) . "\n";
echo "trailing_space_write=" . (update_post_meta($wcId, '_tmc_commission_value ', '888888') ? 'landed' : 'refused') . "\n";
wp_cache_delete($wcId, 'post_meta');
echo "value_after_trailing_space=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";

// ---- CONTROL: the shop manager's ORDINARY WooCommerce work must still save.
// This guard now intercepts `update_metadata_by_mid`, which WooCommerce uses
// for every meta row it already knows — so a guard that is one character too
// wide would stop the whole shop saving products. That is worse than the
// defect it fixes, so it is measured, not assumed.
$product = wc_get_product($wcId);
$product->update_meta_data('_purchase_note', 'control-note');
$product->set_regular_price('450000');
$product->save();
wp_cache_delete($wcId, 'post_meta');
echo "control_note=" . get_post_meta($wcId, '_purchase_note', true) . "\n";
echo "control_price=" . get_post_meta($wcId, '_regular_price', true) . "\n";
$product = wc_get_product($wcId);
$product->delete_meta_data('_purchase_note');
$product->save();
wp_cache_delete($wcId, 'post_meta');
echo "control_note_after_delete=[" . get_post_meta($wcId, '_purchase_note', true) . "]\n";
