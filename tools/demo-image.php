<?php
/**
 * Demo product pictures, drawn and written as PNG byte by byte.
 *
 * This host's PHP has neither GD nor Imagick and the box has no ImageMagick,
 * so everything here — the rasteriser and the PNG container — is arithmetic.
 * A PNG is a signature and three chunks, and `gzcompress` produces exactly
 * the zlib stream it wants.
 *
 * ### Why this was rewritten
 *
 * The first version drew one-bit silhouettes: a shape in ink on paper, hard
 * edged, no depth. They were enough to prove a picture travels through the
 * import, and the owner named them for what they were — «تصاویر هندسیِ آزمون،
 * ارائهٔ نهایی طراحی نیستند». A preview shown to somebody judging the DESIGN
 * has to look like the thing it stands for.
 *
 * So this is a small painter's-algorithm rasteriser: shapes in normalised
 * coordinates, composited front to back, sampled 2×2 per pixel so the curves
 * are smooth instead of stepped. Six subjects, each recognisable at the size
 * a product card actually shows — a stethoscope, a blood-pressure monitor, a
 * syringe, a thermometer, a pulse oximeter, a mask.
 *
 * They are still ILLUSTRATIONS and are meant to read as illustrations. A
 * placeholder that pretends to be a photograph is worse than one that does
 * not: somebody eventually ships it.
 *
 * DEMO DATA. Everything this writes lives on the disposable site; the install
 * package ships no images of its own and a packaging test says so.
 *
 * Included, never run directly:  require_once ABSPATH . 'demo-image.php';
 */

// --- refuses to run anywhere but the disposable install -------------------
//
// This file touches WordPress and writes. On the owner's site that is not a
// tool, it is damage, and a docblock saying «disposable» stops nobody who
// pastes the command at the wrong shell. Two independent facts, the same
// pair tools/disposable-site.sh trusts — NOT wp_get_environment_type(),
// which reports `production` on the disposable container itself.
// The rasteriser half is arithmetic and touches nothing, so a plain CLI run
// with no WordPress under it is let through to WRITE FILES and stop. That is
// how the demo environment gets its pictures: they are drawn to disk here and
// then uploaded through the product form like any other image, rather than
// injected into a site's media library behind the UI's back.
//
//   php tools/demo-image.php <output-dir>
if (PHP_SAPI === 'cli' && !defined('ABSPATH')) {
    $dir = $argv[1] ?? '';
    if ($dir === '') {
        fwrite(STDERR, "usage: php tools/demo-image.php <output-dir>\n");
        exit(2);
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    require __DIR__ . '/demo-image-shapes.php';
    foreach (tmc_demo_shapes() as $shape) {
        $file = rtrim($dir, '/') . '/' . $shape . '.png';
        file_put_contents($file, tmc_demo_png(600, [0x14, 0x57, 0x7A], [0xF4, 0xF8, 0xFA], $shape));
        printf("wrote %s (%d bytes)\n", $file, filesize($file));
    }
    exit(0);
}

if (!defined('DB_NAME') || DB_NAME !== 'tmc_wp_test') {
    fwrite(STDERR, "refused: DB_NAME is not the disposable tmc_wp_test. This tool writes and will not run here.\n");
    echo "refused=1 reason=database_is_not_the_disposable_one\n";
    return;
}
if (!preg_match('~^https?://(127\.0\.0\.1|localhost)(:\d+)?~', (string) home_url())) {
    fwrite(STDERR, "refused: home_url() is not local. This tool writes and will not run here.\n");
    echo "refused=1 reason=home_url_is_not_local\n";
    return;
}


require_once __DIR__ . '/demo-image-shapes.php';

if (!function_exists('tmc_demo_attach_image')) {
    /**
     * Writes a demo image into the uploads folder and attaches it to a post.
     * Returns the attachment id, or 0 when nothing was written.
     *
     * @param array{0:int,1:int,2:int} $ink
     */
    function tmc_demo_attach_image(int $postId, string $name, string $shape, array $ink): int
    {
        if ($postId <= 0) {
            return 0;
        }
        $uploads = wp_upload_dir();
        $file = $uploads['path'] . '/' . $name . '.png';
        file_put_contents($file, tmc_demo_png(600, $ink, [0xF4, 0xF8, 0xFA], $shape));

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachmentId = (int) wp_insert_attachment([
            'post_mime_type' => 'image/png',
            'post_title' => $name,
            'post_status' => 'inherit',
        ], $file, $postId);
        if ($attachmentId <= 0) {
            return 0;
        }
        wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $file));
        set_post_thumbnail($postId, $attachmentId);
        return $attachmentId;
    }
}
