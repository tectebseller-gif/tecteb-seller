<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Infrastructure\WordPress;

/**
 * What this site's intake path costs and who else is standing on it.
 *
 * ### Why this is in the plugin and not only in a tool
 *
 * `tools/plugin-exit-inventory.php intake` answers the same questions and is
 * the right thing to run TODAY, against the package that is already installed,
 * without installing anything. But it needs WP-CLI or SSH, and that access is
 * unconfirmed — «دسترسی SSH و WP-CLI تأییدنشده است: مسیر جایگزینِ محدود به
 * مدیر در staging بده». This is that path: the same reads, from a page that
 * only a manager with `tmc_manage_jobs` can open.
 *
 * ⛔ And it answers one thing the tool cannot. The limit that matters is the
 * one a WEB request gets, and WP-CLI runs with `max_execution_time = 0`. The
 * tool says so about itself; this is measured inside the request that would be
 * cut off.
 *
 * ### What it may do, structurally
 *
 * NOTHING BUT READ. There is no write verb anywhere in this file and
 * `IntakeDiagnosticsAreReadOnlyTest` asserts that from the bytes — the same
 * guard the tool has, for the same reason: a diagnostic that can change the
 * thing it is measuring is not a diagnostic, and this one runs on the owner's
 * staging site while a stalled import is holding a lease.
 *
 * It is also BOUNDED. «ابزار تشخیص باید کافی و محدود باشد»: a fixed hook list,
 * at most `PICTURES` source images, counts rather than listings, and nothing
 * that grows with the catalogue. No option is written, no cache is primed, no
 * attachment is touched.
 *
 * ### What it deliberately does not record
 *
 * No password, token, cookie, request body or query string, and no customer
 * data: the values here are PHP settings, image dimensions, file counts, hook
 * names and the FILE PATHS OF PLUGIN CODE. A plugin's path is the answer to
 * «who is on this hook» and is the whole point; it is not somebody's data.
 *
 * ### The honest limit of «read only»
 *
 * What the test proves is that THIS FILE contains no writer. What it does not
 * prove, and is not claimed:
 *
 *  - `imageWork()` runs `apply_filters('big_image_size_threshold', …)`,
 *    because the effective threshold is whatever the filters make it and
 *    reading the default would be reporting a number this site does not use.
 *    A filter is a call into somebody else's code, and somebody else's code
 *    can write. One filter, chosen because its answer is a size.
 *  - the page renders inside wp-admin, so `plugins_loaded`, `init` and
 *    `admin_init` have already fired — as they do on every admin request,
 *    with or without this section.
 *
 * Neither is a reason to leave the facts unread; both are reasons not to call
 * this a guarantee about the whole request.
 */
final class IntakeDiagnostics
{
    /** How many of the next source pictures to measure. Bounded on purpose. */
    public const PICTURES = 5;

    /**
     * Every hook one product's import passes through, in call order, plus the
     * three that would see an outbound request leaving.
     *
     * Read from WordPress and WooCommerce source, not from memory — the list
     * in `tools/plugin-exit-inventory.php` is the same one and the two are
     * meant to stay identical.
     */
    public const HOOKS = [
        'get_attached_file',
        'upload_dir',
        'wp_unique_filename',
        'upload_mimes',
        'wp_insert_attachment_data',
        'add_attachment',
        'save_post_attachment',
        'save_post',
        'wp_after_insert_post',
        'wp_image_editors',
        'wp_read_image_metadata',
        'big_image_size_threshold',
        'intermediate_image_sizes_advanced',
        'wp_generate_attachment_metadata',
        'wp_update_attachment_metadata',
        'added_post_meta',
        'updated_post_meta',
        'pre_http_request',
        'http_request_args',
        'http_api_debug',
        'requests-requests.before_request',
    ];

