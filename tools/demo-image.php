<?php
/**
 * A PNG, written byte by byte — shared by the fixtures that need a picture.
 *
 * This host's PHP has neither GD nor Imagick and the box has no ImageMagick,
 * so the alternative to thirty lines here is a dependency that has to exist
 * on every machine that ever runs the evidence. A PNG is a signature and
 * three chunks, and `gzcompress` produces exactly the zlib stream it wants.
 *
 * DEMO DATA. Everything this writes lives on the disposable site; the install
 * package ships no images of its own and a packaging test says so.
 *
 * Included, never run directly:  require_once ABSPATH . 'demo-image.php';
 */

if (!function_exists('tmc_demo_png')) {
    /**
     * @param array{0:int,1:int,2:int} $ink
     * @param array{0:int,1:int,2:int} $paper
     */
    function tmc_demo_png(int $size, array $ink, array $paper, string $shape = 'jar'): string
    {
        $raw = '';
        for ($y = 0; $y < $size; $y++) {
            $raw .= chr(0);                     // filter type 0 for this scanline
            for ($x = 0; $x < $size; $x++) {
                $raw .= tmc_demo_pixel($x, $y, $size, $shape) ? tmc_demo_rgb($ink) : tmc_demo_rgb($paper);
            }
        }
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NN', $size, $size) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    /** @param array{0:int,1:int,2:int} $c */
    function tmc_demo_rgb(array $c): string
    {
        return chr($c[0]) . chr($c[1]) . chr($c[2]);
    }

    /**
     * Whether this pixel is part of the drawing.
     *
     * Four silhouettes that read as medical equipment at card size, drawn
     * with arithmetic rather than a drawing library. They are deliberately
     * simple: a placeholder that pretends to be a photograph is worse than
     * one that is plainly a placeholder.
     */
    function tmc_demo_pixel(int $x, int $y, int $size, string $shape): bool
    {
        $u = $x / $size;
        $v = $y / $size;
        switch ($shape) {
            case 'bottle':      // a reagent bottle: narrow neck, square body
                return ($u > 0.30 && $u < 0.70 && $v > 0.12 && $v <= 0.28)
                    || ($u > 0.18 && $u < 0.82 && $v > 0.28 && $v < 0.88);
            case 'box':         // a boxed device
                return ($u > 0.14 && $u < 0.86 && $v > 0.26 && $v < 0.82)
                    || ($u > 0.14 && $u < 0.86 && $v > 0.18 && $v <= 0.26);
            case 'syringe':     // a barrel with a plunger and a needle
                return ($u > 0.20 && $u < 0.74 && $v > 0.42 && $v < 0.58)
                    || ($u >= 0.74 && $u < 0.92 && $v > 0.48 && $v < 0.52)
                    || ($u > 0.12 && $u <= 0.20 && $v > 0.34 && $v < 0.66);
            case 'mask':        // a rounded mask with two straps
                $dx = ($u - 0.5) / 0.34;
                $dy = ($v - 0.5) / 0.24;
                return ($dx * $dx + $dy * $dy) <= 1.0
                    || (($v > 0.34 && $v < 0.40) && ($u < 0.18 || $u > 0.82))
                    || (($v > 0.60 && $v < 0.66) && ($u < 0.18 || $u > 0.82));
            case 'jar':
            default:
                return ($u > 0.34 && $u < 0.66 && $v > 0.14 && $v <= 0.30)
                    || ($u > 0.18 && $u < 0.82 && $v > 0.30 && $v < 0.86);
        }
    }

    /**
     * Writes a demo image into the uploads folder and attaches it to a post.
     * Returns the attachment id, or 0 when nothing was written.
     */
    function tmc_demo_attach_image(int $postId, string $name, string $shape, array $ink): int
    {
        if ($postId <= 0) {
            return 0;
        }
        $uploads = wp_upload_dir();
        $file = $uploads['path'] . '/' . $name . '.png';
        file_put_contents($file, tmc_demo_png(600, $ink, [0xED, 0xF3, 0xF6], $shape));

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
