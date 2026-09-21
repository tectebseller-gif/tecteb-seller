<?php
/**
 * Router for PHP's built-in web server, so the demo has pretty permalinks.
 *
 * This lived only inside the disposable WordPress root until `alpha.24`, which
 * meant the demo build depended on a file the repository did not own — and on
 * a bug nobody could see in a diff. The old version sent every path that is
 * not a FILE to the front-end `index.php`, so `/wp-admin/` — a directory —
 * was handled as a front-end request and WordPress's canonical redirect
 * answered it with `301 → /`. Every other admin URL worked, because
 * `/wp-admin/plugins.php` IS a file; only the dashboard's own address was
 * broken, which is why the owner-guide run signed in successfully and then
 * reported «signed in: no».
 *
 * A real web server serves a directory's `index.php`. So does this now.
 *
 *   php -S 127.0.0.1:8081 -t <wordpress-root> <this file>
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;                       // a real file: let the server send it
}

if ($path !== '/' && is_dir($file)) {
    // `/wp-admin` and `/wp-admin/` are different URLs to WordPress: the admin
    // builds its own links from the trailing-slash form, and a page served at
    // the other one loads its assets from the wrong place.
    if (substr($path, -1) !== '/') {
        header('Location: ' . $path . '/', true, 301);
        return true;
    }
    $index = $file . 'index.php';
    if (is_file($index)) {
        // WordPress reads $pagenow out of these, and the admin behaves
        // differently for `index.php` than for a router it has never heard of.
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path . 'index.php';
        $_SERVER['SCRIPT_FILENAME'] = $index;
        require_once $index;
        return true;
    }
}

require_once __DIR__ . '/index.php';