    /**
     * The limits this REQUEST is running under.
     *
     * ⛔ `max_execution_time` is PHP's own and is not the deadline that kills
     * a request: under FPM that is usually `request_terminate_timeout`, or a
     * web server's read timeout, or a proxy, and PHP can see none of them.
     * Printed because it is what the budget is narrowed by, not because it is
     * the answer.
     *
     * @return array<string,string>
     */
    public static function limits(): array
    {
        $disabled = (string) ini_get('disable_functions');
        return [
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_input_time' => (string) ini_get('max_input_time'),
            'set_time_limit' => function_exists('set_time_limit')
                && !in_array('set_time_limit', array_map('trim', explode(',', $disabled)), true)
                ? 'available'
                : 'blocked',
            'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '__undefined__',
            'wp_max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? (string) WP_MAX_MEMORY_LIMIT : '__undefined__',
        ];
    }

    /**
     * The image half's cost, as far as it can be read without doing the work.
     *
     * The editor matters because GD and Imagick differ by multiples on the
     * same picture, and the number of sizes is the multiplier: every
     * registered size is produced inside
     * `wp_generate_attachment_metadata()`.
     *
     * @return array<string,string>
     */
    public static function imageWork(): array
    {
        $editor = '__unknown__';
        if (function_exists('_wp_image_editor_choose')) {
            $chosen = _wp_image_editor_choose(['mime_type' => 'image/jpeg']);
            $editor = is_string($chosen) && $chosen !== '' ? $chosen : '__none__';
        }
        $sizes = function_exists('wp_get_registered_image_subsizes')
            ? wp_get_registered_image_subsizes()
            : [];
        $threshold = function_exists('apply_filters')
            ? (int) apply_filters('big_image_size_threshold', 2560, [0, 0], '', 0)
            : 0;
        return [
            'editor_for_jpeg' => $editor,
            'registered_sizes' => (string) count(is_array($sizes) ? $sizes : []),
            'size_names' => implode(', ', array_keys(is_array($sizes) ? $sizes : [])),
            'big_image_size_threshold' => (string) $threshold,
            // Above the threshold WordPress also makes a scaled ORIGINAL, so
            // the operation count is the sizes plus one.
            'operations_per_picture_at_or_above_threshold' => (string) (count(is_array($sizes) ? $sizes : []) + 1),
        ];
    }

    /**
     * Who is registered on each intake hook, and in which file.
     *
     * ⛔ THIS IS THE ANSWER TO THE HOST'S HYPOTHESIS, and the honest form of
     * it. The marketplace plugin issues no outbound request anywhere — grep
     * over `src/` for `wp_remote_*`, `download_url`, `media_sideload_image`,
     * `curl_init` and `fsockopen` returns nothing, and an architecture test
     * keeps it that way. So if a request is waiting on an external service
     * during an import, the call is reached through one of these hooks, by
     * somebody else's code. Naming the file is what turns a guess into a
     * name.
     *
     * ⛔ And the limit of it: a plugin can issue an outbound call from code
     * this list does not cover — an autoloaded constructor, a shutdown
     * function, a hook we have not thought of. «جست‌وجوی چند نام تابع اثبات
     * نبودِ همهٔ تماس‌های خارجی نیست.» What `pre_http_request` and
     * `http_api_debug` give is the catch-all: anything going through
     * WordPress's HTTP API passes them, whoever started it.
     *
     * @return list<array{hook:string, count:int, callbacks:list<string>}>
     */
    public static function hooks(): array
    {
        /** @var array<string,mixed> $filters */
        $filters = $GLOBALS['wp_filter'] ?? [];
        $out = [];
        foreach (self::HOOKS as $hook) {
            $found = [];
            $registered = $filters[$hook] ?? null;
            if (is_object($registered) && isset($registered->callbacks) && is_array($registered->callbacks)) {
                foreach ($registered->callbacks as $priority => $group) {
                    if (!is_array($group)) {
                        continue;
                    }
                    foreach ($group as $entry) {
                        $found[] = (string) $priority . ' | ' . self::describe($entry['function'] ?? null);
                    }
                }
            }
            $out[] = ['hook' => $hook, 'count' => count($found), 'callbacks' => $found];
        }
        return $out;
    }

