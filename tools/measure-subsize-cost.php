<?php
/**
 * What one imported picture actually costs, by the size of the original.
 *
 * ⛔ DISPOSABLE INSTALL ONLY. This creates attachments and deletes them again.
 *
 * ### Why this exists
 *
 * On staging with `alpha.74`, job #1 lands one to three products per manual
 * run and then the host returns Request Timeout, with nothing in `debug.log`
 * or `error_log`. The host has not identified the cause and has offered one
 * hypothesis: that something is waiting on an external service.
 *
 * Before accepting that, the cheaper question has to be asked — how long does
 * the intake take with NO external service in it at all? The marketplace
 * plugin issues no outbound request anywhere (`grep` over `src/` for
 * `wp_remote_*`, `download_url`, `media_sideload_image`, `curl_init` and
 * `fsockopen` returns nothing), so a run on this container, with no
 * optimisation plugin and nothing registered on
 * `wp_generate_attachment_metadata`, is a floor for the cost.
 *
 * It measures the two halves of `carryImage()` separately, because the
 * docblock there calls them one slow half and they are not comparable:
 * the `copy()` of the file, and `wp_generate_attachment_metadata()`.
 *
 * ### What it does NOT establish
 *
 * Nothing about `staging.tecteb.com`. The figures depend on the image editor
 * (GD here, Imagick elsewhere and much faster), on how many image sizes the
 * theme registers, and on how big the originals are — all three differ per
 * site. Which is the whole reason the companion probe
 * (`plugin-exit-inventory.php intake`) reports those three from the site
 * itself rather than assuming them.
 *
 *   wp eval-file tools/measure-subsize-cost.php            # the default ladder
 *   wp eval-file tools/measure-subsize-cost.php 2000,3500  # chosen widths
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "run this through: wp eval-file tools/measure-subsize-cost.php\n");
    return;
}

// The refusal comes first, before this file names a single WordPress symbol
// other than the `home_url()` the guard itself needs. A check further down
// would be a sentence, not a guard — `DisposableOnlyToolsTest` measures the
// byte offset for exactly that reason.
if (!defined('DB_NAME') || (DB_NAME !== 'tmc_wp_test' && DB_NAME !== 'tmc_wp_demo')) {
    fwrite(STDERR, "refused: DB_NAME is not a disposable database. This tool creates attachments and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool creates attachments and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}
if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
    echo "refused=1 reason=no_gd_to_draw_a_source_with\n";
    return;
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$widths = [];
foreach (explode(',', (string) ($args[0] ?? '800,1600,3000,4500')) as $candidate) {
    $width = (int) trim($candidate);
    if ($width >= 100 && $width <= 8000) {
        $widths[] = $width;
    }
}
if ($widths === []) {
    echo "refused=1 reason=no_usable_widths\n";
    return;
}

$uploads = wp_upload_dir();
if (!empty($uploads['error'])) {
    echo "refused=1 reason=uploads_unavailable\n";
    return;
}

// ⛔ `class_exists()` on the editor classes answers NO on a normal request:
// core requires those files inside `_wp_image_editor_choose()` and nowhere
// else, so the first version of this line printed `unknown` on a site with a
// perfectly good GD. Ask the chooser, which loads them and returns the class
// it picked.
$editor = function_exists('_wp_image_editor_choose')
    ? (string) (_wp_image_editor_choose(['mime_type' => 'image/jpeg']) ?: 'none')
    : 'unknown';
echo 'editor_for_jpeg=' . $editor . "\n";
echo 'registered_sizes=' . count(get_intermediate_image_sizes()) . "\n";
echo 'big_image_size_threshold=' . (int) apply_filters('big_image_size_threshold', 2560, [0, 0], '', 0) . "\n";
echo 'callbacks_on_generate=' . count(
    (array) (($GLOBALS['wp_filter']['wp_generate_attachment_metadata'] ?? null) instanceof WP_Hook
        ? $GLOBALS['wp_filter']['wp_generate_attachment_metadata']->callbacks
        : [])
) . "\n";

foreach ($widths as $width) {
    // A noisy source, so the JPEG encoder cannot cheat on a flat image and
    // report a cost nobody's photograph would have.
    $image = imagecreatetruecolor($width, $width);
    for ($i = 0; $i < 4000; $i++) {
        imagefilledrectangle(
            $image,
            random_int(0, $width - 1),
            random_int(0, $width - 1),
            random_int(0, $width - 1),
            random_int(0, $width - 1),
            imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255))
        );
    }
    $source = trailingslashit((string) $uploads['path']) . 'tmc-measure-source-' . $width . '.jpg';
    imagejpeg($image, $source, 86);
    imagedestroy($image);

    // The two halves, timed apart.
    $copyAt = hrtime(true);
    $copy = trailingslashit((string) $uploads['path']) . 'tmc-measure-copy-' . $width . '.jpg';
    $copied = @copy($source, $copy);
    $copyMs = (hrtime(true) - $copyAt) / 1e6;
    if (!$copied) {
        echo 'source=' . $width . 'x' . $width . " copy=failed\n";
        @unlink($source);
        continue;
    }

    $id = (int) wp_insert_attachment(
        ['post_mime_type' => 'image/jpeg', 'post_title' => 'tmc subsize measurement', 'post_status' => 'inherit'],
        $copy
    );
    $generateAt = hrtime(true);
    $meta = wp_generate_attachment_metadata($id, $copy);
    $generateMs = (hrtime(true) - $generateAt) / 1e6;
    wp_update_attachment_metadata($id, $meta);

    printf(
        "source=%dx%d bytes=%d copy_ms=%.2f generate_ms=%.2f subsizes=%d scaled_original=%s\n",
        $width,
        $width,
        (int) filesize($source),
        $copyMs,
        $generateMs,
        count((array) ($meta['sizes'] ?? [])),
        isset($meta['original_image']) ? 'yes' : 'no'
    );

    // Everything it made, taken back.
    wp_delete_attachment($id, true);
    @unlink($source);
    @unlink($copy);
}
echo "cleaned=yes\n";
