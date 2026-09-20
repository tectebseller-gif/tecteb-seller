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