    /**
     * Is outbound HTTP blocked on this site at all, and by what.
     *
     * @return array<string,string>
     */
    public static function outbound(): array
    {
        return [
            'WP_HTTP_BLOCK_EXTERNAL' => defined('WP_HTTP_BLOCK_EXTERNAL')
                ? (WP_HTTP_BLOCK_EXTERNAL ? 'true' : 'false')
                : '__undefined__',
            'WP_ACCESSIBLE_HOSTS' => defined('WP_ACCESSIBLE_HOSTS')
                ? (string) WP_ACCESSIBLE_HOSTS
                : '__undefined__',
        ];
    }

    /**
     * The folder `wp_unique_filename()` scans before every copy.
     *
     * It reads the whole month's directory, so a month with tens of thousands
     * of entries is a cost the copy half pays on network-backed storage and
     * nowhere in a profile of our own code.
     *
     * @return array<string,string>
     */
    public static function uploadsMonth(): array
    {
        if (!function_exists('wp_upload_dir')) {
            return ['path' => '__unavailable__', 'entries' => '__unknown__'];
        }
        $dir = wp_upload_dir();
        $path = (string) ($dir['path'] ?? '');
        if ($path === '' || !is_dir($path)) {
            return ['path' => $path === '' ? '__unavailable__' : $path, 'entries' => '__unknown__'];
        }
        $entries = @scandir($path);
        return [
            'path' => $path,
            'entries' => is_array($entries) ? (string) max(0, count($entries) - 2) : '__unreadable__',
        ];
    }

    /**
     * The next few source pictures, with the dimensions that set their cost.
     *
     * Dimensions, bytes and how many sub-sizes already exist — nothing about
     * the product, and no title, filename or URL. `PICTURES` of them at most:
     * the question is what a picture on this site costs, and five answer it.
     *
     * @param list<int> $attachmentIds
     * @return list<array{id:int, size:string, bytes:string, subsizes:string}>
     */
    public static function pictures(array $attachmentIds): array
    {
        $out = [];
        foreach (array_slice($attachmentIds, 0, self::PICTURES) as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $file = function_exists('get_attached_file') ? get_attached_file($id) : '';
            $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
            $width = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
            $height = is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;
            $subsizes = is_array($meta) && is_array($meta['sizes'] ?? null) ? count($meta['sizes']) : 0;
            $out[] = [
                'id' => $id,
                'size' => $width > 0 && $height > 0 ? $width . '×' . $height : '__unknown__',
                'bytes' => is_string($file) && $file !== '' && is_file($file)
                    ? (string) filesize($file)
                    : '__unreadable__',
                'subsizes' => (string) $subsizes,
            ];
        }
        return $out;
    }

    /**
     * A callback, named by what it is and the file it is declared in.
     *
     * Reflection rather than `var_export`: the point is the FILE, because a
     * closure's name says nothing and `array($obj, 'method')` says only a
     * class. Anything that cannot be reflected is said to be unrecognised
     * rather than guessed at.
     */
    private static function describe(mixed $callback): string
    {
        try {
            if ($callback instanceof \Closure) {
                $r = new \ReflectionFunction($callback);
                return 'closure | ' . (string) $r->getFileName() . ':' . (string) $r->getStartLine();
            }
            if (is_string($callback)) {
                if (str_contains($callback, '::')) {
                    [$class, $method] = explode('::', $callback, 2);
                    $r = new \ReflectionMethod($class, $method);
                    return $callback . ' | ' . (string) $r->getFileName();
                }
                $r = new \ReflectionFunction($callback);
                return $callback . ' | ' . (string) $r->getFileName();
            }
            if (is_array($callback) && count($callback) === 2) {
                $owner = $callback[0];
                $name = is_object($owner) ? get_class($owner) : (string) $owner;
                $r = new \ReflectionMethod($name, (string) $callback[1]);
                return $name . '::' . (string) $callback[1] . ' | ' . (string) $r->getFileName();
            }
            if (is_object($callback) && method_exists($callback, '__invoke')) {
                $r = new \ReflectionMethod($callback, '__invoke');
                return get_class($callback) . '::__invoke | ' . (string) $r->getFileName();
            }
        } catch (\Throwable $e) {
            return '(callback could not be reflected)';
        }
        return '(unrecognised callback)';
    }
}
