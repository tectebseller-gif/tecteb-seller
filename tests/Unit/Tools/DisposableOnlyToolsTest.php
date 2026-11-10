<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: no script in `tools/` can touch WordPress before it has
 * refused to run outside the disposable install.
 *
 * These scripts write. They create shops, products, orders and refunds, they
 * drop rows, and several of them deliberately break things to photograph the
 * breakage. On a disposable container that is the job. On the owner's site it
 * is damage, and the only thing standing between the two is which shell the
 * command was pasted into.
 *
 * Until `alpha.22` the protection was a docblock. Sixteen files then got a
 * real runtime refusal — and twelve did not, which is the failure mode this
 * project keeps meeting: «نصفهٔ یک اصلاح، اصلاح نیست». A guard applied to
 * some of the set is indistinguishable, from the shell, from no guard.
 *
 * ### Why the ORDER is asserted, not just the presence
 *
 * A refusal placed after the first WordPress call is not a refusal. So this
 * measures the byte offset: the `DB_NAME` check must come before any
 * WordPress marker other than the `home_url()` the guard itself calls. A
 * check that presence alone would accept — guard at the bottom of the file —
 * fails here, which is the point.
 */
final class DisposableOnlyToolsTest extends TestCase
{
    /**
     * Files that legitimately need no guard, each with the reason it is
     * exempt. An exemption without a reason is how a list like this stops
     * meaning anything, so a new entry needs one written here.
     */
    private const NO_WORDPRESS = [
        // Pure arithmetic over the brand palette. Never loads WordPress, so
        // there is no site it could be pointed at.
        'contrast.php' => 'computes WCAG ratios; defines and calls no WordPress symbol',
        // The rasteriser, split out of demo-image.php in `alpha.23`. It draws
        // PNG bytes and returns them; the caller decides where they go, and
        // the WordPress-side caller (demo-image.php) carries the guard.
        'demo-image-shapes.php' => 'pure rasteriser; returns PNG bytes and writes nothing by itself',
        'demo-translations.php' => 'standalone .mo writer; takes a directory argument and never loads WordPress',
        'demo-router.php' => 'web-server router, copied into the demo root; has no CLI entry point to guard',
    ];

    /**
     * Tools that DO read WordPress and still need no disposable refusal,
     * because they cannot change anything.
     *
     * A third case, and it needed its own list. The two above are «touches no
     * WordPress» and «touches WordPress, so it must refuse off the disposable
     * install» — and between them sits the one tool that is meant to be run
     * on the owner's own site. §5 and §6 of the `alpha.45` instruction need
     * facts about `staging.tecteb.com` that this repository does not have:
     * which plugin «Min Max Control» actually is, what meta keys it uses,
     * what Dokan still holds. The alternative to asking the site is guessing,
     * which the instruction forbids twice.
     *
     * A disposable-database refusal would make such a tool useless for the
     * one job it exists for, so the guard is a different kind — and it is a
     * TEST, not a sentence here: a read-only test reads the file and fails if
     * it gains any writer, WordPress function, wpdb method or writing SQL.
     *
     * ⛔ **The value is the test that covers it, and that is the point.** Until
     * there were two such tools the assertion below looked inside one
     * hard-coded file, so a second entry would have been waved through by a
     * test about the FIRST tool — an exemption whose cover was somebody
     * else's. Each entry now names its own guard, the file has to exist, and
     * it has to name the tool.
     */
    private const READ_ONLY = [
        'plugin-exit-inventory.php' => 'ReadOnlyInventoryTest.php',
        // The second entry, and the reason is the same shape as the first: the
        // owner's instruction says «آیا فروشگاه واقعاً بدون محصول است؟ این
        // موضوع را از سورس به‌تنهایی قابل اثبات ندان», and a census of what a
        // shop id owns is a statement about rows that no amount of reading PHP
        // produces. A disposable-database refusal would make it useless for
        // the one question it exists to answer — whether account 2 and shop 40
        // can be archived — and the guard is
        // `ShopDependencyCensusIsReadOnlyTest`, which the assertion below
        // insists exists and names this file.
        'shop-dependency-census.php' => 'ShopDependencyCensusIsReadOnlyTest.php',
        // ⛔ The third and fourth, and the list that vouches for them is now a
        // SET rather than a hard-coded name. `ReadOnlyInventoryTest` held
        // `private const TOOL` — one path — so a second entry pointing at it
        // would have been waved through by a test about the FIRST tool. That
        // is `alpha.97`'s lesson repeating one layer down, and the fix is the
        // same: the guard names every tool it covers, and
        // `ReadOnlyInventoryTest::testEveryToolHereIsAlsoOnTheReadOnlyExemptionList`
        // compares the two lists in BOTH directions, so neither can drift.
        //
        // Reason for each, as the rule above demands:
        //  - production-audit.php: §3 wants one row per product and per Dokan
        //    financial record on production. No amount of reading PHP produces
        //    rows, and §9-A forbids this repository from connecting.
        //  - payment-readiness.php: §4 wants the gateway settings and the three
        //    currency facts side by side. It prints the credential's length and
        //    shape and never its value, and it sends NOTHING — the guard checks
        //    that too, because an outbound request is not a write to this site
        //    and the writer list would have waved it through.
        'production-audit.php' => 'ReadOnlyInventoryTest.php',
        'payment-readiness.php' => 'ReadOnlyInventoryTest.php',
    ];

    /** The refusal, verbatim enough that a rewrite has to be deliberate. */
    private const DB_CHECK = "DB_NAME !== 'tmc_wp_test'";

    private const HOST_CHECK = '127\.0\.0\.1|localhost';

