<?php
/**
 * A deliberate storage failure, installed OUTSIDE the plugin.
 *
 * The owner's instruction for this round is «ایمنی پس از غیرفعال‌سازی و بازگشت
 * را با ایجاد عمدی خطای ذخیره … بررسی کن», and a failure the plugin knows how
 * to produce is not that: it would prove our own branch, not the shop's
 * behaviour. So the failure is injected by a separate mu-plugin that behaves
 * like any third-party plugin refusing a write — WooCommerce and this plugin
 * both see an ordinary, successful-looking save that did not take.
 *
 * Copied into wp-content/mu-plugins/ by tools/stop-failure-check.sh on a
 * DISPOSABLE WordPress. It is inert unless the option below names a product,
 * so it can be left in place between runs.
 *
 *   wp option update tmc_probe_block_product <wc-product-id>
 *   wp option delete tmc_probe_block_product
 */

add_filter('wp_insert_post_data', static function (array $data, array $postarr) {
    $blocked = (int) get_option('tmc_probe_block_product', 0);
    if ($blocked <= 0) {
        return $data;
    }
    $id = (int) ($postarr['ID'] ?? 0);
    if ($id !== $blocked) {
        return $data;
    }
    // The write goes through and reports success; the status simply does not
    // change. This is what a misbehaving plugin, a stale object cache or a
    // replica lag looks like from the caller's side.
    $data['post_status'] = 'publish';
    return $data;
}, 999, 2);
