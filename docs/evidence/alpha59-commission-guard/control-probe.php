<?php
// CONTROL: the shop manager's own custom product meta, through WooCommerce's
// object — add, then update, then delete, each in its own process so the
// second and third really go through `update_metadata_by_mid()` /
// `delete_metadata_by_mid()`, the filters this round added.
//
// The first attempt used `_purchase_note` and the delete appeared to fail.
// That was not the guard: `_purchase_note` is in WooCommerce's
// `$internal_meta_keys` (`class-wc-product-data-store-cpt.php:59`), so
// `WC_Product` manages it as a PROP and `delete_meta_data()` never sees it.
$stage = $args[0] ?? 'add';
$wcId = (int) wc_get_product_id_by_sku('WC-U56-UPGRADE');
if ($wcId <= 0) {
    $ids = get_posts(['post_type' => 'product', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any']);
    $wcId = (int) ($ids[0] ?? 0);
}
if ($wcId <= 0) { echo "no_product\n"; return; }
$uid = username_exists('tmc-guard-probe-manager');
wp_set_current_user((int) $uid);
echo "stage={$stage} product={$wcId}\n";
echo "manager_has_financial_cap=" . (current_user_can('tmc_review_withdrawals') ? 'yes' : 'no') . "\n";

$p = wc_get_product($wcId);
if ($stage === 'delete') {
    $p->delete_meta_data('_tmc_control_note');
} else {
    $p->update_meta_data('_tmc_control_note', $stage === 'add' ? 'first' : 'second');
}
$p->save();
wp_cache_delete($wcId, 'post_meta');
echo "control_note=[" . get_post_meta($wcId, '_tmc_control_note', true) . "]\n";
echo "commission_untouched=" . get_post_meta($wcId, '_tmc_commission_value', true) . "\n";