    /**
     * Markers that mean «this file is talking to WordPress». `home_url` is
     * deliberately absent: the guard calls it, so including it would make
     * every guarded file look like it reached WordPress first.
     */
    private const WORDPRESS_MARKERS = [
        'get_option(', 'update_option(', 'delete_option(',
        'wp_insert_', 'wp_update_', 'wp_delete_', 'wp_set_',
        'get_users(', 'get_user_by(', 'get_post(', 'get_posts(',
        'wc_get_', 'wc_create_', 'wc_update_',
        '$wpdb', 'add_action(', 'do_action(', 'apply_filters(',
        // `$GLOBALS['wpdb']` does NOT contain `$wpdb`, and that is not a
        // nitpick: `description-state.php` reaches the database through
        // exactly that spelling, and this list waved it through as «touches no
        // WordPress» — which would have put a tool that WRITES product rows on
        // the exemption list, under a reason that was false. `home_url(` is
        // here for the same reason: a tool can read the site without naming
        // `$wpdb` at all.
        "\$GLOBALS['wpdb']", 'home_url(', 'current_time(',
    ];

    /**
     * The file with every comment blanked out, byte offsets preserved.
     *
     * Without this the order check reads docblocks: `refund-crash-probe.php`
     * EXPLAINS `wc_create_refund()` in its header, and two files failed the
     * first run of this test for prose rather than for code. Blanking with
     * spaces rather than deleting keeps the reported offset the offset in the
     * real file, so a failure message points at a line somebody can open.
     */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $isComment = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
            // Newlines are kept so the blanked region still spans the same
            // lines; everything else in a comment becomes a space.
            $out .= $isComment ? preg_replace('/[^\n]/', ' ', $text) : $text;
        }
        self::assertSame(strlen($src), strlen($out), 'blanking changed the length, so offsets would lie');
        return $out;
    }

    /** @return list<array{0:string,1:string}> path and basename */
    private function toolScripts(): array
    {
        $dir = dirname(__DIR__, 3) . '/tools';
        $found = glob($dir . '/*.php');
        self::assertIsArray($found);
        // A directory that stopped matching would make every assertion below
        // vacuously true, so the count is asserted before anything is read.
        self::assertGreaterThan(20, count($found), 'tools/*.php matched almost nothing — the glob is wrong, not the tree');

        $out = [];
        foreach ($found as $path) {
            $out[] = [$path, basename($path)];
        }
        return $out;
    }

    public function testEveryToolThatTouchesWordPressRefusesOffTheDisposableInstall(): void
    {
        $missing = [];
        $guarded = 0;

        foreach ($this->toolScripts() as [$path, $name]) {
            $src = $this->codeOnly((string) file_get_contents($path));
            $touches = false;
            foreach (self::WORDPRESS_MARKERS as $marker) {
                if (str_contains($src, $marker)) {
                    $touches = true;
                    break;
                }
            }

            if (!$touches) {
                self::assertArrayHasKey(
                    $name,
                    self::NO_WORDPRESS,
                    $name . ' touches no WordPress symbol but is not on the exemption list — add it there WITH a reason'
                );
                continue;
            }

            self::assertArrayNotHasKey($name, self::NO_WORDPRESS, $name . ' is exempted but does touch WordPress');

            // A read-only tool needs no refusal — but only while something
            // else proves it is read-only.
            if (isset(self::READ_ONLY[$name])) {
                $guard = __DIR__ . '/../' . self::READ_ONLY[$name];
                self::assertFileExists(
                    $guard,
                    $name . ' is on the read-only list and names a guard that does not exist'
                );
                self::assertStringContainsString(
                    $name,
                    (string) file_get_contents($guard),
                    $name . ' is on the read-only list but ' . self::READ_ONLY[$name] . ' does not name it'
                );
                continue;
            }

            if (!str_contains($src, self::DB_CHECK) || !preg_match('~' . self::HOST_CHECK . '~', $src)) {
                $missing[] = $name;
                continue;
            }
            $guarded++;
        }

        self::assertSame([], $missing, 'these tools write to WordPress with no refusal: ' . implode(', ', $missing));
        self::assertGreaterThanOrEqual(28, $guarded, 'far fewer guarded files than this repository has — the marker list has drifted');
    }

    public function testTheRefusalComesBeforeAnythingReachesWordPress(): void
    {
        $late = [];

        foreach ($this->toolScripts() as [$path, $name]) {
            $src = $this->codeOnly((string) file_get_contents($path));
            $guardAt = strpos($src, self::DB_CHECK);
            if ($guardAt === false) {
                continue; // covered by the test above
            }

            foreach (self::WORDPRESS_MARKERS as $marker) {
                $at = strpos($src, $marker);
                if ($at !== false && $at < $guardAt) {
                    $late[] = $name . ' reaches ' . $marker . ' at byte ' . $at . ', before its refusal at ' . $guardAt;
                    break;
                }
            }
        }

        self::assertSame([], $late, implode("\n", $late));
    }

    /**
     * Both halves, not one. The database name alone would pass on a second
     * disposable database served over a public hostname; the hostname alone
     * would pass on a local copy of the real database, which is exactly the
     * shape a restore-to-localhost has.
     */
    public function testBothHalvesOfTheRefusalArePresentWhereverEitherIs(): void
    {
        $half = [];

        foreach ($this->toolScripts() as [$path, $name]) {
            $src = $this->codeOnly((string) file_get_contents($path));
            $db = str_contains($src, self::DB_CHECK);
            $host = (bool) preg_match('~' . self::HOST_CHECK . '~', $src);
            if ($db !== $host) {
                $half[] = $name . ' has ' . ($db ? 'the database check only' : 'the host check only');
            }
        }

        self::assertSame([], $half, implode("\n", $half));
    }
}
