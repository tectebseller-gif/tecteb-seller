<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the tools meant to be run on the owner's own site cannot
 * write to it.
 *
 * Every other tool in `tools/` refuses to run anywhere but a disposable
 * database, and `DisposableOnlyToolsTest` holds them to it. These are the
 * exceptions by design: §3, §4, §5 and §6 of the owner's instructions need
 * facts about `tecteb.com` and `staging.tecteb.com` that this repository does
 * not have, and the alternative to asking the site is guessing — which the
 * instruction forbids repeatedly, most plainly in §9-A:
 *
 *   «اگر نیاز به بررسی داده production است، ابزار فقط‌خواندنی آماده کن، اما
 *    خودت به سایت اصلی متصل نشو.»
 *
 * So the guard is different in kind. Rather than «refuse unless the database
 * is disposable», it is «contain nothing that could change anything», and that
 * is checked here by reading the file. A promise in a docblock is not a guard;
 * this is.
 *
 * ⛔ **And it is a SET, not a name.** Until `alpha.103` this file held
 * `private const TOOL` — one hard-coded path — so a second read-only tool
 * would have shipped under a test about the FIRST one. That is the
 * `alpha.97` failure in a new place: an exemption whose cover is somebody
 * else's. `DisposableOnlyToolsTest` points every read-only entry at a guard
 * file and insists the guard NAMES the tool, so adding a tool to that list
 * without adding it here now fails.
 *
 * Comments are blanked first, because bytes do not tell code from prose —
 * `alpha.22`'s rule. Each of these files explains in its own header that it
 * contains no `UPDATE` and no `DELETE`, and a byte scan would fail on the
 * explanation.
 */
final class ReadOnlyInventoryTest extends TestCase
{
    /**
     * Every tool this guard vouches for, and why it is allowed to run on a
     * real site. A new entry needs a reason written here, because a list of
     * exemptions with no reasons stops meaning anything.
     *
     * @var array<string,string>
     */
    private const TOOLS = [
        'tools/plugin-exit-inventory.php' =>
            'which plugin «Min Max Control» actually is, what meta keys it uses, what Dokan still holds',
        'tools/production-audit.php' =>
            '§3: one row per product and per Dokan financial record on production, which no amount of reading PHP produces',
        'tools/payment-readiness.php' =>
            '§4: the gateway settings and the three currency facts, side by side, with the credential described and never shown',
    ];

    /**
     * Anything that could write. Named individually rather than matched by a
     * pattern, so adding one has to be a deliberate act with a reason.
     *
     * @var list<string>
     */
    private const WRITERS = [
        'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_metadata',
        'update_option', 'add_option', 'delete_option', 'update_site_option',
        'update_user_meta', 'add_user_meta', 'delete_user_meta',
        'update_term_meta', 'add_term_meta', 'delete_term_meta',
        'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post',
        'wp_insert_term', 'wp_update_term', 'wp_delete_term',
        'wp_insert_user', 'wp_update_user', 'wp_delete_user',
        'wp_schedule_event', 'wp_schedule_single_event', 'wp_clear_scheduled_hook',
        'activate_plugin', 'deactivate_plugins',
        // wpdb's own writers, and the two helpers that wrap them.
        '->insert(', '->update(', '->replace(', '->delete(', '->query(',
    ];

    /** SQL that changes something, in whatever string it is hidden. */
    private const WRITING_SQL = [
        'INSERT ', 'UPDATE ', 'DELETE ', 'ALTER ', 'DROP ', 'TRUNCATE ', 'CREATE ', 'REPLACE ',
    ];

    /**
     * ⛔ And one more class of thing, added with the second and third tools:
     * an outbound HTTP request. It is not a WRITE to this site, so the list
     * above would wave it through — and a «readiness probe» that opens a
     * connection from the owner's production server is a different act from
     * reading its settings. The whole plugin makes zero outbound requests
     * (measured), and these tools keep that true.
     *
     * @var list<string>
     */
    private const OUTBOUND = [
        'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'wp_remote_head',
        'curl_exec(', 'file_get_contents(\'http', 'fsockopen(', 'stream_socket_client(',
    ];

    /** @return iterable<string,array{0:string,1:string}> */
    public static function tools(): iterable
    {
        foreach (self::TOOLS as $path => $why) {
            yield $path => [$path, $why];
        }
    }

    /** @dataProvider tools */
    public function testTheToolCannotWriteAnything(string $relative, string $why): void
    {
        $code = self::codeWithoutComments(self::path($relative));

        $found = [];
        foreach (self::WRITERS as $writer) {
            if (str_contains($code, $writer)) {
                $found[] = $writer;
            }
        }
        foreach (self::WRITING_SQL as $sql) {
            if (stripos($code, $sql) !== false) {
                $found[] = 'SQL: ' . trim($sql);
            }
        }

        self::assertSame(
            [],
            $found,
            $relative . " is meant to be safe to run on the owner's own site (" . $why
                . "), and these could change it:\n" . implode("\n", $found)
        );
    }

    /** @dataProvider tools */
    public function testTheToolSendsNothing(string $relative, string $why): void
    {
        $code = self::codeWithoutComments(self::path($relative));

        $found = [];
        foreach (self::OUTBOUND as $needle) {
            if (str_contains($code, $needle)) {
                $found[] = $needle;
            }
        }

        self::assertSame(
            [],
            $found,
            $relative . ' opens a connection from the owner server: ' . implode(' ', $found)
        );
    }

    /**
     * And they really are the tools the documentation points at — a test about
     * a file that has been renamed proves nothing.
     */
    /** @dataProvider tools */
    public function testTheToolExistsAndSaysItIsReadOnly(string $relative, string $why): void
    {
        $path = self::path($relative);
        self::assertFileExists($path);
        self::assertStringContainsString(
            'READ ONLY',
            (string) file_get_contents($path),
            'the tool must say what it is, where somebody about to run it will read it'
        );
    }

    /**
     * ⛔ The two lists must agree in BOTH directions.
     *
     * A tool on `DisposableOnlyToolsTest::READ_ONLY` that points here but is
     * not in `self::TOOLS` would be exempted from the disposable refusal with
     * nothing actually checking it — the hole this round closed. And a tool
     * here that is NOT on that list is a tool nobody exempted, which means
     * either the list is stale or this one is.
     */
    public function testEveryToolHereIsAlsoOnTheReadOnlyExemptionList(): void
    {
        $guard = (string) file_get_contents(__DIR__ . '/Tools/DisposableOnlyToolsTest.php');

        foreach (array_keys(self::TOOLS) as $relative) {
            $name = basename($relative);
            self::assertStringContainsString(
                "'" . $name . "' => 'ReadOnlyInventoryTest.php'",
                $guard,
                $name . ' is vouched for here but does not point here from the exemption list'
            );
        }

        // The other direction: anything pointing at this file must be here.
        preg_match_all(
            "/'([a-z0-9\-]+\.php)' => 'ReadOnlyInventoryTest\.php'/",
            $guard,
            $matches
        );
        $pointed = array_map('basename', array_keys(self::TOOLS));
        sort($pointed);
        $claimed = $matches[1];
        sort($claimed);
        self::assertSame(
            $pointed,
            $claimed,
            'the exemption list and this guard name different sets of tools'
        );
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    /**
     * Comments blanked, strings kept.
     *
     * A tokenizer rather than a regex: the docblock of each of these tools
     * lists `UPDATE` and `DELETE` while explaining that it contains neither,
     * and a byte scan would fail on the explanation.
     */
    private static function codeWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }
}
