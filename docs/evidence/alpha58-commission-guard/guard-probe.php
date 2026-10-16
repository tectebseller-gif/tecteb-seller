<?php
// Measured on the disposable WordPress, from the installed alpha.58 bytes.
// Every door WooCommerce offers ends in update_post_meta(); this asks THAT.
$wcId = (int) wc_get_product_id_by_sku('WC-U56-UPGRADE');
if ($wcId <= 0) {
    $ids = get_posts(['post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any']);
    $wcId = (int) ($ids[0] ?? 0);
}
echo "product={$wcId}\n";
echo "guard_registered=" . (has_filter('update_post_metadata', ['Tecteb\\Marketplace\\Modules\\Finance\\Infrastructure\\WooCommerce\\TectebCommissionMetaGuard', 'decide']) !== false ? 'yes' : 'no') . "\n";

// A manager who may decide money writes the rule.
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);
echo "admin_has_cap=" . (current_user_can('tmc_review_withdrawals') ? 'yes' : 'no') . "\n";
echo "admin_write=" . (update_post_meta($wcId, '_tmc_commission_type', 'percent') ? 'ok' : 'refused') . "\n";
update_post_meta($wcId, '_tmc_commission_value', '12.50');
echo "stored_type=" . get_post_meta($wcId, '_tmc_commission_type', true) . "\n";
echo "stored_value=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";

// A shop_manager: WooCommerce's REST and CSV importer both run as somebody
// like this, and this is the account the finding was about.
$login = 'tmc-guard-probe-manager';
$uid = username_exists($login);
if (!$uid) {
    $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'role' => 'shop_manager']);
}
wp_set_current_user((int) $uid);
echo "manager_can_edit_products=" . (current_user_can('edit_products') ? 'yes' : 'no') . "\n";
echo "manager_can_manage_woo=" . (current_user_can('manage_woocommerce') ? 'yes' : 'no') . "\n";
echo "manager_has_financial_cap=" . (current_user_can('tmc_review_withdrawals') ? 'yes' : 'no') . "\n";
echo "manager_update=" . (update_post_meta($wcId, '_tmc_commission_type', 'fixed') ? 'landed' : 'refused') . "\n";
echo "manager_update_value=" . (update_post_meta($wcId, '_tmc_commission_value', '999999') ? 'landed' : 'refused') . "\n";
echo "manager_delete=" . (delete_post_meta($wcId, '_tmc_commission_type') ? 'landed' : 'refused') . "\n";
echo "type_after_manager=" . get_post_meta($wcId, '_tmc_commission_type', true) . "\n";
echo "value_after_manager=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
// …and the guard is narrow: his ordinary product work is untouched.
echo "manager_other_meta=" . (update_post_meta($wcId, '_purchase_note', 'probe') ? 'landed' : 'refused') . "\n";
delete_post_meta($wcId, '_purchase_note');
