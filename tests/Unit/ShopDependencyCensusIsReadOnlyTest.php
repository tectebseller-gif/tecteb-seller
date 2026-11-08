<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: the census tool cannot change the site it counts.
 *
 * It is the second tool here meant to be run on the owner's own site, and the
 * reason is the same as the first: «آیا فروشگاه واقعاً بدون محصول است؟ این
 * موضوع را از سورس به‌تنهایی قابل اثبات ندان». A census of what a shop id owns
 * is a statement about rows, and the alternative to asking the site is a
 * guess about whether an account can go.
 *
 * ⛔ And the stakes make the guard stricter than usual rather than looser:
 * this tool exists to inform a DELETION decision, so a tool that could delete
 * while surveying would be the worst possible shape.
 *
 * So the guard is different in kind. Rather than «refuse unless the database
 * is disposable», it is «contain nothing that could change anything», and
 * that is checked here by reading the file. A promise in a docblock is not a
 * guard; this is.
 *
 * Comments are blanked first, because bytes do not tell code from prose —
 * `alpha.22`'s rule, and the thing `SecurityRulesTest` still does not do.
 */
final class ShopDependencyCensusIsReadOnlyTest extends TestCase
{
    private const TOOL = 'tools/shop-dependency-census.php';

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

    public function testTheCensusToolCannotWriteAnything(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../' . self::TOOL);

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
            self::TOOL . " is meant to be safe to run on the owner's own site, and these could change it:\n"
                . implode("\n", $found)
        );
    }

    /**
     * And it really is the tool the documentation points at — a test about a
     * file that has been renamed proves nothing.
     */
    public function testTheCensusToolExistsAndSaysItIsReadOnly(): void
    {
        $path = __DIR__ . '/../../' . self::TOOL;
        self::assertFileExists($path);
        self::assertStringContainsString(
            'READ ONLY',
            (string) file_get_contents($path),
            'the tool must say what it is, where somebody about to run it will read it'
        );
    }

    /**
     * Comments blanked, strings kept.
     *
     * A tokenizer rather than a regex: the docblock of this very tool lists
     * `UPDATE` and `DELETE` while explaining that it contains neither, and a
     * byte scan would fail on the explanation.
     */
    /**
     * ⛔ And the list it asks must be the list the migrations create.
     *
     * A census is only as good as its set of tables: ask twenty of the
     * twenty-nine and the answer «this shop owns nothing» is a statement about
     * the twenty somebody thought of. So the `CREATE TABLE` statements are
     * walked here and every table with a vendor-ish column has to appear in
     * `TmcShopCensusTables::TABLES` — which means a migration that adds one
     * breaks this test rather than quietly narrowing the census.
     */
    public function testTheCensusAsksEveryTableThatCarriesAVendorId(): void
    {
        $root = dirname(__DIR__, 2);
        $declared = [];
        foreach (self::createdTables($root . '/src') as $table => $columns) {
            $vendorish = array_values(array_intersect(
                $columns,
                ['vendor_user_id', 'vendor_id', 'live_vendor_user_id']
            ));
            if ($vendorish !== []) {
                $declared[] = $table;
            }
        }
        sort($declared);

        $asked = (string) file_get_contents($root . '/' . self::TOOL);
        $missing = [];
        foreach ($declared as $table) {
            if (!str_contains($asked, "'" . $table . "'")) {
                $missing[] = $table;
            }
        }

        self::assertNotSame([], $declared, 'the migrations were not parsed at all');
        self::assertSame(
            [],
            $missing,
            "these tables carry a vendor id and the census never asks about them:\n" . implode("\n", $missing)
        );
    }

    /**
     * Every table a migration creates, with its column names.
     *
     * @return array<string,list<string>>
     */
    private static function createdTables(string $dir): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_starts_with($file->getFilename(), 'M0')) {
                continue;
            }
            $text = (string) file_get_contents((string) $file);
            // ⛔ Both spellings. Most migrations declare the whole name
            // (`'tmc_products'`); `M0019BaselineAndDecisions` declares
            // `'product_decisions'` and prepends `tmc_` in its own `table()`.
            // A parser that only knew the first spelling passed this test
            // while the census was missing `tmc_product_decisions` — a guard
            // that measured nothing and looked like a pass (`alpha.80`).
            $prepends = str_contains($text, "prefix() . 'tmc_' . \$suffix");
            preg_match_all("/const\s+[A-Z_]+\s*=\s*'((?:tmc_)?[a-z_]+)'/", $text, $consts);
            if (($consts[1] ?? []) === []) {
                continue;
            }
            // Which table a CREATE belongs to is read from the constant named
            // just above it, the way the file itself reads.
            $offset = 0;
            while (($at = strpos($text, 'CREATE TABLE IF NOT EXISTS', $offset)) !== false) {
                $offset = $at + 1;
                $head = substr($text, max(0, $at - 260), min(260, $at));
                preg_match_all('/self::([A-Z_]+)/', $head, $used);
                $name = null;
                foreach (array_reverse($used[1] ?? []) as $constant) {
                    $pattern = "/const\s+" . preg_quote($constant, '/') . "\s*=\s*'((?:tmc_)?[a-z_]+)'/";
                    if (preg_match($pattern, $text, $m) === 1) {
                        $name = $m[1];
                        if ($prepends && !str_starts_with($name, 'tmc_')) {
                            $name = 'tmc_' . $name;
                        }
                        break;
                    }
                }
                if ($name !== null && !str_starts_with($name, 'tmc_')) {
                    // A constant that is not a table name at all — a column, a
                    // status word. Only `tmc_`-prefixed names are tables here.
                    $name = null;
                }
                if ($name === null) {
                    continue;
                }
                $open = strpos($text, '(', $at);
                if ($open === false) {
                    continue;
                }
                $depth = 1;
                $i = $open + 1;
                while ($i < strlen($text) && $depth > 0) {
                    if ($text[$i] === '(') {
                        $depth++;
                    } elseif ($text[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }
                preg_match_all('/`([a-z_]+)`\s+[A-Z]/', substr($text, $open, $i - $open), $cols);
                $out[$name] = $cols[1] ?? [];
            }
        }
        return $out;
    }

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
