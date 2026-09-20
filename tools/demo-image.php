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

if (!function_exists('tmc_demo_png')) {

    /** The subjects this file can draw, in the order a demo catalogue uses them. */
    function tmc_demo_shapes(): array
    {
        return ['stethoscope', 'monitor', 'syringe', 'thermometer', 'oximeter', 'mask'];
    }

    /**
     * One picture.
     *
     * `$ink` tints the subject so a caller can keep a catalogue from looking
     * like six copies of one colour; `$paper` is the card background. Both
     * stay parameters because the callers already pass them.
     *
     * @param array{0:int,1:int,2:int} $ink
     * @param array{0:int,1:int,2:int} $paper
     */
    function tmc_demo_png(int $size, array $ink, array $paper, string $shape = 'stethoscope'): string
    {
        $shapes = tmc_demo_scene($shape, $ink);
        $samples = 2;                       // 2×2 per pixel: smooth curves, seconds not minutes
        $step = 1.0 / ($size * $samples);
        $raw = '';

        for ($y = 0; $y < $size; $y++) {
            $raw .= chr(0);                 // filter type 0 for this scanline
            for ($x = 0; $x < $size; $x++) {
                $r = 0;
                $g = 0;
                $b = 0;
                for ($sy = 0; $sy < $samples; $sy++) {
                    for ($sx = 0; $sx < $samples; $sx++) {
                        $u = ($x * $samples + $sx + 0.5) * $step;
                        $v = ($y * $samples + $sy + 0.5) * $step;
                        $c = tmc_demo_sample($shapes, $u, $v, $paper);
                        $r += $c[0];
                        $g += $c[1];
                        $b += $c[2];
                    }
                }
                $n = $samples * $samples;
                $raw .= chr(intdiv($r, $n)) . chr(intdiv($g, $n)) . chr(intdiv($b, $n));
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

    /**
     * The colour at one point: the LAST shape covering it wins.
     *
     * Painter's algorithm, which is all a flat illustration needs and is the
     * reason the scene lists are written back to front.
     *
     * @param list<array<string,mixed>> $shapes
     * @param array{0:int,1:int,2:int} $paper
     * @return array{0:int,1:int,2:int}
     */
    function tmc_demo_sample(array $shapes, float $u, float $v, array $paper): array
    {
        $colour = $paper;
        foreach ($shapes as $shape) {
            if (tmc_demo_covers($shape, $u, $v)) {
                $colour = $shape['fill'];
            }
        }
        return $colour;
    }

    /** @param array<string,mixed> $s */
    function tmc_demo_covers(array $s, float $u, float $v): bool
    {
        switch ($s['kind']) {
            case 'circle':
                return tmc_demo_dist($u, $v, $s['x'], $s['y']) <= $s['r'];
            case 'ring':
                $d = tmc_demo_dist($u, $v, $s['x'], $s['y']);
                return $d <= $s['r'] && $d >= $s['r'] - $s['w'];
            // An arc is a ring cut by a half-plane: `from`..`to` in turns,
            // measured clockwise from «east», which is how the tubing of a
            // stethoscope and the cuff of a monitor are drawn.
            case 'arc':
                $d = tmc_demo_dist($u, $v, $s['x'], $s['y']);
                if ($d > $s['r'] || $d < $s['r'] - $s['w']) {
                    return false;
                }
                $a = atan2($v - $s['y'], $u - $s['x']) / (2 * M_PI);
                $a = $a < 0 ? $a + 1.0 : $a;
                return $s['from'] <= $s['to']
                    ? ($a >= $s['from'] && $a <= $s['to'])
                    : ($a >= $s['from'] || $a <= $s['to']);
            case 'capsule':                 // a thick line with round ends
                return tmc_demo_segment($u, $v, $s['x1'], $s['y1'], $s['x2'], $s['y2']) <= $s['r'];
            case 'rrect':
            default:
                $rx = max(0.0, min($s['r'], $s['w'] / 2));
                $ry = max(0.0, min($s['r'], $s['h'] / 2));
                $left = $s['x'];
                $top = $s['y'];
                $right = $s['x'] + $s['w'];
                $bottom = $s['y'] + $s['h'];
                if ($u < $left || $u > $right || $v < $top || $v > $bottom) {
                    return false;
                }
                // Inside the cross; only the four corner quadrants can reject.
                $cx = $u < $left + $rx ? $left + $rx : ($u > $right - $rx ? $right - $rx : $u);
                $cy = $v < $top + $ry ? $top + $ry : ($v > $bottom - $ry ? $bottom - $ry : $v);
                if ($cx === $u || $cy === $v) {
                    return true;
                }
                $dx = ($u - $cx) / max($rx, 1e-6);
                $dy = ($v - $cy) / max($ry, 1e-6);
                return ($dx * $dx + $dy * $dy) <= 1.0;
        }
    }

    function tmc_demo_dist(float $x1, float $y1, float $x2, float $y2): float
    {
        return sqrt(($x1 - $x2) ** 2 + ($y1 - $y2) ** 2);
    }

    /** Shortest distance from a point to a segment. */
    function tmc_demo_segment(float $px, float $py, float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len = $dx * $dx + $dy * $dy;
        if ($len <= 1e-9) {
            return tmc_demo_dist($px, $py, $x1, $y1);
        }
        $t = max(0.0, min(1.0, (($px - $x1) * $dx + ($py - $y1) * $dy) / $len));
        return tmc_demo_dist($px, $py, $x1 + $t * $dx, $y1 + $t * $dy);
    }

    /**
     * Shades of the caller's ink, so one picture reads as one object rather
     * than as a collage: a light face, the ink itself, and a darker edge.
     *
     * @param array{0:int,1:int,2:int} $ink
     * @return array{0:int,1:int,2:int}
     */
    function tmc_demo_shade(array $ink, float $amount): array
    {
        $mix = static function (int $c) use ($amount): int {
            $value = $amount >= 0
                ? $c + (255 - $c) * $amount        // towards white
                : $c * (1 + $amount);              // towards black
            return (int) max(0, min(255, round($value)));
        };
        return [$mix($ink[0]), $mix($ink[1]), $mix($ink[2])];
    }

    /**
     * One subject, back to front.
     *
     * @param array{0:int,1:int,2:int} $ink
     * @return list<array<string,mixed>>
     */
    function tmc_demo_scene(string $shape, array $ink): array
    {
        $dark = tmc_demo_shade($ink, -0.45);
        $mid = $ink;
        $light = tmc_demo_shade($ink, 0.55);
        $pale = tmc_demo_shade($ink, 0.82);
        $steel = [0xB8, 0xC4, 0xCC];
        $steelDark = [0x8A, 0x99, 0xA3];
        $white = [0xFA, 0xFC, 0xFD];
        $glass = [0xE6, 0xF1, 0xF6];
        $shadow = [0xDD, 0xE6, 0xEB];

        // Every subject sits on the same soft disc, so a row of cards has one
        // horizon instead of six.
        $stage = [
            ['kind' => 'circle', 'x' => 0.5, 'y' => 0.52, 'r' => 0.40, 'fill' => $pale],
            ['kind' => 'rrect', 'x' => 0.20, 'y' => 0.845, 'w' => 0.60, 'h' => 0.030, 'r' => 0.015, 'fill' => $shadow],
        ];

        switch ($shape) {
            case 'monitor':     // a blood-pressure monitor: cuff and display
                // The cuff is a C with its opening facing the unit, and the
                // tube starts exactly where the C ends — worked out from the
                // arc's own geometry rather than eyeballed, because a tube
                // that stops in mid-air is the first thing anybody sees.
                return array_merge($stage, [
                    ['kind' => 'arc', 'x' => 0.33, 'y' => 0.52, 'r' => 0.215, 'w' => 0.075, 'from' => 0.08, 'to' => 0.92, 'fill' => $mid],
                    ['kind' => 'arc', 'x' => 0.33, 'y' => 0.52, 'r' => 0.178, 'w' => 0.020, 'from' => 0.08, 'to' => 0.92, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.512, 'y1' => 0.623, 'x2' => 0.655, 'y2' => 0.585, 'r' => 0.017, 'fill' => $dark],
                    ['kind' => 'rrect', 'x' => 0.600, 'y' => 0.165, 'w' => 0.315, 'h' => 0.420, 'r' => 0.055, 'fill' => $white],
                    ['kind' => 'rrect', 'x' => 0.630, 'y' => 0.200, 'w' => 0.255, 'h' => 0.235, 'r' => 0.030, 'fill' => $glass],
                    ['kind' => 'rrect', 'x' => 0.662, 'y' => 0.240, 'w' => 0.135, 'h' => 0.048, 'r' => 0.012, 'fill' => $dark],
                    ['kind' => 'rrect', 'x' => 0.662, 'y' => 0.315, 'w' => 0.092, 'h' => 0.036, 'r' => 0.010, 'fill' => $steelDark],
                    ['kind' => 'circle', 'x' => 0.757, 'y' => 0.512, 'r' => 0.036, 'fill' => $mid],
                ]);

            case 'syringe':     // barrel, plunger, graduations, needle
                return array_merge($stage, [
                    ['kind' => 'capsule', 'x1' => 0.245, 'y1' => 0.62, 'x2' => 0.66, 'y2' => 0.34, 'r' => 0.075, 'fill' => $white],
                    ['kind' => 'capsule', 'x1' => 0.245, 'y1' => 0.62, 'x2' => 0.44, 'y2' => 0.487, 'r' => 0.058, 'fill' => $light],
                    // graduations
                    ['kind' => 'capsule', 'x1' => 0.470, 'y1' => 0.500, 'x2' => 0.494, 'y2' => 0.534, 'r' => 0.007, 'fill' => $steelDark],
                    ['kind' => 'capsule', 'x1' => 0.530, 'y1' => 0.459, 'x2' => 0.554, 'y2' => 0.493, 'r' => 0.007, 'fill' => $steelDark],
                    ['kind' => 'capsule', 'x1' => 0.590, 'y1' => 0.418, 'x2' => 0.614, 'y2' => 0.452, 'r' => 0.007, 'fill' => $steelDark],
                    // needle and hub
                    ['kind' => 'capsule', 'x1' => 0.655, 'y1' => 0.345, 'x2' => 0.715, 'y2' => 0.304, 'r' => 0.030, 'fill' => $mid],
                    ['kind' => 'capsule', 'x1' => 0.712, 'y1' => 0.306, 'x2' => 0.855, 'y2' => 0.208, 'r' => 0.010, 'fill' => $steel],
                    // plunger rod and thumb rest
                    ['kind' => 'capsule', 'x1' => 0.150, 'y1' => 0.685, 'x2' => 0.262, 'y2' => 0.608, 'r' => 0.018, 'fill' => $dark],
                    ['kind' => 'capsule', 'x1' => 0.108, 'y1' => 0.682, 'x2' => 0.176, 'y2' => 0.752, 'r' => 0.018, 'fill' => $dark],
                ]);

            case 'thermometer': // a digital thermometer, tip down
                return array_merge($stage, [
                    ['kind' => 'capsule', 'x1' => 0.50, 'y1' => 0.215, 'x2' => 0.50, 'y2' => 0.78, 'r' => 0.072, 'fill' => $white],
                    ['kind' => 'capsule', 'x1' => 0.50, 'y1' => 0.712, 'x2' => 0.50, 'y2' => 0.796, 'r' => 0.038, 'fill' => $steel],
                    ['kind' => 'rrect', 'x' => 0.443, 'y' => 0.268, 'w' => 0.114, 'h' => 0.150, 'r' => 0.022, 'fill' => $glass],
                    ['kind' => 'rrect', 'x' => 0.466, 'y' => 0.300, 'w' => 0.020, 'h' => 0.085, 'r' => 0.006, 'fill' => $dark],
                    ['kind' => 'rrect', 'x' => 0.500, 'y' => 0.300, 'w' => 0.020, 'h' => 0.085, 'r' => 0.006, 'fill' => $dark],
                    ['kind' => 'circle', 'x' => 0.50, 'y' => 0.470, 'r' => 0.026, 'fill' => $mid],
                    ['kind' => 'capsule', 'x1' => 0.468, 'y1' => 0.545, 'x2' => 0.532, 'y2' => 0.545, 'r' => 0.009, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.468, 'y1' => 0.590, 'x2' => 0.532, 'y2' => 0.590, 'r' => 0.009, 'fill' => $light],
                ]);

            case 'oximeter':    // a fingertip pulse oximeter, clipped open
                // The finger goes in FIRST and the clip covers its end, which
                // is what makes it read as clipped ON something rather than as
                // a box with a sausage beside it.
                return array_merge($stage, [
                    // NOT `$pale`: that is the stage disc's own colour, and a
                    // finger painted in it vanished the moment it crossed the
                    // disc — leaving a blob floating off to the left. An
                    // outline behind it keeps the edge readable either way.
                    ['kind' => 'capsule', 'x1' => 0.205, 'y1' => 0.500, 'x2' => 0.430, 'y2' => 0.500, 'r' => 0.086, 'fill' => $steel],
                    ['kind' => 'capsule', 'x1' => 0.205, 'y1' => 0.500, 'x2' => 0.430, 'y2' => 0.500, 'r' => 0.078, 'fill' => $glass],
                    ['kind' => 'capsule', 'x1' => 0.205, 'y1' => 0.500, 'x2' => 0.240, 'y2' => 0.500, 'r' => 0.078, 'fill' => $white],
                    ['kind' => 'rrect', 'x' => 0.300, 'y' => 0.280, 'w' => 0.480, 'h' => 0.440, 'r' => 0.070, 'fill' => $white],
                    // the jaw line: this is a clip, and a clip has a seam
                    ['kind' => 'capsule', 'x1' => 0.320, 'y1' => 0.585, 'x2' => 0.760, 'y2' => 0.585, 'r' => 0.008, 'fill' => $steel],
                    ['kind' => 'circle', 'x' => 0.780, 'y' => 0.585, 'r' => 0.042, 'fill' => $mid],
                    // screen: the two numbers, and the trace under them
                    ['kind' => 'rrect', 'x' => 0.340, 'y' => 0.320, 'w' => 0.400, 'h' => 0.215, 'r' => 0.030, 'fill' => $dark],
                    ['kind' => 'rrect', 'x' => 0.375, 'y' => 0.352, 'w' => 0.120, 'h' => 0.058, 'r' => 0.014, 'fill' => $light],
                    ['kind' => 'rrect', 'x' => 0.540, 'y' => 0.352, 'w' => 0.085, 'h' => 0.058, 'r' => 0.014, 'fill' => $steel],
                    ['kind' => 'capsule', 'x1' => 0.375, 'y1' => 0.470, 'x2' => 0.445, 'y2' => 0.470, 'r' => 0.007, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.445, 'y1' => 0.470, 'x2' => 0.475, 'y2' => 0.440, 'r' => 0.007, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.475, 'y1' => 0.440, 'x2' => 0.505, 'y2' => 0.500, 'r' => 0.007, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.505, 'y1' => 0.500, 'x2' => 0.705, 'y2' => 0.470, 'r' => 0.007, 'fill' => $light],
                ]);

            case 'mask':        // a pleated mask with ear loops
                return array_merge($stage, [
                    // Angles run clockwise from «east» because v grows downward,
                    // so the LEFT loop is the half around 0.5 and the right one
                    // is the half that wraps through 0. Written the other way
                    // round first, which drew each loop on the side the mask
                    // covers — two little horns instead of ear loops.
                    ['kind' => 'arc', 'x' => 0.200, 'y' => 0.50, 'r' => 0.150, 'w' => 0.022, 'from' => 0.30, 'to' => 0.70, 'fill' => $dark],
                    ['kind' => 'arc', 'x' => 0.800, 'y' => 0.50, 'r' => 0.150, 'w' => 0.022, 'from' => 0.80, 'to' => 0.20, 'fill' => $dark],
                    ['kind' => 'rrect', 'x' => 0.235, 'y' => 0.330, 'w' => 0.530, 'h' => 0.345, 'r' => 0.085, 'fill' => $white],
                    ['kind' => 'capsule', 'x1' => 0.262, 'y1' => 0.425, 'x2' => 0.738, 'y2' => 0.425, 'r' => 0.013, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.262, 'y1' => 0.500, 'x2' => 0.738, 'y2' => 0.500, 'r' => 0.013, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.262, 'y1' => 0.575, 'x2' => 0.738, 'y2' => 0.575, 'r' => 0.013, 'fill' => $light],
                    ['kind' => 'capsule', 'x1' => 0.300, 'y1' => 0.360, 'x2' => 0.700, 'y2' => 0.360, 'r' => 0.014, 'fill' => $mid],
                ]);

            case 'stethoscope':
            default:
                // A Y, built as connected segments rather than as an arc plus
                // two stubs. The first version used an arc for the headband and
                // the tube to the chest piece began below where the arc ended,
                // so the stethoscope came apart in the middle.
                return array_merge($stage, [
                    ['kind' => 'capsule', 'x1' => 0.252, 'y1' => 0.250, 'x2' => 0.330, 'y2' => 0.410, 'r' => 0.017, 'fill' => $mid],
                    ['kind' => 'capsule', 'x1' => 0.330, 'y1' => 0.410, 'x2' => 0.492, 'y2' => 0.548, 'r' => 0.017, 'fill' => $mid],
                    ['kind' => 'capsule', 'x1' => 0.748, 'y1' => 0.250, 'x2' => 0.670, 'y2' => 0.410, 'r' => 0.017, 'fill' => $mid],
                    ['kind' => 'capsule', 'x1' => 0.670, 'y1' => 0.410, 'x2' => 0.508, 'y2' => 0.548, 'r' => 0.017, 'fill' => $mid],
                    ['kind' => 'circle', 'x' => 0.248, 'y' => 0.238, 'r' => 0.034, 'fill' => $dark],
                    ['kind' => 'circle', 'x' => 0.752, 'y' => 0.238, 'r' => 0.034, 'fill' => $dark],
                    ['kind' => 'capsule', 'x1' => 0.500, 'y1' => 0.540, 'x2' => 0.500, 'y2' => 0.672, 'r' => 0.020, 'fill' => $mid],
                    ['kind' => 'circle', 'x' => 0.500, 'y' => 0.548, 'r' => 0.030, 'fill' => $dark],
                    ['kind' => 'circle', 'x' => 0.500, 'y' => 0.752, 'r' => 0.098, 'fill' => $steelDark],
                    ['kind' => 'circle', 'x' => 0.500, 'y' => 0.752, 'r' => 0.080, 'fill' => $steel],
                    ['kind' => 'circle', 'x' => 0.500, 'y' => 0.752, 'r' => 0.054, 'fill' => $white],
                ]);
        }
    }

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
