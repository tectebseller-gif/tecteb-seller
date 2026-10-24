<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Operations\Infrastructure\WordPress\IntakeDiagnostics;

/**
 * The intake diagnostic reads, and that is asserted from the bytes.
 *
 * ⛔ Same guard as `ReadOnlyInventoryTest` has over
 * `tools/plugin-exit-inventory.php`, and for a stronger reason: that tool is
 * run deliberately from a shell, while this one renders on a page a manager
 * opens, on the owner's staging site, while a stalled import may be holding a
 * lease. A diagnostic that can change what it is measuring is not a
 * diagnostic — and a sentence in a docblock saying so is not a guard
 * (`alpha.62`).
 *
 * Comments are stripped first: this file's own prose names most of the
 * forbidden functions (`alpha.22`).
 */
final class IntakeDiagnosticsAreReadOnlyTest extends TestCase
{
    private const FILE = 'src/Modules/Operations/Infrastructure/WordPress/IntakeDiagnostics.php';

    /** Everything that could change this site, grouped by what it would change. */
    private const FORBIDDEN = [
        // WordPress data
        'update_option', 'add_option', 'delete_option', 'update_site_option',
        'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_metadata',
        'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post',
        'wp_insert_attachment', 'wp_update_attachment_metadata', 'wp_delete_attachment',
        'wp_insert_term', 'wp_update_term', 'wp_delete_term', 'wp_set_object_terms',
        'wp_insert_user', 'wp_update_user', 'wp_delete_user',
        'update_user_meta', 'add_user_meta', 'delete_user_meta',
        // the database, directly
        '$wpdb', 'query(', 'execute(',
        // the filesystem
        'file_put_contents', 'fopen', 'unlink', 'copy(', 'rename', 'mkdir', 'touch(',
        // the queue and the clock
        'set_time_limit', 'ini_set', 'wp_schedule_event', 'wp_schedule_single_event',
        // and anything outbound: a diagnostic that phones out is the very
        // thing the diagnostic is looking for.
        'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_safe_remote_get',
        'download_url', 'media_sideload_image', 'curl_init', 'fsockopen', 'file_get_contents',
    ];

    private function code(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::FILE;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * Matched as CALLS, with the string literals taken out first.
     *
     * ⛔ Two traps the first version of this test fell into, both of this
     * repository's own making. `wp_insert_attachment` is a substring of
     * `wp_insert_attachment_data`, which is a HOOK NAME on the list above
     * (`alpha.33`); and `'set_time_limit'` appears inside
     * `function_exists('set_time_limit')`, which asks whether the host has
     * taken it away — a read, and one of the facts worth printing. A rule
     * matched against raw bytes calls both of those a write.
     */
    private function calls(): string
    {
        $code = $this->code();
        // Every single-quoted literal becomes `''`: a hook name in a list and
        // a function name being asked about are both data, not calls.
        return (string) (preg_replace("~'(?:\\\\.|[^'\\\\])*'~s", "''", $code) ?? $code);
    }

    public function testItCannotChangeAnythingOnTheSite(): void
    {
        $code = $this->calls();
        $found = [];
        foreach (self::FORBIDDEN as $needle) {
            $call = str_ends_with($needle, '(')
                ? '~\b' . preg_quote(substr($needle, 0, -1), '~') . '\s*\(~'
                : '~\b' . preg_quote($needle, '~') . '\s*\(~';
            if ($needle === '$wpdb') {
                if (str_contains($code, $needle)) {
                    $found[] = $needle;
                }
                continue;
            }
            if (preg_match($call, $code) === 1) {
                $found[] = $needle;
            }
        }
        self::assertSame([], $found, 'the diagnostic must only read');
    }

    /**
     * And the matcher really does catch a write — falsified here rather than
     * believed.
     *
     * A test whose pattern silently matches nothing passes on any file at
     * all, which is how «the guard exists» becomes «the guard was never
     * exercised».
     */
    public function testTheMatcherWouldCatchAWriteIfThereWereOne(): void
    {
        $sample = '<?php update_option("x", 1); $a = wp_insert_attachment_data($b);';
        $stripped = (string) preg_replace("~'(?:\\\\.|[^'\\\\])*'~s", "''", $sample);
        self::assertSame(1, preg_match('~\bupdate_option\s*\(~', $stripped), 'a real call must match');
        self::assertSame(
            0,
            preg_match('~\bwp_insert_attachment\s*\(~', $stripped),
            'and the longer hook name must not be read as the shorter call'
        );
    }

    /**
     * And it is bounded — «ابزار تشخیص باید کافی و محدود باشد».
     *
     * The picture list is capped by a constant rather than by whatever the
     * caller passes, and the hook list is fixed. Neither grows with the
     * catalogue, so the page costs the same on a hundred products and on a
     * hundred thousand.
     */
    public function testItIsBoundedByItsOwnConstants(): void
    {
        self::assertStringContainsString(
            'array_slice($attachmentIds, 0, self::PICTURES)',
            $this->code(),
            'the caller may not ask for more pictures than the cap'
        );
        self::assertLessThanOrEqual(10, IntakeDiagnostics::PICTURES);
        self::assertNotSame([], IntakeDiagnostics::HOOKS);

        // A reading of the cap that cannot be satisfied by a comment: ask for
        // a hundred ids and count what comes back. No WordPress here, so the
        // rows are «unreadable» — which is the right answer about a file that
        // does not exist, and still exactly `PICTURES` of them.
        $rows = IntakeDiagnostics::pictures(range(1, 100));
        self::assertCount(IntakeDiagnostics::PICTURES, $rows);
    }

    /**
     * The hook list is the same one the tool uses.
     *
     * Two answers to «who is on the intake path» that could disagree would be
     * worse than one, and the one that was wrong would be invisible. So the
     * lists are compared, literally.
     */
    public function testTheHookListMatchesTheToolsOwn(): void
    {
        $tool = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/plugin-exit-inventory.php');
        foreach (IntakeDiagnostics::HOOKS as $hook) {
            self::assertStringContainsString(
                "'" . $hook . "'",
                $tool,
                'the tool must watch the same hook: ' . $hook
            );
        }
        // And the other direction: the three catch-all HTTP hooks must be on
        // this list, because they are what makes «no outbound call» a
        // measurement rather than a grep.
        foreach (['pre_http_request', 'http_api_debug', 'http_request_args'] as $catchAll) {
            self::assertContains($catchAll, IntakeDiagnostics::HOOKS);
        }
    }

    /**
     * No step name, product title, path or amount is recorded by it.
     *
     * The values it returns are PHP settings, dimensions, counts and the file
     * paths of PLUGIN CODE — which is the answer to «who is on this hook» and
     * is not somebody's data. It must not return anything from a customer, an
     * order or a request.
     */
    public function testItReadsNothingFromARequestOrACustomer(): void
    {
        $code = $this->code();
        foreach (['$_POST', '$_GET', '$_COOKIE', '$_REQUEST', '$_SERVER', 'post_title', 'user_email', 'order'] as $needle) {
            self::assertStringNotContainsString($needle, $code, 'must not read ' . $needle);
        }
    }
}
